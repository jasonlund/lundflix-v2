<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/**
 * Structure guard for `.claude/commands/review/claude.md`.
 *
 * The command is prose an agent reads at dispatch time, so nothing else in the
 * suite can see it drift: a deleted gate, an agent name resolving to no file, or
 * a dropped report heading all fail silently at review time — hours after the
 * edit that caused them. This scans the committed file for the structural
 * commitments its consumers depend on.
 *
 * Scope, deliberate: every assertion here checks that an INSTRUCTION IS PRESENT,
 * never that a model obeyed it. A word cap and a fail-closed rule are enforced by
 * the model at runtime; the only thing a static scan can guarantee is that the
 * instruction was not deleted.
 *
 * The report contract is the sharpest of these and the only cross-file one: the
 * headings and per-finding fields it checks are what
 * `.claude/commands/review/add.md` parses to build the PR review payload, so
 * dropping one silently empties a posted review.
 *
 * NB: every test carries a non-vacuous floor — the file was really read and is
 * really substantial — because a mistyped path yields an empty string, and an
 * empty string reads identically to a clean scan on several of these checks.
 */

/**
 * The committed command file, read from disk.
 */
$commandSource = fn (): string => (string) file_get_contents(
    // The Unit suite doesn't boot the app container, so resolve the repo root
    // from this file's location rather than base_path().
    dirname(__DIR__, 2).'/.claude/commands/review/claude.md',
);

/**
 * How many lines the command runs to, for the non-vacuous floor.
 *
 * Split on real newlines only, NOT `\R` — see the note in
 * `tests/Unit/AgentModelPolicyTest.php` for why that miscounts a file carrying
 * multi-byte characters.
 */
$lineCount = fn (string $source): int => count(preg_split('/\r\n|\n|\r/', $source) ?: []);

/**
 * Every `review-*` agent the command names, paired with the byte offset of that
 * mention so ordering can be judged by content position.
 *
 * @return list<array{name: string, offset: int}>
 */
$agentMentions = function (string $source): array {
    // A token with a slash on either side is a path segment
    // (`.claude/skills/review-pipeline/SKILL.md`), not an agent dispatch, so it
    // is excluded structurally rather than deny-listed by name. Corollary: name
    // the review-pipeline skill by its path, as the command already does — a
    // bare mention reads here as a dispatch to an agent that does not exist.
    preg_match_all(
        '#(?<![\w/-])review-[a-z0-9]+(?:-[a-z0-9]+)*(?![\w/-])#',
        $source,
        $matches,
        PREG_OFFSET_CAPTURE,
    );

    return array_map(
        fn (array $match): array => ['name' => $match[0], 'offset' => $match[1]],
        $matches[0],
    );
};

/**
 * The required commitments whose pattern finds no match, named the way a reader
 * states them rather than as the regex that looks for them.
 *
 * Every scan below reports its offenders this way on purpose: a bare
 * `expect($matched)->toBeTrue()` names nothing, so a failure says a check failed
 * without saying which commitment left the file.
 *
 * @param  array<string, string>  $required  human description => pattern
 * @return list<string>
 */
$missingPatterns = fn (string $source, array $required): array => collect($required)
    ->reject(fn (string $pattern): bool => preg_match($pattern, $source) === 1)
    ->keys()
    ->all();

describe('/review:claude deterministic gates', function () use ($commandSource, $lineCount): void {
    it('names every deterministic gate the pipeline runs', function () use ($commandSource, $lineCount): void {
        // The five gates produce facts rather than judgement, so a dropped one is
        // a whole class of defect the review stops catching at all.
        // Arrange
        $source = $commandSource();
        $gates = ['pint', 'rector', 'pest', 'eslint', 'vitest'];

        // Act
        $missing = collect($gates)
            ->reject(fn (string $gate): bool => Str::contains(Str::lower($source), $gate))
            ->values()
            ->all();

        // Assert
        expect($missing)->toBe([])
            ->and($source)->not->toBeEmpty()
            ->and($lineCount($source))->toBeGreaterThan(50);
    });
});

describe('/review:claude agent dispatch', function () use ($commandSource, $agentMentions, $lineCount): void {
    it('runs the skip gate before it dispatches any reviewer', function () use ($commandSource, $agentMentions, $lineCount): void {
        // The skip gate only saves anything if it runs first — a closed, draft or
        // already-reviewed PR must stop the pipeline before four reviewers are
        // paid for. Ordering is judged by content position, never by line number,
        // so inserting or reordering a phase cannot break this guard.
        // Arrange
        $source = $commandSource();
        $reviewers = ['review-compliance', 'review-bug-hunter'];

        // Act
        $mentions = collect($agentMentions($source));
        $skipGateAt = $mentions->firstWhere('name', 'review-skip-check')['offset'] ?? null;
        $firstReviewerAt = $mentions->whereIn('name', $reviewers)->min('offset');

        // Assert
        expect($skipGateAt)->toBeInt()
            ->and($firstReviewerAt)->toBeInt()
            ->and($skipGateAt)->toBeLessThan($firstReviewerAt)
            ->and($lineCount($source))->toBeGreaterThan(50);
    });

    it('dispatches only agents that exist under .claude/agents', function () use ($commandSource, $agentMentions, $lineCount): void {
        // A dispatch to a missing agent is silent: the harness has nothing to run,
        // that phase produces no findings, and the report still renders.
        // The mention floor is the non-vacuous half — a command naming no agents
        // at all has no dangling name either, which reads identically to a clean
        // roster.
        // Arrange
        $source = $commandSource();
        $agentDirectory = dirname(__DIR__, 2).'/.claude/agents/';

        // Act
        $dispatched = collect($agentMentions($source))->pluck('name')->unique()->values();

        // Assert
        expect($dispatched
            ->reject(fn (string $agent): bool => file_exists($agentDirectory.$agent.'.md'))
            ->map(fn (string $agent): string => sprintf('dispatches %s  →  no .claude/agents/%s.md', $agent, $agent))
            ->values()
            ->all())->toBe([])
            ->and($dispatched->all())->not->toBeEmpty()
            ->and($lineCount($source))->toBeGreaterThan(50);
    });
});

describe('/review:claude report contract', function () use ($commandSource, $missingPatterns, $lineCount): void {
    it('states the 400-word reviewer cap — presence of the instruction, not obedience to it', function () use ($commandSource, $missingPatterns, $lineCount): void {
        // A static scan cannot count the words a model actually writes; all it can
        // prove is that the cap was not deleted from the instructions.
        // Arrange
        $source = $commandSource();
        $required = ['a 400-word cap on each reviewer' => '#\b400[\s-]words?\b#i'];

        // Act
        $missing = $missingPatterns($source, $required);

        // Assert
        expect($missing)->toBe([])
            ->and($lineCount($source))->toBeGreaterThan(50);
    });

    it('states the fail-closed drop of unvalidated findings — presence of the instruction, not obedience to it', function () use ($commandSource, $missingPatterns, $lineCount): void {
        // Same limit as the cap above: presence only. The proximity pattern spans
        // newlines so the rule may wrap across the file's ~80-column prose, but
        // stops at a sentence end so an unrelated "drop" cannot pair with an
        // unrelated "validator" paragraphs away.
        // Arrange
        $source = $commandSource();
        $required = [
            'the fail-closed rule, by that name' => '#fail[-\s]closed#i',
            'a drop rule tied to validation' => '#\bdrop\w*\b[^.]{0,160}validat#is',
        ];

        // Act
        $missing = $missingPatterns($source, $required);

        // Assert
        expect($missing)->toBe([])
            ->and($lineCount($source))->toBeGreaterThan(50);
    });

    it('keeps every report section and finding field that /review:add parses', function () use ($commandSource, $missingPatterns, $lineCount): void {
        // Cross-file contract, verified against `.claude/commands/review/add.md`
        // (Phase 1 step 4 names the sections; the Phase 3 review-body template
        // names the fields). `/review:add` reads this report to build the PR
        // payload, so a renamed heading here posts an empty review over there —
        // with no error on either side.
        // The found-by entry accepts both spellings on purpose: this report writes
        // `**Found by:**` and add.md's posted comment writes `_Found by:`. What the
        // contract needs is that the attribution field survives, not which of the
        // two markers carries it.
        // Arrange
        $source = $commandSource();
        // Heading patterns are delimited by `~`, not `#`: a markdown heading opens
        // on the delimiter character itself.
        $required = [
            '## Spec — does it do what the ticket asked?' => '~^## Spec — does it do what the ticket asked\?$~m',
            '## Blocking Issues' => '~^## Blocking Issues~m',
            '## Should Fix' => '~^## Should Fix~m',
            '## Consider' => '~^## Consider~m',
            '**File:**' => '#\*\*File:\*\*#',
            '**Issue:**' => '#\*\*Issue:\*\*#',
            '**Violates:**' => '#\*\*Violates:\*\*#',
            '**Fix:**' => '#\*\*Fix:\*\*#',
            'a found-by field (`**Found by:**` or `_Found by:`)' => '#\*\*Found by:\*\*|_Found by:#',
        ];

        // Act
        $missing = $missingPatterns($source, $required);

        // Assert
        expect($missing)->toBe([])
            ->and($lineCount($source))->toBeGreaterThan(50);
    });
});
