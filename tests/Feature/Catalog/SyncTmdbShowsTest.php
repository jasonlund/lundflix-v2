<?php

declare(strict_types=1);

use App\Domains\Catalog\Exceptions\TmdbRequestFailed;
use App\Domains\Catalog\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Exceptions;
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
        // A default run always hits the changes feed after the insert phase; an
        // empty-results page drives the update phase through its success path
        // (no swallowed exception, no stray stack trace). Listed before the
        // generic detail stub since it lives on the same host.
        '*/tv/changes*' => Http::response('{"results":[],"page":1,"total_pages":1,"total_results":0}'),
        '*api.themoviedb.org*' => fn (Request $request) => str_contains($request->url(), '/tv/1399')
            ? Http::response(fixtureBytes('Catalog/tmdb/tv.json'))
            : Http::response('', 404),
    ]);
}

/*
| Fakes the three hosts the update-changed phase touches. The export is empty so
| the insert-new phase is a no-op and can't interfere with the update phase.
| tv_changes_page1.json declares total_pages:2, so the client pages through to
| page 2 (tv_changes_page2.json) — both are hand-authored representative fixtures
| approximating the /tv/changes wire format, not verbatim live captures. The
| changes feed lives on the TMDB API host too, so its stub is listed BEFORE the
| generic detail stub. The Game of Thrones detail body is re-keyed onto id 23310
| (the only synthetic touch, an accepted pattern here) so the detail-upsert —
| which keys on the payload's id — lands on the existing _tmdb_id 23310 row;
| every other detail id 404s.
*/
function fakeTmdbShowUpdateSync(): void
{
    $decoded = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
    $decoded['id'] = 23310;
    $detailBody = json_encode($decoded);

    Http::fake([
        '*tv_series_ids*' => Http::response(gzencode('')),
        '*/tv/changes*' => function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return (int) ($query['page'] ?? 1) === 2
                ? Http::response(fixtureBytes('Catalog/tmdb/tv_changes_page2.json'))
                : Http::response(fixtureBytes('Catalog/tmdb/tv_changes_page1.json'));
        },
        '*api.themoviedb.org*' => fn (Request $request) => str_ends_with(
            (string) parse_url($request->url(), PHP_URL_PATH),
            '/tv/23310',
        )
            ? Http::response($detailBody)
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
        '*/tv/changes*' => Http::response('{"results":[],"page":1,"total_pages":1,"total_results":0}'),
        '*api.themoviedb.org*' => fn (Request $request) => str_contains($request->url(), '/tv/1399')
            ? Http::response(fixtureBytes('Catalog/tmdb/tv.json'))
            : Http::response('', 404),
    ]);

    // Act
    $this->artisan('tmdb:sync-shows');

    // Assert
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/tv/0'));
});

it('enriches a matching TVDB show with _tmdb_ columns in place', function (): void {
    // Arrange
    Show::factory()->withTvdb()->create(['_imdb_id' => 'tt0944947']);
    fakeTmdbShowSync();

    // Act
    $this->artisan('tmdb:sync-shows');

    // Assert
    $got = Show::where('_tmdb_id', 1399)->first();
    expect($got)->not->toBeNull();
    expect($got->_tmdb_name)->toBe('Game of Thrones');
    expect(Show::count())->toBe(1);
});

it('persists the enriched show images into media', function (): void {
    // Arrange
    Show::factory()->withTvdb()->create(['_imdb_id' => 'tt0944947']);
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

it('refreshes an existing synced show present in the changes feed', function (): void {
    // Arrange
    Show::factory()->create(['_tmdb_id' => 23310, 'tmdb_synced_at' => now(), '_tmdb_name' => 'Stale']);
    fakeTmdbShowUpdateSync();

    // Act
    $this->artisan('tmdb:sync-shows');

    // Assert
    expect(Show::where('_tmdb_id', 23310)->first()->_tmdb_name)->toBe('Game of Thrones');
});

it('ignores a changed tv id not in the local catalog', function (): void {
    // Arrange
    Show::factory()->create(['_tmdb_id' => 23310, 'tmdb_synced_at' => now()]);
    fakeTmdbShowUpdateSync();

    // Act
    $this->artisan('tmdb:sync-shows');

    // Assert
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/tv/325296'));
});

it('requests the rolling 14-day changes window', function (): void {
    // Arrange
    Date::setTestNow('2026-07-09');
    Show::factory()->create(['_tmdb_id' => 23310, 'tmdb_synced_at' => now()]);
    fakeTmdbShowUpdateSync();

    // Act
    $this->artisan('tmdb:sync-shows');

    // Assert
    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/tv/changes')) {
            return false;
        }
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['start_date'] ?? null) === '2026-06-25'
            && ($query['end_date'] ?? null) === '2026-07-09';
    });
});

it('skips the update phase with --fresh', function (): void {
    // Arrange
    Show::factory()->create(['_tmdb_id' => 23310, 'tmdb_synced_at' => now()]);
    fakeTmdbShowUpdateSync();

    // Act
    $this->artisan('tmdb:sync-shows', ['--fresh' => true]);

    // Assert
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/tv/changes'));
});

it('skips the update phase with --limit', function (): void {
    // Arrange
    Show::factory()->create(['_tmdb_id' => 23310, 'tmdb_synced_at' => now()]);
    fakeTmdbShowUpdateSync();

    // Act
    $this->artisan('tmdb:sync-shows', ['--limit' => 1]);

    // Assert
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/tv/changes'));
});

it('reports a persistent changes-feed failure and still exits SUCCESS', function (): void {
    // Arrange
    Exceptions::fake();
    // Empty export → the insert phase is a no-op; the changes feed 404s on every
    // page, which TMDB raises as a fatal TmdbRequestFailed the update phase must
    // report rather than propagate.
    Http::fake([
        '*tv_series_ids*' => Http::response(gzencode('')),
        '*/tv/changes*' => Http::response('', 404),
        '*api.themoviedb.org*' => Http::response('', 404),
    ]);

    // Act
    $this->artisan('tmdb:sync-shows')->assertExitCode(0);

    // Assert
    Exceptions::assertReported(TmdbRequestFailed::class);
});
