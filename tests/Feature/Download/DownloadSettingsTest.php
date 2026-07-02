<?php

declare(strict_types=1);

use App\Domains\Download\Settings\DownloadSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('resolves provider cookie credentials seeded from env', function (): void {
    // Arrange
    // seed migration runs under RefreshDatabase, sourcing DOWNLOADS_UID/PASS from phpunit.xml

    // Act
    $settings = resolve(DownloadSettings::class);

    // Assert
    expect($settings->uid)->toBe('test-uid');
    expect($settings->pass)->toBe('test-pass');
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
    resolve(DownloadSettings::class);

    // Act
    $passPayload = DB::table('settings')
        ->where('group', 'download')
        ->where('name', 'pass')
        ->value('payload');

    // Assert
    expect($passPayload)->not->toContain('test-pass');
    expect(resolve(DownloadSettings::class)->pass)->toBe('test-pass');
});
