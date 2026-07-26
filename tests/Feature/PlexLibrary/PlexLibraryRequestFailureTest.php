<?php

declare(strict_types=1);

use App\Domains\Common\Exceptions\PlexAuthenticationFailed;
use App\Domains\Common\Exceptions\PlexRequestFailed;
use App\Domains\PlexLibrary\Services\PlexLibraryService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Plex library service — transport failure mapping slice
|--------------------------------------------------------------------------
| A transport-level ConnectionException past the global retry middleware must
| surface as a typed PlexRequestFailed, never bubble raw — proven on BOTH call
| shapes the service makes: a section fetch (/library/sections/{key}/all) and a
| metadata fetch (/library/metadata/{rk}/allLeaves). Mirrors how PlexApiService
| maps ConnectionException -> PlexRequestFailed::for($url) in its private get().
| The throwing closure may be retried a few times; after retries it still throws
| ConnectionException, and each test asserts the mapped type. No fixtures here —
| the failure is synthesized at the wire (an input real data can't produce).
|
| An UNSUCCESSFUL HTTP STATUS is the same class of failure and must throw too, on
| every fetch method: an unthrown 401/403/404/5xx/post-retry-429 decodes to an
| empty MediaContainer, which the reconcilers read as "the server confirms this is
| empty" and answer by hard-deleting every local row (a whereNotIn over [] prunes
| unbounded). Mapping mirrors PlexApiService::decode(): 401 -> the distinct
| PlexAuthenticationFailed, any other failed status -> PlexRequestFailed. Statuses
| are synthesized (an error response is not capturable as real data); a retried 429
| carries Retry-After: 0 so the suite stays sleep-free.
*/

it('maps a section fetch ConnectionException to PlexRequestFailed', function (): void {
    // Arrange
    Http::fake([
        '*/library/sections/1/all*' => fn () => throw new ConnectionException('Connection timed out'),
    ]);

    // Act & Assert
    expect(fn () => resolve(PlexLibraryService::class)->fetchSectionItems('https://plex.test:6022', 'tok', '1'))
        ->toThrow(PlexRequestFailed::class);
});

it('maps a metadata leaves fetch ConnectionException to PlexRequestFailed', function (): void {
    // Arrange
    Http::fake([
        '*/library/metadata/34112/allLeaves*' => fn () => throw new ConnectionException('Connection timed out'),
    ]);

    // Act & Assert
    expect(fn () => resolve(PlexLibraryService::class)->fetchShowLeaves('https://plex.test:6022', 'tok', '34112'))
        ->toThrow(PlexRequestFailed::class);
});

it('maps a sections fetch 500 to PlexRequestFailed', function (): void {
    // Arrange
    Http::fake([
        '*/library/sections' => Http::response('', 500),
    ]);

    // Act & Assert
    expect(fn () => resolve(PlexLibraryService::class)->fetchSections('https://plex.test:6022', 'tok'))
        ->toThrow(PlexRequestFailed::class);
});

it('maps a section items fetch 503 to PlexRequestFailed', function (): void {
    // Arrange
    Http::fake([
        '*/library/sections/1/all*' => Http::response('', 503),
    ]);

    // Act & Assert
    expect(fn () => resolve(PlexLibraryService::class)->fetchSectionItems('https://plex.test:6022', 'tok', '1'))
        ->toThrow(PlexRequestFailed::class);
});

it('maps a metadata children fetch 429 past retries to PlexRequestFailed', function (): void {
    // Arrange
    Http::fake([
        '*/library/metadata/34112/children*' => Http::response('', 429, ['Retry-After' => '0']),
    ]);

    // Act & Assert
    expect(fn () => resolve(PlexLibraryService::class)->fetchShowChildren('https://plex.test:6022', 'tok', '34112'))
        ->toThrow(PlexRequestFailed::class);
});

it('maps a metadata leaves fetch 403 to PlexRequestFailed', function (): void {
    // Arrange
    Http::fake([
        '*/library/metadata/34112/allLeaves*' => Http::response('', 403),
    ]);

    // Act & Assert
    expect(fn () => resolve(PlexLibraryService::class)->fetchShowLeaves('https://plex.test:6022', 'tok', '34112'))
        ->toThrow(PlexRequestFailed::class);
});

it('maps a metadata leaves fetch 404 to PlexRequestFailed', function (): void {
    // Arrange
    Http::fake([
        '*/library/metadata/34112/allLeaves*' => Http::response('', 404),
    ]);

    // Act & Assert
    expect(fn () => resolve(PlexLibraryService::class)->fetchShowLeaves('https://plex.test:6022', 'tok', '34112'))
        ->toThrow(PlexRequestFailed::class);
});

it('maps a sections fetch 401 to PlexAuthenticationFailed', function (): void {
    // Arrange
    Http::fake([
        '*/library/sections' => Http::response('', 401),
    ]);

    // Act & Assert
    expect(fn () => resolve(PlexLibraryService::class)->fetchSections('https://plex.test:6022', 'tok'))
        ->toThrow(PlexAuthenticationFailed::class);
});
