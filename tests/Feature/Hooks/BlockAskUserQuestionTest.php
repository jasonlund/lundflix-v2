<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * The PreToolUse guard is a bash script, so it is exercised end to end — real
 * stdin, real jq — and judged on the two things Claude Code reads: the exit code
 * (2 blocks the tool call, anything else lets it run) and stderr, which is what
 * the calling agent is shown when it is blocked.
 *
 * Expected exit codes below are hand-written literals, never re-derived from the
 * hook's own matching; a test that recomputed them could never disagree with the
 * script.
 */
function runAskUserQuestionHookRaw(string $stdin): ProcessResult
{
    return Process::input($stdin)
        ->run('bash '.base_path('.claude/hooks/block-ask-user-question.sh'));
}

/** The real hook payload shape: the tool's name and its input under `.tool_input`. */
function runAskUserQuestionHookFor(string $toolName, array $toolInput): ProcessResult
{
    $json = (string) json_encode([
        'tool_name' => $toolName,
        'tool_input' => $toolInput,
    ]);

    return runAskUserQuestionHookRaw($json);
}

/** A realistic AskUserQuestion payload — the picker always carries a questions array. */
function askUserQuestionToolInput(): array
{
    return [
        'questions' => [
            [
                'question' => 'Which storage driver should the cache use?',
                'header' => 'Cache driver',
                'multiSelect' => false,
                'options' => [
                    ['label' => 'Redis', 'description' => 'Shared across workers.'],
                    ['label' => 'Database', 'description' => 'No new service to run.'],
                ],
            ],
        ],
    ];
}

describe('ask-user-question guard blocking', function (): void {
    it('blocks the picker tool', function (): void {
        // Arrange
        $toolInput = askUserQuestionToolInput();

        // Act
        $result = runAskUserQuestionHookFor('AskUserQuestion', $toolInput);

        // Assert
        expect($result->exitCode())->toBe(2);
    });

    // The deny message is the third place this rule is discoverable, after
    // project.md and the Pest guard — and the only one an agent meets at the
    // moment it gets it wrong. So it has to teach the alternative, not just
    // refuse: the substrings below pin that intent without pinning the wording,
    // which would make every rephrasing a test failure.
    it('names the plain-markdown alternative and where the rule is written', function (): void {
        // Arrange
        $toolInput = askUserQuestionToolInput();

        // Act
        $result = runAskUserQuestionHookFor('AskUserQuestion', $toolInput);

        // Assert
        expect($result->errorOutput())->toContain('plain markdown')
            ->and($result->errorOutput())->toContain('.ai/guidelines/project.md')
            ->and($result->errorOutput())->toContain('Asking the user a question');
    });
});

describe('ask-user-question guard allowing', function (): void {
    it('lets a different tool through', function (): void {
        // Arrange
        $toolInput = ['command' => 'php artisan test --compact'];

        // Act
        $result = runAskUserQuestionHookFor('Bash', $toolInput);

        // Assert
        expect($result->exitCode())->toBe(0);
    });

    // DELIBERATELY THE OPPOSITE CHOICE from block-destructive-git.sh, which
    // fails CLOSED (exit 2) on a payload it cannot parse. That hook guards an
    // irreversible act — nothing recovers a `reset --hard` over a dirty tree —
    // so an unreadable payload there is worth refusing. A picker call is not
    // irreversible: the worst a miss costs is one question asked the wrong way.
    // Failing closed here would instead jam every tool call in the session on a
    // malformed payload, which is far more expensive than the hole it closes.
    // A future reader comparing the two hooks should read this as a weighed
    // divergence, not an inconsistency.
    it('stays out of the way when it cannot read the payload', function (string $stdin): void {
        // Arrange
        // the raw payload under test is the dataset row

        // Act
        $result = runAskUserQuestionHookRaw($stdin);

        // Assert
        expect($result->exitCode())->toBe(0);
    })->with([
        'stdin that is not JSON' => 'not json at all',
        'empty stdin' => '',
    ]);
});
