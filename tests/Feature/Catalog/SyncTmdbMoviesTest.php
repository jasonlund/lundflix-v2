<?php

declare(strict_types=1);

use App\Domains\Catalog\Exceptions\TmdbRequestFailed;
use App\Domains\Catalog\Models\Movie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
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

it('writes _imdb_id from the payload on the upserted _tmdb_id row', function (): void {
    // Arrange
    fakeTmdbSync();

    // Act
    $this->artisan('tmdb:sync-movies');

    // Assert
    $matrix = Movie::where('_tmdb_id', 603)->first();
    expect($matrix)->not->toBeNull();
    expect($matrix->_imdb_id)->toBe('tt0133093');
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

it('prints a phase-labeled heartbeat every 1000th hydrated title', function (): void {
    // Arrange
    // 1000 hydratable ids so the running count reaches the every-1000th boundary.
    // A minimal per-id payload (no images) keeps this volume test fast — the
    // heartbeat only needs a title; ingest correctness is covered by other tests.
    $lines = array_map(fn (int $id): string => json_encode(['id' => $id]), range(1, 1000));
    $export = gzencode(implode("\n", $lines));
    Http::fake([
        '*movie_ids*' => Http::response($export),
        '*api.themoviedb.org*' => function (Request $request) {
            preg_match('#/movie/(\d+)#', (string) $request->url(), $m);
            $id = (int) ($m[1] ?? 0);

            return Http::response(json_encode(['id' => $id, 'title' => "Movie {$id}"]));
        },
    ]);

    // Act & Assert
    $this->artisan('tmdb:sync-movies')->expectsOutputToContain('[movies 1000]');
});

it('continues to the next batch when one batch throws', function (): void {
    // Arrange
    Exceptions::fake();
    // Synthetic export body: a >1000-row export is a structural input a committed
    // real fixture can't practically provide — ids 1..1001 force a second batch
    // (batch 1 = 1..1000, batch 2 = id 1001) across the BATCH_SIZE=1000 boundary.
    $lines = array_map(fn (int $id): string => json_encode(['id' => $id]), range(1, 1001));
    $export = gzencode(implode("\n", $lines));
    $matrix = fixtureBytes('Catalog/tmdb/movie.json');
    $decoded = json_decode($matrix, true);
    $decoded['id'] = 1001;
    $batchTwoBody = json_encode($decoded);
    Http::fake([
        '*movie_ids*' => Http::response($export),
        '*api.themoviedb.org*' => function (Request $request) use ($batchTwoBody) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/movie/1001') => Http::response($batchTwoBody),
                // One batch-1 id 500s persistently; TMDB aggregates a persistent
                // non-404 failure into a thrown TmdbRequestFailed, so batch 1 throws.
                str_ends_with($path, '/movie/1') => Http::response('', 500),
                default => Http::response('', 404),
            };
        },
    ]);

    // Act
    // The command reports a failing batch's TmdbRequestFailed rather than
    // throwing, so it runs to completion and processes batch 2 regardless.
    $this->artisan('tmdb:sync-movies');

    // Assert
    expect(Movie::where('_tmdb_id', 1001)->exists())->toBeTrue();
    Exceptions::assertReported(TmdbRequestFailed::class);
});
