<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The PreToolUse(Bash) guard is a bash script, so it is exercised end to end —
 * real stdin, real jq, real grep — and judged only by its exit code, the one
 * thing Claude Code reads: 2 blocks the tool call, anything else lets it run.
 *
 * Expected exit codes below are hand-written literals, never re-derived from the
 * hook's own patterns; a test that recomputed the regex could never disagree
 * with the script.
 */
function runDestructiveGitHookRaw(string $stdin): ProcessResult
{
    return Process::input($stdin)
        ->run('bash '.base_path('.claude/hooks/block-destructive-git.sh'));
}

/** The real hook payload shape: the Bash tool's command under `.tool_input`. */
function runDestructiveGitHook(string $command): int
{
    $json = (string) json_encode([
        'tool_name' => 'Bash',
        'tool_input' => ['command' => $command],
    ]);

    return (int) runDestructiveGitHookRaw($json)->exitCode();
}

/**
 * A PATH holding only the externals the hook needs (`cat`, `grep`) and NOT jq,
 * so "jq is missing from this machine" can be reproduced deterministically on
 * macOS and CI alike — both ship jq, just from different directories, so
 * trimming PATH to a known prefix would not remove it.
 */
function pathWithoutJq(): string
{
    $dir = sys_get_temp_dir().'/block-destructive-git-'.uniqid('', true);
    mkdir($dir, 0777, true);

    foreach (['cat', 'grep'] as $binary) {
        symlink(Str::trim(Process::run('command -v '.$binary)->output()), $dir.'/'.$binary);
    }

    return $dir;
}

it('blocks a git invocation that destroys uncommitted work', function (string $command): void {
    // Arrange
    // the command under test is the dataset row

    // Act
    $exitCode = runDestructiveGitHook($command);

    // Assert
    expect($exitCode)->toBe(2);
})->with([
    '-C worktree option before reset --hard' => 'git -C /tmp/other-worktree reset --hard',
    '--git-dir option before clean -fd' => 'git --git-dir=/tmp/x/.git clean -fd',
    '-c config option before branch -D' => 'git -c core.pager=cat branch -D topic',
    'clean with the long --force spelling' => 'git clean --force',
    'clean with --force and a separate -d' => 'git clean --force -d',
    'checkout with a -- separator' => 'git checkout -- .',
    'restore with a -- separator' => 'git restore -- .',
    'reset --keep' => 'git reset --keep HEAD~1',
    'reset --hard' => 'git reset --hard',
    'clean -fd' => 'git clean -fd',
    'checkout .' => 'git checkout .',
    'branch -D' => 'git branch -D old-branch',
    'restore . after a shell separator' => 'cd /tmp && git restore .',
    'stash drop' => 'git stash drop',
]);

it('allows a command that destroys nothing', function (string $command): void {
    // Arrange
    // the command under test is the dataset row

    // Act
    $exitCode = runDestructiveGitHook($command);

    // Assert
    expect($exitCode)->toBe(0);
})->with([
    'clean dry run' => 'git clean -ndf',
    'push to a feature branch' => 'git push -u origin some-branch',
    'commit' => 'git commit -m "wip"',
    'status' => 'git status',
    'soft reset' => 'git reset HEAD~1',
    'restore of one named file from the index' => 'git restore --staged path/to/one/file.php',
    'a mention inside a quoted string' => 'echo "never run git reset --hard here"',
    'a grep for the command rather than the command' => 'grep -rn "git clean -fd" docs/',
]);

it('fails closed on stdin it cannot parse', function (string $stdin): void {
    // Arrange
    // the raw payload under test is the dataset row

    // Act
    $exitCode = (int) runDestructiveGitHookRaw($stdin)->exitCode();

    // Assert
    expect($exitCode)->toBe(2);
})->with([
    'stdin that is not JSON' => 'not json at all',
    'empty stdin' => '',
    'JSON carrying no command' => '{"tool_name":"Bash"}',
]);

it('lets the tool call through with a warning when jq is unavailable', function (): void {
    // A machine without jq would otherwise have every single Bash call blocked,
    // which is worse than the hole it closes — so the missing-interpreter case
    // is loud (exit 1 surfaces stderr to the user) but never blocking.
    // Arrange
    $json = (string) json_encode(['tool_input' => ['command' => 'git reset --hard']]);
    $path = pathWithoutJq();

    // Act
    $result = Process::input($json)
        ->run('env PATH='.$path.' /bin/bash '.base_path('.claude/hooks/block-destructive-git.sh'));

    // Assert
    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('jq');
});
