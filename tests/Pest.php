<?php

declare(strict_types=1);

use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexMovie;
use App\Domains\PlexLibrary\Models\PlexServer;
use App\Domains\PlexLibrary\Models\PlexShow;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Http::preventStrayRequests();
    })
    ->in('Feature', 'Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Read a test fixture's raw bytes (Pest's built-in fixture() resolves the path
 * under tests/Fixtures/ and asserts it exists).
 *
 * Fixtures are byte-exact copies of real API responses in the API's native
 * wire format, domained under tests/Fixtures/{Domain}/{source}/.
 */
function fixtureBytes(string $path): string
{
    return file_get_contents(fixture($path));
}

/**
 * Fake the entire faked Plex crawl: config the owner token + server id, then
 * wire every outbound call by host-agnostic path pattern. The two section
 * fetches page until MediaContainer.totalSize (53 / 35), so each is a sequence
 * of the real capture followed by an empty page that terminates the walk. The
 * show /children and /allLeaves fixtures are reused for all three shows.
 *
 * Shared by the plex:seed and plex:sync command tests; each documents the
 * fixtures it relies on in its own file-header provenance banner.
 *
 * When $failLeavesForRatingKey is non-null, that one show's allLeaves fetch is
 * wired to throw a ConnectionException (a synthesized transport failure real
 * data can't produce) so the crawl's per-show failure isolation can be tested;
 * it is registered BEFORE the generic allLeaves key so the specific pattern
 * wins. Default null leaves the crawl byte-identical for every other caller.
 *
 * $showSection overrides the show-section fixture the section-2 fetch returns,
 * so a test can swap the default 3-show capture for the 12-show one without
 * touching production code. Defaults to the 3-show fixture every existing
 * caller relies on.
 *
 * $movieSectionPages replaces the movie section's single-capture sequence with an
 * ordered list of pages, so a test can drive a genuinely multi-page section. Each
 * entry is either a fixture path (served as a 200) or a closure invoked in its
 * place, which returns the page's response and may run a side effect at the page
 * boundary first (e.g. advancing the clock). Requests past the end of the list
 * answer with the empty terminator page.
 *
 * $failMoviePage makes the nth (1-based) movie-section page fetch throw a
 * ConnectionException instead of answering — the same synthesized transport
 * failure as $failLeavesForRatingKey, here mid-walk. It latches on the page
 * rather than on the request count, so a retried fetch fails on the same page
 * instead of silently skipping ahead to the next one.
 *
 * @param  list<string|Closure>|null  $movieSectionPages
 */
function fakePlexSeedCrawl(
    ?string $failLeavesForRatingKey = null,
    string $showSection = 'PlexLibrary/plex/section_show_all_includeGuids.json',
    ?array $movieSectionPages = null,
    ?int $failMoviePage = null,
): void {
    config([
        'services.plex.token' => 'owner-token-xyz',
        'services.plex.server_identifier' => 'servermachineidentifier000000000',
    ]);

    $emptyPage = Http::response(json_encode(['MediaContainer' => ['Metadata' => []]]));

    $fakes = [
        '*clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources.json')),
        '*/library/sections/1/all*' => Http::sequence()
            ->push(fixtureBytes('PlexLibrary/plex/section_movie_all_includeGuids.json'))
            ->pushResponse($emptyPage),
        '*/library/sections/2/all*' => Http::sequence()
            ->push(fixtureBytes($showSection))
            ->pushResponse($emptyPage),
        '*/library/sections' => Http::response(fixtureBytes('PlexLibrary/plex/sections.json')),
        '*/children*' => Http::response(fixtureBytes('PlexLibrary/plex/show_children_seasons.json')),
    ];

    if ($movieSectionPages !== null || $failMoviePage !== null) {
        $pages = $movieSectionPages ?? [];
        $served = 0;

        $fakes['*/library/sections/1/all*'] = function () use ($pages, $failMoviePage, $emptyPage, &$served) {
            if ($served + 1 === $failMoviePage) {
                throw new ConnectionException('Connection timed out');
            }

            $page = $pages[$served] ?? null;
            $served++;

            if ($page === null) {
                return $emptyPage;
            }

            return is_string($page) ? Http::response(fixtureBytes($page)) : $page();
        };
    }

    if ($failLeavesForRatingKey !== null) {
        $fakes["*/library/metadata/{$failLeavesForRatingKey}/allLeaves*"] = fn () => throw new ConnectionException('Connection timed out');
    }

    $fakes['*/allLeaves*'] = Http::response(fixtureBytes('PlexLibrary/plex/show_allLeaves_episodes.json'));

    Http::fake($fakes);
}

/**
 * Point Scout at a spy engine and hand back a getter for the model keys the
 * engine has been handed by $operation — `update` for the index writes,
 * `delete` for the removals — each call kept in its own group.
 *
 * The suite runs `SCOUT_DRIVER=collection`, whose engine writes nothing a DB
 * assertion can see — what the engine is handed is the only observable evidence
 * of what was (or wasn't) indexed. The groups are kept unflattened because the
 * call boundaries can themselves be the subject: a flat list of ids can't tell
 * one 5-row call from three smaller ones.
 *
 * Only one spy can own the driver at a time, so a test spanning both operations
 * picks the one whose absence is the failure it is proving.
 *
 * Call this LAST in Arrange: the `Searchable` trait syncs on every model save,
 * so a spy registered earlier also captures the arranged rows' create-time syncs
 * — every row looks reindexed and nothing can look quiet.
 *
 * @return Closure(): list<list<int|string>>
 */
function spyOnScoutEngine(string $operation = 'update'): Closure
{
    $captured = [];

    $spy = Mockery::spy(Engine::class);

    $spy->shouldReceive($operation)->andReturnUsing(
        function (EloquentCollection $models) use (&$captured): void {
            $captured[] = $models->modelKeys();
        },
    );

    resolve(EngineManager::class)->extend('spy', fn (): Engine => $spy);
    config(['scout.driver' => 'spy']);

    return function () use (&$captured): array {
        return $captured;
    };
}

/**
 * Every key spyOnScoutEngine() captured, in call order, with the chunk grouping
 * dropped. For the assertions that care only about WHICH rows reached the engine
 * and how many times — a row split across two update() calls must still read as
 * two occurrences. The tests whose subject IS the chunking read the groups
 * unflattened instead.
 *
 * @param  list<list<int|string>>  $chunks
 * @return list<int|string>
 */
function reindexedIds(array $chunks): array
{
    return collect($chunks)->flatten()->all();
}

/**
 * The query-log entries whose SQL satisfies $matches.
 *
 * The predicate always receives LOWERCASED SQL, and every matcher must stick to
 * dialect-stable lowercase substrings — the suite runs sqlite while prod is MySQL —
 * so no upsert conflict tail and no identifier quoting is ever asserted.
 *
 * @param  Closure(string): bool  $matches
 * @return Collection<int, array{query: string, bindings: array<int, mixed>}>
 */
function loggedStatements(Closure $matches): Collection
{
    return collect(DB::getQueryLog())
        ->filter(fn (array $entry): bool => $matches(Str::lower((string) $entry['query'])))
        ->values();
}

/**
 * The logged `insert into` statements naming one table. Scoping to the ingested
 * table is the point: UpsertTmdbImages writes `insert into … media` on the very
 * same path, and counting those would measure artwork, not batch size.
 *
 * @return Collection<int, array{query: string, bindings: array<int, mixed>}>
 */
function loggedInsertsInto(string $table): Collection
{
    return loggedStatements(fn (string $sql): bool => Str::startsWith($sql, 'insert into')
        && Str::contains($sql, $table));
}

/**
 * Whether a statement is a probe of our already-synced rows — SQL naming the
 * `tmdb_synced_at` predicate, minus the upsert, whose `insert into` column list
 * names that column too. Deliberately NOT "a select against the table": keying
 * on the predicate is what keeps the leg's other reads from answering to the
 * same description. Lowercases its own input, since it is also called directly
 * on raw QueryExecuted SQL.
 *
 * $requiresIdList additionally demands a buffered `in (…)` list, for the ingests
 * whose own candidate stream filters on `tmdb_synced_at` too — see
 * isShowChangesProbe().
 */
function isSyncedProbe(string $sql, bool $requiresIdList = false): bool
{
    $sql = Str::lower($sql);

    return Str::contains($sql, 'tmdb_synced_at')
        && (! $requiresIdList || Str::contains($sql, 'in ('))
        && ! Str::startsWith($sql, 'insert into');
}

/**
 * The logged narrow `select`s against one table: naming `_tmdb_id`, mentioning no
 * `*`, and satisfying the caller's $alsoNarrow clauses (which exclude the probe
 * shapes that would otherwise answer to the same description).
 *
 * Asserted as a PRESENCE — "the narrow select happened" — never as the negative
 * "no wide `select *` against the table"; a whole-model read elsewhere on the
 * leg is not this helper's subject.
 *
 * @param  Closure(string): bool  $alsoNarrow
 * @return Collection<int, array{query: string, bindings: array<int, mixed>}>
 */
function narrowSelects(string $table, Closure $alsoNarrow): Collection
{
    return loggedStatements(fn (string $sql): bool => Str::startsWith($sql, 'select')
        && Str::contains($sql, $table)
        && Str::contains($sql, '_tmdb_id')
        && ! Str::contains($sql, '*')
        && $alsoNarrow($sql));
}

/**
 * A saved plex_shows row scoped to the given server + library.
 *
 * The stamp defaults a minute back because `synced_at` is second-precision: a
 * factory default of now() would NOT be `< $now` within the same wall-clock
 * second and a prune assertion over it would pass vacuously. Pass $syncedAt to
 * stamp a row AT the pass clock instead — the shape a prune must spare.
 */
function staleShow(PlexServer $server, PlexLibrary $library, string $ratingKey, ?DateTimeInterface $syncedAt = null): PlexShow
{
    return PlexShow::factory()->create([
        'plex_server_id' => $server->id,
        'plex_library_id' => $library->id,
        '_plex_ratingKey' => $ratingKey,
        'synced_at' => $syncedAt ?? now()->subMinute(),
    ]);
}

/**
 * A saved plex_movies row scoped to the given server + library, stamped by the
 * same second-precision rule as staleShow().
 */
function staleMovie(PlexServer $server, PlexLibrary $library, string $ratingKey, ?DateTimeInterface $syncedAt = null): PlexMovie
{
    return PlexMovie::factory()->create([
        'plex_server_id' => $server->id,
        'plex_library_id' => $library->id,
        '_plex_ratingKey' => $ratingKey,
        'synced_at' => $syncedAt ?? now()->subMinute(),
    ]);
}
