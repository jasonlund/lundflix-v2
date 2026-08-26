<?php

declare(strict_types=1);

use App\Domains\Download\Actions\UpsertDownloads;
use App\Domains\Download\Data\DownloadDescription;
use App\Domains\Download\Data\DownloadFile;
use App\Domains\Download\Data\DownloadResult;
use App\Domains\Download\Enums\Category;
use App\Domains\Download\Enums\Codec;
use App\Domains\Download\Enums\Quality;
use App\Domains\Download\Enums\ReleaseTag;
use App\Domains\Download\Enums\Source;
use App\Domains\Download\Enums\SyncChannel;
use App\Domains\Download\Models\Download;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function downloadResult(): DownloadResult
{
    return new DownloadResult(
        downloadId: 998877,
        name: 'Some Great Movie 1080p BluRay x264 PROPER',
        filename: 'some.great.movie.1080p.bluray.x264.proper.bin',
        quality: Quality::P1080,
        codec: Codec::X264,
        source: Source::BluRay,
        releaseTag: ReleaseTag::Proper,
        availability: 42,
        sizeBytes: 2_000_000_000,
        isRar: true,
        demand: 7,
        subcategory: 'Movies/x264',
        uploader: 'someUploader',
    );
}

describe('handle() insert', function (): void {
    it('inserts one row and returns a Download for an unseen provider id', function (): void {
        // Arrange
        $result = downloadResult();

        // Act
        $download = resolve(UpsertDownloads::class)->handle($result, Category::Movies, SyncChannel::Index);

        // Assert
        expect($download)->toBeInstanceOf(Download::class);
        $this->assertDatabaseCount('downloads', 1);
    });

    it('inserts a brand-new row when subcategory and demand are both null', function (): void {
        // Arrange
        $result = downloadResult();
        $result->subcategory = null;
        $result->demand = null;

        // Act
        $download = resolve(UpsertDownloads::class)->handle($result, Category::Movies, SyncChannel::Index);

        // Assert
        $this->assertDatabaseCount('downloads', 1);
        expect($download->refresh()->_provider_subcategory)->toBeNull();
        expect($download->_provider_demand)->toBeNull();
    });
});

describe('handle() column writes & channel stamping', function (): void {
    it('writes provider and name-derived fields to their columns', function (): void {
        // Arrange
        $result = downloadResult();
        $result->publishedAt = CarbonImmutable::parse('2026-01-15 12:00:00');
        $result->imdbId = 'tt7654321';
        $result->tmdbId = 12345;

        // Act
        $download = resolve(UpsertDownloads::class)->handle($result, Category::Movies, SyncChannel::Index);

        // Assert
        $this->assertDatabaseHas('downloads', [
            '_provider_name' => 'Some Great Movie 1080p BluRay x264 PROPER',
            '_provider_size_bytes' => 2_000_000_000,
            '_provider_availability' => 42,
            '_provider_demand' => 7,
            '_provider_subcategory' => 'Movies/x264',
            '_provider_published_at' => '2026-01-15 12:00:00',
            '_imdb_id' => 'tt7654321',
            '_tmdb_id' => 12345,
        ]);
        expect($download->quality)->toBe(Quality::P1080);
        expect($download->codec)->toBe(Codec::X264);
        expect($download->source)->toBe(Source::BluRay);
        expect($download->release_tag)->toBe(ReleaseTag::Proper);
        expect($download->is_rar)->toBeTrue();
    });

    it('writes the Category argument to _provider_category', function (): void {
        // Arrange
        $result = downloadResult();

        // Act
        resolve(UpsertDownloads::class)->handle($result, Category::Movies, SyncChannel::Index);

        // Assert
        $this->assertDatabaseHas('downloads', ['_provider_category' => Category::Movies->value]);
    });

    it('leaves _provider_category untouched on the Detail channel', function (): void {
        // Arrange
        resolve(UpsertDownloads::class)->handle(downloadResult(), Category::Movies, SyncChannel::Index);

        // Act
        resolve(UpsertDownloads::class)->handle(downloadResult(), Category::Tv, SyncChannel::Detail);

        // Assert
        $this->assertDatabaseHas('downloads', ['_provider_category' => Category::Movies->value]);
    });

    it('stamps only index_synced_at for the Index channel', function (): void {
        // Arrange
        $result = downloadResult();

        // Act
        $download = resolve(UpsertDownloads::class)->handle($result, Category::Movies, SyncChannel::Index);

        // Assert
        expect($download->index_synced_at)->not->toBeNull();
        expect($download->rss_synced_at)->toBeNull();
        expect($download->detail_synced_at)->toBeNull();
        expect($download->filelist_synced_at)->toBeNull();
    });
});

describe('handle() update semantics', function (): void {
    it('updates the existing row in place for a repeated provider id', function (): void {
        // Arrange
        resolve(UpsertDownloads::class)->handle(downloadResult(), Category::Movies, SyncChannel::Index);

        // Act
        $download = resolve(UpsertDownloads::class)->handle(downloadResult(), Category::Movies, SyncChannel::Rss);

        // Assert
        $this->assertDatabaseCount('downloads', 1);
        expect($download->rss_synced_at)->not->toBeNull();
    });

    it('preserves a stored field when a later write carries null', function (): void {
        // Arrange
        $first = downloadResult();
        $first->uploader = 'someone';
        resolve(UpsertDownloads::class)->handle($first, Category::Movies, SyncChannel::Index);
        $second = downloadResult();
        $second->uploader = null;

        // Act
        $download = resolve(UpsertDownloads::class)->handle($second, Category::Movies, SyncChannel::Index);

        // Assert
        expect($download->refresh()->_provider_uploader)->toBe('someone');
    });
});

describe('handle() file list & description enrichment', function (): void {
    it('stamps filelist_synced_at when the result carries files', function (): void {
        // Arrange
        resolve(UpsertDownloads::class)->handle(downloadResult(), Category::Movies, SyncChannel::Index);
        $result = downloadResult();
        $result->files = collect([new DownloadFile('file.bin', 1_000_000)]);

        // Act
        $download = resolve(UpsertDownloads::class)->handle($result, Category::Movies, SyncChannel::Detail);

        // Assert
        expect($download->filelist_synced_at)->not->toBeNull();
    });

    it('leaves filelist_synced_at null when the result carries no files', function (): void {
        // Arrange
        resolve(UpsertDownloads::class)->handle(downloadResult(), Category::Movies, SyncChannel::Index);
        $result = downloadResult();

        // Act
        $download = resolve(UpsertDownloads::class)->handle($result, Category::Movies, SyncChannel::Detail);

        // Assert
        expect($download->filelist_synced_at)->toBeNull();
    });

    it('transforms the description value object and preserves it against a later null', function (): void {
        // Arrange
        resolve(UpsertDownloads::class)->handle(downloadResult(), Category::Movies, SyncChannel::Index);
        $result = downloadResult();
        $result->description = new DownloadDescription(html: '<b>x</b>', screenshots: ['https://e.test/a.jpg']);
        $download = resolve(UpsertDownloads::class)->handle($result, Category::Movies, SyncChannel::Detail);
        $followUp = downloadResult();
        $followUp->description = null;

        // Act
        resolve(UpsertDownloads::class)->handle($followUp, Category::Movies, SyncChannel::Detail);

        // Assert
        expect($download->refresh()->_provider_description)->toBe([
            'text' => '<b>x</b>',
            'screenshots' => ['https://e.test/a.jpg'],
        ]);
    });
});
