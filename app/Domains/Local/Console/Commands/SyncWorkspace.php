<?php

declare(strict_types=1);

namespace App\Domains\Local\Console\Commands;

use App\Domains\Common\Console\Concerns\EmitsHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Clearing and fast-forwarding are one command because LaborForest rewrites the
 * seeded `.laborforest/ignored/.gitignore` whenever it touches the workspace: a
 * separate delete step would be undone again before any later merge ran.
 */
#[Description('Clear LaborForest\'s seeded files from a workspace worktree and fast-forward it onto origin/main')]
#[Signature('lf:workspace-sync {dir}')]
final class SyncWorkspace extends Command
{
    use EmitsHeartbeat;

    private const string UPSTREAM = 'origin/main';

    private const string SEEDED_DIR = '.laborforest';

    public function handle(): int
    {
        // The worktree comes from the argument, never base_path(): under LaborForest
        // the primary checkout's artisan runs this against a different tree.
        $dir = (string) $this->argument('dir');

        if (! File::isDirectory($dir)) {
            $this->error("Workspace directory does not exist: {$dir}");

            return self::FAILURE;
        }

        $ahead = $this->git($dir, ['rev-list', '--count', self::UPSTREAM.'..HEAD']);

        if ($ahead->failed()) {
            $this->reportGitFailure('Failed to count the commits ahead of '.self::UPSTREAM.": {$dir}", $ahead);

            return self::FAILURE;
        }

        if ((int) Str::trim($ahead->output()) > 0) {
            $this->output->writeln('Branch carries its own commits; leaving the workspace untouched.');

            return self::SUCCESS;
        }

        if (! $this->clearSeededFiles($dir)) {
            return self::FAILURE;
        }

        $this->output->writeln('Fast-forwarding onto '.self::UPSTREAM.'…');

        $merge = $this->git($dir, ['merge', '--ff-only', self::UPSTREAM]);

        if ($merge->failed()) {
            $this->reportGitFailure('Failed to fast-forward onto '.self::UPSTREAM.": {$dir}", $merge);

            return self::FAILURE;
        }

        $this->output->writeln('Done.');

        return self::SUCCESS;
    }

    /**
     * @return bool whether the workspace is safe to fast-forward
     */
    private function clearSeededFiles(string $dir): bool
    {
        $this->output->writeln('Clearing seeded files…');

        $upstreamListing = $this->git($dir, ['ls-tree', '-r', '--name-only', self::UPSTREAM, '--', self::SEEDED_DIR]);
        $localListing = $this->git($dir, ['ls-files', '--', self::SEEDED_DIR]);

        // Both listings are exit-checked before the diff, never after: a failed
        // read returns empty stdout, and an empty local side makes the diff select
        // every upstream-tracked path — deleting the very files it exists to spare.
        foreach ([$upstreamListing, $localListing] as $listing) {
            if ($listing->failed()) {
                $this->reportGitFailure('Failed to list the '.self::SEEDED_DIR." files: {$dir}", $listing);

                return false;
            }
        }

        $trackedUpstream = $this->gitPaths($upstreamListing);
        $trackedLocally = $this->gitPaths($localListing);

        // The pathspec already scopes each listing; re-checking the prefix keeps a
        // future widening of it from turning this loop loose on the whole worktree.
        $conflicting = $trackedUpstream->diff($trackedLocally)
            ->filter(fn (string $path): bool => Str::startsWith($path, self::SEEDED_DIR.'/'))
            ->filter(fn (string $path): bool => File::exists($dir.'/'.$path));

        $cleared = 0;

        foreach ($conflicting as $path) {
            File::delete($dir.'/'.$path);

            $this->mark('cleared', ++$cleared, $path);
        }

        $this->flushTotal('cleared', $cleared);

        return $this->discardLocalEdits($dir, $trackedLocally);
    }

    /**
     * Deleting the untracked seeds only answers one of git's two ff-only refusals.
     * A seeded path tracked in the index and modified on disk draws the other
     * ("your local changes … would be overwritten") and the diff above structurally
     * excludes it, so only a checkout clears it.
     *
     * This therefore discards ANY local edit under the seeded directory, not just
     * the untracked seeds — safe because the skip check has already established the
     * branch carries no commits of its own, so nothing under it here is real work.
     *
     * @param  Collection<int, string>  $trackedLocally
     */
    private function discardLocalEdits(string $dir, Collection $trackedLocally): bool
    {
        // A workspace cut from a local main that predates the seeded directory
        // tracks none of it, and a checkout against a pathspec HEAD never knew is a
        // hard git error — which would abort the very run this command exists to fix.
        if ($trackedLocally->isEmpty()) {
            return true;
        }

        $checkout = $this->git($dir, ['checkout', 'HEAD', '--', self::SEEDED_DIR]);

        if ($checkout->failed()) {
            $this->reportGitFailure('Failed to discard local changes under '.self::SEEDED_DIR.": {$dir}", $checkout);

            return false;
        }

        return true;
    }

    /**
     * Git's own reason for the abort is the whole diagnostic value in LaborForest's
     * run log — the inline step this command replaced piped it there verbatim.
     */
    private function reportGitFailure(string $message, ProcessResult $result): void
    {
        $this->error($message);

        $reason = Str::trim($result->errorOutput());

        if ($reason !== '') {
            $this->output->writeln($reason);
        }
    }

    /**
     * @return Collection<int, string>
     */
    private function gitPaths(ProcessResult $result): Collection
    {
        return Str::of($result->output())
            ->explode("\n")
            ->map(fn (string $line): string => Str::trim($line))
            ->filter(fn (string $line): bool => $line !== '')
            ->values();
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(string $dir, array $arguments): ProcessResult
    {
        return Process::run(['git', '-C', $dir, ...$arguments]);
    }
}
