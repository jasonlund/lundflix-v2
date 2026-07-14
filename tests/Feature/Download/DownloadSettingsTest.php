<?php

declare(strict_types=1);

use App\Domains\Download\Settings\DownloadSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('defaults credentials to empty when unset', function (): void {
    // Arrange
    // migration registers the keys with empty defaults; no operator value stored

    // Act
    $settings = resolve(DownloadSettings::class);

    // Assert
    expect($settings->uid)->toBe('');
    expect($settings->pass)->toBe('');
});

it('defaults rss_key to empty when unset', function (): void {
    // Arrange
    // migration registers the key with an empty default; no operator value stored

    // Act
    $settings = resolve(DownloadSettings::class);

    // Assert
    expect($settings->rss_key)->toBe('');
});

it('persists rotated credentials', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);

    // Act
    $settings->uid = 'rotated-uid';
    $settings->pass = 'rotated-pass';
    $settings->save();

    // Assert
    app()->forgetInstance(DownloadSettings::class);
    $reloaded = resolve(DownloadSettings::class);
    expect($reloaded->uid)->toBe('rotated-uid');
    expect($reloaded->pass)->toBe('rotated-pass');
});

it('stores the pass encrypted at rest', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->pass = 'secret-pass';
    $settings->save();

    // Act
    $passPayload = DB::table('settings')
        ->where('group', 'download')
        ->where('name', 'pass')
        ->value('payload');

    // Assert
    expect($passPayload)->not->toContain('secret-pass');
    app()->forgetInstance(DownloadSettings::class);
    expect(resolve(DownloadSettings::class)->pass)->toBe('secret-pass');
});

it('stores the rss_key encrypted at rest', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->rss_key = 'secret-rss';
    $settings->save();

    // Act
    $rssKeyPayload = DB::table('settings')
        ->where('group', 'download')
        ->where('name', 'rss_key')
        ->value('payload');

    // Assert
    expect($rssKeyPayload)->not->toContain('secret-rss');
    app()->forgetInstance(DownloadSettings::class);
    expect(resolve(DownloadSettings::class)->rss_key)->toBe('secret-rss');
});
