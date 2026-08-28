<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * A throwaway env file under storage/framework/testing, seeded with $contents.
 *
 * Every test passes its path through --file so the command can never touch the
 * repo's real .env; afterEach sweeps the whole family up again.
 */
function workspaceEnvFile(string $contents = ''): string
{
    $path = storage_path('framework/testing/workspace-env-'.uniqid().'.env');

    File::ensureDirectoryExists(dirname($path));
    File::put($path, $contents);

    return $path;
}

/**
 * The non-blank lines of a written env file.
 *
 * Lines, not the raw string: a rewrite that appended a duplicate key still
 * "contains" the new value, so the duplicate is only visible by counting lines.
 *
 * @return list<string>
 */
function workspaceEnvLines(string $file): array
{
    return collect(explode("\n", File::get($file)))
        ->map(fn (string $line): string => Str::trim($line))
        ->filter()
        ->values()
        ->all();
}

/**
 * The value written for $key in an env file, without its `KEY=` prefix.
 */
function workspaceEnvValue(string $file, string $key): string
{
    $prefix = $key.'=';

    $line = collect(workspaceEnvLines($file))
        ->first(fn (string $line): bool => Str::startsWith($line, $prefix));

    return is_string($line) ? Str::substr($line, Str::length($prefix)) : '';
}

afterEach(function (): void {
    File::delete(File::glob(storage_path('framework/testing/workspace-env-*.env')));
});

describe('lf:workspace-env derivation', function (): void {
    it('writes the database name derived from the workspace directory slug', function (): void {
        // Arrange
        $file = workspaceEnvFile();

        // Act
        $this->artisan('lf:workspace-env', [
            'workspace' => 'lundflix-v2-flix-294-laborforest-solo-worktrees',
            'project' => 'lundflix-v2',
            '--file' => $file,
        ])->assertSuccessful();

        // Assert
        expect(workspaceEnvLines($file))->toContain('DB_DATABASE=lf_flix_294_laborforest_solo_worktrees');
    });

    it('writes the Herd site name and its https app URL', function (): void {
        // Arrange
        $file = workspaceEnvFile();

        // Act
        $this->artisan('lf:workspace-env', [
            'workspace' => 'lundflix-v2-flix-294-laborforest-solo-worktrees',
            'project' => 'lundflix-v2',
            '--file' => $file,
        ])->assertSuccessful();

        // Assert
        expect(workspaceEnvLines($file))
            ->toContain('LF_SITE=lf-flix-294-laborforest-solo-worktrees')
            ->toContain('APP_URL=https://lf-flix-294-laborforest-solo-worktrees.test');
    });

    it('strips only the leading project slug from the workspace slug', function (): void {
        // Arrange
        $file = workspaceEnvFile();

        // Act
        $this->artisan('lf:workspace-env', [
            'workspace' => 'lundflix-v2-lundflix-v2-tweaks',
            'project' => 'lundflix-v2',
            '--file' => $file,
        ])->assertSuccessful();

        // Assert
        expect(workspaceEnvLines($file))
            ->toContain('LF_SITE=lf-lundflix-v2-tweaks')
            ->toContain('DB_DATABASE=lf_lundflix_v2_tweaks');
    });

    // The branch slug here is 47 characters, and its 40-character cut lands ON a
    // separator ('…-worktrees-abc-'). The name must stay inside the 40-character
    // budget, still read as the branch it came from, carry no trailing separator,
    // and — because a bare cut is what lets two branches collide — must not be
    // the bare cut 'flix-294-laborforest-solo-worktrees-abc' itself.
    it('trims a branch slug past 40 characters to a bounded, separator-free name that is not the bare cut', function (): void {
        // Arrange
        $file = workspaceEnvFile();

        // Act
        $this->artisan('lf:workspace-env', [
            'workspace' => 'lundflix-v2-flix-294-laborforest-solo-worktrees-abc-def-ghi',
            'project' => 'lundflix-v2',
            '--file' => $file,
        ])->assertSuccessful();

        // Assert
        $branch = Str::after(workspaceEnvValue($file, 'LF_SITE'), 'lf-');
        expect(Str::length($branch))->toBeLessThanOrEqual(40);
        expect($branch)->toStartWith('flix-294-laborforest-solo');
        expect($branch)->not->toEndWith('-');
        expect($branch)->not->toBe('flix-294-laborforest-solo-worktrees-abc');
    });

    // Both slugs share the 40-character head 'flix-294-laborforest-solo-worktrees-abcd'
    // and differ only past it, so a bare 40-character cut hands them the same Herd
    // site and the same database — and 'up' then wipes the first workspace's data.
    it('derives different names for two branch slugs sharing their first 40 characters', function (): void {
        // Arrange
        $alphaFile = workspaceEnvFile();
        $betaFile = workspaceEnvFile();
        $this->artisan('lf:workspace-env', [
            'workspace' => 'lundflix-v2-flix-294-laborforest-solo-worktrees-abcd-alpha',
            'project' => 'lundflix-v2',
            '--file' => $alphaFile,
        ])->assertSuccessful();

        // Act
        $this->artisan('lf:workspace-env', [
            'workspace' => 'lundflix-v2-flix-294-laborforest-solo-worktrees-abcd-beta',
            'project' => 'lundflix-v2',
            '--file' => $betaFile,
        ])->assertSuccessful();

        // Assert
        expect(workspaceEnvValue($betaFile, 'DB_DATABASE'))
            ->not->toBe(workspaceEnvValue($alphaFile, 'DB_DATABASE'));
        expect(workspaceEnvValue($betaFile, 'LF_SITE'))
            ->not->toBe(workspaceEnvValue($alphaFile, 'LF_SITE'));
    });
});

describe('lf:workspace-env file writing', function (): void {
    it('rewrites a key already present instead of appending a duplicate', function (): void {
        // Arrange
        $file = workspaceEnvFile("APP_NAME=Lundflix\nDB_DATABASE=lundflix_v2\nQUEUE_CONNECTION=sync\n");

        // Act
        $this->artisan('lf:workspace-env', [
            'workspace' => 'lundflix-v2-flix-294-laborforest-solo-worktrees',
            'project' => 'lundflix-v2',
            '--file' => $file,
        ])->assertSuccessful();

        // Assert
        $lines = workspaceEnvLines($file);
        expect($lines)->toContain('DB_DATABASE=lf_flix_294_laborforest_solo_worktrees');
        expect(collect($lines)->filter(fn (string $line): bool => Str::startsWith($line, 'DB_DATABASE='))->all())
            ->toHaveCount(1);
    });

    it('appends a key absent from the file and keeps the existing lines', function (): void {
        // Arrange
        $file = workspaceEnvFile("APP_NAME=Lundflix\nQUEUE_CONNECTION=sync\n");

        // Act
        $this->artisan('lf:workspace-env', [
            'workspace' => 'lundflix-v2-flix-294-laborforest-solo-worktrees',
            'project' => 'lundflix-v2',
            '--file' => $file,
        ])->assertSuccessful();

        // Assert
        expect(workspaceEnvLines($file))
            ->toContain('LF_SITE=lf-flix-294-laborforest-solo-worktrees')
            ->toContain('APP_NAME=Lundflix')
            ->toContain('QUEUE_CONNECTION=sync');
    });
});
