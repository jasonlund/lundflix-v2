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

it('inserts one row and returns a Download for an unseen provider id', function () {
    // Arrange
    $result = downloadResult();

    // Act
    $download = app(UpsertDownloads::class)->handle($result, Category::Movies, SyncChannel::Index);

    // Assert
    expect($download)->toBeInstanceOf(Download::class);
    $this->assertDatabaseCount('downloads', 1);
});

it('writes provider and name-derived fields to their columns', function () {
    // Arrange
    $result = downloadResult();

    // Act
    $download = app(UpsertDownloads::class)->handle($result, Category::Movies, SyncChannel::Index);

    // Assert
    $this->assertDatabaseHas('downloads', [
        '_provider_name' => 'Some Great Movie 1080p BluRay x264 PROPER',
        '_provider_size_bytes' => 2_000_000_000,
        '_provider_availability' => 42,
        '_provider_demand' => 7,
    ]);
    expect($download->quality)->toBe(Quality::P1080);
    expect($download->codec)->toBe(Codec::X264);
    expect($download->source)->toBe(Source::BluRay);
    expect($download->release_tag)->toBe(ReleaseTag::Proper);
    expect($download->is_rar)->toBeTrue();
});

it('writes the Category argument to _provider_category', function () {
    // Arrange
    $result = downloadResult();

    // Act
    app(UpsertDownloads::class)->handle($result, Category::Movies, SyncChannel::Index);

    // Assert
    $this->assertDatabaseHas('downloads', ['_provider_category' => Category::Movies->value]);
});

it('stamps only index_synced_at for the Index channel', function () {
    // Arrange
    $result = downloadResult();

    // Act
    $download = app(UpsertDownloads::class)->handle($result, Category::Movies, SyncChannel::Index);

    // Assert
    expect($download->index_synced_at)->not->toBeNull();
    expect($download->rss_synced_at)->toBeNull();
    expect($download->detail_synced_at)->toBeNull();
    expect($download->filelist_synced_at)->toBeNull();
});

it('updates the existing row in place for a repeated provider id', function () {
    // Arrange
    app(UpsertDownloads::class)->handle(downloadResult(), Category::Movies, SyncChannel::Index);

    // Act
    $download = app(UpsertDownloads::class)->handle(downloadResult(), Category::Movies, SyncChannel::Rss);

    // Assert
    $this->assertDatabaseCount('downloads', 1);
    expect($download->rss_synced_at)->not->toBeNull();
});

it('preserves a stored field when a later write carries null', function () {
    // Arrange
    $first = downloadResult();
    $first->uploader = 'someone';
    app(UpsertDownloads::class)->handle($first, Category::Movies, SyncChannel::Index);
    $second = downloadResult();
    $second->uploader = null;

    // Act
    $download = app(UpsertDownloads::class)->handle($second, Category::Movies, SyncChannel::Index);

    // Assert
    expect($download->refresh()->_provider_uploader)->toBe('someone');
});

it('stamps filelist_synced_at when the result carries files', function () {
    // Arrange
    $result = downloadResult();
    $result->files = collect([new DownloadFile('file.bin', 1_000_000)]);

    // Act
    $download = app(UpsertDownloads::class)->handle($result, Category::Movies, SyncChannel::Detail);

    // Assert
    expect($download->filelist_synced_at)->not->toBeNull();
});

it('leaves filelist_synced_at null when the result carries no files', function () {
    // Arrange
    $result = downloadResult();

    // Act
    $download = app(UpsertDownloads::class)->handle($result, Category::Movies, SyncChannel::Detail);

    // Assert
    expect($download->filelist_synced_at)->toBeNull();
});

it('transforms the description value object and preserves it against a later null', function () {
    // Arrange
    $result = downloadResult();
    $result->description = new DownloadDescription(html: '<b>x</b>', screenshots: ['https://e.test/a.jpg']);
    $download = app(UpsertDownloads::class)->handle($result, Category::Movies, SyncChannel::Detail);
    $followUp = downloadResult();
    $followUp->description = null;

    // Act
    app(UpsertDownloads::class)->handle($followUp, Category::Movies, SyncChannel::Detail);

    // Assert
    expect($download->refresh()->_provider_description)->toBe([
        'text' => '<b>x</b>',
        'screenshots' => ['https://e.test/a.jpg'],
    ]);
});
