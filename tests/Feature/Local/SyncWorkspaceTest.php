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
 * Stray processes are prevented on purpose: every stubbed dir lives *inside* this
 * repo, so an unmatched git call would resolve to the real checkout and mutate it.
 * A loud throw is the only acceptable answer to an unstubbed leg.
 *
 * Counts carry their trailing newline as git emits it, and must: FakeProcessResult
 * runs its output through an empty() check, so a bare '0' is swallowed to '' and
 * the stub silently stops discriminating.
 *
 * @param  string  $upstream  stdout of `ls-tree -r --name-only origin/main -- .laborforest`
 * @param  string  $tracked  stdout of `ls-files -- .laborforest`
 * @param  string  $ahead  stdout of `rev-list --count origin/main..HEAD`
 * @param  int  $merge  exit code of `merge --ff-only origin/main`
 * @param  string  $mergeError  stderr of `merge --ff-only origin/main`
 * @param  int  $aheadExit  exit code of `rev-list --count origin/main..HEAD`
 * @param  string  $aheadError  stderr of `rev-list --count origin/main..HEAD`
 * @param  int  $upstreamExit  exit code of `ls-tree …`
 * @param  int  $trackedExit  exit code of `ls-files …`
 * @param  string  $listingError  stderr of whichever listing leg the test fails
 * @param  int  $checkout  exit code of `checkout HEAD -- .laborforest`
 */
function fakeWorkspaceSyncGit(
    string $upstream,
    string $tracked = '',
    string $ahead = "0\n",
    int $merge = 0,
    string $mergeError = '',
    int $aheadExit = 0,
    string $aheadError = '',
    int $upstreamExit = 0,
    int $trackedExit = 0,
    string $listingError = '',
    int $checkout = 0,
): void {
    Process::preventStrayProcesses();

    Process::fake([
        '*rev-list*' => Process::result(output: $ahead, errorOutput: $aheadError, exitCode: $aheadExit),
        '*ls-tree*' => Process::result(output: $upstream, errorOutput: $listingError, exitCode: $upstreamExit),
        '*ls-files*' => Process::result(output: $tracked, errorOutput: $listingError, exitCode: $trackedExit),
        '*checkout*' => Process::result(exitCode: $checkout),
        '*merge*' => Process::result(errorOutput: $mergeError, exitCode: $merge),
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

/**
 * True when the sync shelled a git call whose command line contains every needle.
 *
 * @param  list<string>  $needles
 */
function workspaceSyncRan(array $needles): Closure
{
    return function ($process) use ($needles): bool {
        $command = workspaceSyncCommandLine($process->command);

        foreach ($needles as $needle) {
            if (! Str::contains($command, $needle)) {
                return false;
            }
        }

        return true;
    };
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

    // FLIX-302's named offender: LaborForest rewrites this file whenever it
    // touches the workspace, which is why the clear cannot be its own step.
    it('removes the seeded .laborforest/ignored/.gitignore', function (): void {
        // Arrange
        $dir = workspaceSyncDir(['.laborforest/ignored/.gitignore']);
        fakeWorkspaceSyncGit(upstream: ".laborforest/ignored/.gitignore\n");

        // Act
        $this->artisan('lf:workspace-sync', ['dir' => $dir])->assertSuccessful();

        // Assert
        expect(File::exists($dir.'/.laborforest/ignored/.gitignore'))->toBeFalse();
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

    // Blast radius: the stubs answer on the git subcommand alone, so a listing
    // that lost its pathspec would hand the delete loop every tracked path in the
    // repo. The seeded-prefix filter is what keeps that a no-op rather than a wipe.
    it('leaves a file outside .laborforest in place even when the listing names it', function (): void {
        // Arrange
        $dir = workspaceSyncDir(['README.md', '.laborforest/workflows/up.yaml']);
        fakeWorkspaceSyncGit(upstream: "README.md\n.laborforest/workflows/up.yaml\n");

        // Act
        $this->artisan('lf:workspace-sync', ['dir' => $dir])->assertSuccessful();

        // Assert
        expect(File::exists($dir.'/README.md'))->toBeTrue();
        expect(File::exists($dir.'/.laborforest/workflows/up.yaml'))->toBeFalse();
    });

    // The other half of the blast radius: both reads must stay scoped to the
    // workspace (-C) and to the seeded directory (the pathspec), or the listings
    // themselves start naming paths the delete loop has no business seeing.
    it('scopes both listings to the workspace and the .laborforest pathspec', function (): void {
        // Arrange
        $dir = workspaceSyncDir(['.laborforest/workflows/up.yaml']);
        fakeWorkspaceSyncGit(upstream: ".laborforest/workflows/up.yaml\n");

        // Act
        $this->artisan('lf:workspace-sync', ['dir' => $dir])->assertSuccessful();

        // Assert
        Process::assertRan(workspaceSyncRan(["-C {$dir}", 'ls-tree', '-- .laborforest']));
        Process::assertRan(workspaceSyncRan(["-C {$dir}", 'ls-files', '-- .laborforest']));
    });

    it('reports each cleared file as a heartbeat', function (): void {
        // Arrange
        $dir = workspaceSyncDir(['.laborforest/workflows/up.yaml']);
        fakeWorkspaceSyncGit(upstream: ".laborforest/workflows/up.yaml\n");

        // Act & Assert
        $this->artisan('lf:workspace-sync', ['dir' => $dir])
            ->expectsOutputToContain('  [cleared 1] .laborforest/workflows/up.yaml')
            ->assertSuccessful();
    });
});

describe('lf:workspace-sync listing failures', function (): void {
    // A failed listing still returns exit-checked-nothing: empty stdout. Left
    // unchecked on the ls-files leg that empties the "tracked locally" side of the
    // diff, so every upstream-tracked path — protected ones included — is selected.
    it('deletes nothing when the local listing fails', function (): void {
        // Arrange
        $dir = workspaceSyncDir(['.laborforest/workflows/up.yaml']);
        fakeWorkspaceSyncGit(upstream: ".laborforest/workflows/up.yaml\n", trackedExit: 1);

        // Act
        $this->artisan('lf:workspace-sync', ['dir' => $dir])->assertFailed();

        // Assert
        expect(File::exists($dir.'/.laborforest/workflows/up.yaml'))->toBeTrue();
        Process::assertDidntRun(workspaceSyncRan(['merge']));
    });

    it('fails without merging when the upstream listing fails', function (): void {
        // Arrange
        $dir = workspaceSyncDir(['.laborforest/workflows/up.yaml']);
        fakeWorkspaceSyncGit(upstream: '', upstreamExit: 1);

        // Act
        $this->artisan('lf:workspace-sync', ['dir' => $dir])->assertFailed();

        // Assert
        Process::assertDidntRun(workspaceSyncRan(['merge']));
    });

    it('surfaces git\'s own reason when a listing fails', function (): void {
        // Arrange
        $dir = workspaceSyncDir(['.laborforest/workflows/up.yaml']);
        fakeWorkspaceSyncGit(
            upstream: '',
            upstreamExit: 1,
            listingError: "fatal: not a git repository (or any of the parent directories): .git\n",
        );

        // Act & Assert
        $this->artisan('lf:workspace-sync', ['dir' => $dir])
            ->expectsOutputToContain('fatal: not a git repository')
            ->assertFailed();
    });
});

describe('lf:workspace-sync discarding local edits', function (): void {
    // Deleting the untracked seeds only answers git's "untracked working tree
    // files would be overwritten" refusal. A seeded path that is tracked in the
    // index and modified on disk draws the other one — "your local changes …" —
    // and the diff structurally excludes it, so only a checkout clears it.
    it('discards local edits to tracked .laborforest paths', function (): void {
        // Arrange
        $dir = workspaceSyncDir(['.laborforest/workflows/up.yaml']);
        fakeWorkspaceSyncGit(
            upstream: ".laborforest/workflows/up.yaml\n",
            tracked: ".laborforest/workflows/up.yaml\n",
        );

        // Act
        $this->artisan('lf:workspace-sync', ['dir' => $dir])->assertSuccessful();

        // Assert
        Process::assertRan(workspaceSyncRan(["-C {$dir}", 'checkout HEAD -- .laborforest']));
    });

    // A workspace cut from a local main that predates `.laborforest/` tracks none
    // of it, and `checkout HEAD -- .laborforest` on a pathspec HEAD never knew is
    // a hard git error — which would abort the very run this command exists to fix.
    it('skips the checkout when the workspace tracks no .laborforest path', function (): void {
        // Arrange
        $dir = workspaceSyncDir(['.laborforest/workflows/up.yaml']);
        fakeWorkspaceSyncGit(upstream: ".laborforest/workflows/up.yaml\n");

        // Act
        $this->artisan('lf:workspace-sync', ['dir' => $dir])->assertSuccessful();

        // Assert
        Process::assertDidntRun(workspaceSyncRan(['checkout']));
    });

    it('fails without merging when the checkout fails', function (): void {
        // Arrange
        $dir = workspaceSyncDir(['.laborforest/workflows/up.yaml']);
        fakeWorkspaceSyncGit(
            upstream: ".laborforest/workflows/up.yaml\n",
            tracked: ".laborforest/workflows/up.yaml\n",
            checkout: 1,
        );

        // Act
        $this->artisan('lf:workspace-sync', ['dir' => $dir])->assertFailed();

        // Assert
        Process::assertDidntRun(workspaceSyncRan(['merge']));
    });
});

describe('lf:workspace-sync fast-forward', function (): void {
    // The directory has to come from the argument, never base_path(): under
    // LaborForest this runs from the primary checkout against another worktree.
    it('fast-forwards the given worktree onto origin/main after clearing', function (): void {
        // Arrange
        $dir = workspaceSyncDir(['.laborforest/workflows/up.yaml']);
        fakeWorkspaceSyncGit(upstream: ".laborforest/workflows/up.yaml\n");

        // Act
        $this->artisan('lf:workspace-sync', ['dir' => $dir])
            ->expectsOutputToContain('Done.')
            ->assertSuccessful();

        // Assert
        Process::assertRan(workspaceSyncRan(['merge --ff-only origin/main', $dir]));
    });

    it('fails when the fast-forward is refused', function (): void {
        // Arrange
        $dir = workspaceSyncDir(['.laborforest/workflows/up.yaml']);
        fakeWorkspaceSyncGit(upstream: ".laborforest/workflows/up.yaml\n", merge: 1);

        // Act & Assert
        $this->artisan('lf:workspace-sync', ['dir' => $dir])->assertFailed();
    });

    // Git's own abort reason is the whole diagnostic value in LaborForest's run
    // log — it is how FLIX-302 was read off a failed up run in the first place.
    it('surfaces git\'s own reason when the fast-forward is refused', function (): void {
        // Arrange
        $dir = workspaceSyncDir(['.laborforest/workflows/up.yaml']);
        fakeWorkspaceSyncGit(
            upstream: ".laborforest/workflows/up.yaml\n",
            merge: 1,
            mergeError: "fatal: Not possible to fast-forward, aborting.\n",
        );

        // Act & Assert
        $this->artisan('lf:workspace-sync', ['dir' => $dir])
            ->expectsOutputToContain('fatal: Not possible to fast-forward, aborting.')
            ->assertFailed();
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
        $this->artisan('lf:workspace-sync', ['dir' => $dir])
            ->expectsOutputToContain('Branch carries its own commits; leaving the workspace untouched.')
            ->assertSuccessful();

        // Assert
        expect(File::exists($dir.'/.laborforest/workflows/up.yaml'))->toBeTrue();
        Process::assertDidntRun(workspaceSyncRan(['merge']));
    });
});

describe('lf:workspace-sync preconditions', function (): void {
    it('fails without shelling any git call when the workspace directory is missing', function (): void {
        // Arrange
        fakeWorkspaceSyncGit(upstream: '');

        // Act
        $this->artisan('lf:workspace-sync', ['dir' => storage_path('framework/testing/workspace-sync-absent')])->assertFailed();

        // Assert
        Process::assertNothingRan();
    });

    it('fails without merging when the commit count cannot be read', function (): void {
        // Arrange
        $dir = workspaceSyncDir(['.laborforest/workflows/up.yaml']);
        fakeWorkspaceSyncGit(upstream: ".laborforest/workflows/up.yaml\n", aheadExit: 1);

        // Act
        $this->artisan('lf:workspace-sync', ['dir' => $dir])->assertFailed();

        // Assert
        expect(File::exists($dir.'/.laborforest/workflows/up.yaml'))->toBeTrue();
        Process::assertDidntRun(workspaceSyncRan(['merge']));
    });

    it('surfaces git\'s own reason when the commit count cannot be read', function (): void {
        // Arrange
        $dir = workspaceSyncDir(['.laborforest/workflows/up.yaml']);
        fakeWorkspaceSyncGit(
            upstream: '',
            aheadExit: 1,
            aheadError: "fatal: ambiguous argument 'origin/main..HEAD': unknown revision\n",
        );

        // Act & Assert
        $this->artisan('lf:workspace-sync', ['dir' => $dir])
            ->expectsOutputToContain("fatal: ambiguous argument 'origin/main..HEAD'")
            ->assertFailed();
    });
});
