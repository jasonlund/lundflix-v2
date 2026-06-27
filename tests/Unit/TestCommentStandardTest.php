<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Self-policing guard: every `//` line-comment that begins with an AAA label
 * (Arrange / Act / Assert) across the test suite and the React frontend must
 * conform to the one canonical form. See the "Test-comment standard (strict)"
 * section of `.claude/skills/laravel-testing/SKILL.md`.
 *
 * NB: example offender strings live in PHP string literals (never in `//`
 * comments) so this file's own scanner never trips on its own examples.
 */

/**
 * A single scanned AAA label line.
 *
 * @return list<array{file: string, line: int, text: string}>
 */
$scanAaaLabelLines = function (): array {
    // The Unit suite doesn't boot the app container, so resolve the repo root
    // from this file's location rather than base_path().
    $root = dirname(__DIR__, 2);

    // A single `->name()` applies to every `->in()` path, so it can't be
    // path-specific. The standard governs *test* comments only — scope the
    // frontend pass to colocated `*.test.ts(x)` so production TS/TSX (which may
    // legitimately carry an `// Act as a proxy`-style comment) is never scanned.
    $finders = [
        (new Finder)->files()->in($root.'/tests')->name('*.php'),
        (new Finder)->files()->in($root.'/resources/js')->name(['*.test.ts', '*.test.tsx']),
    ];

    $labelLines = [];

    foreach ($finders as $finder) {
        foreach ($finder as $file) {
            $relative = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getRealPath());
            $lines = preg_split('/\R/', (string) file_get_contents($file->getRealPath()));

            foreach ($lines as $index => $text) {
                // Collect case-INSENSITIVELY so wrong-case labels (`// arrange`,
                // `// ACT`) are gathered here, then flagged as offenders by the
                // case-SENSITIVE conforming regex below.
                if (preg_match('#^\s*//\s*(Arrange|Act|Assert)\b#i', $text) === 1) {
                    $labelLines[] = [
                        'file' => $relative,
                        'line' => $index + 1,
                        'text' => $text,
                    ];
                }
            }
        }
    }

    return $labelLines;
};

/**
 * Format an offender set as `file:line  <line>` strings for the failure message.
 *
 * @param  list<array{file: string, line: int, text: string}>  $offenders
 * @return list<string>
 */
$report = (fn (array $offenders): array => array_map(
    fn (array $o): string => sprintf('%s:%d  %s', $o['file'], $o['line'], trim((string) $o['text'])),
    $offenders,
));

it('has no AAA label line that uses "/" or collapses labels without " & "', function () use ($scanAaaLabelLines, $report): void {
    // Arrange
    // Require exactly ONE space after `//` (case-SENSITIVE): `//Arrange` (no
    // space) and wrong-case labels collected above are thus reported as
    // offenders. `// Arrange` / `// Act & Assert` remain the only valid forms.
    $conforming = '#^\s*// (Arrange|Act|Assert|Arrange & Act|Act & Assert)\s*$#';

    // Act
    $offenders = array_values(array_filter(
        $scanAaaLabelLines(),
        function (array $l) use ($conforming): bool {
            $isOffender = preg_match($conforming, (string) $l['text']) !== 1;
            // Strip the leading `//` opener so its own slashes aren't mistaken
            // for a label separator; then a remaining `/` or a no-` & ` label
            // adjacency is the collapse offence.
            $body = preg_replace('#^\s*//#', '', (string) $l['text']);
            $usesSlashOrCollapse = str_contains($body, '/')
                || preg_match('#^\s*(Arrange|Act|Assert)\s+(Arrange|Act|Assert)#', $body) === 1;

            return $isOffender && $usesSlashOrCollapse;
        },
    ));

    // Assert
    expect($report($offenders))->toBe([]);
});

it('has no AAA label line whose "&" join is anything but exactly " & "', function () use ($scanAaaLabelLines, $report): void {
    // Arrange
    // Require exactly ONE space after `//` (case-SENSITIVE): `//Arrange` (no
    // space) and wrong-case labels collected above are thus reported as
    // offenders. `// Arrange` / `// Act & Assert` remain the only valid forms.
    $conforming = '#^\s*// (Arrange|Act|Assert|Arrange & Act|Act & Assert)\s*$#';

    // Act
    $offenders = array_values(array_filter(
        $scanAaaLabelLines(),
        function (array $l) use ($conforming): bool {
            $isOffender = preg_match($conforming, (string) $l['text']) !== 1;

            return $isOffender && str_contains((string) $l['text'], '&');
        },
    ));

    // Assert
    expect($report($offenders))->toBe([]);
});

it('has no AAA label line carrying prose after the label', function () use ($scanAaaLabelLines, $report): void {
    // Arrange
    // Require exactly ONE space after `//` (case-SENSITIVE): `//Arrange` (no
    // space) and wrong-case labels collected above are thus reported as
    // offenders. `// Arrange` / `// Act & Assert` remain the only valid forms.
    $conforming = '#^\s*// (Arrange|Act|Assert|Arrange & Act|Act & Assert)\s*$#';

    // Act
    $offenders = array_values(array_filter(
        $scanAaaLabelLines(),
        function (array $l) use ($conforming): bool {
            $isOffender = preg_match($conforming, (string) $l['text']) !== 1;
            // Trailing-prose offenders are the leftover: non-conforming label
            // lines that aren't slash/collapse (sub-rule 1) or `&` (sub-rule 2).
            $body = preg_replace('#^\s*//#', '', (string) $l['text']);
            $usesSlashOrCollapse = str_contains($body, '/')
                || preg_match('#^\s*(Arrange|Act|Assert)\s+(Arrange|Act|Assert)#', $body) === 1;

            return $isOffender && ! $usesSlashOrCollapse && ! str_contains($body, '&');
        },
    ));

    // Assert
    expect($report($offenders))->toBe([]);
});
