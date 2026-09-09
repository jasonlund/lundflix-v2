<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * A throwaway worktree directory under storage/framework/testing, seeded with an
 * empty file at each repo-relative path in $paths.
 *
 * @param  list<string>  $paths
 */
function workspaceSyncDir(array $paths = []): string
{
    $dir = storage_path('framework/testing/workspace-sync-'.uniqid());

    File::ensureDirectoryExists($dir);

    foreach ($paths as $path) {
        File::ensureDirectoryExists($dir.'/'.dirname($path));
        File::put($dir.'/'.$path, '');
    }

    return $dir;
}

/**
 * Discriminating Process stubs, one per git subcommand the sync shells.
 *
 * A blanket Process::fake() answers every call with the same empty output, so a
 * run that never reads the upstream listing looks identical to one that did.
 * Keyed handlers are what make each leg's absence observable.
 *
 * Counts carry their trailing newline as git emits it, and must: FakeProcessResult
 * runs its output through an empty() check, so a bare '0' is swallowed to '' and
 * the stub silently stops discriminating.
 *
 * @param  string  $upstream  stdout of `ls-tree -r --name-only origin/main -- .laborforest`
 * @param  string  $tracked  stdout of `ls-files -- .laborforest`
 * @param  string  $ahead  stdout of `rev-list --count origin/main..HEAD`
 * @param  int  $merge  exit code of `merge --ff-only origin/main`
 */
function fakeWorkspaceSyncGit(string $upstream, string $tracked = '', string $ahead = "0\n", int $merge = 0): void
{
    Process::fake([
        '*rev-list*' => Process::result($ahead),
        '*ls-tree*' => Process::result($upstream),
        '*ls-files*' => Process::result($tracked),
        '*merge*' => Process::result(exitCode: $merge),
    ]);
}

/**
 * A recorded process's command as one string, whether it was shelled as a string
 * or as an argument list.
 */
function workspaceSyncCommandLine(mixed $command): string
{
    return is_array($command) ? implode(' ', $command) : (string) $command;
}

afterEach(function (): void {
    foreach (File::glob(storage_path('framework/testing/workspace-sync-*')) ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
});

describe('lf:workspace-sync clearing seeded files', function (): void {
    // LaborForest seeds its workflow files into a fresh worktree as untracked
    // files, and those same paths are tracked on origin/main — so the ff-only
    // merge refuses to clobber them and the whole up run aborts.
    it('removes a .laborforest file tracked upstream but untracked locally', function (): void {
        // Arrange
        $dir = workspaceSyncDir(['.laborforest/workflows/up.yaml']);
        fakeWorkspaceSyncGit(upstream: ".laborforest/workflows/up.yaml\n");

        // Act
        $this->artisan('lf:workspace-sync', ['dir' => $dir])->assertSuccessful();

        // Assert
        expect(File::exists($dir.'/.laborforest/workflows/up.yaml'))->toBeFalse();
    });

    it('leaves a locally tracked file and an upstream-absent file in place', function (): void {
        // Arrange
        $dir = workspaceSyncDir(['.laborforest/workflows/up.yaml', '.laborforest/ignored/logs/run.json']);
        fakeWorkspaceSyncGit(
            upstream: ".laborforest/workflows/up.yaml\n.laborforest/workflows/down.yaml\n",
            tracked: ".laborforest/workflows/up.yaml\n",
        );

        // Act
        $this->artisan('lf:workspace-sync', ['dir' => $dir])->assertSuccessful();

        // Assert
        expect(File::exists($dir.'/.laborforest/workflows/up.yaml'))->toBeTrue();
        expect(File::exists($dir.'/.laborforest/ignored/logs/run.json'))->toBeTrue();
    });
});

describe('lf:workspace-sync fast-forward', function (): void {
    // The directory has to come from the argument, never base_path(): production
    // runs this from the primary checkout against another worktree.
    it('fast-forwards the given worktree onto origin/main after clearing', function (): void {
        // Arrange
        $dir = workspaceSyncDir(['.laborforest/workflows/up.yaml']);
        fakeWorkspaceSyncGit(upstream: ".laborforest/workflows/up.yaml\n");

        // Act
        $this->artisan('lf:workspace-sync', ['dir' => $dir])->assertSuccessful();

        // Assert
        Process::assertRan(function ($process) use ($dir): bool {
            $command = workspaceSyncCommandLine($process->command);

            return Str::contains($command, 'merge --ff-only origin/main') && Str::contains($command, $dir);
        });
    });

    it('fails when the fast-forward is refused', function (): void {
        // Arrange
        $dir = workspaceSyncDir(['.laborforest/workflows/up.yaml']);
        fakeWorkspaceSyncGit(upstream: ".laborforest/workflows/up.yaml\n", merge: 1);

        // Act & Assert
        $this->artisan('lf:workspace-sync', ['dir' => $dir])->assertFailed();
    });
});

describe('lf:workspace-sync skip check', function (): void {
    // Own commits mean the seeded files may since have been committed and edited;
    // deleting them or fast-forwarding over them would discard real work.
    it('skips both the clear and the fast-forward when the branch carries its own commits', function (): void {
        // Arrange
        $dir = workspaceSyncDir(['.laborforest/workflows/up.yaml']);
        fakeWorkspaceSyncGit(upstream: ".laborforest/workflows/up.yaml\n", ahead: "2\n");

        // Act
        $this->artisan('lf:workspace-sync', ['dir' => $dir])->assertSuccessful();

        // Assert
        expect(File::exists($dir.'/.laborforest/workflows/up.yaml'))->toBeTrue();
        Process::assertDidntRun(fn ($process): bool => Str::contains(workspaceSyncCommandLine($process->command), 'merge'));
    });
});
