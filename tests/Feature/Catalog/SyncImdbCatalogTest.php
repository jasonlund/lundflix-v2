<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\ImdbDatasetMarker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures (byte-exact real source slices)
|--------------------------------------------------------------------------
| tests/Fixtures/Catalog/imdb/title.ratings.tsv.gz — tt0133093 8.7/2252453,
|   tt0137523 8.8/2615814, tt0816692 8.7/2541567, tt0000001 5.7/2211.
| tests/Fixtures/Catalog/imdb/title.basics.tsv.gz — 6 rows incl. tt0133093
|   (movie / The Matrix / 1999 / 136 / Action,Sci-Fi) and tt0903747 (tvSeries /
|   Breaking Bad), which the ratings fixture deliberately lacks.
| tests/Fixtures/Catalog/imdb/title.akas.tsv.gz — 5 titles' contiguous aka rows,
|   incl. tt0133093 (67 rows).
| All three fixtures carry tt0133093, so one seeded row proves each of the three
| legs landed on it.
|
| No TMDB/TVDB leg runs under catalog:sync-imdb, so the row the legs enrich is
| factory-seeded rather than born from an upstream sync.
|
| The gate probes with a HEAD and downloads with a GET against the same dataset
| URL. The url-keyed fakes below answer both with the fixture bytes: a HEAD with
| no Last-Modified header leaves the gate ungated, which is what the un-forced
| tests want. The forced test dispatches on $request->method() so it can hand the
| probe a real header to compare against a pre-advanced marker.
*/

/**
 * Fake the three IMDb datasets with their happy-path fixtures.
 */
function fakeImdbDatasets(): void
{
    Http::fake([
        '*title.ratings*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz')),
        '*title.basics*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz')),
        '*title.akas*' => Http::response(fixtureBytes('Catalog/imdb/title.akas.tsv.gz')),
    ]);
}

/**
 * A GET for the named dataset file, ignoring the gate's HEAD probe of the same URL.
 */
function downloadedImdbDataset(string $filename): Closure
{
    return fn (Request $request): bool => $request->method() === 'GET'
        && Str::contains($request->url(), $filename);
}

it('runs all three IMDb legs and enriches the catalog', function (): void {
    // Arrange
    $matrix = Movie::factory()->create([
        '_imdb_id' => 'tt0133093',
        '_imdb_numVotes' => 1,
        '_imdb_averageRating' => 1.0,
    ]);
    fakeImdbDatasets();

    // Act & Assert
    $this->artisan('catalog:sync-imdb')->assertExitCode(Command::SUCCESS);

    // Assert
    Http::assertSent(downloadedImdbDataset('title.ratings'));
    Http::assertSent(downloadedImdbDataset('title.basics'));
    Http::assertSent(downloadedImdbDataset('title.akas'));

    $matrix->refresh();
    expect($matrix->_imdb_numVotes)->toBe(2252453)
        ->and($matrix->_imdb_averageRating)->toBe(8.7)
        ->and($matrix->_imdb_titleType)->toBe('movie')
        ->and($matrix->_imdb_primaryTitle)->toBe('The Matrix')
        ->and($matrix->_imdb_genres)->toBe(['Action', 'Sci-Fi'])
        ->and($matrix->_imdb_akas)->toBeArray()->not->toBeEmpty();
});

it('leaves a title absent from the ratings dataset unrated', function (): void {
    // Arrange
    Movie::factory()->create(['_imdb_id' => 'tt0133093']);
    $breakingBad = Show::factory()->create([
        '_imdb_id' => 'tt0903747',
        '_imdb_numVotes' => null,
        '_imdb_averageRating' => null,
    ]);
    fakeImdbDatasets();

    // Act
    $this->artisan('catalog:sync-imdb');

    // Assert
    // Breaking Bad sits in the basics fixture but not the ratings one, so the
    // basics landing proves the legs ran against it — which is what makes the
    // still-null rating a real miss rather than an untouched row.
    $breakingBad->refresh();
    expect($breakingBad->_imdb_titleType)->toBe('tvSeries')
        ->and($breakingBad->_imdb_primaryTitle)->toBe('Breaking Bad')
        ->and($breakingBad->_imdb_numVotes)->toBeNull()
        ->and($breakingBad->_imdb_averageRating)->toBeNull();
});

it('continues past a failing titles leg, exits FAILURE and still runs akas', function (): void {
    // Arrange
    Sleep::fake();
    Exceptions::fake();
    $matrix = Movie::factory()->create(['_imdb_id' => 'tt0133093']);
    // Http::fake merges stubs and the first registered match wins, so this 500
    // registered ahead of the happy-path helper overrides only the basics fetch.
    Http::fake(['*title.basics*' => Http::response('', 500)]);
    fakeImdbDatasets();

    // Act & Assert
    $this->artisan('catalog:sync-imdb')->assertExitCode(Command::FAILURE);

    // Assert
    Exceptions::assertReported(fn (RequestException $e): bool => true);
    Http::assertSent(downloadedImdbDataset('title.akas'));
    expect($matrix->refresh()->_imdb_akas)->toBeArray()->not->toBeEmpty();
});

it('continues past a failing ratings leg and still runs titles and akas', function (): void {
    // Arrange
    Sleep::fake();
    Exceptions::fake();
    $matrix = Movie::factory()->create(['_imdb_id' => 'tt0133093']);
    Http::fake(['*title.ratings*' => Http::response('', 500)]);
    fakeImdbDatasets();

    // Act
    $this->artisan('catalog:sync-imdb');

    // Assert
    Http::assertSent(downloadedImdbDataset('title.basics'));
    Http::assertSent(downloadedImdbDataset('title.akas'));

    $matrix->refresh();
    expect($matrix->_imdb_titleType)->toBe('movie')
        ->and($matrix->_imdb_primaryTitle)->toBe('The Matrix')
        ->and($matrix->_imdb_akas)->toBeArray()->not->toBeEmpty();
});

it('emits a phase line and an elapsed heartbeat per leg', function (): void {
    // Arrange
    Movie::factory()->create(['_imdb_id' => 'tt0133093']);
    fakeImdbDatasets();

    // Act & Assert
    // Shape only — wall clock is not freezable, so the elapsed value itself is
    // never asserted. The phase wording is deliberately distinct from each leg's
    // own "Importing IMDb …" line, so the wrapper's output is what is proven.
    $this->artisan('catalog:sync-imdb')
        ->expectsOutputToContain('Syncing IMDb ratings')
        ->expectsOutputToContain('Syncing IMDb titles')
        ->expectsOutputToContain('Syncing IMDb akas')
        ->expectsOutputToContain('[elapsed');
});

it('forwards --force so every dataset downloads despite matching markers', function (): void {
    // Arrange
    $header = 'Tue, 12 Aug 2026 01:02:03 GMT';
    $marker = resolve(ImdbDatasetMarker::class);
    $marker->advance(ImdbDataset::TitleRatings, $header);
    $marker->advance(ImdbDataset::TitleBasics, $header);
    $marker->advance(ImdbDataset::TitleAkas, $header);
    Movie::factory()->create(['_imdb_id' => 'tt0133093']);
    Http::fake(fn (Request $request) => $request->method() === 'HEAD'
        ? Http::response('', 200, ['Last-Modified' => $header])
        : Http::response(fixtureBytes('Catalog/imdb/'.Str::afterLast($request->url(), '/'))));

    // Act
    $this->artisan('catalog:sync-imdb', ['--force' => true]);

    // Assert
    Http::assertSent(downloadedImdbDataset('title.ratings'));
    Http::assertSent(downloadedImdbDataset('title.basics'));
    Http::assertSent(downloadedImdbDataset('title.akas'));
});
