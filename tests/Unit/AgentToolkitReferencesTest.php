<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * Drift guard for the `.claude/` agent toolkit's internal reference graph.
 *
 * Skills and commands route work by naming each other's paths in prose. A path
 * that stops resolving — a renamed skill, a deleted command — produces no error:
 * the agent simply fails to load the file and carries on without the rules it
 * was supposed to read. Nothing else in the suite can see that.
 *
 * The scan root is `.claude/` — that is where the routing prose lives — but the
 * *targets* it checks are wider: a skill naming `.ai/guidelines/project.md` or
 * `docs/agents/domain.md` dangles exactly as loudly as one naming a sibling
 * skill, so every prefix the toolkit points at is checked.
 *
 * Scope limit, deliberate: this guard answers `file_exists` and nothing more, so
 * it catches a path that stopped resolving, never a pointer that dangles on
 * *content* (a file that exists but no longer carries the rule cited from it).
 * Scanning more source files buys no coverage of that second failure and only
 * makes a green run look like it did — fix content drift with a check that reads
 * the target, not by widening the Finder below.
 *
 * NB: the placeholder-bearing paths this file must ignore (`{Domain}`, `*.md`)
 * are excluded by the character class rather than a deny-list, so a new
 * placeholder style can never silently become an offender.
 */

/**
 * Every `.claude/…`, `.ai/…` or `docs/…` path referenced in the toolkit's
 * markdown, paired with where it was referenced from.
 *
 * @return list<array{file: string, line: int, path: string}>
 */
$scanReferences = function (): array {
    // The Unit suite doesn't boot the app container, so resolve the repo root
    // from this file's location rather than base_path().
    $root = dirname(__DIR__, 2);

    $finder = (new Finder)->files()->in($root.'/.claude')->name('*.md');

    $references = [];

    foreach ($finder as $file) {
        $relative = Str::replace($root.DIRECTORY_SEPARATOR, '', $file->getRealPath());
        // Split on real newlines only, NOT `\R`: without the `u` modifier PCRE
        // treats the 0x85 byte as NEL, and 0x85 is a UTF-8 continuation byte of
        // characters these files contain, which would shift every later line
        // number by one and misreport file:line.
        $lines = preg_split('/\r\n|\n|\r/', (string) file_get_contents($file->getRealPath()));

        foreach ($lines as $index => $text) {
            // Three deliberate narrowings, each closing a false positive:
            //   - the lookbehind drops `~/.claude/…` and any absolute path, which
            //     are real files but not resolvable against the repo root;
            //   - the trailing extension requires a *file*, so prose like "a
            //     docs/config edit" is not read as a path;
            //   - the class excludes `{`, `}` and `*`, so templated paths
            //     (`app/Domains/{Domain}/…`, `docs/agents/*.md`) never match.
            // A file is what an agent actually loads, so a file is what drifts
            // silently — a bare directory mention has no load to fail.
            preg_match_all('#(?<![~/\w])(?:\.claude|\.ai|docs)/[A-Za-z0-9._\-/]+\.[A-Za-z0-9]{2,4}\b#', $text, $matches);

            foreach ($matches[0] as $path) {
                $references[] = [
                    'file' => $relative,
                    'line' => $index + 1,
                    'path' => $path,
                ];
            }
        }
    }

    return $references;
};

describe('toolkit reference graph', function () use ($scanReferences): void {
    it('references only toolkit paths that exist', function () use ($scanReferences): void {
        // Arrange
        $root = dirname(__DIR__, 2);

        // Act
        $dangling = collect($scanReferences())
            ->reject(fn (array $r): bool => file_exists($root.'/'.$r['path']))
            ->map(fn (array $r): string => sprintf('%s:%d  →  %s', $r['file'], $r['line'], $r['path']))
            ->unique()
            ->values()
            ->all();

        // Assert
        expect($dangling)->toBe([]);
    });

    it('actually scans the toolkit rather than silently finding nothing', function () use ($scanReferences): void {
        // A guard that scans an empty set passes forever. Pin a floor so a broken
        // finder or regex fails here instead of masquerading as a clean graph.
        // Arrange
        $references = $scanReferences();

        // Act
        $files = collect($references)->pluck('file')->unique();

        // Assert
        expect($references)->not->toBeEmpty()
            ->and($files->count())->toBeGreaterThan(5);
    });
});
