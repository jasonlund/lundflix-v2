<?php

declare(strict_types=1);

use App\Domains\Catalog\Exceptions\TvdbRequestFailed;
use App\Domains\Catalog\Models\Show;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
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

describe('catalog:seed-shows-tvdb crawl', function (): void {
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
});

describe('catalog:seed-shows-tvdb exit code and output', function (): void {
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
});

describe('catalog:seed-shows-tvdb failure retry', function (): void {
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
});

describe('catalog:seed-shows-tvdb --ids-file parsing', function (): void {
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
});

describe('catalog:seed-shows-tvdb batching', function (): void {
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
});

describe('catalog:seed-shows-tvdb narrowed show lookup', function (): void {
    it('selects only id and _tvdb_id when resolving the upserted shows', function (): void {
        // Arrange
        fakeTvdbSeedCrawl();
        DB::enableQueryLog();

        // Act
        $this->artisan('catalog:seed-shows-tvdb');

        // Assert
        // Asserting the exact narrowed select list, because the log already carries a
        // `select id from shows where _tvdb_id in (…)` (the upsert's own id lookup) and a
        // wide `select *` from Scout's reindex — so "no select *" and "a narrow _tvdb_id
        // select" are both green today and would prove nothing.
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
});

describe('catalog:seed-shows-tvdb --ids-file guard', function (): void {
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
});
