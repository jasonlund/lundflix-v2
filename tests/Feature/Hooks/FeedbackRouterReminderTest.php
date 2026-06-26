<?php

use Illuminate\Support\Facades\Process;

/**
 * Run the feedback-router-reminder UserPromptSubmit hook with a JSON payload on
 * stdin and return its stdout. Exercises the real bash script + jq (no mocking).
 */
function runFeedbackRouterHook(string $prompt, string $cwd): string
{
    $json = json_encode(['prompt' => $prompt, 'cwd' => $cwd]);

    $result = Process::input($json)
        ->run('bash '.base_path('.claude/hooks/feedback-router-reminder.sh'));

    expect($result->successful())->toBeTrue('hook should exit 0; stderr: '.$result->errorOutput());

    return $result->output();
}

/** A fresh empty temp dir with no comments attachment present. */
function freshHookCwd(): string
{
    $dir = sys_get_temp_dir().'/feedback-router-'.uniqid('', true);
    mkdir($dir, 0777, true);

    return $dir;
}

it('fires on a reviewer reporting broken work', function () {
    // Arrange
    $cwd = freshHookCwd();

    // Act
    $output = runFeedbackRouterHook('the reviewer said the importer is broken', $cwd);

    // Assert
    expect($output)->toContain('[feedback-router]');
});

it('fires on a bug-fix request against existing work', function () {
    // Arrange
    $cwd = freshHookCwd();

    // Act
    $output = runFeedbackRouterHook('fix this bug in the dataset importer', $cwd);

    // Assert
    expect($output)->toContain('[feedback-router]');
});

it('fires on a request to remove an unused helper', function () {
    // Arrange
    $cwd = freshHookCwd();

    // Act
    $output = runFeedbackRouterHook('remove the now-unused helper method', $cwd);

    // Assert
    expect($output)->toContain('[feedback-router]');
});

it('fires when a comment attachment landed recently even with a trivial prompt', function () {
    // Arrange
    $cwd = freshHookCwd();
    $commentsDir = $cwd.'/.context/attachments/comments';
    mkdir($commentsDir, 0777, true);
    file_put_contents($commentsDir.'/x.md', "diff comment\n");

    // Act
    $output = runFeedbackRouterHook('ok', $cwd);

    // Assert
    expect($output)->toContain('[feedback-router]');
});

it('stays silent on a new feature request', function () {
    // Arrange
    $cwd = freshHookCwd();

    // Act
    $output = runFeedbackRouterHook('add an export button to the dashboard', $cwd);

    // Assert
    expect(trim($output))->toBe('');
});

it('stays silent on an unrelated question', function () {
    // Arrange
    $cwd = freshHookCwd();

    // Act
    $output = runFeedbackRouterHook('what time is it', $cwd);

    // Assert
    expect(trim($output))->toBe('');
});
