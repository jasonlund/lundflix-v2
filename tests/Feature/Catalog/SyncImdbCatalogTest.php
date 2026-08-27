<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\ImdbDatasetMarker;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;

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

/**
 * A Scout engine spy whose first update() throws and whose later calls capture.
 *
 * Models a search-engine outage that opens mid-run — the movies reindex dies on
 * its first push, and whether the shows reindex still gets to run is the subject.
 * Register it after Arrange, like spyOnScoutEngine(), so factory auto-syncs don't
 * spend the one throw.
 *
 * @return Closure(): list<list<int|string>> the chunks captured after the throw
 */
function spyOnScoutEngineFailingOnce(): Closure
{
    $captured = [];
    $updates = 0;

    $spy = Mockery::spy(Engine::class);

    $spy->shouldReceive('update')->andReturnUsing(
        function (EloquentCollection $models) use (&$captured, &$updates): void {
            $updates++;

            if ($updates === 1) {
                throw new RuntimeException('search engine unavailable');
            }

            $captured[] = $models->modelKeys();
        },
    );

    resolve(EngineManager::class)->extend('failing-spy', fn (): Engine => $spy);
    config(['scout.driver' => 'failing-spy']);

    // use (&$captured), never an arrow fn: that would bind a copy of the empty
    // array and the reader would report nothing however much the spy captured.
    return function () use (&$captured): array {
        return $captured;
    };
}

/**
 * Run the wrapper against a buffer we own and hand back everything it wrote.
 *
 * `Artisan::output()` reads back empty for this command: the wrapper forwards
 * `$this->output` into its own `Artisan::call` per leg, which leaves the kernel's
 * last output an `OutputStyle` — no `fetch()`, so the read yields ''. Passing our
 * own buffer keeps the whole run, legs included, in one readable string.
 */
function imdbCatalogOutput(): string
{
    Artisan::call('catalog:sync-imdb', [], $buffer = new BufferedOutput);

    return $buffer->fetch();
}

describe('catalog:sync-imdb leg dispatch', function (): void {
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
});

describe('catalog:sync-imdb failure handling', function (): void {
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
});

describe('catalog:sync-imdb progress output', function (): void {
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
});

describe('catalog:sync-imdb end-of-leg reindex', function (): void {
    it('reindexes each touched movie and show exactly once at the end of the run', function (): void {
        // Arrange
        // The spy captures bare model keys, so a movie and a show that both fell on
        // id 1 would read as an ambiguous [1, 1] — the keys are pinned apart here so
        // "one movie call and one show call" stays distinguishable from "one row
        // pushed twice".
        $matrix = Movie::factory()->create(['id' => 101, '_imdb_id' => 'tt0133093']);
        $breakingBad = Show::factory()->create(['id' => 202, '_imdb_id' => 'tt0903747']);
        fakeImdbDatasets();
        // Registered after the factory saves so their auto-syncs aren't captured.
        $capturedChunks = spyOnScoutEngine();

        // Act
        $this->artisan('catalog:sync-imdb');

        // Assert
        expect($capturedChunks())->toEqualCanonicalizing([[$matrix->id], [$breakingBad->id]]);
    });

    it('leaves a row untouched by the run out of the reindex', function (): void {
        // Arrange
        Movie::factory()->create(['_imdb_id' => 'tt0133093']);
        $untouched = Movie::factory()->create(['_imdb_id' => 'tt1375666']);
        // updated_at is second-precision, so a row merely created just before the Act
        // can still satisfy a job-start watermark; the stale stamp is written
        // explicitly, and through the base query so the model doesn't re-stamp it.
        $untouched->newQuery()->whereKey($untouched->getKey())->toBase()->update(['updated_at' => '2020-01-01 00:00:00']);
        fakeImdbDatasets();
        $capturedChunks = spyOnScoutEngine();

        // Act
        $this->artisan('catalog:sync-imdb');

        // Assert
        // A negative control: it also holds with no reindex at all, so it pins the
        // watermark's polarity rather than driving the wiring.
        expect(reindexedIds($capturedChunks()))->not->toContain($untouched->id);
    });

    it('emits a reindex heartbeat in the wrapper output', function (): void {
        // Arrange
        Movie::factory()->create(['_imdb_id' => 'tt0133093']);
        fakeImdbDatasets();

        // Act & Assert
        // Shape only — the cumulative counts and their exact format belong to
        // ReindexTouchedRows, which pins them in its own test.
        $this->artisan('catalog:sync-imdb')->expectsOutputToContain('[reindex');
    });

    it('prints a phase line and an elapsed close for each model it reindexes', function (): void {
        // The two passes are announced separately so an operator can tell ingest from
        // reindexing, and the movies pass from the shows one. Asserted over the run's
        // captured text, not chained expectsOutputToContain: the closing line contains
        // the opening one, and Mockery routes both writes to the first matching
        // expectation, leaving the closing expectation forever unsatisfied.
        // Arrange
        Date::setTestNow('2026-08-12 12:00:00');
        Movie::factory()->create(['_imdb_id' => 'tt0133093']);
        Show::factory()->create(['_imdb_id' => 'tt0903747']);
        fakeImdbDatasets();

        // Act
        $output = imdbCatalogOutput();

        // Assert
        expect($output)
            ->toContain('Reindexing movies…')
            ->toContain('Reindexed 1 movie in 0s')
            ->toContain('Reindexing shows…')
            ->toContain('Reindexed 1 show in 0s');
    });

    it('prints the reindex phase lines in queued wording when scout queues its index writes', function (): void {
        // Production runs SCOUT_QUEUE=true, where the passes only DISPATCH the index
        // writes — their elapsed seconds time the dispatch, not the indexing, so the
        // lines must not claim the rows were indexed.
        // Arrange
        Date::setTestNow('2026-08-12 12:00:00');
        config(['scout.queue' => true]);
        Queue::fake();
        Movie::factory()->create(['_imdb_id' => 'tt0133093']);
        Show::factory()->create(['_imdb_id' => 'tt0903747']);
        fakeImdbDatasets();

        // Act
        $output = imdbCatalogOutput();

        // Assert
        expect($output)
            ->toContain('Queueing movies for reindex…')
            ->toContain('Queued 1 movie for reindex in 0s')
            ->toContain('Queueing shows for reindex…')
            ->toContain('Queued 1 show for reindex in 0s');
    });

    it('names the model whose reindex died and still closes the surviving pass', function (): void {
        // Arrange
        Date::setTestNow('2026-08-12 12:00:00');
        Exceptions::fake();
        Movie::factory()->create(['_imdb_id' => 'tt0133093']);
        Show::factory()->create(['_imdb_id' => 'tt0903747']);
        fakeImdbDatasets();
        // Registered after the factory saves so the movies pass, not a factory save,
        // is what eats the one throw.
        spyOnScoutEngineFailingOnce();

        // Act
        $output = imdbCatalogOutput();

        // Assert
        // Without a failure close, the movies pass would just stop mid-phase and the
        // next phase line would read as its result.
        expect($output)
            ->toContain('Reindexing movies…')
            ->toContain('Reindexing movies failed after 0s')
            ->not->toContain('Reindexed 1 movie in 0s')
            ->toContain('Reindexed 1 show in 0s');
    });

    it('still reindexes the rows a surviving leg touched when another leg fails', function (): void {
        // Arrange
        Sleep::fake();
        Exceptions::fake();
        $matrix = Movie::factory()->create(['_imdb_id' => 'tt0133093']);
        // Http::fake merges stubs and the first registered match wins, so this 500
        // registered ahead of the happy-path helper overrides only the basics fetch.
        Http::fake(['*title.basics*' => Http::response('', 500)]);
        fakeImdbDatasets();
        $capturedChunks = spyOnScoutEngine();

        // Act & Assert
        $this->artisan('catalog:sync-imdb')->assertExitCode(Command::FAILURE);

        // Assert
        expect(reindexedIds($capturedChunks()))->toContain($matrix->id);
    });

    it('still reindexes shows when the movies reindex throws, and exits FAILURE', function (): void {
        // Arrange
        Exceptions::fake();
        Movie::factory()->create(['id' => 101, '_imdb_id' => 'tt0133093']);
        $breakingBad = Show::factory()->create(['id' => 202, '_imdb_id' => 'tt0903747']);
        fakeImdbDatasets();
        // Registered after the factory saves so their auto-syncs aren't captured —
        // and so the movies pass, not a factory save, is what eats the throw.
        $capturedChunks = spyOnScoutEngineFailingOnce();

        // Act & Assert
        $this->artisan('catalog:sync-imdb')->assertExitCode(Command::FAILURE);

        // Assert
        Exceptions::assertReported(fn (RuntimeException $e): bool => true);
        expect(reindexedIds($capturedChunks()))->toContain($breakingBad->id);
    });

    it('sends nothing to the engine when every leg is skipped', function (): void {
        // Arrange
        $header = 'Tue, 12 Aug 2026 01:02:03 GMT';
        $marker = resolve(ImdbDatasetMarker::class);
        $marker->advance(ImdbDataset::TitleRatings, $header);
        $marker->advance(ImdbDataset::TitleBasics, $header);
        $marker->advance(ImdbDataset::TitleAkas, $header);
        $matrix = Movie::factory()->create(['_imdb_id' => 'tt0133093']);
        // Same second-precision trap as above: without an explicit stale stamp the
        // seeded row could satisfy the watermark and mask a skipped run.
        $matrix->newQuery()->whereKey($matrix->getKey())->toBase()->update(['updated_at' => '2020-01-01 00:00:00']);
        Http::fake(fn (Request $request) => $request->method() === 'HEAD'
            ? Http::response('', 200, ['Last-Modified' => $header])
            : Http::response(fixtureBytes('Catalog/imdb/'.Str::afterLast($request->url(), '/'))));
        $capturedChunks = spyOnScoutEngine();

        // Act
        $this->artisan('catalog:sync-imdb');

        // Assert
        // The second negative control: nothing reaching the engine is also today's
        // behavior, so this guards the skip path against a future epoch watermark.
        expect(reindexedIds($capturedChunks()))->toBe([]);
    });
});

describe('catalog:sync-imdb --force forwarding', function (): void {
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
});
