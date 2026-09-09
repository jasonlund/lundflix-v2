<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Fixture provenance: tests/Fixtures/Local/laborforest/up-success.yaml is a
 * byte-exact copy of a real LaborForest run log — a successful `up` on this
 * branch — committed unedited, and reused here as the success body so no test
 * hand-writes a passing log. Every failed/malformed document below is an
 * obviously-synthetic inline string: no failed run and no half-written run
 * exists to capture, and a fabricated file pretending to be a capture would be
 * worse than a string that admits what it is.
 *
 * Every test passes --dir so the command can never read this worktree's live
 * .laborforest/ignored/logs; afterEach sweeps the whole family up again.
 */
/**
 * A throwaway log directory seeded with $files (filename => YAML contents).
 *
 * The mtime stamping is the whole point of the helper: it makes wall-clock order
 * the exact INVERSE of filename order. Selection is specified as "the
 * lexicographically greatest matching filename", and two files a test writes land
 * in the same second — so an mtime-based implementation would otherwise pass by
 * coincidence. With the greatest NAME carrying the oldest mtime, an mtime-based
 * pick can only ever choose the wrong file.
 *
 * @param  array<string, string>  $files
 */
function runLogDir(array $files): string
{
    $dir = sys_get_temp_dir().'/lundflix-run-logs-'.uniqid('', true);

    File::ensureDirectoryExists($dir);

    foreach ($files as $name => $contents) {
        File::put($dir.'/'.$name, $contents);
    }

    foreach (collect(array_keys($files))->sort()->values() as $rank => $name) {
        touch($dir.'/'.$name, 1_700_000_000 - ($rank * 3600));
    }

    return $dir;
}

/**
 * A synthetic run log for a run that failed at $step.
 */
function failedRunLog(string $workflow, string $step): string
{
    return <<<YAML
    resource_type: run_log
    name: {$workflow}
    status: failed
    exception: null
    steps:
        - name: 'Fetch from origin'
          type: shell
          exitCode: 0
          output: ''
        - name: '{$step}'
          type: shell
          exitCode: 1
          output: ''
    YAML;
}

afterEach(function (): void {
    foreach (File::glob(sys_get_temp_dir().'/lundflix-run-logs-*') ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
});

describe('lf:run-log log selection', function (): void {
    // Three logs, because a single-file directory passes against an implementation
    // that ignores both the timestamp and the workflow name — the two things this
    // command exists to get right. Each decoy carries a verdict that would flip the
    // result if it were wrongly selected: the older `up` failed, and the `down` log
    // is both lexicographically greatest overall and a failure.
    it('reads the newest log for the named workflow and exits SUCCESS', function (): void {
        // Arrange
        $dir = runLogDir([
            '20260909T170000Z_lundflix-v2-flix-303_up.yaml' => failedRunLog('up', 'Install Composer dependencies'),
            '20260909T171446Z_lundflix-v2-flix-303_up.yaml' => fixtureBytes('Local/laborforest/up-success.yaml'),
            '20260909T180000Z_lundflix-v2-flix-303_down.yaml' => failedRunLog('down', 'Drop MySQL database'),
        ]);

        // Act
        $exitCode = Artisan::call('lf:run-log', ['workflow' => 'up', '--dir' => $dir]);

        // Assert
        $output = Artisan::output();
        expect($exitCode)->toBe(Command::SUCCESS);
        expect(Str::trim($output))->not->toBe('');
        expect($output)
            ->toContain('up')
            ->not->toContain('Install Composer dependencies')
            ->not->toContain('Drop MySQL database');
    });
});

describe('lf:run-log verdict reporting', function (): void {
    // The markdown commands branch on the exit code alone, so a failed run that
    // exits 0 reads as a clean workflow; the step name is what makes the report
    // actionable once it does exit non-zero.
    it('exits FAILURE naming the failing step when the newest log failed', function (): void {
        // Arrange
        $dir = runLogDir([
            '20260909T170000Z_lundflix-v2-flix-303_up.yaml' => fixtureBytes('Local/laborforest/up-success.yaml'),
            '20260909T171446Z_lundflix-v2-flix-303_up.yaml' => failedRunLog('up', 'Install Composer dependencies'),
        ]);

        // Act
        $exitCode = Artisan::call('lf:run-log', ['workflow' => 'up', '--dir' => $dir]);

        // Assert
        expect($exitCode)->toBe(Command::FAILURE);
        expect(Artisan::output())->toContain('Install Composer dependencies');
    });
});

describe('lf:run-log unreadable logs', function (): void {
    // A directory holding another workflow's log, not an empty one: an
    // implementation globbing every *.yaml would find a file and judge the wrong
    // run. Absence of evidence is not evidence of success, so this still exits
    // FAILURE — it just says so in a line instead of a stack trace.
    it('reports plainly when no log exists for that workflow, instead of erroring', function (): void {
        // Arrange
        $downLog = <<<'YAML'
        resource_type: run_log
        name: down
        status: success
        exception: null
        steps:
            - name: 'Drop MySQL database'
              type: shell
              exitCode: 0
              output: ''
        YAML;
        $dir = runLogDir(['20260909T180000Z_lundflix-v2-flix-303_down.yaml' => $downLog]);

        // Act
        $exitCode = Artisan::call('lf:run-log', ['workflow' => 'up', '--dir' => $dir]);

        // Assert
        $output = Artisan::output();
        expect($exitCode)->toBe(Command::FAILURE);
        expect(Str::trim($output))->not->toBe('');
        expect($output)->toContain('up');
    });

    // What a run killed mid-write leaves on disk: `steps:` present but not a list
    // of maps. RunLogVerdict's `array $step` closure hints throw a TypeError on
    // this shape, and a TypeError escaping as a stack trace is not a report.
    it('reports plainly on a malformed log instead of throwing', function (): void {
        // Arrange
        $halfWritten = <<<'YAML'
        resource_type: run_log
        name: up
        status: success
        exception: null
        steps: 5
        YAML;
        $dir = runLogDir(['20260909T171446Z_lundflix-v2-flix-303_up.yaml' => $halfWritten]);

        // Act
        $exitCode = Artisan::call('lf:run-log', ['workflow' => 'up', '--dir' => $dir]);

        // Assert
        $output = Artisan::output();
        expect($exitCode)->toBe(Command::FAILURE);
        expect(Str::trim($output))->not->toBe('');
        expect($output)->toContain('up');
    });
});
