<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The PreToolUse(Agent) guard is a node script, so it is exercised end to end —
 * real stdin, real interpreter, no mocking — and judged by the two things Claude
 * Code reads: the JSON on stdout and the exit code.
 *
 * The guarded agent names below are hand-written literals taken from the
 * guarded-set spec, never re-derived from the hook's own table; a test that read
 * the set back out of the script could never disagree with it.
 */

/**
 * Run the hook against raw stdin and return its stdout.
 *
 * The exit-0 assertion lives here rather than in each test on purpose: every
 * path of this hook exits 0 and carries its rewrite in the JSON, so a missing
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
 * An Agent dispatch carrying exactly the given tool input, so a test controls
 * which keys are present — an omitted `run_in_background` is not the same input
 * as an explicit `false`.
 *
 * @param  array<string, mixed>  $toolInput
 * @return array<string, mixed>
 */
function agentDispatch(array $toolInput): array
{
    return [
        'tool_name' => 'Agent',
        'tool_input' => $toolInput,
    ];
}

/**
 * An explicitly backgrounded dispatch of one subagent.
 *
 * @return array<string, mixed>
 */
function backgroundedAgentDispatch(string $subagentType): array
{
    return agentDispatch([
        'subagent_type' => $subagentType,
        'run_in_background' => true,
    ]);
}

/**
 * The committed `.claude/settings.json`, decoded off disk — the file IS the
 * behavior under test, so it is never fixtured or faked. Decoding throws rather
 * than yielding null so a malformed settings file fails as itself instead of
 * masquerading as a missing entry.
 *
 * @return array<string, mixed>
 */
function committedClaudeSettings(): array
{
    return (array) json_decode(
        (string) file_get_contents(base_path('.claude/settings.json')),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/**
 * Every `command` Claude Code would run for a PreToolUse(Agent) dispatch.
 *
 * @return list<string>
 */
function registeredAgentPreToolUseCommands(): array
{
    return collect(data_get(committedClaudeSettings(), 'hooks.PreToolUse', []))
        ->where('matcher', 'Agent')
        ->flatMap(fn (array $entry): array => (array) data_get($entry, 'hooks', []))
        ->pluck('command')
        ->map(fn (mixed $command): string => (string) $command)
        ->values()
        ->all();
}

/**
 * Claude Code runs a dispatch in-turn only when `run_in_background` is explicitly
 * `false` — omitting the flag backgrounds it just as `true` does. So the guard
 * rewrites every guarded dispatch that is not already `false` rather than denying
 * it: the call proceeds, in the foreground, with no round trip to the caller.
 */
describe('guarded subagents forced to the foreground', function (): void {
    it('rewrites a no-flag dispatch of a guarded agent to run in the foreground', function (string $subagentType): void {
        // Arrange
        $payload = agentDispatch([
            'subagent_type' => $subagentType,
            'description' => 'Write the failing tests',
            'prompt' => 'RED phase for one slice.',
        ]);

        // Act
        $output = runBackgroundGuardHook($payload);

        // Assert
        expect(data_get($output, 'hookSpecificOutput.hookEventName'))->toBe('PreToolUse')
            ->and(data_get($output, 'hookSpecificOutput.updatedInput.run_in_background'))->toBeFalse();
    })->with([
        'tdd-test-writer',
        'tdd-implementer',
        'tdd-refactorer',
        'review-fixer',
    ]);

    it('rewrites an explicit background dispatch to run in the foreground', function (): void {
        // Arrange
        $payload = backgroundedAgentDispatch('tdd-implementer');

        // Act
        $output = runBackgroundGuardHook($payload);

        // Assert
        expect(data_get($output, 'hookSpecificOutput.updatedInput.run_in_background'))->toBeFalse();
    });

    it('carries every other input field through the rewrite unchanged', function (): void {
        // Claude Code uses `updatedInput` AS the tool input and schema-validates it
        // alone, so a rewrite that dropped `prompt` would get the dispatch rejected.
        // The comparison is key-order-insensitive on purpose: only the set of fields
        // and their values are observable to Claude Code.
        // Arrange
        $toolInput = [
            'subagent_type' => 'review-fixer',
            'description' => 'Apply the approved review fixes',
            'prompt' => 'Fix items 1, 3 and 4 from the disposition list.',
            'model' => 'sonnet',
            'isolation' => 'worktree',
        ];

        // Act
        $output = runBackgroundGuardHook(agentDispatch($toolInput));

        // Assert
        expect(data_get($output, 'hookSpecificOutput.updatedInput'))
            ->toEqual([...$toolInput, 'run_in_background' => false]);
    });

    it('rewrites without making a permission decision', function (): void {
        // Arrange
        $payload = backgroundedAgentDispatch('tdd-refactorer');

        // Act
        $output = runBackgroundGuardHook($payload);

        // Assert
        expect(data_get($output, 'hookSpecificOutput'))
            ->not->toHaveKey('permissionDecision')
            ->not->toHaveKey('permissionDecisionReason')
            ->toHaveKey('updatedInput');
    });

    it('leaves a guarded dispatch already sent in the foreground untouched', function (): void {
        // An explicit `false` is already the in-turn shape, so there is nothing to
        // rewrite — this is what keeps "rewrite every guarded dispatch" from passing.
        // Arrange
        $payload = agentDispatch([
            'subagent_type' => 'tdd-test-writer',
            'run_in_background' => false,
        ]);

        // Act
        $output = runBackgroundGuardHook($payload);

        // Assert
        expect($output)->toBe([]);
    });
});

/**
 * Writing nothing IS the allow: Claude Code reads absent output as "this hook has
 * no opinion" and runs the tool call as sent. So both tests below assert
 * emptiness, and they are kept apart by their INPUT rather than their output — a
 * backgrounded unguarded dispatch and a payload the hook could not read at all are
 * observationally identical, which is the intended behavior.
 */
describe('dispatches the guard lets through', function (): void {
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
 * it proves Claude Code ever runs it, or honors the rewrite it returns. An
 * unregistered hook, one registered at a path that does not resolve, or a
 * foreground rewrite the fork-subagent gate overrides, is inert and silent: the
 * tool call goes through backgrounded and no test in this file notices. So the
 * committed settings file is read off disk as its own seam.
 */
describe('settings.json wiring', function (): void {
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

    it('turns off the fork-subagent gate that would force every dispatch into the background', function (): void {
        // While the gate is on, Claude Code backgrounds every subagent and drops
        // `run_in_background` from the Agent schema, so the guard's foreground
        // rewrite can never take effect. This env entry is the only thing that
        // restores per-call foreground, and nothing else in the suite reads it.
        // Arrange
        // the committed settings file is the input; there is no state to set up

        // Act
        $gate = data_get(committedClaudeSettings(), 'env.CLAUDE_CODE_FORK_SUBAGENT');

        // Claude Code lowercases and trims the value, then treats these as false.
        // Assert
        expect($gate)->toBeString('env.CLAUDE_CODE_FORK_SUBAGENT must be set in .claude/settings.json')
            ->and(Str::lower(Str::trim((string) $gate)))->toBeIn(['0', 'false', 'no', 'off']);
    });
});
