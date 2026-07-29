<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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
 */
function fakePlexSeedCrawl(
    ?string $failLeavesForRatingKey = null,
    string $showSection = 'PlexLibrary/plex/section_show_all_includeGuids.json',
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

    if ($failLeavesForRatingKey !== null) {
        $fakes["*/library/metadata/{$failLeavesForRatingKey}/allLeaves*"] = fn () => throw new ConnectionException('Connection timed out');
    }

    $fakes['*/allLeaves*'] = Http::response(fixtureBytes('PlexLibrary/plex/show_allLeaves_episodes.json'));

    Http::fake($fakes);
}
