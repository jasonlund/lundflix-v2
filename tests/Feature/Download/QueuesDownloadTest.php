<?php

declare(strict_types=1);

use App\Domains\Download\Contracts\QueuesDownload;
use App\Domains\Download\Exceptions\DownloadRequestFailed;
use App\Domains\Download\Models\Download;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| QueuesDownload — hand a chosen row to the fetch path
|--------------------------------------------------------------------------
| queue() takes the `downloads.id` primary key FindsAcquirableDownloads::for()
| returns, looks the row up, and drives the existing DownloadService fetch with
| that row's own `_provider_id` and `_provider_filename` — so the outbound path
| is `/download.php/<_provider_id>/<_provider_filename>` and the bytes land on
| the default disk under downloads/.
|
| The URL assertion is host-free on purpose (Str::endsWith over the path), like
| the sibling DownloadService tests.
|
| Fixture (byte-exact real capture):
|   tests/Fixtures/Download/downloads/sample.bin — real download file bytes.
*/

describe('queue() fetch hand-off', function (): void {
    it('requests the row from the source and stores the file', function (): void {
        // Arrange
        Storage::fake();
        Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/sample.bin'), 200)]);
        $download = Download::factory()->create([
            '_provider_id' => 7537888,
            '_provider_filename' => 'The.Matrix.Reloaded.2003.1080p.MA.WEB-DL.H.264.DDP5.1-HHWEB',
        ]);

        // Act
        resolve(QueuesDownload::class)->queue($download->id);

        // Assert
        Http::assertSent(fn ($request): bool => Str::endsWith(
            (string) $request->url(),
            '/download.php/7537888/The.Matrix.Reloaded.2003.1080p.MA.WEB-DL.H.264.DDP5.1-HHWEB',
        ));
        Storage::disk()->assertExists('downloads/The.Matrix.Reloaded.2003.1080p.MA.WEB-DL.H.264.DDP5.1-HHWEB');
    });

    it('makes no request for an unknown download id', function (): void {
        // Arrange
        // no row exists, so there is nothing to fetch; Http::preventStrayRequests()
        // is global for Feature tests, so a stray call fails loudly on its own
        Storage::fake();

        // Act
        resolve(QueuesDownload::class)->queue(404404);

        // Assert
        Http::assertNothingSent();
        Storage::disk()->assertDirectoryEmpty('/');
    });

    it('surfaces the domain failure when the source refuses the request', function (): void {
        // Arrange
        Storage::fake();
        Http::fake(['*' => Http::response('', 500)]);
        $download = Download::factory()->create([
            '_provider_id' => 7537888,
            '_provider_filename' => 'The.Matrix.Reloaded.2003.1080p.MA.WEB-DL.H.264.DDP5.1-HHWEB',
        ]);

        // Act & Assert
        expect(fn () => resolve(QueuesDownload::class)->queue($download->id))->toThrow(DownloadRequestFailed::class);
    });
});
