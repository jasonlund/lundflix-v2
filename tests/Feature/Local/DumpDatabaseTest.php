<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Media;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Season;
use App\Domains\Catalog\Models\Show;
use App\Domains\Download\Models\Download;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('writes both the VC set and the full set by default', function (): void {
    // Arrange
    Movie::factory()->count(3)->create();
    Show::factory()->count(3)->create();
    Media::factory()->count(3)->create();
    Download::factory()->count(3)->create();
    Process::fake();

    // Act
    $this->artisan('db:dump')->assertSuccessful();

    // Assert
    $fullDir = config('database.dump_path');
    foreach (['movies', 'shows', 'media', 'downloads'] as $table) {
        Process::assertRan(fn ($process): bool => Str::contains((string) $process->command, 'mysqldump')
            && Str::contains((string) $process->command, $table)
            && Str::contains((string) $process->command, "database/dumps/{$table}.sql.gz"));
    }
    Process::assertRan(fn ($process): bool => Str::contains((string) $process->command, 'mysqldump')
        && Str::contains((string) $process->command, 'movies')
        && Str::contains((string) $process->command, "{$fullDir}/movies.sql.gz"));
});

it('dumps only the four catalog and download tables, never framework tables', function (): void {
    // Arrange
    Movie::factory()->count(3)->create();
    Show::factory()->count(3)->create();
    Process::fake();

    // Act
    $this->artisan('db:dump')->assertSuccessful();

    // Assert
    foreach (['settings', 'users', 'cache', 'migrations', 'jobs'] as $forbidden) {
        Process::assertNotRan(fn ($process): bool => Str::contains((string) $process->command, "{$forbidden}.sql.gz"));
    }
});

it('caps the VC parent dump best-first by popularity', function (): void {
    // Arrange
    Movie::factory()->count(3)->create();
    Process::fake();

    // Act
    $this->artisan('db:dump', ['--vc' => true])->assertSuccessful();

    // Assert
    Process::assertRan(fn ($process): bool => Str::contains((string) $process->command, 'database/dumps/movies.sql.gz')
        && Str::contains((string) $process->command, 'ORDER BY _tmdb_popularity DESC, id DESC')
        && Str::contains((string) $process->command, 'LIMIT '));
});

it('dumps lock-free and gtid-portable so child subqueries resolve and imports load', function (): void {
    // Arrange
    Movie::factory()->count(3)->create();
    Process::fake();

    // Act
    $this->artisan('db:dump', ['--vc' => true])->assertSuccessful();

    // Assert
    Process::assertRan(fn ($process): bool => Str::contains((string) $process->command, 'mysqldump')
        && Str::contains((string) $process->command, '--single-transaction')
        && Str::contains((string) $process->command, '--set-gtid-purged=OFF'));
});

it('dumps seasons kept coherent to the included shows', function (): void {
    // Arrange
    Show::factory()->count(3)->create();
    Season::factory()->count(3)->create();
    Process::fake();

    // Act
    $this->artisan('db:dump', ['--vc' => true])->assertSuccessful();

    // Assert
    Process::assertRan(fn ($process): bool => Str::contains((string) $process->command, 'mysqldump')
        && Str::contains((string) $process->command, 'database/dumps/seasons.sql.gz')
        && Str::contains((string) $process->command, 'FROM shows'));
});

it('caps downloads independently, best-first by availability', function (): void {
    // Arrange
    Download::factory()->count(3)->create();
    Process::fake();

    // Act
    $this->artisan('db:dump', ['--vc' => true])->assertSuccessful();

    // Assert
    Process::assertRan(fn ($process): bool => Str::contains((string) $process->command, 'database/dumps/downloads.sql.gz')
        && Str::contains((string) $process->command, 'ORDER BY _provider_availability DESC, id DESC')
        && Str::contains((string) $process->command, 'LIMIT '));
});

it('drops the cap on the VC set when --unlimited is passed', function (): void {
    // Arrange
    Movie::factory()->count(3)->create();
    Process::fake();

    // Act
    $this->artisan('db:dump', ['--unlimited' => true])->assertSuccessful();

    // Assert
    Process::assertRan(fn ($process): bool => Str::contains((string) $process->command, 'database/dumps/movies.sql.gz')
        && ! Str::contains((string) $process->command, 'LIMIT '));
});

it('writes only the VC set when --vc is passed', function (): void {
    // Arrange
    Movie::factory()->count(3)->create();
    Process::fake();

    // Act
    $this->artisan('db:dump', ['--vc' => true])->assertSuccessful();

    // Assert
    $fullDir = config('database.dump_path');
    Process::assertRan(fn ($process): bool => Str::contains((string) $process->command, 'database/dumps/'));
    Process::assertNotRan(fn ($process): bool => Str::contains((string) $process->command, "{$fullDir}/"));
});

it('fails and writes no dump file when mysqldump errors', function (): void {
    // Arrange
    Movie::factory()->count(3)->create();
    Process::fake(['*' => Process::result(exitCode: 1)]);

    // Act
    $result = $this->artisan('db:dump', ['--full' => true, '--path' => '/tmp/lundflix-dump-fail']);

    // Assert
    $result->assertFailed();
    expect(File::exists('/tmp/lundflix-dump-fail/movies.sql.gz'))->toBeFalse();
});

it('guards the dump against pipe-masking and shell-unsafe destination paths', function (): void {
    // Arrange
    Movie::factory()->count(3)->create();
    Process::fake();

    // Act
    $this->artisan('db:dump', ['--vc' => true])->assertSuccessful();

    // Assert
    Process::assertRan(fn ($process): bool => Str::contains((string) $process->command, 'bash -o pipefail -c')
        && Str::contains((string) $process->command, 'database/dumps/movies.sql.gz')
        && Str::contains((string) $process->command, "gzip -c > '"));
});

it('writes only the full set to the override dir when --full --path is passed', function (): void {
    // Arrange
    Movie::factory()->count(3)->create();
    Process::fake();

    // Act
    $this->artisan('db:dump', ['--full' => true, '--path' => '/tmp/lundflix-dumps'])->assertSuccessful();

    // Assert
    Process::assertRan(fn ($process): bool => Str::contains((string) $process->command, '/tmp/lundflix-dumps/movies.sql.gz'));
    Process::assertNotRan(fn ($process): bool => Str::contains((string) $process->command, 'database/dumps/'));
});
