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

    $finder = (new Finder)
        ->files()
        ->in([$root.'/tests', $root.'/resources/js'])
        ->name(['*.php', '*.ts', '*.tsx']);

    $labelLines = [];

    foreach ($finder as $file) {
        $relative = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getRealPath());
        $lines = preg_split('/\R/', (string) file_get_contents($file->getRealPath()));

        foreach ($lines as $index => $text) {
            if (preg_match('#^\s*//\s*(Arrange|Act|Assert)\b#', $text) === 1) {
                $labelLines[] = [
                    'file' => $relative,
                    'line' => $index + 1,
                    'text' => $text,
                ];
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
    $conforming = '#^\s*//\s*(Arrange|Act|Assert)( & (Arrange|Act|Assert))*\s*$#';

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
    $conforming = '#^\s*//\s*(Arrange|Act|Assert)( & (Arrange|Act|Assert))*\s*$#';

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
    $conforming = '#^\s*//\s*(Arrange|Act|Assert)( & (Arrange|Act|Assert))*\s*$#';

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
