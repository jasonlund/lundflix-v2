<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tests\Support\ToolkitFiles;

/**
 * Drift guard for the one canonical procedure an agent follows when it asks the
 * user a question.
 *
 * The asking format used to be restated in every skill and command that asks —
 * six copies of a round template, none of them the source of truth. Copies drift
 * silently: an agent reading a stale one still renders a round, the user still
 * answers, and nothing reports that the format it used was superseded. So the
 * procedure is written ONCE in `.ai/guidelines/project.md` and every asking site
 * points at it by name.
 *
 * Three commitments, all static: the guideline source carries the **section**
 * (heading, round template, silence contract), the **generated** agent files
 * carry its anchor, and every pinned **asking site** cites that anchor instead of
 * restating a format of its own.
 *
 * Four more make the ban enforceable rather than merely stated: the picker tool
 * is sanctioned nowhere on the instruction surface, no site keeps a private copy
 * of the round template, `/map` names the rule among the standing conventions it
 * routes to, and the `PreToolUse` guard that refuses the picker is both
 * registered in `.claude/settings.json` and documented in the hooks README. The
 * last one is the one that would otherwise pass on a script nobody wired in.
 *
 * The last three close the one hole the rest would leave open. A rule stated as an
 * **always** survives exactly as long as no site is allowed an exception, and
 * `/review:process` had one: its `DISCUSS` bucket existed precisely so an item
 * would NOT stand on silence, and its Phase 2 gate held the whole run until the
 * user named every such number. Two contradictory contracts then govern the same
 * reply — silence locks, silence waits — and which one an agent follows is decided
 * by which file it read last. So the exception collapses: an item lands in
 * `APPROVE` or `SKIP` by the lean already written on it, four recommendation
 * buckets become three, and nothing in that command waits for an answer.
 *
 * Four more split the one thing all of that quietly assumed: that there is a
 * single asking FORMAT. There is one contract — durable numbering, a
 * recommendation per item, silence accepts — and two renderings of it. Planning
 * work renders a **decision round** (the `Qn` template, defined in the guideline
 * source and nowhere else); review feedback renders a **disposition list**
 * (`N. [SEVERITY]` over a bare `path:line`, with `Issue`/`Fix`/`Why` slots and a
 * lean), whose shape stays in `/review:process`, its only user. Conflating the
 * two wrote a real contradiction into that command: it cites "the canonical
 * format" and then shows a different one — and it CANNOT show that one, because
 * the round glyph is single-sourced by the guard below. So the guideline section
 * has to state the contract apart from its renderings, no site may claim a single
 * canonical format, every site must name the rendering it uses, and the gate's
 * item numbers must be durable across rounds rather than scoped to one run.
 *
 * Scope, deliberate: every assertion checks that TEXT IS PRESENT, never that an
 * agent obeyed it. Whether a model actually asks in the canonical shape is a
 * runtime property no static scan can reach; what a scan can guarantee is that
 * the instruction exists in one place and that every site sends the reader there.
 *
 * File reading, line splitting and the named-pattern checks come from
 * `Tests\Support\ToolkitFiles`, shared with the other toolkit guards.
 *
 * NB: the round template's glyphs live in PHP string literals as escaped
 * codepoints, never as literal characters, so a later guard forbidding those
 * glyphs under `.claude/` can never read this file's patterns as an offence.
 */

/**
 * The anchor every asking site must cite — the guideline section's own heading
 * text, so a reader following the citation lands on the procedure itself.
 */
$anchor = 'Asking the user a question';

/**
 * Rendering A of the contract, by the name a site cites it under.
 *
 * The two-line `Qn` template — one numbered question, one recommendation. Every
 * planning site renders this way, and its shape stays defined in the guideline
 * source and nowhere else, which is what the glyph sweep below enforces.
 */
$decisionRound = 'decision round';

/**
 * Rendering B, the shape review feedback arrives in.
 *
 * `N. [SEVERITY]` over a bare `path:line`, with source attribution and
 * `Issue`/`Fix`/`Why` slots closing on a lean. It obeys the same contract —
 * durable numbers, a recommendation per item, silence accepts — and none of that
 * fits two lines, so its shape is written where its only user is.
 */
$dispositionList = 'disposition list';

/**
 * The phrase that presumes one rendering where there are two.
 *
 * Held as a literal here for the same reason `$pickerTool` is: the sweep it feeds
 * reads the instruction surface only, and `tests/` is not on it.
 */
$singleFormatClaim = 'canonical format';

/**
 * Every skill and command that asks the user a question, by repo-relative path.
 *
 * Named explicitly rather than swept: "this file asks the user something" is a
 * property of what the prose does, not of where it sits, so a Finder would either
 * miss a site or drag in files that never ask. Adding an asking site means adding
 * it here — which is the point, since an unpinned site is exactly the copy that
 * drifts.
 *
 * @var list<string>
 */
$askingSites = [
    '.claude/skills/plan-draft/SKILL.md',
    '.claude/skills/plan-breakdown/SKILL.md',
    '.claude/skills/plan-slices/SKILL.md',
    '.claude/skills/tdd/SKILL.md',
    '.claude/commands/review/process.md',
    '.claude/commands/plan/run.md',
];

/**
 * The two files `php artisan boost:install --guidelines` writes the guideline
 * source into, and which every agent actually loads.
 *
 * @var list<string>
 */
$generatedGuidelineFiles = [
    'CLAUDE.md',
    'AGENTS.md',
];

/**
 * The smallest line count a real asking site can plausibly have.
 *
 * A file emptied or renamed out from under the roster reads back as one blank
 * line, which passes a `is it non-empty` check and reports nothing — so the floor
 * sits above that rather than at zero.
 */
$minimumSiteLines = 10;

/**
 * One `## ` section of a markdown file, from its heading to the next one.
 *
 * The empty string when the heading is absent, so the in-section patterns fail by
 * name instead of the extraction throwing.
 *
 * Sectioning rather than reading the whole file matters where a guard asserts a
 * name is listed *somewhere specific*: a passing mention elsewhere in the same
 * document would otherwise satisfy a check that meant "under this heading".
 */
$sectionOf = function (string $file, string $heading): string {
    $source = ToolkitFiles::read($file);
    $pattern = sprintf('~^##\s+%s\s*$.*?(?=^##\s|\z)~ms', preg_quote($heading, '~'));

    return preg_match($pattern, $source, $matches) === 1 ? $matches[0] : '';
};

/**
 * One `## ` section of the guideline source, the file most of these checks read.
 */
$guidelineSection = fn (string $heading): string => $sectionOf('.ai/guidelines/project.md', $heading);

/**
 * The picker tool this repo bans outright, by its exact tool name.
 *
 * Naming the literal here is safe: the scan below covers the instruction surface
 * only, and `tests/` is not on it — so this guard can never read itself as an
 * offender.
 */
$pickerTool = 'AskUserQuestion';

/**
 * The `PreToolUse` guard that refuses the picker, by repo-relative path.
 */
$hookScript = '.claude/hooks/block-ask-user-question.sh';

/**
 * Every line of the **instruction surface** — the prose an agent reads as orders.
 *
 * Deliberately narrower than `.claude/`. `.claude/hooks/` is machinery, and
 * machinery has to name what it blocks: the hooks README's table row carries the
 * literal tool name for the same reason the destructive-git row carries
 * `reset --hard`. Excluding the one README by filename would rot the moment the
 * file moved or a second hook needed the same latitude; excluding the whole
 * machinery directory states the reason instead — a script and its docs *describe*
 * the ban, they do not instruct an agent to use it.
 *
 * @return list<array{file: string, line: int, text: string}>
 */
$instructionSurfaceLines = fn (): array => ToolkitFiles::scanLines(
    ...collect(['.claude/skills', '.claude/commands', '.claude/agents'])
        ->map(fn (string $root): Finder => (new Finder)->files()->in(ToolkitFiles::path($root))->name('*.md'))
        ->all(),
);

/**
 * The smallest number of instruction-surface lines a real sweep reads back.
 *
 * A finder that resolved nothing reports no offenders, which is indistinguishable
 * from a clean surface — so the sweep is pinned non-vacuous.
 */
$minimumSurfaceLines = 1000;

/**
 * The one asking site that used to exempt itself from the silence contract, by
 * repo-relative path.
 *
 * Pinned apart from `$askingSites` because the roster above asks a different
 * question of it. There, `process.md` is one of six files that must CITE the
 * canonical procedure; here it is the single file that must no longer CONTRADICT
 * it — a citation and an exception can sit in one document without either one
 * reporting the other.
 */
$reviewGate = '.claude/commands/review/process.md';

/**
 * Every line of the review gate, paired with its line number.
 *
 * A whole-file `survivingPatterns` call reports that a forbidden token is still
 * in the file; it cannot say where, and the token this guard bans is scattered
 * over a dozen lines across five sections. So the token sweep reads lines and
 * hands back the `file:line  →  text` list a reader can work down and delete.
 *
 * @return list<array{file: string, line: int, text: string}>
 */
$reviewGateLines = fn (): array => ToolkitFiles::scanLines(
    (new Finder)
        ->files()
        ->in(ToolkitFiles::path(dirname($reviewGate)))
        ->name(basename($reviewGate))
        ->depth(0),
);

/**
 * The smallest line count the review gate can plausibly read back.
 *
 * Every check below reports an empty offender list when the file is clean AND
 * when the read resolved nothing — a moved or renamed command reads identically
 * to a collapsed bucket. The floor sits under the command's real length so the
 * two cannot be confused.
 */
$minimumGateLines = 300;

/**
 * Which of the two renderings a pinned asking site is expected to name.
 *
 * Derived from the roster rather than restating it as a second list: `$askingSites`
 * stays the one place a site is written down, and this only answers which shape
 * that site renders. Review feedback is the disposition list; every other asking
 * site runs a decision round. Declared here rather than beside the roster because
 * it leans on `$reviewGate` to say which is which.
 */
$renderingOf = fn (string $file): string => $file === $reviewGate ? $dispositionList : $decisionRound;

describe('canonical question procedure', function () use ($anchor, $guidelineSection, $decisionRound, $dispositionList): void {
    it('writes the whole asking procedure once in the guideline source', function () use ($anchor, $guidelineSection): void {
        // The section is the single source of truth, so it has to carry the whole
        // procedure — not just a heading the other files can point at. Three parts:
        // the heading itself, the verbatim two-line round template an agent copies,
        // and the silence contract that makes a recommendation on every line worth
        // writing. Drop the silence half and the format still renders, but every
        // question becomes mandatory to answer — the exact cost the round shape
        // exists to avoid.
        // The template glyphs are matched as escaped codepoints under `u`: the
        // question mark is U+2753 and the arrow U+27A1, and a byte-wise pattern
        // would split them mid-character.
        // Arrange
        $section = $guidelineSection($anchor);
        $required = [
            'a top-level `## '.$anchor.'` heading' => '~^##\s+'.preg_quote($anchor, '~').'\s*$~m',
            'the round template\'s numbered question line' => '~^\x{2753}\s*\*\*Q1\*\*~mu',
            'the round template\'s recommendation line' => '~^\x{27A1}~mu',
            'the silence contract, naming an unanswered question' => '~unanswered~i',
            'silence locking that question at its recommendation' => '~recommendation~i',
            'the literal `nt` accept token' => '~`nt`~',
            'the empty message as the other accept token' => '~empty message~i',
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($section, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(Str::length($section))->toBeGreaterThan(200);
    });

    it('states the contract apart from the two renderings that carry it', function () use ($anchor, $guidelineSection, $decisionRound, $dispositionList): void {
        // The section conflates the contract with ONE rendering of it: it defines the
        // `Qn` round template and then governs every asking site, including the one
        // whose items cannot be written that way. A review item carries a
        // `[SEVERITY]` tag, a bare `path:line`, who flagged it, `Issue`/`Fix`/`Why`
        // slots and a lean — none of which fits two lines. So `/review:process` cites
        // "the canonical format" and then shows a different one, and it cannot show
        // the cited one, because the sweep below single-sources the round glyph. Two
        // contradictory instructions in one file, with nothing to report it.
        // The split is what fixes that: the contract under its own `### ` heading,
        // then both renderings by name — the decision round, whose shape stays here,
        // and the disposition list, whose shape stays with its only user.
        // Clause 1 is pinned directly because it is the clause the review gate breaks
        // today: a number is durable for the whole session, so a later round continues
        // the sequence rather than restarting it.
        // Arrange
        $section = $guidelineSection($anchor);
        $required = [
            'a `### ` sub-heading for the contract itself, apart from any rendering' => '~^###\s+[^\n]*\bcontract\b~mi',
            'rendering A, named the "'.$decisionRound.'"' => '~\b'.preg_quote($decisionRound, '~').'\b~i',
            'rendering B, named the "'.$dispositionList.'"' => '~\b'.preg_quote($dispositionList, '~').'\b~i',
            'clause 1: numbering durable across rounds, continued rather than restarted' => '~\bnumber(?:ing|s)?\b[^\n]{0,160}\b(?:continu\w*|durable|never restarts?|not restart\w*)\b~i',
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($section, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(Str::length($section))->toBeGreaterThan(200);
    });
});

describe('generated agent guideline files', function () use ($anchor, $generatedGuidelineFiles, $guidelineSection): void {
    it('carries the whole section into both generated agent files', function () use ($anchor, $generatedGuidelineFiles, $guidelineSection): void {
        // `CLAUDE.md` and `AGENTS.md` are regenerated from the guideline source by
        // `php artisan boost:install --guidelines`, and they — not the source — are
        // what an agent loads at session start. Skipping the regeneration leaves the
        // rule committed in the repo and out of every agent's context, with no error
        // on either side: the procedure is there for a human reading `project.md`
        // and invisible to the agents it governs. That silent half-landing is the
        // failure this ticket exists to stop, so the generated copies are pinned
        // rather than assumed.
        // The whole section is compared, never the heading alone. A heading-only check
        // reports nothing when the BODY goes stale — which is the likelier half to
        // rot, because a section gets rewritten far more often than it gets renamed.
        // This very branch is the proof: the body was rewritten from one round
        // template into a contract plus two renderings under an untouched heading, so
        // a heading-only guard would have passed on generated copies still teaching
        // the superseded rule.
        // Both sides are blank-line-normalized before the compare, because
        // `boost:install` is NOT byte-preserving: it reads a `#` inside a fenced block
        // as a heading and inserts the blank line a heading would need. Compare
        // `.ai/guidelines/project.md`'s `resources/js/` tree against the generated copy
        // to see it — same lines, one blank line added after each `#`-bearing one. This
        // section survives a raw compare today only because it happens to contain no
        // `#`, so a raw compare would sit here until someone added a shell snippet or a
        // commented path and then fail on a CORRECTLY regenerated tree, with no fix
        // available but deleting the assertion. Collapsing blank runs costs nothing the
        // guard needs: a stale body still differs in its words.
        // Arrange
        $collapseBlankLines = fn (string $text): string => (string) preg_replace('~\n{2,}~', "\n", $text);
        $section = $collapseBlankLines($guidelineSection($anchor));
        $sources = collect($generatedGuidelineFiles)
            ->mapWithKeys(fn (string $file): array => [$file => ToolkitFiles::read($file)]);

        // Act
        $stale = $sources
            ->reject(fn (string $source): bool => Str::contains($collapseBlankLines($source), $section))
            ->keys()
            ->all();

        // Assert
        expect($stale)->toBe([])
            ->and(Str::length($section))->toBeGreaterThan(200)
            ->and($sources->map(fn (string $source): int => ToolkitFiles::lineCount($source))->min())
            ->toBeGreaterThan(100);
    });
});

describe('pinned asking sites', function () use ($anchor, $askingSites, $minimumSiteLines, $renderingOf): void {
    it('cites the canonical anchor from every asking site', function () use ($anchor, $askingSites): void {
        // A site that restates the format instead of citing it is a copy, and a copy
        // is what drifts. The citation is the whole point: it is the only thing that
        // makes the guideline section the source of truth rather than a seventh
        // version of the same prose.
        // The two failure modes are reported apart on purpose. A missing file means
        // the roster below is stale and this guard is checking less than it claims;
        // a present file with no citation means the rewrite skipped a site. They
        // need opposite fixes, so a reader has to be told which one happened.
        // Arrange
        $sites = collect($askingSites);

        // Act
        $offenders = $sites
            ->map(function (string $file) use ($anchor): ?string {
                if (! file_exists(ToolkitFiles::path($file))) {
                    return $file.'  →  file is missing';
                }

                return Str::contains(ToolkitFiles::read($file), $anchor)
                    ? null
                    : $file.'  →  present, but cites no "'.$anchor.'" anchor';
            })
            ->filter()
            ->values()
            ->all();

        // Assert
        expect($offenders)->toBe([]);
    });

    it('names the rendering it uses at every asking site', function () use ($askingSites, $renderingOf): void {
        // The anchor tells a reader where the contract lives; it does not tell them
        // which shape to write. Both renderings obey that contract and neither is the
        // default, so a site that cites and stops leaves the agent to guess — and the
        // guess it makes is the template it can see, which is how review feedback came
        // to be told to render as a round.
        // Which rendering a site uses comes from the roster above rather than from a
        // second list, so adding an asking site still means editing one place.
        // The two failure modes are reported apart for the same reason as the citation
        // check: a missing file means the roster is stale, a present file with no
        // rendering name means the rewrite skipped a site, and they need opposite fixes.
        // Arrange
        $sites = collect($askingSites);

        // Act
        $offenders = $sites
            ->map(function (string $file) use ($renderingOf): ?string {
                $rendering = $renderingOf($file);

                if (! file_exists(ToolkitFiles::path($file))) {
                    return $file.'  →  file is missing';
                }

                return Str::contains(ToolkitFiles::read($file), $rendering)
                    ? null
                    : $file.'  →  present, but names no "'.$rendering.'" rendering';
            })
            ->filter()
            ->values()
            ->all();

        // Assert
        expect($offenders)->toBe([]);
    });

    it('actually scans the roster rather than silently finding nothing', function () use ($askingSites, $minimumSiteLines): void {
        // The check above reports an empty offender list both when every site cites
        // the anchor and when the roster resolved nothing at all — a renamed or
        // emptied file reads identically to a clean sweep. So the roster is pinned
        // non-empty and every entry has to read back real prose.
        // Arrange
        $sites = collect($askingSites);

        // Act
        $lineCounts = $sites->mapWithKeys(fn (string $file): array => [
            $file => file_exists(ToolkitFiles::path($file))
                ? ToolkitFiles::lineCount(ToolkitFiles::read($file))
                : 0,
        ]);

        // Assert
        expect($sites)->not->toBeEmpty()
            ->and($lineCounts->count())->toBe($sites->count())
            ->and($lineCounts->filter(fn (int $count): bool => $count < $minimumSiteLines)->keys()->all())->toBe([]);
    });
});

describe('instruction surface prose', function () use ($pickerTool, $singleFormatClaim, $instructionSurfaceLines, $minimumSurfaceLines): void {
    it('sanctions the question picker nowhere it instructs an agent', function () use ($pickerTool, $instructionSurfaceLines, $minimumSurfaceLines): void {
        // The ban is on the tool, not on one phrasing of it: a skill that tells the
        // agent to reach for the picker has handed it a second, unwritten asking
        // procedure, and the canonical section becomes advice rather than the rule.
        // Nothing at runtime reports that — the picker renders, the user answers,
        // and the round format the guideline defines is simply never used.
        // Arrange
        $lines = $instructionSurfaceLines();

        // Act
        $offenders = collect($lines)
            ->filter(fn (array $line): bool => Str::contains($line['text'], $pickerTool))
            ->map(fn (array $line): string => sprintf('%s:%d  →  %s', $line['file'], $line['line'], Str::trim($line['text'])))
            ->values()
            ->all();

        // Assert
        expect($offenders)->toBe([])
            ->and(count($lines))->toBeGreaterThan($minimumSurfaceLines);
    });

    it('claims a single canonical format nowhere it instructs an agent', function () use ($singleFormatClaim, $instructionSurfaceLines, $minimumSurfaceLines): void {
        // The phrase is the defect, not a wording preference. "Write it in the
        // canonical format" asserts that one shape exists, and six files now say so —
        // including the one that then prints a numbered severity list instead. An
        // agent cannot follow both halves of that file, and nothing at runtime says
        // which half it followed.
        // What replaces the phrase is a rendering name, so a citation stays checkable:
        // "write it as a decision round" and "write it as a disposition list" each
        // point at a shape that exists, and the guideline section says what the two
        // have in common.
        // Arrange
        $lines = $instructionSurfaceLines();

        // Act
        $offenders = collect($lines)
            ->filter(fn (array $line): bool => Str::contains($line['text'], $singleFormatClaim))
            ->map(fn (array $line): string => sprintf('%s:%d  →  %s', $line['file'], $line['line'], Str::trim($line['text'])))
            ->values()
            ->all();

        // Assert
        expect($offenders)->toBe([])
            ->and(count($lines))->toBeGreaterThan($minimumSurfaceLines);
    });

    it('keeps the round template in the guideline source and nowhere else', function () use ($instructionSurfaceLines): void {
        // The strongest anti-drift assertion here. A citation alone does not stop a
        // site re-growing its own copy of the format beside the pointer — and once
        // two renderings of the same round exist, the one an agent reads is whichever
        // file it happened to load. The template's opening glyph is the tell: it
        // appears where the procedure is DEFINED, and a second occurrence means a
        // second definition.
        // The glyph is held as an escaped codepoint (U+2753) under `u`, never as a
        // literal character, so this guard cannot match itself and a byte-wise
        // pattern cannot split it mid-character.
        // Arrange
        $glyph = '~\x{2753}~u';
        $lines = $instructionSurfaceLines();
        $canonical = ToolkitFiles::read('.ai/guidelines/project.md');

        // Act
        $offenders = collect($lines)
            ->filter(fn (array $line): bool => preg_match($glyph, $line['text']) === 1)
            ->map(fn (array $line): string => sprintf('%s:%d  →  %s', $line['file'], $line['line'], Str::trim($line['text'])))
            ->values()
            ->all();

        // Assert
        expect($offenders)->toBe([])
            ->and(ToolkitFiles::missingPatterns($canonical, [
                'the round template glyph, in the one file that defines the procedure' => $glyph,
            ]))->toBe([]);
    });
});

describe('map routing to the rule', function () use ($anchor, $sectionOf): void {
    it('names the asking procedure in the upkeep list', function () use ($anchor, $sectionOf): void {
        // `/map` is the router an agent opens when it has forgotten what exists, and
        // Upkeep is where it lists the standing conventions that govern how work is
        // written rather than what to do next. A rule every skill must follow and no
        // skill owns is invisible unless it is listed there.
        // The sentinel entry rides along on purpose: it proves the section extracted,
        // so a renamed heading fails as "the heading moved" rather than as "the rule
        // is missing".
        // Arrange
        $upkeep = $sectionOf('.claude/skills/map/SKILL.md', 'Upkeep');

        // Act
        $missing = ToolkitFiles::missingPatterns($upkeep, [
            'the canonical asking procedure, named under `## Upkeep`' => '~'.preg_quote($anchor, '~').'~i',
            'the existing `codebase-design` entry, proving the section resolved' => '~codebase-design~',
        ]);

        // Assert
        expect($missing)->toBe([]);
    });
});

describe('picker hook wiring', function () use ($pickerTool, $hookScript): void {
    it('registers the guard as a PreToolUse hook for the picker', function () use ($pickerTool, $hookScript): void {
        // A hook that is not registered never fires, and nothing says so: running the
        // script by hand proves the script, not the wiring. So this reads the decoded
        // settings rather than grepping the raw file — a substring match would pass on
        // an entry parked under the wrong event, or on a line left behind in prose.
        // Each level is shape-checked before it is walked. The file is valid JSON by
        // the time it is decoded, but nothing makes its SHAPE ours: an entry edited
        // down to a string still decodes, and indexing it would abort the Act with a
        // TypeError. That reports a broken settings file as a crashed test, when the
        // finding the reader needs is that the hook is not registered.
        // Arrange
        $settings = json_decode(ToolkitFiles::read('.claude/settings.json'), true, 512, JSON_THROW_ON_ERROR);

        // Act
        $registered = collect($settings['hooks']['PreToolUse'] ?? [])
            ->filter(fn (mixed $entry): bool => is_array($entry) && ($entry['matcher'] ?? null) === $pickerTool)
            ->flatMap(fn (array $entry): array => is_array($entry['hooks'] ?? null) ? $entry['hooks'] : [])
            ->pluck('command')
            ->filter(fn (mixed $command): bool => is_string($command) && Str::contains($command, $hookScript))
            ->values()
            ->all();

        // Assert
        expect($settings['hooks']['PreToolUse'] ?? null)->toBeArray()
            ->and($registered)->not->toBeEmpty();
    });

    it('documents the guard in the hooks README', function () use ($hookScript): void {
        // The README's table is where an operator learns which calls this repo refuses
        // and why. A fourth hook wired in without a row makes the table quietly wrong,
        // and the opening count is the part that goes stale silently — it still reads
        // as a true sentence, just about a different set of hooks.
        // Arrange
        $readme = ToolkitFiles::read('.claude/hooks/README.md');

        // Act
        $missing = ToolkitFiles::missingPatterns($readme, [
            'a table row naming the picker guard' => '~^\|[^|\n]*'.preg_quote(basename($hookScript), '~').'[^|\n]*\|~m',
            'an opening count that reads four hooks rather than three' => '~\bfour\s+hooks\b~i',
        ]);

        // Assert
        expect($missing)->toBe([]);
    });
});

describe('review gate silence exception', function () use ($reviewGate, $reviewGateLines, $minimumGateLines, $sectionOf): void {
    it('leaves no `DISCUSS` bucket anywhere in the review gate', function () use ($reviewGateLines, $minimumGateLines): void {
        // `DISCUSS` is the name of the exception, so the name goes with it. While the
        // token is still in the file the fourth bucket still exists for any agent
        // reading it: the frontmatter promises the command "holds for every item
        // marked DISCUSS", the Phase 2 heading advertises the wait, the slot table
        // routes `Fix`/`Why` by it, and the worked example shows an entry filed under
        // it. Collapse the behavior but leave the vocabulary and the next agent
        // reinvents the wait from the words it was handed.
        // Matched case-insensitively and on the word start, so the lowercase
        // `discuss 6 (lean skip)` in the override example and the past-tense
        // `Discussed` in the closing summary count too — they name the same bucket,
        // and a token sweep that spelled out one casing would leave the other.
        // Arrange
        $lines = $reviewGateLines();

        // Act
        $offenders = collect($lines)
            ->filter(fn (array $line): bool => preg_match('~\bdiscuss~i', $line['text']) === 1)
            ->map(fn (array $line): string => sprintf('%s:%d  →  %s', $line['file'], $line['line'], Str::trim($line['text'])))
            ->values()
            ->all();

        // Assert
        expect($offenders)->toBe([])
            ->and(count($lines))->toBeGreaterThan($minimumGateLines);
    });

    it('keeps no construct that holds the run for an answer', function () use ($reviewGate, $minimumGateLines): void {
        // The bucket is one name for the wait; these are the others, and each one on
        // its own is enough to stop the run. The prompt closes by listing the numbers
        // it is waiting on, one paragraph says silence leaves an item OPEN — the exact
        // inverse of the canonical contract — and another says the run holds until
        // every number is settled.
        // The out-of-scope `BLOCKING` hold is the fourth, and it is the same construct
        // wearing a different label: the slot table gives it the identical `Fix`/`Why`
        // treatment as a `DISCUSS` item, and the lean paragraph closes both the same
        // way. Exempt it and the rule is an always with one exception, which is not an
        // always. It is matched on the word `hold` beside the out-of-scope phrasing —
        // never on `BLOCKING` alone, which stays a legitimate severity, and never on
        // `out of scope` alone, which stays a legitimate skip rationale in Phase 1 and
        // a legitimate summary line in Phase 6.
        // Each shape is named apart so a failure says which one is still there; they
        // sit in four different sections and need four different edits.
        // Arrange
        $source = ToolkitFiles::read($reviewGate);
        $forbidden = [
            'the `Waiting on:` line in the prompt example' => '~^\s*Waiting on\s*:~mi',
            'the "Silence leaves it open … wait again" paragraph' => '~Silence leaves it open|\bwait again\b~i',
            'the "The run holds here until every … number is settled" paragraph' => '~\brun holds here\b~i',
            'the out-of-scope `BLOCKING` hold, the same wait under another name' => '~out[- ]of[- ]scope[^\n]{0,60}\bhold|\bBLOCKING\s+hold~i',
        ];

        // Act
        $surviving = ToolkitFiles::survivingPatterns($source, $forbidden);

        // Assert
        expect($surviving)->toBe([])
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan($minimumGateLines);
    });

    it('reports no settled-with-the-user counts in the closing summary', function () use ($reviewGate, $sectionOf): void {
        // The closing summary is what the user reads when the run is over, and it
        // still budgets two lines for decisions the user made in the gate — items
        // "discussed and settled with you", and out-of-scope `BLOCKING` items
        // "decided in the gate". Neither can be non-zero once nothing waits, so a
        // surviving line reports a count of zero forever while telling the reader the
        // command still consults them. It is the cheapest place for the retired
        // behavior to hide, because it reads as reporting rather than as a rule.
        // Scoped to the Phase 6 section rather than the whole file on purpose: the
        // sibling line for out-of-scope SKIPS survives untouched, and only its
        // position in this block distinguishes the two.
        // Arrange
        $summary = $sectionOf($reviewGate, 'Phase 6: Reinforcements, then commit');
        $forbidden = [
            'the "Discussed and settled with you" count' => '~^-\s*Discussed and settled~mi',
            'the out-of-scope `BLOCKING` "decided in the gate" count' => '~^-\s*Out of scope[^\n]*BLOCKING~mi',
        ];

        // Act
        $surviving = ToolkitFiles::survivingPatterns($summary, $forbidden);

        // Assert
        expect($surviving)->toBe([])
            ->and(Str::length($summary))->toBeGreaterThan(200);
    });
});

describe('review gate numbering scope', function () use ($reviewGate, $minimumGateLines): void {
    it('scopes an item number across rounds rather than to one run', function () use ($reviewGate, $minimumGateLines): void {
        // Clause 1 of the contract makes a number durable for the whole session, so a
        // later round continues the sequence. The gate scopes it to the run instead,
        // and `/review:run` presents a second, delta list under that rule — which
        // restarts at 1. Item 3 then names two different findings in one session, and
        // an override reply of `skip 3` is ambiguous by construction: nothing in the
        // command, and nothing the user can see, says which 3 was meant.
        // The scoping is named as a sentence rather than swept as a token, because
        // `run` is a legitimate word throughout this command and only this phrase
        // binds a number's life to one of them.
        // Arrange
        $source = ToolkitFiles::read($reviewGate);
        $forbidden = [
            'the "a number … stays with its item for the whole run" scoping' => '~\bfor\s+the\s+whole\s+run\b~i',
        ];

        // Act
        $surviving = ToolkitFiles::survivingPatterns($source, $forbidden);

        // Assert
        expect($surviving)->toBe([])
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan($minimumGateLines);
    });
});
