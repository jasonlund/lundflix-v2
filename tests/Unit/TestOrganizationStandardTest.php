<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tests\Support\TestOrganizationScanner;

/*
 * Self-policing guard: every test file in the repository must follow the
 * suite's test-organization standard — tests grouped in describe() blocks, a
 * canonical top-level skeleton, lowercase non-"should" descriptions unique
 * within their group, unique describe labels per file, and helper function
 * names declared in exactly one file.
 *
 * SCAN SCOPE (deliberate):
 *
 * - PHP: EVERY `*.php` under `tests/` — Feature, Unit, Browser, Architecture,
 *   the meta-guards themselves, ExampleTest, `tests/Pest.php` and
 *   `tests/TestCase.php`, and `tests/Support/`. Nothing is exempted by path.
 *   A file that declares no tests carries no `it(`/`describe(` construct, so
 *   every check but the helper-name one is naturally inert on it — but it IS
 *   scanned for helper names, which is the whole point: `tests/Pest.php` is
 *   where global helpers live, and a per-file helper that shadows one of them
 *   is exactly the collision the rule exists to catch.
 * - Frontend: `*.test.ts` / `*.test.tsx` under `resources/js/` — the grouping
 *   and description-form checks ONLY, including duplicate describe labels,
 *   since those key off `describe(`/`it(`, identical in both languages. The
 *   skeleton-order and helper-name checks key off PHP-shaped
 *   `declare`/`use`/`function` source patterns that do not exist in a Vitest
 *   module, so running them over TSX would assert nothing. Excluding them is a
 *   decision, not an oversight.
 *
 * NB: example offender strings live in PHP string literals (never at column 0)
 * so this file's own scanner never trips on its own examples.
 */

// The Unit suite doesn't boot the app container, so resolve the repo root from
// this file's location rather than base_path().
$root = dirname(__DIR__, 2);

/** @var array<string, array<string, string>> $readCache */
$readCache = [];

/**
 * Every file under a repo-relative directory matching the given Finder name
 * patterns, keyed by repo-relative path. Memoized per directory+patterns: each
 * of the rules below asks for the same sets, and re-walking the tree once per
 * assertion is the difference between one scan and eight.
 *
 * @param  list<string>  $patterns
 * @return array<string, string>
 */
$read = function (string $directory, array $patterns) use ($root, &$readCache): array {
    $key = $directory.'|'.implode(',', $patterns);

    if (! isset($readCache[$key])) {
        $sources = [];

        foreach ((new Finder)->files()->in($root.DIRECTORY_SEPARATOR.$directory)->name($patterns) as $file) {
            $path = (string) $file->getRealPath();
            $sources[Str::after($path, $root.DIRECTORY_SEPARATOR)] = (string) file_get_contents($path);
        }

        $readCache[$key] = $sources;
    }

    return $readCache[$key];
};

/**
 * Every scanned PHP source, keyed by repo-relative path.
 *
 * @return array<string, string>
 */
$phpSources = fn (): array => $read('tests', ['*.php']);

/**
 * Every scanned frontend test source, keyed by repo-relative path.
 *
 * @return array<string, string>
 */
$frontendSources = fn (): array => $read('resources/js', ['*.test.ts', '*.test.tsx']);

/**
 * The trimmed source text of a 1-indexed line, for the failure message.
 */
$lineText = (fn (string $source, int $line): string => Str::trim(
    (preg_split('/\R/', $source) ?: [])[$line - 1] ?? '',
));

describe('test grouping and file skeleton', function () use ($phpSources, $frontendSources, $lineText): void {
    it('has no test declared outside a describe() group', function () use ($phpSources, $frontendSources, $lineText): void {
        // Arrange
        $sources = array_merge($phpSources(), $frontendSources());

        // Act
        $offenders = [];

        foreach ($sources as $file => $source) {
            foreach (TestOrganizationScanner::ungroupedTests($source) as $line) {
                $offenders[] = sprintf('%s:%d  %s', $file, $line, $lineText($source, $line));
            }
        }

        // Assert
        expect($offenders)->toBe([]);
    });

    it('has no file whose top-level constructs are out of canonical order', function () use ($phpSources, $lineText): void {
        // Arrange
        $sources = $phpSources();

        // Act
        $offenders = [];

        foreach ($sources as $file => $source) {
            foreach (TestOrganizationScanner::skeletonOffenders($source) as $offender) {
                $offenders[] = sprintf(
                    '%s:%d  [%s] %s',
                    $file,
                    $offender['line'],
                    $offender['kind'],
                    $lineText($source, $offender['line']),
                );
            }
        }

        // Assert
        expect($offenders)->toBe([]);
    });
});

describe('test descriptions and helper names', function () use ($phpSources, $frontendSources, $lineText): void {
    it('has no test description breaking the naming form and no duplicate describe label in a file', function () use ($phpSources, $frontendSources, $lineText): void {
        // Arrange
        $sources = array_merge($phpSources(), $frontendSources());

        // Act
        $offenders = [];

        foreach ($sources as $file => $source) {
            foreach (TestOrganizationScanner::descriptionOffenders($source) as $offender) {
                $offenders[] = sprintf('%s:%d  %s', $file, $offender['line'], $lineText($source, $offender['line']));
            }

            foreach (TestOrganizationScanner::duplicateDescribeLabels($source) as $duplicate) {
                $offenders[] = sprintf('%s:%d  %s', $file, $duplicate['line'], $lineText($source, $duplicate['line']));
            }
        }

        // Assert
        expect($offenders)->toBe([]);
    });

    it('has no helper function name declared in more than one file', function () use ($phpSources): void {
        // Arrange
        $sources = $phpSources();

        // Act
        $duplicates = TestOrganizationScanner::duplicateHelperNames($sources);

        // Assert
        $offenders = array_map(
            fn (array $duplicate): string => sprintf('%s:%d  %s()', $duplicate['file'], $duplicate['line'], $duplicate['name']),
            $duplicates,
        );

        expect($offenders)->toBe([]);
    });
});
