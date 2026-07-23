<?php

declare(strict_types=1);

use App\Domains\PlexLibrary\Services\PlexLibraryService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Plex library service — section transport slice
|--------------------------------------------------------------------------
| fetchSections(uri, token) reads MediaContainer.Directory; fetchSectionItems
| walks /library/sections/{key}/all in X-Plex-Container-Start/Size pages until
| totalSize, concatenating members with their Guid[]. Both take the resolved
| server uri + access token as explicit params. HTTP is faked at the wire by
| host-agnostic path pattern; a paged walk is faked as a response sequence.
|
| Fixtures (byte-exact real captures / spliced-real members):
|   tests/Fixtures/PlexLibrary/plex/sections.json — MediaContainer.Directory of
|     2 entries: {key:"1",type:"movie"} and {key:"2",type:"show"}.
|   tests/Fixtures/PlexLibrary/plex/section_all_page1.json — offset:0 size:2
|     totalSize:3, Metadata ratingKeys 26278,36080; first member Guid[] carries
|     imdb://tt8368368.
|   tests/Fixtures/PlexLibrary/plex/section_all_page2.json — offset:2 size:1
|     totalSize:3, Metadata ratingKey 32202.
*/

it('returns both directory entries from fetchSections', function (): void {
    // Arrange
    $uri = 'https://plex.test:6022';
    $token = 'access-token-abc';
    Http::fake([
        '*/library/sections' => Http::response(fixtureBytes('PlexLibrary/plex/sections.json')),
    ]);

    // Act
    $sections = resolve(PlexLibraryService::class)->fetchSections($uri, $token);

    // Assert
    expect($sections)->toHaveCount(2);
});

it('carries the key and type of each directory entry', function (): void {
    // Arrange
    $uri = 'https://plex.test:6022';
    $token = 'access-token-abc';
    Http::fake([
        '*/library/sections' => Http::response(fixtureBytes('PlexLibrary/plex/sections.json')),
    ]);

    // Act
    $sections = resolve(PlexLibraryService::class)->fetchSections($uri, $token);

    // Assert
    expect(collect($sections)->map(fn (array $s): array => [$s['key'], $s['type']])->all())
        ->toBe([['1', 'movie'], ['2', 'show']]);
});

it('walks both pages and concatenates members in order', function (): void {
    // Arrange
    $uri = 'https://plex.test:6022';
    $token = 'access-token-abc';
    Http::fake([
        '*/library/sections/1/all*' => Http::sequence()
            ->push(fixtureBytes('PlexLibrary/plex/section_all_page1.json'))
            ->push(fixtureBytes('PlexLibrary/plex/section_all_page2.json')),
    ]);

    // Act
    $items = resolve(PlexLibraryService::class)->fetchSectionItems($uri, $token, '1');

    // Assert
    expect($items)->toHaveCount(3)
        ->and(collect($items)->pluck('ratingKey')->all())->toBe(['26278', '36080', '32202']);
});

it('advances X-Plex-Container-Start across the two page requests', function (): void {
    // Arrange
    $uri = 'https://plex.test:6022';
    $token = 'access-token-abc';
    Http::fake([
        '*/library/sections/1/all*' => Http::sequence()
            ->push(fixtureBytes('PlexLibrary/plex/section_all_page1.json'))
            ->push(fixtureBytes('PlexLibrary/plex/section_all_page2.json')),
    ]);

    // Act
    resolve(PlexLibraryService::class)->fetchSectionItems($uri, $token, '1');

    // Assert
    Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '/library/sections/1/all')
        && ($request->header('X-Plex-Container-Start')[0] ?? null) === '0');
    Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '/library/sections/1/all')
        && ($request->header('X-Plex-Container-Start')[0] ?? null) === '2');
});

it('requests includeGuids and members retain their Guid entries', function (): void {
    // Arrange
    $uri = 'https://plex.test:6022';
    $token = 'access-token-abc';
    Http::fake([
        '*/library/sections/1/all*' => Http::sequence()
            ->push(fixtureBytes('PlexLibrary/plex/section_all_page1.json'))
            ->push(fixtureBytes('PlexLibrary/plex/section_all_page2.json')),
    ]);

    // Act
    $items = resolve(PlexLibraryService::class)->fetchSectionItems($uri, $token, '1');

    // Assert
    Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '/library/sections/1/all')
        && Str::contains((string) $request->url(), 'includeGuids=1'));
    expect(data_get($items, '0.Guid.0.id'))->toStartWith('imdb://');
});
