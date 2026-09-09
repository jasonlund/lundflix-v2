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
        // The worktree comes from the argument, never base_path(): in production
        // the primary checkout's artisan runs this against a different tree.
        $dir = (string) $this->argument('dir');

        if (! File::isDirectory($dir)) {
            $this->error("Workspace directory does not exist: {$dir}");

            return self::FAILURE;
        }

        $ahead = $this->git($dir, ['rev-list', '--count', self::UPSTREAM.'..HEAD']);

        if ($ahead->failed()) {
            $this->error('Failed to count the commits ahead of '.self::UPSTREAM.": {$dir}");

            return self::FAILURE;
        }

        if ((int) Str::trim($ahead->output()) > 0) {
            $this->output->writeln('Branch carries its own commits; leaving the workspace untouched.');

            return self::SUCCESS;
        }

        $this->clearSeededFiles($dir);

        $this->output->writeln('Fast-forwarding onto '.self::UPSTREAM.'…');

        if ($this->git($dir, ['merge', '--ff-only', self::UPSTREAM])->failed()) {
            $this->error('Failed to fast-forward onto '.self::UPSTREAM.": {$dir}");

            return self::FAILURE;
        }

        $this->output->writeln('Done.');

        return self::SUCCESS;
    }

    private function clearSeededFiles(string $dir): void
    {
        $this->output->writeln('Clearing seeded files…');

        $trackedUpstream = $this->gitPaths($dir, ['ls-tree', '-r', '--name-only', self::UPSTREAM, '--', self::SEEDED_DIR]);
        $trackedLocally = $this->gitPaths($dir, ['ls-files', '--', self::SEEDED_DIR]);

        $conflicting = $trackedUpstream->diff($trackedLocally)
            ->filter(fn (string $path): bool => File::exists($dir.'/'.$path));

        $cleared = 0;

        foreach ($conflicting as $path) {
            File::delete($dir.'/'.$path);

            $this->mark('cleared', ++$cleared, $path);
        }

        $this->flushTotal('cleared', $cleared);
    }

    /**
     * @param  list<string>  $arguments
     * @return Collection<int, string>
     */
    private function gitPaths(string $dir, array $arguments): Collection
    {
        return Str::of($this->git($dir, $arguments)->output())
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
