<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The PreToolUse(Agent) guard is a node script, so it is exercised end to end —
 * real stdin, real interpreter, no mocking — and judged by the two things Claude
 * Code reads: the JSON on stdout and the exit code.
 *
 * The gate tokens asserted below ("RED gate", "GREEN gate", …) are hand-written
 * literals taken from the guarded-set spec, never re-derived from the hook's own
 * table; a test that read the map back out of the script could never disagree
 * with it.
 */

/**
 * Run the hook against raw stdin and return its stdout.
 *
 * The exit-0 assertion lives here rather than in each test on purpose: every
 * path of this hook exits 0 and carries its decision in the JSON, so a missing
 * or crashing script (node exits 1 with empty stdout) would otherwise satisfy
 * the allow-path tests, which assert exactly that emptiness.
 */
function runBackgroundGuardHookRaw(string $stdin): string
{
    $result = Process::input($stdin)
        ->run('node '.base_path('.claude/hooks/no-background-gated-subagents.js'));

    expect($result->exitCode())->toBe(0, 'hook should exit 0; stderr: '.$result->errorOutput());

    return $result->output();
}

/**
 * The real hook payload shape: the Agent tool's input under `.tool_input`. The
 * payload is passed whole so a caller can send any shape, including one missing
 * the keys the guard reads.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function runBackgroundGuardHook(array $payload): array
{
    $stdout = runBackgroundGuardHookRaw((string) json_encode($payload));

    return (array) json_decode($stdout, true);
}

/**
 * A background dispatch of one subagent, as Claude Code sends it.
 *
 * @return array<string, mixed>
 */
function backgroundedAgentDispatch(string $subagentType): array
{
    return [
        'tool_name' => 'Agent',
        'tool_input' => [
            'subagent_type' => $subagentType,
            'run_in_background' => true,
        ],
    ];
}

/**
 * Every `command` Claude Code would run for a PreToolUse(Agent) dispatch, read
 * from the committed `.claude/settings.json` off disk — the registration IS the
 * behavior under test, so it is never fixtured or faked. Decoding throws rather
 * than yielding null so a malformed settings file fails as itself instead of
 * masquerading as a missing registration.
 *
 * @return list<string>
 */
function registeredAgentPreToolUseCommands(): array
{
    $settings = (array) json_decode(
        (string) file_get_contents(base_path('.claude/settings.json')),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    return collect(data_get($settings, 'hooks.PreToolUse', []))
        ->where('matcher', 'Agent')
        ->flatMap(fn (array $entry): array => (array) data_get($entry, 'hooks', []))
        ->pluck('command')
        ->map(fn (mixed $command): string => (string) $command)
        ->values()
        ->all();
}

describe('backgrounded guarded subagents', function (): void {
    it('denies a backgrounded tdd-test-writer, naming the RED gate', function (): void {
        // Arrange
        $payload = backgroundedAgentDispatch('tdd-test-writer');

        // Act
        $output = runBackgroundGuardHook($payload);

        // Assert
        expect(data_get($output, 'hookSpecificOutput.permissionDecision'))->toBe('deny')
            ->and(data_get($output, 'hookSpecificOutput.permissionDecisionReason'))->toContain('RED gate');
    });

    it('denies a backgrounded tdd-implementer, naming the GREEN gate', function (): void {
        // Arrange
        $payload = backgroundedAgentDispatch('tdd-implementer');

        // Act
        $output = runBackgroundGuardHook($payload);

        // Assert
        expect(data_get($output, 'hookSpecificOutput.permissionDecision'))->toBe('deny')
            ->and(data_get($output, 'hookSpecificOutput.permissionDecisionReason'))->toContain('GREEN gate');
    });

    it('denies a backgrounded tdd-refactorer, naming the REFACTOR gate', function (): void {
        // Arrange
        $payload = backgroundedAgentDispatch('tdd-refactorer');

        // Act
        $output = runBackgroundGuardHook($payload);

        // Assert
        expect(data_get($output, 'hookSpecificOutput.permissionDecision'))->toBe('deny')
            ->and(data_get($output, 'hookSpecificOutput.permissionDecisionReason'))->toContain('REFACTOR gate');
    });

    it('denies a backgrounded review-fixer, naming the review-process phase it waits on', function (): void {
        // Arrange
        $payload = backgroundedAgentDispatch('review-fixer');

        // Act
        $output = runBackgroundGuardHook($payload);

        // Assert
        expect(data_get($output, 'hookSpecificOutput.permissionDecision'))->toBe('deny')
            ->and(data_get($output, 'hookSpecificOutput.permissionDecisionReason'))->toContain('Phase 3');
    });
});

/**
 * Writing nothing IS the allow: Claude Code reads absent output as "this hook has
 * no opinion" and runs the tool call. So every test below asserts emptiness, and
 * the three are kept apart by their INPUT rather than their output — a foreground
 * guarded dispatch, a backgrounded unguarded one, and a payload the hook could not
 * read at all are observationally identical, which is the intended behavior.
 */
describe('dispatches the guard lets through', function (): void {
    it('allows a guarded subagent dispatched in the foreground', function (): void {
        // Claude Code omits the flag entirely on a foreground dispatch rather than
        // sending false, and "omit it" is what the deny reason tells the caller to
        // do — so the omitted shape is the one that has to come back clean.
        // Arrange
        $payload = [
            'tool_name' => 'Agent',
            'tool_input' => ['subagent_type' => 'tdd-implementer'],
        ];

        // Act
        $output = runBackgroundGuardHook($payload);

        // Assert
        expect($output)->toBe([]);
    });

    it('allows an unguarded subagent dispatched backgrounded', function (): void {
        // `/review:suite` backgrounds this one deliberately — it genuinely overlaps
        // `/review:claude` running concurrently — so the guard must stay keyed on the
        // gated set and never widen to "any backgrounded subagent".
        // Arrange
        $payload = backgroundedAgentDispatch('coderabbit-reviewer');

        // Act
        $output = runBackgroundGuardHook($payload);

        // Assert
        expect($output)->toBe([]);
    });

    it('fails open on a payload it cannot parse', function (): void {
        // Truncated mid-object, so JSON.parse throws rather than yielding a shape
        // with missing keys. Failing open here is deliberate, not a gap to close
        // into a denial — the hook's own comment above its catch-block exit carries
        // why.
        // Arrange
        $stdin = '{"tool_name":"Agent","tool_input":';

        // Act
        $stdout = runBackgroundGuardHookRaw($stdin);

        // Assert
        expect($stdout)->toBe('');
    });
});

/**
 * Everything above proves the script decides correctly when it is run — none of
 * it proves Claude Code ever runs it. An unregistered hook, or one registered at
 * a path that does not resolve, is inert and silent: the tool call goes through
 * and no test in this file notices. So the committed settings file is read off
 * disk as its own seam.
 */
describe('settings.json registration', function (): void {
    it('registers the guard as a PreToolUse hook on the Agent matcher', function (): void {
        // Arrange
        // the committed settings file is the input; there is no state to set up

        // Act
        $commands = registeredAgentPreToolUseCommands();

        // Assert
        expect(collect($commands)->join("\n"))->toContain('no-background-gated-subagents.js');
    });

    it('registers a command whose path resolves to a file that exists', function (): void {
        // A registration naming the hook can still point at a directory that does
        // not exist — Claude Code then surfaces a hook error and the guard never
        // runs, which is operationally identical to no hook at all. Hence the path
        // is taken FROM the registered command rather than asserted against a
        // hardcoded expectation.
        // Arrange
        $command = (string) collect(registeredAgentPreToolUseCommands())
            ->first(fn (string $c): bool => Str::contains($c, 'no-background-gated-subagents.js'));

        // Act
        // Claude Code expands $CLAUDE_PROJECT_DIR to the repo root before running
        // the command, so the same substitution yields the file it will execute.
        preg_match('#\$CLAUDE_PROJECT_DIR[^"\']*#', $command, $matches);
        $path = Str::replace('$CLAUDE_PROJECT_DIR', base_path(), $matches[0] ?? '');

        // Assert
        expect($path)->toBeFile();
    });
});
