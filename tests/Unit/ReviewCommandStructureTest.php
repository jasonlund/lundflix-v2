<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Support\ToolkitFiles;

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
 * File reading, line counting and the named-pattern check come from
 * `Tests\Support\ToolkitFiles`, shared with the other toolkit guards.
 *
 * NB: every test carries a non-vacuous floor — the file was really read and is
 * really substantial — because a mistyped path yields an empty string, and an
 * empty string reads identically to a clean scan on several of these checks.
 */

/**
 * The committed command file, read from disk.
 */
$commandSource = fn (): string => ToolkitFiles::read('.claude/commands/review/claude.md');

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
 * The sections `.claude/skills/review-pipeline/SKILL.md` publishes, each paired
 * with the prose spellings a dispatcher or an agent uses to name it.
 *
 * Matched on prose rather than on the heading text, because neither side quotes
 * a heading: the command writes "the finding format, severity definitions", the
 * agent writes "Smell Baseline and **Convention Override Rule**". What the guard
 * compares is which sections each side NAMES, so the patterns have to read the
 * way people write them.
 *
 * @var array<string, string> section name => pattern that recognises a mention
 */
$contractSections = [
    'Finding Format' => '~finding format~i',
    'Severity Definitions' => '~severity definitions?~i',
    'Simplified Technical English' => '~simplified technical english|ASD-STE100~i',
    'Smell Baseline' => '~smell baseline~i',
    'Convention Override Rule' => '~convention override rule~i',
];

/**
 * Which contract sections a passage names, in contract order.
 *
 * @param  array<string, string>  $sections
 * @return list<string>
 */
$sectionsNamedIn = fn (string $passage, array $sections): array => collect($sections)
    ->filter(fn (string $pattern): bool => preg_match($pattern, $passage) === 1)
    ->keys()
    ->all();

/**
 * The `## Phase 3` block of the command, from its heading to the next `## ` one.
 *
 * Empty when the heading no longer matches — the floor in each test turns that
 * into a loud failure rather than an empty-set comparison that passes.
 */
$phaseThreeBlock = (fn (string $source): string => preg_match('~^## Phase 3:.*?(?=^## |\z)~ms', $source, $matches) === 1 ? $matches[0] : '');

/**
 * The paragraph of Phase 3 that hands the reviewers their inputs — everything
 * from `Pass each one` to the blank line that closes the paragraph.
 */
$passList = (fn (string $phase): string => preg_match('~^Pass each one\b.*?(?=\n[ \t]*\n|\z)~ms', $phase, $matches) === 1 ? $matches[0] : '');

/**
 * The dispatch bullets of Phase 3 — the per-agent briefs written above the
 * pass-list, where the command states what each reviewer works from.
 *
 * A bullet carries its indented continuation lines, because the file wraps at
 * ~80 columns and the rubric half of a brief usually lands on the second line.
 * Taking the opener alone reads the bullets as saying less than they do.
 */
$dispatchBullets = function (string $phase): string {
    $brief = Str::before($phase, 'Pass each one');
    $inBullet = false;

    return collect(ToolkitFiles::splitLines($brief))
        ->filter(function (string $line) use (&$inBullet): bool {
            if (preg_match('~^\s*[-*]\s~', $line) === 1) {
                return $inBullet = true;
            }

            return $inBullet = $inBullet && preg_match('~^\s+\S~', $line) === 1;
        })
        ->implode("\n");
};

/**
 * The `## Input` section of an agent file — the agent's own statement of what it
 * must be handed.
 */
$agentInputSection = function (string $agent): string {
    $source = ToolkitFiles::read('.claude/agents/'.$agent.'.md');

    return preg_match('~^## Input\s*$.*?(?=^## |\z)~ms', $source, $matches) === 1 ? $matches[0] : '';
};

describe('/review:claude deterministic gates', function () use ($commandSource): void {
    it('names every deterministic gate the pipeline runs', function () use ($commandSource): void {
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
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan(50);
    });
});

describe('/review:claude agent dispatch', function () use ($commandSource, $agentMentions): void {
    it('runs the skip gate before it dispatches any reviewer', function () use ($commandSource, $agentMentions): void {
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
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan(50);
    });

    it('dispatches only agents that exist under .claude/agents', function () use ($commandSource, $agentMentions): void {
        // A dispatch to a missing agent is silent: the harness has nothing to run,
        // that phase produces no findings, and the report still renders.
        // The mention floor is the non-vacuous half — a command naming no agents
        // at all has no dangling name either, which reads identically to a clean
        // roster.
        // Arrange
        $source = $commandSource();
        $agentDirectory = ToolkitFiles::path('.claude/agents').'/';

        // Act
        $dispatched = collect($agentMentions($source))->pluck('name')->unique()->values();

        // Assert
        expect($dispatched
            ->reject(fn (string $agent): bool => file_exists($agentDirectory.$agent.'.md'))
            ->map(fn (string $agent): string => sprintf('dispatches %s  →  no .claude/agents/%s.md', $agent, $agent))
            ->values()
            ->all())->toBe([])
            ->and($dispatched->all())->not->toBeEmpty()
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan(50);
    });
});

describe('/review:claude report contract', function () use ($commandSource): void {
    it('states the 400-word reviewer cap — presence of the instruction, not obedience to it', function () use ($commandSource): void {
        // A static scan cannot count the words a model actually writes; all it can
        // prove is that the cap was not deleted from the instructions.
        // Arrange
        $source = $commandSource();
        $required = ['a 400-word cap on each reviewer' => '#\b400[\s-]words?\b#i'];

        // Act
        $missing = ToolkitFiles::missingPatterns($source, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan(50);
    });

    it('states the fail-closed drop of unvalidated findings — presence of the instruction, not obedience to it', function () use ($commandSource): void {
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
        $missing = ToolkitFiles::missingPatterns($source, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan(50);
    });

    it('keeps every report section and finding field that /review:add parses', function () use ($commandSource): void {
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
            // The section every gate NIT lands in — Pint violations and ESLint
            // warnings. It is the one severity section produced entirely by
            // machines, so nobody misses it by hand when it stops being posted.
            '## Nits' => '~^## Nits~m',
            '**File:**' => '#\*\*File:\*\*#',
            '**Issue:**' => '#\*\*Issue:\*\*#',
            '**Violates:**' => '#\*\*Violates:\*\*#',
            '**Fix:**' => '#\*\*Fix:\*\*#',
            'a found-by field (`**Found by:**` or `_Found by:`)' => '#\*\*Found by:\*\*|_Found by:#',
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($source, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan(50);
    });
});

describe('/review:claude Phase 3 dispatch contract', function () use ($commandSource, $contractSections, $sectionsNamedIn, $phaseThreeBlock, $passList, $dispatchBullets, $agentInputSection): void {
    it('hands every reviewer the contract sections its own Input section expects', function () use ($commandSource, $contractSections, $sectionsNamedIn, $phaseThreeBlock, $passList, $agentInputSection): void {
        // A reviewer runs in isolated context: it can only read a contract section
        // the dispatcher named for it. So a section an agent's Input declares and
        // Phase 3 never passes is a rubric the agent is told to apply and never
        // receives — silent on both sides, because neither file reads the other.
        // The direction is one-way on purpose: agent-expects ⊆ command-passes.
        // Passing an agent more than it asks for is harmless surplus, so
        // review-bug-hunter omitting the Convention Override Rule that Phase 3
        // hands it is not an offence here; asking for what is never passed is.
        // Arrange
        $phase = $phaseThreeBlock($commandSource());
        $reviewers = ['review-compliance', 'review-bug-hunter'];

        // Act
        $passed = $sectionsNamedIn($passList($phase), $contractSections);

        // Assert
        expect(collect($reviewers)
            ->flatMap(fn (string $agent): array => collect($sectionsNamedIn($agentInputSection($agent), $contractSections))
                ->diff($passed)
                ->map(fn (string $section): string => sprintf(
                    '%s expects "%s", claude.md Phase 3 does not pass it',
                    $agent,
                    $section,
                ))
                ->all())
            ->values()
            ->all())->toBe([])
            ->and($phase)->not->toBeEmpty()
            ->and($passList($phase))->not->toBeEmpty();
    });

    it('names a rubric it also passes', function () use ($commandSource, $contractSections, $sectionsNamedIn, $phaseThreeBlock, $passList, $dispatchBullets): void {
        // The general form of the same defect, read off one file alone: Phase 3's
        // dispatch bullets tell each reviewer what it works from, and the pass-list
        // below them is the only thing the reviewer actually gets. A section the
        // bullets name as a rubric and the pass-list omits is the command promising
        // a basis it does not deliver — and it contradicts itself two lines apart,
        // so nothing outside this file has to change for it to be wrong.
        // Arrange
        $phase = $phaseThreeBlock($commandSource());
        $bullets = $dispatchBullets($phase);

        // Act
        $promised = $sectionsNamedIn($bullets, $contractSections);

        // Assert
        expect(collect($promised)
            ->diff($sectionsNamedIn($passList($phase), $contractSections))
            ->map(fn (string $section): string => sprintf(
                'Phase 3 names "%s" as a reviewer rubric, the pass-list omits it',
                $section,
            ))
            ->values()
            ->all())->toBe([])
            ->and($bullets)->not->toBeEmpty()
            ->and($passList($phase))->not->toBeEmpty();
    });
});
