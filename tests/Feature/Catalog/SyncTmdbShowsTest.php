<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures (byte-exact real TMDB slices)
|--------------------------------------------------------------------------
| tests/Fixtures/Catalog/tmdb/tv_series_ids.json.gz — gz JSONL daily export of
|   real rows {"id":int,"original_name":string,"popularity":float}, including
|   id 1399 (Game of Thrones), alongside the other real export ids.
| tests/Fixtures/Catalog/tmdb/tv.json — the /tv/1399 detail response (Game of
|   Thrones, _tmdb_name "Game of Thrones") with an images block.
|
| The export host and the TMDB API host are distinct, and stray requests are
| globally prevented, so both hosts are faked. The API closure serves Game of
| Thrones only for id 1399 and 404s every other exported id, exercising the
| pooled-miss path.
*/

function fakeTmdbShowSync(): void
{
    Http::fake([
        '*tv_series_ids*' => Http::response(fixtureBytes('Catalog/tmdb/tv_series_ids.json.gz')),
        '*api.themoviedb.org*' => fn (Request $request) => str_contains($request->url(), '/tv/1399')
            ? Http::response(fixtureBytes('Catalog/tmdb/tv.json'))
            : Http::response('', 404),
    ]);
}

it('skips a non-numeric export id without hydrating it', function (): void {
    // Arrange
    // A non-numeric id can't occur in the byte-exact export fixture, so this
    // synthetic gz export injects one to prove the stream skips it rather than
    // casting it to 0 and firing a wasted /tv/0 hydration.
    $jsonl = '{"id":"not-a-number","original_name":"Malformed","popularity":1.0}'."\n"
        .'{"id":1399,"original_name":"Game of Thrones","popularity":1.0}'."\n";

    Http::fake([
        '*tv_series_ids*' => Http::response(gzencode($jsonl)),
        '*api.themoviedb.org*' => fn (Request $request) => str_contains($request->url(), '/tv/1399')
            ? Http::response(fixtureBytes('Catalog/tmdb/tv.json'))
            : Http::response('', 404),
    ]);

    // Act
    $this->artisan('tmdb:sync-shows');

    // Assert
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/tv/0'));
});

it('persists hydrated shows with _tmdb_ columns', function (): void {
    // Arrange
    fakeTmdbShowSync();

    // Act
    $this->artisan('tmdb:sync-shows');

    // Assert
    $got = Show::where('_tmdb_id', 1399)->first();
    expect($got)->not->toBeNull();
    expect($got->_tmdb_name)->toBe('Game of Thrones');
});

it('persists the hydrated show images into media', function (): void {
    // Arrange
    fakeTmdbShowSync();

    // Act
    $this->artisan('tmdb:sync-shows');

    // Assert
    $got = Show::where('_tmdb_id', 1399)->firstOrFail();
    expect($got->media()->where('is_active', true)->count())->toBeGreaterThan(0);
});

it('exits SUCCESS and deletes the export temp file', function (): void {
    // Arrange
    fakeTmdbShowSync();
    $tempFiles = fn (): array => glob(sys_get_temp_dir().'/tmdb_*');
    $before = $tempFiles();

    // Act
    $this->artisan('tmdb:sync-shows')->assertExitCode(0);

    // Assert
    expect($tempFiles())->toBe($before);
});

it('caps processed ids with --limit', function (): void {
    // Arrange
    fakeTmdbShowSync();

    // Act
    $this->artisan('tmdb:sync-shows', ['--limit' => 1]);

    // Assert
    $hydrateCalls = 0;
    Http::assertSent(function (Request $request) use (&$hydrateCalls): bool {
        if (str_contains($request->url(), 'api.themoviedb.org/3/tv/')) {
            $hydrateCalls++;
        }

        return true;
    });
    expect($hydrateCalls)->toBe(1);
});

it('skips an already-synced show on a default run', function (): void {
    // Arrange
    Show::factory()->create(['_tmdb_id' => 1399, 'tmdb_synced_at' => now()]);
    fakeTmdbShowSync();

    // Act
    $this->artisan('tmdb:sync-shows');

    // Assert
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/tv/1399'));
});

it('reprocesses an already-synced show with --fresh', function (): void {
    // Arrange
    Show::factory()->create(['_tmdb_id' => 1399, 'tmdb_synced_at' => now()]);
    fakeTmdbShowSync();

    // Act
    $this->artisan('tmdb:sync-shows', ['--fresh' => true]);

    // Assert
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/tv/1399'));
});
