<?php

declare(strict_types=1);

namespace App\Domains\Local\Console\Commands;

use App\Domains\Local\LaborForest\RunLogVerdict;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Description('Report the verdict of the newest LaborForest run log for a workflow, exiting non-zero when the run did not succeed')]
#[Signature('lf:run-log {workflow} {--dir=}')]
final class ReadRunLog extends Command
{
    public function handle(): int
    {
        $workflow = (string) $this->argument('workflow');

        $this->output->writeln("Reading the newest {$workflow} run log…");

        $exitCode = $this->report($workflow, $this->directory());

        // The single closing line, past every branch: a run that found no log or a
        // failed one still closes, and no branch added later can return without it.
        $this->output->writeln('Done.');

        return $exitCode;
    }

    /**
     * Emit the verdict of the newest matching log and return the exit code for it.
     */
    private function report(string $workflow, string $directory): int
    {
        $path = $this->newestLog($directory, $workflow);

        if ($path === null) {
            // A missing log says nothing ran, not that a run went well — exiting 0 here
            // would report an untouched workspace as provisioned.
            $this->output->writeln("No {$workflow} run log in {$directory}; the run left no evidence, so the workspace is unproven.");

            return self::FAILURE;
        }

        $this->output->writeln('  [lf log '.File::basename($path).']');

        $verdict = RunLogVerdict::from(File::get($path));

        foreach ($verdict->orphans as $orphan) {
            $this->output->writeln('  '.$orphan);
        }

        if ($verdict->succeeded) {
            return self::SUCCESS;
        }

        $this->output->writeln($this->failureLine($workflow, $verdict->failedStep));

        return self::FAILURE;
    }

    private function directory(): string
    {
        $dir = $this->option('dir');

        return is_string($dir) && $dir !== '' ? $dir : base_path('.laborforest/ignored/logs');
    }

    /**
     * Newest means the lexicographically greatest matching filename, never the newest
     * mtime: LaborForest names each log for the run's UTC start, so two runs landing in
     * the same second share an mtime that the name still orders — an mtime pick reports
     * the wrong run's verdict, and a stale success is exactly the answer that must not
     * be wrong.
     *
     * The workflow belongs in the glob, not in a filter after it: every workflow writes
     * into the one directory, so a bare `*.yaml` judges `down`'s log as `up`'s.
     */
    private function newestLog(string $directory, string $workflow): ?string
    {
        $newest = collect(File::glob($directory."/*_{$workflow}.yaml") ?: [])
            ->sort()
            ->last();

        return is_string($newest) ? $newest : null;
    }

    /**
     * A log too damaged to name a step still failed — the run it recorded cannot be
     * shown to have finished, which is the same consequence for the caller.
     */
    private function failureLine(string $workflow, ?string $failedStep): string
    {
        $cause = $failedStep === null
            ? "The newest {$workflow} run log records no successful run"
            : "The {$workflow} run failed at '{$failedStep}'";

        return $cause.'; the workspace is part-built, so fix the cause and run the workflow again.';
    }
}
