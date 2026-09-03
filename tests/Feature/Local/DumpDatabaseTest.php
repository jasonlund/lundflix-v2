<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Media;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Season;
use App\Domains\Catalog\Models\Show;
use App\Domains\Download\Models\Download;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('db:dump default run', function (): void {
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
});

describe('db:dump VC set selection', function (): void {
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
});

describe('db:dump set flags', function (): void {
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
});

describe('db:dump destination paths and failure', function (): void {
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
});

describe('db:dump output', function (): void {
    it('announces both sets on a default run', function (): void {
        // Arrange
        Movie::factory()->count(3)->create();
        Show::factory()->count(3)->create();
        Media::factory()->count(3)->create();
        Download::factory()->count(3)->create();
        Process::fake();

        // Act & Assert
        $this->artisan('db:dump')
            ->expectsOutputToContain('Dumping the version-controlled set…')
            ->expectsOutputToContain('Dumping the full set…')
            ->assertSuccessful();
    });

    it('announces only the version-controlled set when --vc is passed', function (): void {
        // Arrange
        Movie::factory()->count(3)->create();
        Process::fake();

        // Act & Assert
        $this->artisan('db:dump', ['--vc' => true])
            ->expectsOutputToContain('Dumping the version-controlled set…')
            ->doesntExpectOutputToContain('Dumping the full set…')
            ->assertSuccessful();
    });

    it('closes the run with a completion line', function (): void {
        // Arrange
        Movie::factory()->count(3)->create();
        Process::fake();

        // Act & Assert
        $this->artisan('db:dump', ['--vc' => true])
            ->expectsOutputToContain('Done.')
            ->assertSuccessful();
    });

    it('reports the fitted row count for a capped table', function (): void {
        // Arrange
        Movie::factory()->count(3)->create();
        // Only the measuring pipeline ends in `wc -c`; the dump pipeline ends in
        // `gzip -c > '…'`. Answering every measurement oversized drives the binary
        // search down to a fitted 0, which a plain row count could never print — so
        // the reported number can only have come from the fit.
        Process::fake(['*wc -c*' => Process::result(output: '99999999'), '*' => Process::result()]);

        // Act & Assert
        $this->artisan('db:dump', ['--vc' => true])
            ->expectsOutputToContain('  [dump movies 0]')
            ->assertSuccessful();
    });

    it('reports the table row count for an uncapped table', function (): void {
        // Arrange
        Movie::factory()->count(3)->create();
        Process::fake();

        // Act & Assert
        $this->artisan('db:dump', ['--full' => true, '--path' => '/tmp/lundflix-dumps'])
            ->expectsOutputToContain('  [dump movies 3]')
            ->assertSuccessful();
    });

    it('reports a capped table once, not once per fit measurement', function (): void {
        // Arrange
        Movie::factory()->count(3)->create();
        // The oversized measuring stage makes the binary search take several
        // measurements for movies, so an implementation that beat inside measure()
        // would emit more than one line here.
        Process::fake(['*wc -c*' => Process::result(output: '99999999'), '*' => Process::result()]);

        // Act
        Artisan::call('db:dump', ['--vc' => true]);

        // Assert
        expect(substr_count(Artisan::output(), '[dump movies'))->toBe(1);
    });
});
