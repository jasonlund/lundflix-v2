<?php

declare(strict_types=1);

describe('database.dump_path config', function (): void {
    it('defaults the database dump path to storage_path(app/backups) when DB_DUMP_PATH is unset', function (): void {
        // Arrange
        // (no override set; asserting the config default resolved at load)

        // Act
        $dumpPath = config('database.dump_path');

        // Assert
        expect($dumpPath)->toBe(storage_path('app/backups'));
    });

    it('resolves the database dump path to an absolute override path when configured', function (): void {
        // Arrange
        config()->set('database.dump_path', '/tmp/lundflix-dumps');

        // Act
        $dumpPath = config('database.dump_path');

        // Assert
        expect($dumpPath)->toBe('/tmp/lundflix-dumps');
    });
});
