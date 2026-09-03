<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Media;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Season;
use App\Domains\Catalog\Models\Show;
use App\Domains\Download\Models\Download;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('db:import table loading and truncation', function (): void {
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

    it('skips absent dump files and leaves their tables untouched', function (): void {
        // Arrange
        $dir = storage_path('framework/testing/import-'.uniqid());
        File::ensureDirectoryExists($dir);
        File::copy(base_path('tests/Fixtures/Database/movies.sql.gz'), "{$dir}/movies.sql.gz");
        Movie::factory()->count(3)->create();
        Show::factory()->count(2)->create();
        Season::factory()->count(2)->create();
        Media::factory()->count(2)->create();
        Download::factory()->count(2)->create();
        $untouched = collect(['shows', 'seasons', 'media', 'downloads'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);
        Process::fake();

        // Act
        $this->artisan('db:import', ['--from' => $dir])->assertSuccessful();

        // Assert
        expect(DB::table('movies')->count())->toBe(0);
        foreach ($untouched as $table => $count) {
            expect(DB::table($table)->count())->toBe($count);
        }
        Process::assertRan(fn ($process): bool => Str::contains((string) $process->command, 'movies.sql.gz')
            && Str::contains((string) $process->command, 'mysql'));
        $untouched->keys()->each(fn (string $absent) => Process::assertNotRan(
            fn ($process): bool => Str::contains((string) $process->command, "{$absent}.sql.gz"),
        ));
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
});

describe('db:import source dir', function (): void {
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
});

describe('db:import load failure', function (): void {
    it('fails and names the table when a dump load fails', function (): void {
        // Arrange
        Process::fake(['*movies.sql.gz*' => Process::result(exitCode: 1)]);

        // Act & Assert
        $this->artisan('db:import', ['--from' => base_path('tests/Fixtures/Database')])
            ->expectsOutputToContain('movies')
            ->assertFailed();
    });

    it('stops loading later tables once a load fails', function (): void {
        // Arrange
        Process::fake(['*movies.sql.gz*' => Process::result(exitCode: 1)]);

        // Act
        $this->artisan('db:import', ['--from' => base_path('tests/Fixtures/Database')])->assertFailed();

        // Assert
        Process::assertNotRan(fn ($process): bool => Str::contains((string) $process->command, 'shows.sql.gz'));
    });

    it('re-enables foreign key constraints even when a load fails', function (): void {
        // Arrange
        Schema::spy();
        Process::fake(['*movies.sql.gz*' => Process::result(exitCode: 1)]);

        // Act
        $this->artisan('db:import', ['--from' => base_path('tests/Fixtures/Database')])->assertFailed();

        // Assert
        Schema::shouldHaveReceived('enableForeignKeyConstraints');
    });
});

describe('db:import production guard', function (): void {
    it('refuses to truncate in production without --force', function (): void {
        // Arrange
        Movie::factory()->count(3)->create();
        Process::fake();
        app()->detectEnvironment(fn (): string => 'production');

        // Act
        $this->artisan('db:import', ['--from' => base_path('tests/Fixtures/Database')])
            ->expectsConfirmation('Are you sure you want to run this command?', 'no')
            ->assertFailed();

        // Assert
        expect(DB::table('movies')->count())->toBe(3);
        Process::assertNothingRan();
    });

    it('proceeds in production when --force is passed', function (): void {
        // Arrange
        Movie::factory()->count(3)->create();
        Process::fake();
        app()->detectEnvironment(fn (): string => 'production');

        // Act
        $this->artisan('db:import', ['--from' => base_path('tests/Fixtures/Database'), '--force' => true])->assertSuccessful();

        // Assert
        expect(DB::table('movies')->count())->toBe(0);
        Process::assertRan(fn ($process): bool => Str::contains((string) $process->command, 'movies.sql.gz'));
    });
});

describe('db:import shell escaping', function (): void {
    it('escapes the dump file path in the shell load command', function (): void {
        // Arrange
        Process::fake();

        // Act
        $this->artisan('db:import', ['--from' => base_path('tests/Fixtures/Database')])->assertSuccessful();

        // Assert
        Process::assertRan(fn ($process): bool => Str::contains(
            (string) $process->command,
            escapeshellarg(base_path('tests/Fixtures/Database').'/movies.sql.gz'),
        ));
    });
});

describe('db:import output', function (): void {
    it('announces itself before the first table it loads', function (): void {
        // Arrange
        Process::fake();

        // Act
        Artisan::call('db:import', ['--from' => base_path('tests/Fixtures/Database')]);

        // The phase line has to precede the first destructive truncate; at this seam
        // its position ahead of the first per-table line is the observable form of that.
        // Assert
        $output = Artisan::output();
        expect($output)->toContain('Importing the dumps…');
        expect(strpos($output, 'Importing the dumps…'))->toBeLessThan(strpos($output, '  [import movies]'));
    });

    it('reports every table it loads', function (): void {
        // Arrange
        Process::fake();

        // Act
        Artisan::call('db:import', ['--from' => base_path('tests/Fixtures/Database')]);

        // Assert
        $output = Artisan::output();
        foreach (['movies', 'shows', 'media', 'downloads'] as $table) {
            expect($output)->toContain("  [import {$table}]");
        }
    });

    it('reports nothing for a table whose dump file is absent', function (): void {
        // Arrange
        $dir = storage_path('framework/testing/import-'.uniqid());
        File::ensureDirectoryExists($dir);
        File::copy(base_path('tests/Fixtures/Database/movies.sql.gz'), "{$dir}/movies.sql.gz");
        Process::fake();

        // Act
        Artisan::call('db:import', ['--from' => $dir]);

        // Assert
        $output = Artisan::output();
        expect($output)->toContain('  [import movies]');
        foreach (['shows', 'seasons', 'media', 'downloads'] as $absent) {
            expect($output)->not->toContain("[import {$absent}]");
        }
    });

    it('ends a completed run with a Done. line', function (): void {
        // Arrange
        Process::fake();

        // Act & Assert
        $this->artisan('db:import', ['--from' => base_path('tests/Fixtures/Database')])
            ->expectsOutputToContain('Done.')
            ->assertSuccessful();
    });
});
