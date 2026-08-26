<?php

declare(strict_types=1);

use App\Domains\Catalog\Exceptions\TvdbRequestFailed;
use App\Domains\Catalog\Models\Show;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures (byte-exact real TheTVDB v4 slices)
|--------------------------------------------------------------------------
| tests/Fixtures/Catalog/tvdb/login.json — POST /login → data.token JWT;
|   every fake map answers it because Http::preventStrayRequests() is global
|   and the JWT is fetched (and cached) before any /series call.
| tests/Fixtures/Catalog/tvdb/series_page1.json — GET /series?page=0, 500 BASE
|   records, first id 70327, links.next set (the crawl's first page).
| tests/Fixtures/Catalog/tvdb/series_empty.json — GET past the end, data [],
|   links.next null (the crawl terminus for page 1).
| tests/Fixtures/Catalog/tvdb/series_extended.json — GET /series/{id}/extended
|   (wrapped {status,data}); the extended Breaking Bad payload, data.id 81189,
|   data.name 'Breaking Bad', data.artworks 343 entries (109 mapped to media).
|
| 81189 is in NONE of the crawl fixtures, so the fake serves the extended
| payload for exactly ONE discovered id and 404s every other /extended — that
| one success upserts the show as _tvdb_id 81189. The crawl fake serves it for
| the crawled id 70327.
|
| The batching test below is the one place that needs MORE ids than any capture
| supplies: a batch boundary is a structural input, so its 251-id --ids-file is
| synthetic (one hydratable id, 249 fillers that 404 cheaply, one more hydratable
| id). Its second hydratable id is served a payload DERIVED from the real
| series_extended.json — json_decode, override data.id/data.name, re-encode — the
| same trick fakeTvdbSeedCrawlWithMalformedId() uses. A verbatim second copy would
| carry data.id 81189 again, and two rows sharing one _tvdb_id conflict key in a
| single upsert is a DB error rather than the batch count under test.
*/

function fakeTvdbSeedCrawl(): void
{
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series?page=0*' => Http::response(fixtureBytes('Catalog/tvdb/series_page1.json')),
        '*api4.thetvdb.com/v4/series?page=1*' => Http::response(fixtureBytes('Catalog/tvdb/series_empty.json')),
        '*api4.thetvdb.com/v4/series/*/extended*' => fn (Request $request) => Str::contains($request->url(), '/series/70327/extended')
            ? Http::response(fixtureBytes('Catalog/tvdb/series_extended.json'))
            : Http::response('', 404),
    ]);
}

function fakeTvdbSeedCrawlWithMalformedId(): void
{
    // A non-numeric export id can't occur in the real byte-exact page fixtures, so
    // this synthetic page injects one to prove the crawl skips it rather than
    // casting it to 0 and firing a wasted /series/0/extended hydration.
    $malformedPage = json_encode([
        'status' => 'success',
        'data' => [
            ['id' => 'not-a-number', 'name' => 'Malformed'],
            ['id' => 70327, 'name' => 'Valid'],
        ],
        'links' => ['prev' => null, 'self' => null, 'next' => null, 'total_items' => 2, 'page_size' => 500],
    ]);

    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series?page=0*' => Http::response($malformedPage),
        '*api4.thetvdb.com/v4/series?page=1*' => Http::response(fixtureBytes('Catalog/tvdb/series_empty.json')),
        '*api4.thetvdb.com/v4/series/*/extended*' => fn (Request $request) => Str::contains($request->url(), '/series/70327/extended')
            ? Http::response(fixtureBytes('Catalog/tvdb/series_extended.json'))
            : Http::response('', 404),
    ]);
}

function fakeTvdbSeedCrawlWithMissingId(): void
{
    // A record omitting the `id` key can't occur in the real byte-exact page
    // fixtures, so this synthetic page injects one to prove the crawl skips it
    // without raising an "Undefined array key" warning per malformed record.
    $missingIdPage = json_encode([
        'status' => 'success',
        'data' => [
            ['name' => 'No Id Key'],
            ['id' => 70327, 'name' => 'Valid'],
        ],
        'links' => ['prev' => null, 'self' => null, 'next' => null, 'total_items' => 2, 'page_size' => 500],
    ]);

    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series?page=0*' => Http::response($missingIdPage),
        '*api4.thetvdb.com/v4/series?page=1*' => Http::response(fixtureBytes('Catalog/tvdb/series_empty.json')),
        '*api4.thetvdb.com/v4/series/*/extended*' => fn (Request $request) => Str::contains($request->url(), '/series/70327/extended')
            ? Http::response(fixtureBytes('Catalog/tvdb/series_extended.json'))
            : Http::response('', 404),
    ]);
}

beforeEach(function (): void {
    Cache::flush();
    config(['services.tvdb.key' => 'test-key']);
});

it('crawls allSeries pages and persists hydrated shows with _tvdb_ columns', function (): void {
    // Arrange
    fakeTvdbSeedCrawl();

    // Act
    $this->artisan('catalog:seed-shows-tvdb');

    // Assert
    $show = Show::where('_tvdb_id', 81189)->first();
    expect($show)->not->toBeNull();
    expect($show->_tvdb_name)->toBe('Breaking Bad');
});

it('keeps paging the allSeries crawl past the first page until a page returns no records', function (): void {
    // Arrange
    fakeTvdbSeedCrawl();

    // Act
    $this->artisan('catalog:seed-shows-tvdb');

    // Assert
    Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/series?page=1'));
});

it('persists the hydrated series artworks into media', function (): void {
    // Arrange
    fakeTvdbSeedCrawl();

    // Act
    $this->artisan('catalog:seed-shows-tvdb');

    // Assert
    $show = Show::where('_tvdb_id', 81189)->firstOrFail();
    expect($show->media()->where('is_active', true)->count())->toBeGreaterThan(0);
});

it('skips a non-numeric crawl id without firing /series/0/extended', function (): void {
    // Arrange
    fakeTvdbSeedCrawlWithMalformedId();

    // Act
    $this->artisan('catalog:seed-shows-tvdb');

    // Assert
    Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/series/0/extended'));
});

it('skips a crawl record missing its id key without raising a warning', function (): void {
    // Arrange
    fakeTvdbSeedCrawlWithMissingId();

    // Act
    $this->artisan('catalog:seed-shows-tvdb');

    // Assert
    expect(Show::where('_tvdb_id', 81189)->first())->not->toBeNull();
});

it('exits SUCCESS', function (): void {
    // Arrange
    fakeTvdbSeedCrawl();

    // Act & Assert
    $this->artisan('catalog:seed-shows-tvdb')->assertExitCode(0);
});

it('announces it is starting before the pipeline runs', function (): void {
    // Arrange
    fakeTvdbSeedCrawl();

    // Act & Assert
    $this->artisan('catalog:seed-shows-tvdb')->expectsOutputToContain('Syncing shows…');
});

it('persists an id that fails its first hydrate then succeeds on the retry pass', function (): void {
    // Arrange
    // An undecodable 200 is non-retryable, so the crawl pass fails 70327 with exactly one
    // request; the retry pass then serves the valid extended body and heals it to 81189.
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series?page=0*' => Http::response(fixtureBytes('Catalog/tvdb/series_page1.json')),
        '*api4.thetvdb.com/v4/series?page=1*' => Http::response(fixtureBytes('Catalog/tvdb/series_empty.json')),
        '*api4.thetvdb.com/v4/series/70327/extended*' => Http::sequence()
            ->push('not json', 200)
            ->push(fixtureBytes('Catalog/tvdb/series_extended.json'), 200),
        '*api4.thetvdb.com/v4/series/*/extended*' => Http::response('', 404),
    ]);

    // Act
    $this->artisan('catalog:seed-shows-tvdb');

    // Assert
    expect(Show::where('_tvdb_id', 81189)->first())->not->toBeNull();
});

it('retries a chunk-level failure\'s ids within the run', function (): void {
    // Arrange
    // Complements the per-id retry test above (an undecodable 200, the pooled arm): a 401
    // forgets the JWT and throws out of the pool, so syncChunkSafely() fans the WHOLE chunk
    // into the failed set. The retry pass must still re-hydrate those ids and heal 70327.
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series?page=0*' => Http::response(fixtureBytes('Catalog/tvdb/series_page1.json')),
        '*api4.thetvdb.com/v4/series?page=1*' => Http::response(fixtureBytes('Catalog/tvdb/series_empty.json')),
        '*api4.thetvdb.com/v4/series/70327/extended*' => Http::sequence()
            ->push('', 401)
            ->push(fixtureBytes('Catalog/tvdb/series_extended.json'), 200),
        '*api4.thetvdb.com/v4/series/*/extended*' => Http::response('', 404),
    ]);

    // Act
    $this->artisan('catalog:seed-shows-tvdb');

    // Assert
    expect(Show::where('_tvdb_id', 81189)->first())->not->toBeNull();
});

it('reports an id that fails both the crawl pass and the retry pass', function (): void {
    // Arrange
    Sleep::fake();
    Exceptions::fake();
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series?page=0*' => Http::response(fixtureBytes('Catalog/tvdb/series_page1.json')),
        '*api4.thetvdb.com/v4/series?page=1*' => Http::response(fixtureBytes('Catalog/tvdb/series_empty.json')),
        '*api4.thetvdb.com/v4/series/70327/extended*' => Http::response('', 500),
        '*api4.thetvdb.com/v4/series/*/extended*' => Http::response('', 404),
    ]);

    // Act
    $this->artisan('catalog:seed-shows-tvdb');

    // Assert
    Exceptions::assertReported(fn (TvdbRequestFailed $e): bool => Str::contains($e->getMessage(), '70327'));
});

it('prints an end-of-run summary line naming the still-failing ids', function (): void {
    // Arrange
    Sleep::fake();
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series?page=0*' => Http::response(fixtureBytes('Catalog/tvdb/series_page1.json')),
        '*api4.thetvdb.com/v4/series?page=1*' => Http::response(fixtureBytes('Catalog/tvdb/series_empty.json')),
        '*api4.thetvdb.com/v4/series/70327/extended*' => Http::response('', 500),
        '*api4.thetvdb.com/v4/series/*/extended*' => Http::response('', 404),
    ]);

    // Act & Assert
    $this->artisan('catalog:seed-shows-tvdb')
        ->expectsOutputToContain('still failing')
        ->expectsOutputToContain('70327')
        ->assertExitCode(0);
});

it('propagates a non-API upsert exception instead of swallowing it as a retryable failure', function (): void {
    // Arrange
    // The API crawl fully succeeds; the DB upsert then throws a non-API exception —
    // a real bug, not a transient miss. Dropping `shows` makes the real upsert raise
    // a genuine QueryException (final readonly UpsertTvdbShows can't be Mockery-doubled);
    // SQLite's transactional DDL rolls the drop back with RefreshDatabase.
    fakeTvdbSeedCrawl();
    Schema::drop('shows');

    // Act & Assert
    expect(fn (): int => $this->artisan('catalog:seed-shows-tvdb')->run())
        ->toThrow(QueryException::class);
});

it('hydrates only the ids in the --ids-file without crawling allSeries', function (): void {
    // Arrange
    // No page listing is faked; --ids-file must hydrate directly, so any allSeries crawl
    // trips both this assertion and the global Http::preventStrayRequests().
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series/*/extended*' => fn (Request $request): mixed => Str::contains($request->url(), '/series/70327/extended')
            ? Http::response(fixtureBytes('Catalog/tvdb/series_extended.json'))
            : Http::response('', 404),
    ]);
    $path = tempnam(sys_get_temp_dir(), 'ids');
    file_put_contents($path, '70327');

    // Act
    $this->artisan('catalog:seed-shows-tvdb', ['--ids-file' => $path]);

    // Assert
    expect(Show::where('_tvdb_id', 81189)->first())->not->toBeNull();
    Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/series?page='));

    unlink($path);
});

it('parses the single-line comma list in --ids-file and ignores misses', function (): void {
    // Arrange
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series/*/extended*' => fn (Request $request): mixed => Str::contains($request->url(), '/series/70327/extended')
            ? Http::response(fixtureBytes('Catalog/tvdb/series_extended.json'))
            : Http::response('', 404),
    ]);
    $path = tempnam(sys_get_temp_dir(), 'ids');
    file_put_contents($path, '70327,999999');

    // Act
    $this->artisan('catalog:seed-shows-tvdb', ['--ids-file' => $path]);

    // Assert
    expect(Show::where('_tvdb_id', 81189)->first())->not->toBeNull();
    expect(Show::count())->toBe(1);

    unlink($path);
});

it('skips blank and non-numeric --ids-file entries without firing /series/0/extended', function (): void {
    // Arrange
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series/*/extended*' => fn (Request $request): mixed => Str::contains($request->url(), '/series/70327/extended')
            ? Http::response(fixtureBytes('Catalog/tvdb/series_extended.json'))
            : Http::response('', 404),
    ]);
    $path = tempnam(sys_get_temp_dir(), 'ids');
    file_put_contents($path, '70327,,not-a-number');

    // Act
    $this->artisan('catalog:seed-shows-tvdb', ['--ids-file' => $path]);

    // Assert
    Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/series/0/extended'));
    expect(Show::where('_tvdb_id', 81189)->first())->not->toBeNull();

    unlink($path);
});

it('refuses a missing --ids-file without crawling allSeries', function (): void {
    // Arrange
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series/*/extended*' => fn (Request $request): mixed => Str::contains($request->url(), '/series/70327/extended')
            ? Http::response(fixtureBytes('Catalog/tvdb/series_extended.json'))
            : Http::response('', 404),
    ]);

    // Act & Assert
    $this->artisan('catalog:seed-shows-tvdb', ['--ids-file' => '/nonexistent/path/ids.csv'])
        ->expectsOutputToContain('--ids-file not found')
        ->assertExitCode(1);
    Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/series?page='));
    Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/series/70327/extended'));
    expect(Show::where('_tvdb_id', 81189)->first())->toBeNull();
});

it('skips a decimal --ids-file id instead of truncating it to a real series id', function (): void {
    // Arrange
    // "70327.5" would (int)-truncate to the real unrelated series 70327; SourceId
    // rejects the decimal so that id never hydrates.
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series/*/extended*' => fn (Request $request): mixed => Str::contains($request->url(), '/series/70327/extended')
            ? Http::response(fixtureBytes('Catalog/tvdb/series_extended.json'))
            : Http::response('', 404),
    ]);
    $path = tempnam(sys_get_temp_dir(), 'ids');
    file_put_contents($path, '70327.5');

    // Act
    $this->artisan('catalog:seed-shows-tvdb', ['--ids-file' => $path]);

    // Assert
    Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/series/70327/extended'));
    expect(Show::where('_tvdb_id', 81189)->first())->toBeNull();

    unlink($path);
});

it('parses a newline-separated --ids-file, hydrating each id', function (): void {
    // Arrange
    // An operator's log dump is newline-separated; the file must split on newlines
    // rather than collapse to one non-numeric element.
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series/*/extended*' => fn (Request $request): mixed => Str::contains($request->url(), '/series/70327/extended')
            ? Http::response(fixtureBytes('Catalog/tvdb/series_extended.json'))
            : Http::response('', 404),
    ]);
    $path = tempnam(sys_get_temp_dir(), 'ids');
    file_put_contents($path, "70327\n999999");

    // Act
    $this->artisan('catalog:seed-shows-tvdb', ['--ids-file' => $path]);

    // Assert
    expect(Show::where('_tvdb_id', 81189)->first())->not->toBeNull();
    Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/series/999999/extended'));

    unlink($path);
});

it('hydrates the requested ids in batches of 250', function (): void {
    // Arrange
    // 70327 plus 249 filler ids fills the first batch exactly, so 70328 can only be
    // hydrated by a second one; the fillers 404, so they cost a request and nothing else.
    // See the fixture banner for why 70328 is served a derived payload.
    $derived = json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true);
    $derived['data']['id'] = 81190;
    $derived['data']['name'] = 'Derived Sibling';
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series/*/extended*' => function (Request $request) use ($derived): mixed {
            if (Str::contains($request->url(), '/series/70327/extended')) {
                return Http::response(fixtureBytes('Catalog/tvdb/series_extended.json'));
            }

            return Str::contains($request->url(), '/series/70328/extended')
                ? Http::response(json_encode($derived))
                : Http::response('', 404);
        },
    ]);
    $path = tempnam(sys_get_temp_dir(), 'ids');
    file_put_contents($path, implode(',', [70327, ...range(900000, 900248), 70328]));
    DB::enableQueryLog();

    // Act
    $this->artisan('catalog:seed-shows-tvdb', ['--ids-file' => $path]);

    // Assert
    // One `insert into shows` per batch, so the count is an honest proxy for the thing
    // the slice is really about — how many live decoded payloads a batch holds at once.
    // Memory itself isn't assertable; the number of upserts stands in for the bound.
    $showInserts = collect(DB::getQueryLog())
        ->filter(fn (array $entry): bool => Str::startsWith(
            Str::replace(['"', '`'], '', (string) $entry['query']),
            'insert into shows',
        ));
    expect($showInserts)->toHaveCount(2);

    unlink($path);
});

it('selects only id and _tvdb_id when resolving the upserted shows', function (): void {
    // Arrange
    fakeTvdbSeedCrawl();
    DB::enableQueryLog();

    // Act
    $this->artisan('catalog:seed-shows-tvdb');

    // Assert
    // Asserting the exact narrowed select list, because the log already carries two other
    // reads of `shows`: a narrow `select _tvdb_id, _tmdb_id from shows where _tmdb_id in
    // (…)` (the upsert's own crosswalk lookup) and a wide `select * from shows where
    // updated_at >= ?` (the end-of-leg reindex walking the rows the leg touched) — so
    // neither "no select *" nor "some narrow select on shows" would prove anything about
    // the lookup under test.
    $lookups = collect(DB::getQueryLog())
        ->filter(fn (array $entry): bool => Str::contains(
            Str::replace(['"', '`'], '', (string) $entry['query']),
            'select id, _tvdb_id from shows',
        ));
    expect($lookups)->not->toBeEmpty();
});

it('binds only the batch payload ids on the narrowed show lookup', function (): void {
    // Arrange
    fakeTvdbSeedCrawl();
    DB::enableQueryLog();

    // Act
    $this->artisan('catalog:seed-shows-tvdb');

    // Assert
    // The crawl fake hydrates exactly one id, so the lookup that resolves the upserted
    // shows must carry one binding — the narrowed select is scoped to the batch's
    // payloads, not widened to the whole table.
    $lookup = collect(DB::getQueryLog())
        ->first(fn (array $entry): bool => Str::contains(
            Str::replace(['"', '`'], '', (string) $entry['query']),
            'select id, _tvdb_id from shows',
        )) ?? [];
    expect(count($lookup['bindings'] ?? []))->toBe(1);
});

it('fails fast when --ids-file yields no valid ids', function (): void {
    // Arrange
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
    ]);
    $path = tempnam(sys_get_temp_dir(), 'ids');
    file_put_contents($path, "not-a-number\n");

    // Act & Assert
    $this->artisan('catalog:seed-shows-tvdb', ['--ids-file' => $path])
        ->expectsOutputToContain('no valid series ids')
        ->assertExitCode(1);
    Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/extended'));

    unlink($path);
});

/*
|--------------------------------------------------------------------------
| End-of-leg reindex
|--------------------------------------------------------------------------
| The ingest upserts through `Show::upsert()`, which fires no model events, so
| nothing is indexed during the leg; the leg reindexes exactly the rows it
| touched (updated_at >= run start) once, at the END of the leg — after the
| in-run retry pass, so a row the retry healed rides the same single pass rather
| than needing a second one. Every test below freezes the clock, which both pins
| the elapsed phase lines at `0s` and makes the run-start watermark exactly equal
| to the `updated_at` the upsert writes.
*/

/**
 * Stamp a row's `updated_at` without the model touching timestamps itself.
 */
$stampUpdatedAt = function (Show $row, CarbonImmutable $updatedAt): void {
    $row->newQuery()->whereKey($row->getKey())->update(['updated_at' => $updatedAt]);
};

it('passes exactly the crawled-and-upserted shows to the engine once at end of leg', function () use ($stampUpdatedAt): void {
    // Arrange
    // 9_000_001 is in neither the crawl page nor the extended payload, so this row is
    // never re-upserted — only its stale updated_at keeps it out of the reindex.
    Date::setTestNow('2026-07-16 12:00:00');
    $stale = Show::factory()->withTvdb()->create(['_tvdb_id' => 9_000_001]);
    $stampUpdatedAt($stale, CarbonImmutable::now()->subDay());
    fakeTvdbSeedCrawl();
    $capturedChunks = spyOnScoutEngine();

    // Act
    $this->artisan('catalog:seed-shows-tvdb')->run();

    // Assert
    $touched = Show::query()->where('_tvdb_id', 81189)->firstOrFail();
    expect($capturedChunks())->toBe([[$touched->id]]);
});

it('includes a show recovered by the in-run retry pass in the one end-of-leg reindex', function (): void {
    // Arrange
    // An undecodable 200 is non-retryable, so the crawl pass fails 70327 with exactly one
    // request and the retry pass heals it to 81189 — a row that only exists because of the
    // second pass, so a reindex placed before it would miss the row entirely.
    Date::setTestNow('2026-07-16 12:00:00');
    Sleep::fake();
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series?page=0*' => Http::response(fixtureBytes('Catalog/tvdb/series_page1.json')),
        '*api4.thetvdb.com/v4/series?page=1*' => Http::response(fixtureBytes('Catalog/tvdb/series_empty.json')),
        '*api4.thetvdb.com/v4/series/70327/extended*' => Http::sequence()
            ->push('not json', 200)
            ->push(fixtureBytes('Catalog/tvdb/series_extended.json'), 200),
        '*api4.thetvdb.com/v4/series/*/extended*' => Http::response('', 404),
    ]);
    $capturedChunks = spyOnScoutEngine();

    // Act
    Artisan::call('catalog:seed-shows-tvdb');

    // Assert
    // The whole output buffer, not expectsOutputToContain(): "the phase ran once" is a
    // count, and a per-pass reindex would satisfy any containment assertion just as well.
    $healed = Show::query()->where('_tvdb_id', 81189)->firstOrFail();
    expect($capturedChunks())->toBe([[$healed->id]]);
    expect(Str::substrCount(Artisan::output(), 'Reindexing shows…'))->toBe(1);
});

it('emits the reindex heartbeat and completion line', function (): void {
    // Arrange
    Date::setTestNow('2026-07-16 12:00:00');
    fakeTvdbSeedCrawl();

    // Act & Assert
    $this->artisan('catalog:seed-shows-tvdb')
        ->expectsOutputToContain('  [reindex 1]')
        ->expectsOutputToContain('Reindexed 1 show in 0s');
});

it('prints the ingest completion line with elapsed time once both passes complete', function (): void {
    // Arrange
    Date::setTestNow('2026-07-16 12:00:00');
    fakeTvdbSeedCrawl();

    // Act & Assert
    $this->artisan('catalog:seed-shows-tvdb')->expectsOutputToContain('Synced shows in 0s');
});
