<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use App\Domains\Download\Models\Download;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('truncates a target table then shells the gzipped load for it', function (): void {
    // Arrange
    Movie::factory()->count(3)->create();
    Process::fake();

    // Act
    $this->artisan('db:import', ['--from' => base_path('tests/Fixtures/Database')])->assertSuccessful();

    // Assert
    expect(DB::table('movies')->count())->toBe(0);
    Process::assertRan(fn ($process): bool => Str::contains((string) $process->command, 'movies.sql.gz')
        && Str::contains((string) $process->command, 'mysql'));
});

it('loads every present table dump file', function (): void {
    // Arrange
    Process::fake();

    // Act
    $this->artisan('db:import', ['--from' => base_path('tests/Fixtures/Database')])->assertSuccessful();

    // Assert
    foreach (['movies', 'shows', 'media', 'downloads'] as $table) {
        Process::assertRan(fn ($process): bool => Str::contains((string) $process->command, "{$table}.sql.gz")
            && Str::contains((string) $process->command, 'mysql'));
    }
});

it('skips absent dump files and still succeeds', function (): void {
    // Arrange
    $dir = storage_path('framework/testing/import-'.uniqid());
    File::ensureDirectoryExists($dir);
    File::copy(base_path('tests/Fixtures/Database/movies.sql.gz'), "{$dir}/movies.sql.gz");
    Process::fake();

    // Act
    $this->artisan('db:import', ['--from' => $dir])->assertSuccessful();

    // Assert
    Process::assertRan(fn ($process): bool => Str::contains((string) $process->command, 'movies.sql.gz'));
    foreach (['shows', 'media', 'downloads'] as $absent) {
        Process::assertNotRan(fn ($process): bool => Str::contains((string) $process->command, "{$absent}.sql.gz"));
    }
});

it('loads the seasons dump when present', function (): void {
    // Arrange
    $dir = storage_path('framework/testing/import-'.uniqid());
    File::ensureDirectoryExists($dir);
    File::copy(base_path('tests/Fixtures/Database/movies.sql.gz'), "{$dir}/seasons.sql.gz");
    Process::fake();

    // Act
    $this->artisan('db:import', ['--from' => $dir])->assertSuccessful();

    // Assert
    Process::assertRan(fn ($process): bool => Str::contains((string) $process->command, 'seasons.sql.gz')
        && Str::contains((string) $process->command, 'mysql'));
});

it('disables foreign key constraints around the truncate loop', function (): void {
    // Arrange
    Schema::spy();
    Process::fake();

    // Act
    $this->artisan('db:import', ['--from' => base_path('tests/Fixtures/Database')])->assertSuccessful();

    // Assert
    Schema::shouldHaveReceived('disableForeignKeyConstraints');
    Schema::shouldHaveReceived('enableForeignKeyConstraints');
});

it('honors the --path override for the source dir', function (): void {
    // Arrange
    Process::fake();

    // Act
    $this->artisan('db:import', ['--path' => base_path('tests/Fixtures/Database')])->assertSuccessful();

    // Assert
    Process::assertRan(fn ($process): bool => Str::contains((string) $process->command, base_path('tests/Fixtures/Database'))
        && Str::contains((string) $process->command, 'movies.sql.gz'));
});

it('fails without truncating when the source dir is missing', function (): void {
    // Arrange
    Movie::factory()->count(3)->create();
    Download::factory()->count(3)->create();
    Process::fake();

    // Act
    $this->artisan('db:import', ['--from' => '/nonexistent/dir'])->assertFailed();

    // Assert
    expect(DB::table('movies')->count())->toBe(3);
    Process::assertNothingRan();
});
