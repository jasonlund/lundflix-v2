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
 * The temp directories pathWithoutJq() has created but not yet removed. A
 * registry rather than a glob over the temp dir: sibling workspaces run their
 * own copy of this suite against the same /tmp, and a glob would delete a
 * concurrent run's directory out from under it mid-test.
 */
function jqFreePathRegistry(): ArrayObject
{
    static $paths = null;

    return $paths ??= new ArrayObject;
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

    jqFreePathRegistry()->append($dir);

    return $dir;
}

// Teardown, not the test body, so a failed expectation still leaves no litter
// behind — pathWithoutJq() writes outside the project and nothing else reaps it.
afterEach(function (): void {
    $registry = jqFreePathRegistry();

    foreach ($registry as $dir) {
        foreach ((array) glob($dir.'/*') as $link) {
            unlink((string) $link);
        }

        rmdir((string) $dir);
    }

    $registry->exchangeArray([]);
});

it('blocks a git invocation that destroys uncommitted work', function (string $command): void {
    // Arrange
    // the command under test is the dataset row

    // Act
    $exitCode = runDestructiveGitHook($command);

    // Assert
    expect($exitCode)->toBe(2);
})->with([
    // A dry run only ever describes the invocation it was passed to, so a
    // preview earlier on the line cannot make a later `clean -fd` safe — and
    // preview-then-execute is the ordinary way people reach for clean.
    'clean previewed then executed on one line' => 'git clean -ndf && git clean -fd',
    'clean executed then previewed on one line' => 'git clean -f && git clean -n',
    // `-d` plus `-f` in any spelling or order force-deletes an unmerged branch
    // exactly as `-D` does, so every spelling has to land the same way.
    'branch delete and force in one combined group' => 'git branch -df old-branch',
    'branch force and delete in one combined group' => 'git branch -fd old-branch',
    'branch --delete with a short force flag' => 'git branch --delete -f old-branch',
    'branch -d with a long --force flag' => 'git branch -d --force old-branch',
    'branch -f before a long --delete flag' => 'git branch -f --delete old-branch',
    // A quoted global-option value may contain a space; the destructive
    // subcommand still sits right behind it.
    'quoted -C path containing a space' => 'git -C "/tmp/my worktree" reset --hard',
    'quoted -c value containing a space' => 'git -c user.name="Jane Doe" reset --hard',
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
    // Closing a heredoc has to hand the scanner back to normal command reading.
    // If the terminator were missed, everything after a documented command
    // would be swallowed as data and the guard would be silently disarmed for
    // the rest of the line.
    'clean -fd after a closed heredoc' => "cat <<EOF\nnotes about git clean -fd\nEOF\n; git clean -fd",
    'reset --hard after a closed tab-stripping heredoc' => "cat <<-END\n\tnotes\n\tEND\ngit reset --hard",
    // A here-string (`<<<`) is a single-line redirection: it feeds ONE word to
    // the command on its own line and opens no body, so the next line is an
    // ordinary command position. Reading `<<<` as a heredoc opener made the
    // scanner wait for a terminator line that can never arrive and swallow the
    // rest of the payload as data — the whole guard silently disarmed by three
    // characters.
    'clean -fd on the line after a here-string' => "echo hi <<<foo\ngit clean -fd",
    'reset --hard on the line after a here-string' => "cat <<<word\ngit reset --hard",
    'clean -fd several lines after a here-string' => "echo hi <<<foo\necho still running\ngit clean -fd",
    // Verified against real bash: `bash -c 'echo \"; echo WOULD_RUN'` prints `"`
    // and then WOULD_RUN, so the second command genuinely executes. A backslash
    // outside quotes makes the next character literal — it opens no quoted
    // stretch — and a scanner that reads the escaped quote as an opener swallows
    // the separator behind it and the destructive command with it.
    'escaped double quote before a separator' => 'echo \"; git clean -fd',
    'escaped single quote before a separator' => "echo \\'; git reset --hard",
    // Bash treats a backslash inside single quotes as an ordinary character, so
    // the quote closes on the very next `'` and the separator after it splits.
    'trailing backslash inside a single-quoted string' => "echo 'a\\'; git clean -fd",
    // DELIBERATE OVER-BLOCK. Real bash refuses this line outright ("unexpected
    // EOF while looking for matching quote", exit 2) so nothing runs at all. The
    // scanner cannot tell that from a construct it merely failed to model, so it
    // scans the unresolved remainder rather than dropping it — blocking a line
    // the shell would not have run either way.
    'unterminated quote (over-block: bash rejects the line as a syntax error)' => 'echo "unterminated; git clean -fd',
    // DELIBERATE OVER-BLOCK. Real bash reads an unterminated body to end of
    // input and hands it to `cat` as data, so the command inside never runs. It
    // is refused anyway: an unterminated body is exactly the state the
    // here-string defect produced, where the scanner waited for a terminator
    // that could never arrive and silently allowed everything after it.
    'unterminated heredoc body (over-block: bash treats the body as data)' => "cat <<EOF\nnotes\ngit clean -fd",
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
    'clean dry run with no force flag at all' => 'git clean -n',
    // Deleting a merged branch is the normal path and the commit stays in the
    // reflog, so only the force spelling is worth refusing.
    'branch delete without force' => 'git branch -d merged-branch',
    'push to a feature branch' => 'git push -u origin some-branch',
    'commit' => 'git commit -m "wip"',
    'status' => 'git status',
    'soft reset' => 'git reset HEAD~1',
    'restore of one named file from the index' => 'git restore --staged path/to/one/file.php',
    'a mention inside a quoted string' => 'echo "never run git reset --hard here"',
    'a grep for the command rather than the command' => 'grep -rn "git clean -fd" docs/',
    // A heredoc body is stdin data for the command in front of it, exactly like
    // a quoted string is data — the shell never executes a line of it. This
    // repo writes about these commands constantly (commit messages, ADRs, skill
    // docs, this hook's own notes), and the reproduction below is a real
    // `git commit -F -` that the guard refused.
    'commit message documenting destructive commands' => <<<'BASH'
        git commit -F - <<'EOF'
        Guard notes:
          git clean -ndf && git clean -fd    ->  allowed
          git clean -f && git clean -n       ->  allowed
        EOF
        BASH,
    'quoted heredoc delimiter' => "cat <<'EOF'\ngit clean -fd\nEOF",
    'double-quoted heredoc delimiter' => "cat <<\"EOF\"\ngit reset --hard\nEOF",
    'unquoted heredoc delimiter' => "cat <<EOF\ngit clean -fd\nEOF",
    // `<<-` strips leading tabs from the body, so its terminator is indented
    // too and an exact line match would never find it.
    'tab-stripping heredoc with an indented terminator' => "cat <<-END\n\tgit clean -fd\n\tEND",
    'heredoc delimiter word other than EOF' => "cat <<MESSAGE\ngit branch -D topic\nMESSAGE",
    // The here-string's own word is stdin data for `cat`, exactly like a quoted
    // string — the command position on this line is still `cat`.
    'a here-string carrying the command as data' => 'cat <<< "git clean -fd"',
    // Verified against real bash: `bash -c 'echo a\; echo WOULD_RUN'` prints
    // `a; echo WOULD_RUN`, so the escaped semicolon is an argument character and
    // the git words behind it are more arguments to echo — no second command
    // exists to refuse.
    'an escaped separator that keeps the git words as echo arguments' => 'echo a\; git clean -fd',
    // Verified against real bash: this prints `a"; echo WOULD_RUN` — the escaped
    // quote does not close the string, so the whole line is one argument. The
    // guard allowed it before escapes were modelled only by accident (the
    // trailing `"` sat where the flag matcher required whitespace or end of
    // segment); it has to stay allowed for the stated reason instead.
    'an escaped quote that keeps a double-quoted string open' => 'echo "a\"; git clean -fd"',
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
