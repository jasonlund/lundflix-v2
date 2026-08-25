<?php

declare(strict_types=1);

use App\Domains\Local\Database\MysqlConnection;
use Tests\TestCase;

// args() and passwordEnv() read config(), so this pure-logic test still needs the
// app container booted — TestCase only, no RefreshDatabase (no DB is touched).
uses(TestCase::class);

describe('args() connection flags', function (): void {
    it('builds escaped host, port, user, and database flags from the mysql connection config', function (): void {
        // Arrange
        config()->set('database.connections.mysql', [
            'host' => 'db.internal',
            'port' => '3307',
            'username' => 'seeder',
            'password' => 'secret',
            'database' => 'lundflix',
        ]);

        // Act
        $args = MysqlConnection::args();

        // Assert
        expect($args)
            ->toContain('-h '.escapeshellarg('db.internal'))
            ->toContain('-P '.escapeshellarg('3307'))
            ->toContain('-u '.escapeshellarg('seeder'))
            ->toEndWith(escapeshellarg('lundflix'));
    });

    it('never emits a password flag on the command line even when a password is configured', function (): void {
        // Arrange
        config()->set('database.connections.mysql', [
            'host' => 'db.internal',
            'port' => '3306',
            'username' => 'seeder',
            'password' => 'secret',
            'database' => 'lundflix',
        ]);

        // Act
        $args = MysqlConnection::args();

        // Assert
        expect($args)
            ->not->toContain(' -p')
            ->not->toContain('secret');
    });
});

describe('passwordEnv() password handling', function (): void {
    it('exposes the configured password out-of-band as a MYSQL_PWD env entry', function (): void {
        // Arrange
        config()->set('database.connections.mysql', [
            'host' => 'db.internal',
            'port' => '3306',
            'username' => 'seeder',
            'password' => 'secret',
            'database' => 'lundflix',
        ]);

        // Act
        $env = MysqlConnection::passwordEnv();

        // Assert
        expect($env)->toBe(['MYSQL_PWD' => 'secret']);
    });

    it('returns an empty env when no password is configured', function (): void {
        // Arrange
        config()->set('database.connections.mysql', [
            'host' => 'db.internal',
            'port' => '3306',
            'username' => 'seeder',
            'password' => '',
            'database' => 'lundflix',
        ]);

        // Act
        $env = MysqlConnection::passwordEnv();

        // Assert
        expect($env)->toBe([]);
    });
});

describe('args() shell escaping', function (): void {
    it('quotes credential values containing shell metacharacters rather than emitting them raw', function (): void {
        // Arrange
        config()->set('database.connections.mysql', [
            'host' => 'db.internal',
            'port' => '3306',
            'username' => 'seeder; rm -rf /',
            'password' => 'secret',
            'database' => 'lundflix',
        ]);

        // Act
        $args = MysqlConnection::args();

        // Assert
        expect($args)
            ->toContain(escapeshellarg('seeder; rm -rf /'))
            ->not->toContain('-u seeder; rm -rf /');
    });
});
