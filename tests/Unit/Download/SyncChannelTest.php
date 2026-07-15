<?php

declare(strict_types=1);

use App\Domains\Download\Enums\SyncChannel;

it('maps the Index channel to the index_synced_at freshness column', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $column = SyncChannel::Index->syncedAtColumn();

    // Assert
    expect($column)->toBe('index_synced_at');
});

it('maps the Rss channel to the rss_synced_at freshness column', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $column = SyncChannel::Rss->syncedAtColumn();

    // Assert
    expect($column)->toBe('rss_synced_at');
});

it('maps the Detail channel to the detail_synced_at freshness column', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $column = SyncChannel::Detail->syncedAtColumn();

    // Assert
    expect($column)->toBe('detail_synced_at');
});
