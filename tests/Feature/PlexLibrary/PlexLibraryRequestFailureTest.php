<?php

declare(strict_types=1);

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
