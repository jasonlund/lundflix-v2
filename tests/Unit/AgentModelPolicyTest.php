<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * Drift guard for the `.claude/` agent toolkit: the commit trailer it instructs
 * agents to write carries no model version stamp, and every review/hunter agent
 * pins the model its review phase is meant to run on.
 *
 * NB: the offending trailer shape lives in a PHP string literal (never in a `//`
 * comment) so widening the scan can never make this file its own offender; the
 * scan is scoped strictly to `.claude/` for the same reason.
 */

/**
 * Every line of every markdown file in the `.claude/` toolkit.
 *
 * @return list<array{file: string, line: int, text: string}>
 */
$scanToolkitLines = function (): array {
    // The Unit suite doesn't boot the app container, so resolve the repo root
    // from this file's location rather than base_path().
    $root = dirname(__DIR__, 2);

    $finder = (new Finder)->files()->in($root.'/.claude')->name('*.md');

    $lines = [];

    foreach ($finder as $file) {
        $relative = Str::replace($root.DIRECTORY_SEPARATOR, '', $file->getRealPath());
        // Split on real newlines only, NOT `\R`: without the `u` modifier PCRE
        // treats the 0x85 byte as NEL, and 0x85 is a UTF-8 continuation byte of
        // characters these files contain (e.g. the ✅ in a summary block). `\R`
        // would break mid-character and shift every later line number by one,
        // making the reported file:line wrong.
        $text = preg_split('/\r\n|\n|\r/', (string) file_get_contents($file->getRealPath()));

        foreach ($text as $index => $line) {
            $lines[] = [
                'file' => $relative,
                'line' => $index + 1,
                'text' => $line,
            ];
        }
    }

    return $lines;
};

/**
 * The `model:` value an agent declares in its frontmatter, or null when absent.
 */
$declaredModel = function (string $agent): ?string {
    $path = dirname(__DIR__, 2).'/.claude/agents/'.$agent.'.md';
    $lines = preg_split('/\r\n|\n|\r/', (string) file_get_contents($path));

    foreach ($lines as $index => $text) {
        // The opening fence is line 1; the next fence closes the frontmatter.
        if ($index > 0 && Str::trim($text) === '---') {
            break;
        }

        if (preg_match('#^model:\s*(\S+)\s*$#', $text, $matches) === 1) {
            return $matches[1];
        }
    }

    return null;
};

it('carries no model-stamped co-author trailer anywhere under .claude/', function () use ($scanToolkitLines): void {
    // The bare `Co-Authored-By: Claude <noreply@anthropic.com>` is the
    // drift-free form we want; anything wedged between the name and the address
    // is a version stamp that rots the moment the model changes. Matched
    // case-insensitively because history also carries `Co-authored-by`.
    // Arrange
    $stamped = '#co-authored-by:\s*Claude\s+[^<]+<noreply@anthropic\.com>#i';

    // Act
    $offenders = collect($scanToolkitLines())
        ->filter(fn (array $l): bool => preg_match($stamped, $l['text']) === 1)
        ->map(fn (array $l): string => sprintf('%s:%d  %s', $l['file'], $l['line'], Str::trim($l['text'])))
        ->values()
        ->all();

    // Assert
    expect($offenders)->toBe([]);
});

it('pins every breadth reviewer to the sonnet alias', function () use ($declaredModel): void {
    // The breadth phase runs on the bare alias so it tracks the current Sonnet,
    // never a dated model id that pins the phase to a retired snapshot.
    // Arrange
    $agents = [
        'requirements-reviewer',
        'conventions-reviewer',
        'edge-case-reviewer',
        'integration-reviewer',
        'discipline-reviewer',
        'testing-reviewer',
        'coderabbit-reviewer',
    ];

    // Act
    $declared = array_combine($agents, array_map($declaredModel, $agents));

    // Assert
    expect($declared)->toBe(array_fill_keys($agents, 'sonnet'));
});

it('runs the phase 5 hunters on the session model', function () use ($declaredModel): void {
    // Verification adversaries reason over the whole review, so they follow
    // whatever model the session runs rather than pinning their own.
    // Arrange
    $agents = [
        'false-positive-hunter',
        'missing-defect-hunter',
    ];

    // Act
    $declared = array_combine($agents, array_map($declaredModel, $agents));

    // Assert
    expect($declared)->toBe(array_fill_keys($agents, 'inherit'));
});
