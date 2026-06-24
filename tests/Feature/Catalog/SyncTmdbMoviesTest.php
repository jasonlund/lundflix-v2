<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures (byte-exact real TMDB slices)
|--------------------------------------------------------------------------
| tests/Fixtures/Catalog/tmdb/movie_ids.json.gz — gz JSONL daily export; the
|   kept (non-adult/non-softcore) rows include id 603 (The Matrix), appended for
|   this ingestor slice, alongside the other real export ids.
| tests/Fixtures/Catalog/tmdb/movie.json — the /movie/603 detail response
|   (The Matrix, imdb_id tt0133093) with appended images.{posters,backdrops,logos}.
|
| The export host and the TMDB API host are distinct, and stray requests are
| globally prevented, so both hosts are faked. The API closure serves The Matrix
| only for id 603 and 404s every other exported id, exercising the pooled-miss path.
*/

function fakeTmdbSync(): void
{
    Http::fake([
        '*movie_ids*' => Http::response(fixtureBytes('Catalog/tmdb/movie_ids.json.gz')),
        '*api.themoviedb.org*' => fn (Request $request) => str_contains($request->url(), '/movie/603')
            ? Http::response(fixtureBytes('Catalog/tmdb/movie.json'))
            : Http::response('', 404),
    ]);
}

it('persists hydrated movies with _tmdb_ columns', function (): void {
    // Arrange
    fakeTmdbSync();

    // Act
    $this->artisan('tmdb:sync-movies');

    // Assert
    $matrix = Movie::where('_tmdb_id', 603)->first();
    expect($matrix)->not->toBeNull();
    expect($matrix->_tmdb_title)->toBe('The Matrix');
});

it('persists the hydrated movie images into media', function (): void {
    // Arrange
    fakeTmdbSync();

    // Act
    $this->artisan('tmdb:sync-movies');

    // Assert
    $matrix = Movie::where('_tmdb_id', 603)->firstOrFail();
    expect($matrix->media()->where('is_active', true)->count())->toBeGreaterThan(0);
});

it('exits SUCCESS and deletes the export temp file', function (): void {
    // Arrange
    fakeTmdbSync();
    $tempFiles = fn (): array => glob(sys_get_temp_dir().'/tmdb_*');
    $before = $tempFiles();

    // Act
    $this->artisan('tmdb:sync-movies')->assertExitCode(0);

    // Assert
    expect($tempFiles())->toBe($before);
});

it('merges onto an existing IMDb row instead of creating a duplicate', function (): void {
    // Arrange
    Movie::factory()->create(['imdb_id' => 'tt0133093']);
    fakeTmdbSync();

    // Act
    $this->artisan('tmdb:sync-movies');

    // Assert
    expect(Movie::where('imdb_id', 'tt0133093')->count())->toBe(1);
    expect(Movie::where('imdb_id', 'tt0133093')->first()->_tmdb_id)->toBe(603);
});

it('caps processed ids with --limit', function (): void {
    // Arrange
    fakeTmdbSync();

    // Act
    $this->artisan('tmdb:sync-movies', ['--limit' => 1]);

    // Assert
    $hydrateCalls = 0;
    Http::assertSent(function (Request $request) use (&$hydrateCalls): bool {
        if (str_contains($request->url(), 'api.themoviedb.org/3/movie/')) {
            $hydrateCalls++;
        }

        return true;
    });
    expect($hydrateCalls)->toBe(1);
});

it('skips an already-synced movie on a default run', function (): void {
    // Arrange
    Movie::factory()->create(['_tmdb_id' => 603, 'tmdb_synced_at' => now()]);
    fakeTmdbSync();

    // Act
    $this->artisan('tmdb:sync-movies');

    // Assert
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/movie/603'));
});

it('reprocesses an already-synced movie with --fresh', function (): void {
    // Arrange
    Movie::factory()->create(['_tmdb_id' => 603, 'tmdb_synced_at' => now()]);
    fakeTmdbSync();

    // Act
    $this->artisan('tmdb:sync-movies', ['--fresh' => true]);

    // Assert
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/movie/603'));
});
