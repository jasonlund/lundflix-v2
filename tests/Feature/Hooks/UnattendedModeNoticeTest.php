<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The UserPromptSubmit notice is a bash script, so it is exercised end to end —
 * real stdin, real jq, real bash — and judged only by what Claude Code reads:
 * stdout (added context) and the exit code.
 *
 * Exit 0 is asserted inside the runner rather than as its own case, so every
 * path below carries the "never blocks a prompt" half of the contract.
 *
 * A $path replaces the process PATH to reproduce a machine without jq. Bash is
 * off that trimmed PATH too, so it has to be invoked by absolute path — a bare
 * `bash` there fails to launch and the run would prove nothing about the hook.
 */
function runUnattendedModeHook(string $stdin, ?string $path = null): string
{
    $script = base_path('.claude/hooks/unattended-mode-notice.sh');

    $command = $path === null
        ? 'bash '.$script
        : 'env PATH='.$path.' /bin/bash '.$script;

    $result = Process::input($stdin)->run($command);

    expect($result->successful())->toBeTrue('hook should exit 0; stderr: '.$result->errorOutput());

    return $result->output();
}

/**
 * The live UserPromptSubmit payload shape. A null $permissionMode drops the field
 * entirely, which is the shape an older client (or a non-Claude caller) sends.
 */
function unattendedModePayload(?string $permissionMode): string
{
    $payload = [
        'session_id' => '9f1c0b7e-2d4a-4c66-9d51-6a0f2b8c1e33',
        'transcript_path' => '/Users/dev/.claude/projects/-Users-dev-Sites-app/9f1c0b7e.jsonl',
        'cwd' => '/Users/dev/Sites/app',
        'prompt_id' => 'p_01HZX9Q4M7K2',
        'permission_mode' => $permissionMode,
        'hook_event_name' => 'UserPromptSubmit',
    ];

    if ($permissionMode === null) {
        unset($payload['permission_mode']);
    }

    return (string) json_encode($payload);
}

/**
 * Every command Claude Code would run on a submitted prompt, flattened out of the
 * event's registration groups.
 *
 * @return Collection<int, string>
 */
function userPromptSubmitHookCommands(): Collection
{
    $settings = (array) json_decode((string) file_get_contents(base_path('.claude/settings.json')), true);

    return collect($settings['hooks']['UserPromptSubmit'] ?? [])
        ->flatMap(fn (array $entry): array => (array) ($entry['hooks'] ?? []))
        ->map(fn (array $hook): string => (string) ($hook['command'] ?? ''))
        ->values();
}

describe('unattended-mode hook firing', function (): void {
    it('fires when the session runs with permissions bypassed', function (): void {
        // Arrange
        $payload = unattendedModePayload('bypassPermissions');

        // Act
        $output = runUnattendedModeHook($payload);

        // Assert
        expect($output)->toContain('[unattended-mode]');
    });

    it('states that the correctness gates still apply', function (): void {
        // Arrange
        $payload = unattendedModePayload('bypassPermissions');

        // Act
        $output = runUnattendedModeHook($payload);

        // Substrings only: a full-text match would pin the notice's prose, where
        // the contract is that it names each of the three gates.
        // Assert
        expect($output)->toContain('RED must fail for the right reason')
            ->and($output)->toContain('GREEN must pass')
            ->and($output)->toContain('REFACTOR must stay green');
    });

    it('states that the planning skills stay gated', function (): void {
        // Arrange
        $payload = unattendedModePayload('bypassPermissions');

        // Act
        $output = runUnattendedModeHook($payload);

        // An open-ended list of examples reads as a universal rule, which would take
        // the interview out of `plan-draft` — the one thing it exists for. Substring
        // only, as above: the contract is that the exclusion is named, not its prose.
        // Assert
        expect($output)->toContain('planning skills');
    });
});

describe('unattended-mode hook silence', function (): void {
    it('stays silent on a gated permission mode', function (string $permissionMode): void {
        // Arrange
        $payload = unattendedModePayload($permissionMode);

        // Act
        $output = runUnattendedModeHook($payload);

        // Assert
        expect(Str::trim($output))->toBe('');
    })->with([
        'default' => 'default',
        'plan' => 'plan',
        'acceptEdits' => 'acceptEdits',
        'auto' => 'auto',
        'dontAsk' => 'dontAsk',
    ]);

    it('stays silent when the payload carries no permission mode', function (): void {
        // Arrange
        $payload = unattendedModePayload(null);

        // Act
        $output = runUnattendedModeHook($payload);

        // Assert
        expect(Str::trim($output))->toBe('');
    });

    it('stays silent on stdin it cannot parse', function (): void {
        // Arrange
        $payload = 'not json at all';

        // Act
        $output = runUnattendedModeHook($payload);

        // Assert
        expect(Str::trim($output))->toBe('');
    });

    it('stays silent on stdin that is not exactly one JSON document', function (string $stream): void {
        // jq reads stdin as a STREAM of back-to-back values, so a real payload with a
        // second document beside it still prints `bypassPermissions` — and with junk
        // trailing it, jq flushes that line before failing on the junk. Reading the
        // mode alone cannot tell either apart from a lone trusted payload.
        // Arrange
        $payload = Str::replace('{document}', unattendedModePayload('bypassPermissions'), $stream);

        // Act
        $output = runUnattendedModeHook($payload);

        // Assert
        expect(Str::trim($output))->toBe('');
    })->with([
        'a second document ahead of the payload' => '{}{document}',
        'junk trailing the payload' => '{document}xyz',
    ]);

    it('stays silent when jq is unavailable', function (): void {
        // Failing closed on a machine without jq costs one skipped notice; guessing
        // "unattended" without being able to read the mode would strip a human's
        // approval from a loop that writes code.
        // Arrange
        $payload = unattendedModePayload('bypassPermissions');
        $path = pathWithoutJq();

        // Act
        $output = runUnattendedModeHook($payload, $path);

        // Assert
        expect(Str::trim($output))->toBe('');
    });
});

/**
 * Everything above proves the script behaves; nothing above proves anything ever
 * runs it. Claude Code reads `.claude/settings.json` — an unregistered hook is a
 * file that passes its own tests and never fires, which no other test in this
 * suite can see. So the subject here is the committed settings file itself, read
 * from disk and parsed as data, the way the LaborForest workflow guard reads its
 * committed YAML.
 */
describe('unattended-mode hook wiring', function (): void {
    it('registers the unattended-mode notice on the UserPromptSubmit event', function (): void {
        // Arrange
        $commands = userPromptSubmitHookCommands();

        // Matched as a substring, not as the whole command: the sibling registrations
        // wrap the script in `bash "$CLAUDE_PROJECT_DIR/…"`, and pinning that literal
        // would make this test own quoting details it does not care about.
        // Act
        $registered = $commands
            ->filter(fn (string $command): bool => Str::contains($command, 'unattended-mode-notice.sh'))
            ->all();

        // Assert
        expect($registered)->not->toBeEmpty();
    });

    it('names a script that exists on disk', function (): void {
        // Arrange
        $commands = userPromptSubmitHookCommands();

        // The path comes out of the registration rather than a literal, so a green
        // run means the wiring points at a real file — a hardcoded path would only
        // re-prove that the script exists, which the runner above already does.
        // Act
        $script = $commands
            ->map(fn (string $command): ?string => preg_match('/[^"\s]*unattended-mode-notice\.sh/', $command, $matches) === 1 ? $matches[0] : null)
            ->filter()
            ->map(fn (string $path): string => Str::replace('$CLAUDE_PROJECT_DIR', base_path(), $path))
            ->first();

        // Assert
        expect($script)->not->toBeNull()
            ->and(is_file((string) $script))->toBeTrue();
    });
});
