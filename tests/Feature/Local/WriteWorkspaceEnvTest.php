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

    // The 40-character cut of this branch slug lands ON a separator
    // ('…-worktrees-abc-'), so a bare trim would leave a trailing '-' in the
    // site name and a trailing '_' in the database name.
    it('trims a branch slug past 40 characters without leaving a trailing separator', function (): void {
        // Arrange
        $file = workspaceEnvFile();

        // Act
        $this->artisan('lf:workspace-env', [
            'workspace' => 'lundflix-v2-flix-294-laborforest-solo-worktrees-abc-def-ghi',
            'project' => 'lundflix-v2',
            '--file' => $file,
        ])->assertSuccessful();

        // Assert
        expect(workspaceEnvLines($file))
            ->toContain('LF_SITE=lf-flix-294-laborforest-solo-worktrees-abc')
            ->toContain('DB_DATABASE=lf_flix_294_laborforest_solo_worktrees_abc');
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
