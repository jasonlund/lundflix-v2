<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Tests\Support\ToolkitFiles;

/**
 * Drift guard for the review engine's written contract: the toolkit must
 * describe the engine that actually exists.
 *
 * The review pipeline is prose an agent reads at dispatch time, so a retired
 * agent leaves no error behind when its name survives in a command or a skill —
 * the harness finds nothing to run, that phase produces no findings, and the
 * report still renders. The same holds for a contract section: a rule for a
 * phase that no longer exists reads as live guidance to the next agent that
 * loads the file, and to the next human that edits it.
 *
 * Five parts, all static: the **roster** (which agent files exist, and who
 * names them), the **contract** (which sections of
 * `.claude/skills/review-pipeline/SKILL.md` survive, and what they say), the
 * **inventory** (whether `.claude/skills/map/SKILL.md` — the router a human opens
 * to find a file — both counts and names the files that are actually on disk),
 * the **renames** (whether every file that routes to a renamed stage names that
 * stage as it is called now), and the **stage contracts** (whether a command
 * file carries the commitments the stage it serves is defined by — its own, or
 * a stage's whose work it does under a flag, the way
 * `/review:process --human-round` is where the human stage's whole ingest is
 * written down).
 *
 * A rename goes wrong more quietly still. A retired name at least dangles —
 * the harness looks for a file and finds none. But a stage is renamed away from
 * a name because a later stage wants it, so a router left pointing at the old
 * name does not dangle: it resolves, to a stage doing something else entirely,
 * and the author is sent there instead.
 *
 * A stage contract fails more quietly again, because the file is there and
 * dispatches fine — only a step is missing from its prose, so the agent never
 * does it and nothing reports that it did not. If `/review:human` stopped saying
 * that a Linear review must be submitted rather than drafted, the reviewer would
 * draft one, the collect would come back empty, and the loop would carry on to
 * the engines as though nobody had commented.
 *
 * Scope, deliberate: every assertion checks that TEXT IS PRESENT OR ABSENT,
 * never that a model obeyed it. A tier pin and a silence rule are enforced at
 * runtime by the harness and the model; the only thing a static scan can
 * guarantee is that the instruction was written, or that it was removed.
 *
 * File reading, line splitting, the agent-roster sweep and the named-pattern
 * checks come from `Tests\Support\ToolkitFiles`, shared with the other toolkit
 * guards.
 *
 * NB: the retired agent names live in a PHP array literal in this file, which
 * would make this file its own offender — so the scan excludes itself by
 * realpath. This is the one place those names are allowed to appear, because
 * this is the list that forbids them.
 *
 * NB: every test carries a non-vacuous floor — the scan really resolved files,
 * or the file really was read — because a mistyped path yields an empty scan,
 * and an empty scan reads identically to a clean one on every check here.
 */

/**
 * The eight reviewer/hunter agents the new engine retired. Nothing in version
 * control may name them.
 *
 * @var list<string>
 */
$retiredAgents = [
    'requirements-reviewer',
    'conventions-reviewer',
    'edge-case-reviewer',
    'integration-reviewer',
    'discipline-reviewer',
    'testing-reviewer',
    'false-positive-hunter',
    'missing-defect-hunter',
];

/**
 * The agents the engine still runs — the whole of `.claude/agents/`.
 *
 * @var list<string>
 */
$survivingAgents = [
    'coderabbit-reviewer',
    'review-feedback-collector',
    'review-fixer',
    'tdd-test-writer',
    'tdd-implementer',
    'tdd-refactorer',
    'review-skip-check',
    'review-summarizer',
    'review-compliance',
    'review-compliance-validator',
    'review-bug-hunter',
    'review-bug-validator',
];

/**
 * Every line of every committed toolkit, guideline or test file, paired with
 * where it came from.
 *
 * Scanned by extension rather than wholesale: `tests/Fixtures/` holds byte-exact
 * third-party captures that are not ours to police, and a `.tsv.gz` carries no
 * prose to drift. This file excludes itself — see the banner.
 *
 * `.ai/guidelines` is swept for a reason the other two roots don't share: it is
 * the SOURCE `php artisan boost:install --guidelines` generates `CLAUDE.md` and
 * `AGENTS.md` from. A retired name left in `project.md` is copied verbatim into
 * both generated files on the next regeneration, so catching it at the generated
 * copies would be catching it one step too late.
 *
 * Finder throws on a directory that isn't there, so a root renamed out from
 * under this list fails loudly rather than quietly scanning less.
 *
 * @return list<array{file: string, line: int, text: string}>
 */
$scanCommittedLines = fn (): array => ToolkitFiles::scanLines(
    (new Finder)->files()->in(ToolkitFiles::path('.claude'))->name(['*.md', '*.json', '*.sh']),
    (new Finder)->files()->in(ToolkitFiles::path('.ai/guidelines'))->name('*.md'),
    (new Finder)->files()->in(ToolkitFiles::path('tests'))->name('*.php')->exclude('Fixtures')
        ->filter(fn (SplFileInfo $file): bool => $file->getRealPath() !== __FILE__),
);

/**
 * The committed shared contract, read from disk.
 */
$contractSource = fn (): string => ToolkitFiles::read('.claude/skills/review-pipeline/SKILL.md');

/**
 * One committed `/review:*` command, read from disk by the name it is invoked
 * under — `$reviewCommandSource('debrief')` reads the file `/review:debrief`
 * dispatches to.
 *
 * Takes the name rather than resolving a path per command on purpose: a guard is
 * written before the stage it guards, and reading by the not-yet-written path is
 * what makes the RED phase legible. `ToolkitFiles::read()` answers `''` for a
 * file that is not there, so the guard reports every commitment missing BY NAME
 * instead of dying on the read.
 */
$reviewCommandSource = fn (string $command): string => ToolkitFiles::read(
    '.claude/commands/review/'.$command.'.md',
);

/**
 * One `## ` section of a markdown source, from its heading to the next one.
 */
$contractSection = function (string $source, string $heading): string {
    $pattern = sprintf('~^##\s+%s\s*$.*?(?=^##\s|\z)~ms', preg_quote($heading, '~'));

    return preg_match($pattern, $source, $matches) === 1 ? $matches[0] : '';
};

/**
 * A pattern that must match INSIDE one `## Phase N` block of a command file.
 *
 * A phase's commitment is a commitment of that phase: the same words sitting in
 * a later phase satisfy a file-wide pattern while the phase that was supposed to
 * carry them does nothing. The tempered `(?:(?!^##\s).)*` walks no further than
 * the next `## ` heading, and each inner pattern becomes its own lookahead from
 * the heading, so several commitments can be required of one phase in any order.
 *
 * Matched on the phase NUMBER alone — the wording of the title beside it is the
 * author's to choose, and pinning it would fail a rename that changed nothing.
 */
$withinPhase = function (int $phase, string ...$inner): string {
    $lookaheads = implode('', array_map(
        fn (string $pattern): string => sprintf('(?=(?:(?!^##\s).)*%s)', $pattern),
        $inner,
    ));

    return sprintf('~^##\s+Phase\s+%d\b%s~ms', $phase, $lookaheads);
};

/**
 * A pattern that must match INSIDE one `### Stage N` block of the orchestrator.
 *
 * The `### Stage N` sibling of `$withinPhase`, temper and all, for the same
 * reason: a stage's own commitment has to sit in that stage. `/review:run` is
 * one file of seven near-identical blocks, so a file-wide pattern is satisfied
 * by any of them — and prose that explains a stage from two stages away is
 * prose the agent reads after it has already run the stage.
 *
 * Matched on the stage NUMBER alone; the title beside it is asserted separately,
 * against the command that stage defers to.
 *
 * Kept separate from `$withinPhase` rather than parameterized by heading level,
 * because the two tempers are not the same window: this one closes on the next
 * `### ` and NOT on a `## `, so the LAST stage's block runs on into `## Rules`
 * and past it to the end of the file. Harmless while every stage asserted here
 * has a stage after it — but a guard on the final stage is scoped to the rest of
 * the document, not to that stage.
 */
$withinStage = function (int $stage, string ...$inner): string {
    $lookaheads = implode('', array_map(
        fn (string $pattern): string => sprintf('(?=(?:(?!^###\s).)*%s)', $pattern),
        $inner,
    ));

    return sprintf('~^###\s+Stage\s+%d\b%s~ms', $stage, $lookaheads);
};

/**
 * A pattern requiring two expressions inside ONE paragraph, in either order.
 *
 * A rule and its reason are a pair — an exemption that never names the validator
 * it exempts from, or a bucket that never says it carries no severity, is half a
 * rule. A file-wide pattern per half passes on two sentences written a hundred
 * lines apart, which is exactly how a rule loses the clause that made it make
 * sense.
 *
 * The tempered `(?:(?!\n\n).)` walks no further than the next blank line, so the
 * window is the paragraph rather than a byte count that drifts on a rewrap. Both
 * orders are spelled out because which half a writer leads with is theirs to
 * choose, and pinning it would fail a sentence that says the same thing
 * backwards.
 *
 * Case is the CALLER's to set with an inline `(?i:…)` — `CONVERSATION` and
 * `ANSWER` are literal uppercase tokens of the printed report, and a blanket `i`
 * flag would let the prose word `conversation` already in the file stand in for
 * the bucket.
 */
$nearInParagraph = fn (string $first, string $second): string => sprintf(
    '~(?:%1$s%3$s%2$s|%2$s%3$s%1$s)~s',
    $first,
    $second,
    '(?:(?!\n\n).){0,400}',
);

/**
 * Every file named in a required-commitment map, read from disk and keyed by
 * the same repo-relative path its commitments are keyed by.
 *
 * That shared keying is what lets `$missingAcrossFiles` name the file an
 * offender came from, so the two are written to be used together.
 *
 * @param  array<string, array<string, string>>  $required  file => (commitment => pattern)
 * @return Collection<string, string>
 */
$requiredSources = fn (array $required): Collection => collect($required)
    ->map(fn (array $patterns, string $file): string => ToolkitFiles::read($file));

/**
 * The missing commitments of several files at once, each prefixed with the file
 * that owes it.
 *
 * A sequence written in four places goes stale in three, so a guard over one has
 * to say WHICH copy lost it: the commitment names repeat across files almost
 * verbatim, and an unprefixed failure sends the reader to whichever copy they
 * happen to open first.
 *
 * @param  Collection<string, string>  $sources  file => source
 * @param  array<string, array<string, string>>  $required  file => (commitment => pattern)
 * @return list<string>
 */
$missingAcrossFiles = fn (Collection $sources, array $required): array => $sources
    ->flatMap(fn (string $source, string $file): array => collect(ToolkitFiles::missingPatterns($source, $required[$file]))
        ->map(fn (string $commitment): string => sprintf('%s  →  %s', $file, $commitment))
        ->all())
    ->values()
    ->all();

/**
 * Every `### Stage N: title` heading of the orchestrator, in document order.
 *
 * Parsed into its number and its title rather than matched against a literal
 * sequence, so a guard over the numbering can report the run of numbers it
 * actually found — `0,1,2,3,3,4` says which hand-renumber went wrong, where a
 * bare "pattern did not match" says only that one did.
 *
 * @return Collection<int, array{number: int, title: string}>
 */
$stageHeadings = function (string $source): Collection {
    preg_match_all('~^###\s+Stage\s+(\d+):\s*(.*)$~m', $source, $matches, PREG_SET_ORDER);

    return collect($matches)->map(fn (array $heading): array => [
        'number' => (int) $heading[1],
        'title' => Str::trim($heading[2]),
    ]);
};

/**
 * Every command the harness can invoke, by the name it is invoked under.
 *
 * Recursive, like the map's own command count: a command in a subdirectory is
 * namespaced by it rather than hidden — `review/claude.md` is invoked as
 * `/review:claude` — so the separator becomes the `:` and the extension goes.
 *
 * @return Collection<int, string>
 */
$commandNames = fn (): Collection => collect((new Finder)
    ->files()
    ->in(ToolkitFiles::path('.claude/commands'))
    ->name('*.md')
    ->sortByName())
    ->map(fn (SplFileInfo $file): string => Str::replace(
        DIRECTORY_SEPARATOR,
        ':',
        Str::beforeLast($file->getRelativePathname(), '.md'),
    ))
    ->values();

/**
 * The committed `map` skill — the router that names every toolkit file — read
 * from disk.
 */
$mapSource = fn (): string => ToolkitFiles::read('.claude/skills/map/SKILL.md');

/**
 * The number the map's inventory sentence states before a noun — `33 toolkit
 * files`, `13 subagents` — or null when the sentence no longer states one.
 *
 * Null rather than 0 so a caller can tell "the map claims none" apart from "the
 * pattern stopped matching": the second must fail loudly instead of comparing
 * nothing to nothing.
 */
$statedCount = function (string $source, string $noun): ?int {
    $pattern = sprintf('~(\d+)\s+%s\b~', preg_quote($noun, '~'));

    return preg_match($pattern, $source, $matches) === 1 ? (int) $matches[1] : null;
};

/**
 * How many toolkit files of one kind are actually on disk.
 *
 * Every expected count in the inventory tests comes from here, never from a
 * literal — this roster has changed three times in one ticket, and a guard
 * carrying its own hardcoded 12 is the same staleness one layer down.
 */
$countToolkitFiles = function (string $directory, string $name, ?string $depth = null): int {
    $finder = (new Finder)->files()->in(ToolkitFiles::path($directory))->name($name);

    if ($depth !== null) {
        $finder->depth($depth);
    }

    return count($finder);
};

describe('review agent roster', function () use ($scanCommittedLines, $retiredAgents, $survivingAgents): void {
    it('names no retired reviewer or hunter agent anywhere in version control', function () use ($scanCommittedLines, $retiredAgents): void {
        // A dispatch to a retired agent is silent: the harness has nothing to
        // run, that phase contributes nothing, and the report still renders. So
        // the retired names must be gone from every committed file, not merely
        // from the dispatch list of the command that used to call them.
        // The hyphen is added to the boundary class so a longer name that ends
        // in a retired one could not slip past a bare `\b`.
        // Arrange
        $lines = $scanCommittedLines();
        $retired = '#(?<![\w-])(?:'.implode('|', array_map(
            fn (string $agent): string => preg_quote($agent, '#'),
            $retiredAgents,
        )).')(?![\w-])#';

        // Act
        $offenders = collect($lines)
            ->filter(fn (array $l): bool => preg_match($retired, $l['text']) === 1)
            ->map(fn (array $l): string => sprintf('%s:%d  →  %s', $l['file'], $l['line'], Str::trim($l['text'])))
            ->values()
            ->all();

        // Assert
        expect($offenders)->toBe([])
            ->and(collect($lines)->pluck('file')->unique()->count())->toBeGreaterThan(40);
    });

    it('holds exactly the agents the engine still runs', function () use ($survivingAgents): void {
        // Equality both ways on purpose: an extra file is a retired agent that
        // outlived its dispatch, and a missing one is a phase that silently
        // stops running.
        // Arrange
        $expected = collect($survivingAgents)->sort()->values()->all();

        // Act
        $roster = ToolkitFiles::agentNames()->sort()->values();

        // Assert
        expect($roster->all())->toBe($expected)
            ->and($roster->count())->toBeGreaterThan(5);
    });
});

describe('review-pipeline contract sections', function () use ($contractSource): void {
    it('keeps the contract sections the new engine uses', function () use ($contractSource): void {
        // Regression guard: every agent the engine still dispatches is handed
        // these sections by name from `/review:claude`, so deleting one leaves a
        // dispatch pointing at a section that is not there — with no error on
        // either side. Heading patterns are delimited by `~`, not `#`, because a
        // markdown heading opens on the delimiter character itself.
        // Arrange
        $source = $contractSource();
        $required = [
            '## Finding Format' => '~^## Finding Format\s*$~m',
            'the ASD-STE100 section, by that name' => '~ASD-STE100~',
            '## The Comment Bar' => '~^## The Comment Bar~m',
            '## Severity Definitions' => '~^## Severity Definitions~m',
            '## Smell Baseline' => '~^## Smell Baseline~m',
            '## Convention Override Rule' => '~^## Convention Override Rule~m',
            '## Model Selection' => '~^## Model Selection~m',
            '## Ticket ID Auto-Extraction' => '~^## Ticket ID Auto-Extraction~m',
            '## PR Number Auto-Extraction' => '~^## PR Number Auto-Extraction~m',
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($source, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan(100);
    });

    it('drops the contract sections the new engine retired', function () use ($contractSource): void {
        // Each of these governs machinery the engine no longer has. Consensus
        // and the tiebreaker grade findings by how many of six reviewers agreed;
        // grounding verification gates a routing step that one validator per
        // finding replaced; the aggregate nit cap budgets a report the 400-word
        // reviewer cap now bounds. Left in place they read as live rules to the
        // next agent that loads the file.
        // The nit cap is matched on its own bold label and on the numeric
        // aggregate it states, so a failure names which half survived.
        // Arrange
        $source = $contractSource();
        $forbidden = [
            'the Consensus Rules section' => '~^##.*Consensus Rules~mi',
            'the Tiebreaker Rule section' => '~^##.*Tiebreaker Rule~mi',
            'the Mechanical Grounding Verification section' => '~^##.*Mechanical Grounding Verification~mi',
            'the **Nit cap.** rule label' => '~\*\*Nit cap~i',
            'the aggregate "at most 5 NITs" cap' => '~at most\s+\**\s*5\s+NITs~i',
        ];

        // Act
        $surviving = ToolkitFiles::survivingPatterns($source, $forbidden);

        // Assert
        expect($surviving)->toBe([])
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan(100);
    });
});

describe('review-pipeline contract rules', function () use ($contractSource, $reviewCommandSource, $contractSection): void {
    it('documents the model tiers it enforces', function () use ($contractSource, $contractSection): void {
        // Model Selection is the written half of a rule `AgentModelPolicyTest`
        // enforces mechanically, so the two must agree: a tier that test pins on
        // disk and this section never mentions is a rule with no stated reason,
        // and the next agent to add a file has nothing to follow. Haiku is the
        // gap the retired roster left — triage runs on it, and only the test
        // says so.
        // The roles are checked one representative per tier rather than every
        // name, so prose may group a pair without breaking the guard.
        // Arrange
        $section = $contractSection($contractSource(), 'Model Selection');
        $required = [
            'the haiku tier' => '~\bhaiku\b~i',
            'the sonnet tier' => '~\bsonnet\b~i',
            'the inherit tier' => '~\binherit~i',
            'review-skip-check, which runs on haiku' => '~review-skip-check~',
            'review-summarizer, which runs on haiku' => '~review-summarizer~',
            'review-compliance, which runs on sonnet' => '~review-compliance~',
            'coderabbit-reviewer, which runs on sonnet' => '~coderabbit-reviewer~',
            'review-bug-hunter, which inherits the session model' => '~review-bug-hunter~',
            'review-fixer, which inherits the session model' => '~review-fixer~',
            'the tdd phases, which inherit the session model' => '~tdd-~',
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($section, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(Str::length($section))->toBeGreaterThan(200);
    });

    it('grants no permission to report a pre-existing issue', function () use ($contractSource, $reviewCommandSource): void {
        // `/review:claude` lists pre-existing issues among the things a reviewer
        // stays silent on. A contract that also grades them — allows them when
        // tagged, or caps them at a severity — hands the same agent two rules and
        // lets it pick. Giving a pre-existing issue a severity IS telling the
        // agent to report it, so a severity token near the mention is the
        // offence; the command's own silence line is asserted as the premise, so
        // this can never pass by the command having quietly changed instead.
        // Scanned by line window rather than byte offset: these files carry
        // multi-byte characters, and mixing byte offsets with multi-byte string
        // helpers slices mid-character.
        // Arrange
        $lines = ToolkitFiles::splitLines($contractSource());
        $severity = '~\b(?:BLOCKING|SHOULD_FIX|CONSIDER|NIT)\b~';
        $permission = '~\b(?:allowed|tagged)\b~i';

        // Act
        $offenders = collect($lines)
            ->filter(fn (string $text): bool => preg_match('~pre-existing~i', $text) === 1)
            ->filter(function (string $text, int $index) use ($lines, $severity, $permission): bool {
                $window = implode("\n", array_slice($lines, max(0, $index - 1), 3));

                return preg_match($severity, $window) === 1 || preg_match($permission, $window) === 1;
            })
            ->map(fn (string $text, int $index): string => sprintf(
                '.claude/skills/review-pipeline/SKILL.md:%d  →  %s',
                $index + 1,
                Str::trim($text),
            ))
            ->values()
            ->all();

        // Assert
        expect($offenders)->toBe([])
            ->and(preg_match('~stay silent[^.]{0,400}pre-existing~is', $reviewCommandSource('claude')))->toBe(1)
            ->and(count($lines))->toBeGreaterThan(100);
    });
});

describe('map skill inventory', function () use ($mapSource, $statedCount, $countToolkitFiles): void {
    it('states the subagent count it actually ships', function () use ($mapSource, $statedCount, $countToolkitFiles): void {
        // The map opens by counting the toolkit, and a count nobody can verify
        // rots on every roster change — this one has already said 14 when there
        // were 13 and 13 when there were 12. The sweep is flat because an agent
        // is loaded by basename alone, so a nested file is not one.
        // Arrange
        $shipped = $countToolkitFiles('.claude/agents', '*.md', '== 0');

        // Act
        $stated = $statedCount($mapSource(), 'subagents');

        // Assert
        expect($stated)->not->toBeNull()
            ->and($shipped)->toBeGreaterThan(5)
            ->and($stated)->toBe($shipped);
    });

    it('states the skill count it actually ships', function () use ($mapSource, $statedCount, $countToolkitFiles): void {
        // A skill is a directory holding a `SKILL.md`, so the count is of those
        // manifests one level down — supporting `.md` files beside a manifest are
        // reference material the skill loads, not skills of their own.
        // Arrange
        $shipped = $countToolkitFiles('.claude/skills', 'SKILL.md', '== 1');

        // Act
        $stated = $statedCount($mapSource(), 'skills');

        // Assert
        expect($stated)->not->toBeNull()
            ->and($shipped)->toBeGreaterThan(5)
            ->and($stated)->toBe($shipped);
    });

    it('states the command count it actually ships', function () use ($mapSource, $statedCount, $countToolkitFiles): void {
        // Recursive, unlike the agent sweep: a command in a subdirectory is
        // namespaced by it rather than hidden — `review/claude.md` is invoked as
        // `/review:claude` — so a depth-0 count would miss most of them.
        // Arrange
        $shipped = $countToolkitFiles('.claude/commands', '*.md');

        // Act
        $stated = $statedCount($mapSource(), 'commands');

        // Assert
        expect($stated)->not->toBeNull()
            ->and($shipped)->toBeGreaterThan(3)
            ->and($stated)->toBe($shipped);
    });

    it('states a toolkit total equal to its own parts', function () use ($mapSource, $statedCount): void {
        // The one check here that reads no directory: the sentence names a total
        // and then breaks it into three parts, so it can contradict itself
        // without any file changing. Whoever edits one number has to edit both.
        // Arrange
        $source = $mapSource();

        // Act
        $stated = [
            'total' => $statedCount($source, 'toolkit files'),
            'skills' => $statedCount($source, 'skills'),
            'commands' => $statedCount($source, 'commands'),
            'subagents' => $statedCount($source, 'subagents'),
        ];

        // Assert
        expect(array_keys($stated, null, true))->toBe([])
            ->and($stated['total'])->toBe($stated['skills'] + $stated['commands'] + $stated['subagents']);
    });
});

describe('review debrief rename', function () use (
    $reviewCommandSource,
    $mapSource,
    $contractSection,
    $requiredSources,
    $missingAcrossFiles,
): void {
    it('ships a debrief command carrying the renamed stage own commitments', function () use ($reviewCommandSource): void {
        // The orientation stage follows the build rather than preceding it, so
        // it is a debrief, and the name `human` is wanted for a stage that
        // really does host a human. A rename is only real once the renamed file
        // is on disk announcing itself: the harness dispatches on the
        // frontmatter `name`, and a command that still announces the old one is
        // reachable only under the old one, with no error either way. The two
        // other commitments are what the stage leaves behind — the Linear diff
        // link no later stage regenerates, and the `Next:` line that is the
        // loop's only thread onward.
        // Arrange
        $source = $reviewCommandSource('debrief');
        $required = [
            'the frontmatter names the command `review:debrief`' => '~^name:\s*review:debrief\s*$~m',
            'the closing handoff emits the Linear diff link `linear.review/{owner}/{repo}/pull/{n}`' => '~linear\.review/\{[^}\n]+\}/\{[^}\n]+\}/pull/\{[^}\n]+\}~',
            'the closing `Next:` line points at the human-review stage' => '~^Next:\s*/review:human\b~m',
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($source, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan(100);
    });

    it('hands the loop from create-pr to the debrief stage', function () use ($reviewCommandSource): void {
        // `/review:create-pr` states the handoff twice — in the chain sentence a
        // reader skims, and in the closing line the agent actually prints — and
        // the two drift apart independently. Neither can fail loudly: a stale
        // name routes the author to a command that will belong to a different
        // stage, so the orientation pass is skipped and the report still
        // renders.
        // Arrange
        $source = $reviewCommandSource('create-pr');
        $required = [
            'the pipeline chain sentence routes create-pr into `/review:debrief`' => '~`/review:create-pr`\*\*[^\n]*→\s*`/review:debrief`~',
            'the closing `Next:` line names `/review:debrief`' => '~^Next:\s*/review:debrief\s*$~m',
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($source, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan(100);
    });

    it('attributes spec-axis findings in the add template to the debrief stage', function () use ($reviewCommandSource, $contractSection): void {
        // That footer is the only attribution a posted finding carries once it
        // is on the PR. Naming a retired command there credits the wrong stage
        // to every reader of the comment, and nothing on GitHub can reveal the
        // mistake — the finding reads as authoritative either way. Scoped to the
        // Spec section so a `/review:debrief` mention elsewhere in the file
        // cannot stand in for the template line itself.
        // Arrange
        $section = $contractSection($reviewCommandSource('add'), 'Spec — does it do what the ticket asked?');
        $required = [
            'the Spec report template footer reads `_Found by: /review:debrief_`' => '~^_Found by:\s*/review:debrief_\s*$~m',
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($section, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(Str::length($section))->toBeGreaterThan(200);
    });

    it('names the debrief stage as the spec-axis owner in the claude command', function () use ($reviewCommandSource): void {
        // `/review:claude` declines the spec axis twice by naming who owns it —
        // once telling the agent not to review it, once telling the report's
        // reader where it went. Both are prose obeyed at dispatch time, so a
        // stale owner points at a command that will be doing something else
        // entirely, and the spec axis reads as covered by someone when nobody
        // covered it.
        // Arrange
        $source = $reviewCommandSource('claude');
        $required = [
            'the Input section assigns the spec axis to `/review:debrief`' => '~the spec axis itself belongs to\s+`/review:debrief`~',
            'the Spec section names `/review:debrief` Phase 3 as the axis owner' => '~^`/review:debrief` Phase 3 owns the spec axis~m',
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($source, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan(100);
    });

    it('routes every remaining pipeline reference to the debrief stage', function () use ($requiredSources, $missingAcrossFiles): void {
        // The rename reached the stage's own file, but the files that ROUTE to
        // it still carry the old name: three chain sentences, one frontmatter
        // `description:` the harness renders in the command list, and the
        // planning skill that credits the spec pass. Each reads as live routing
        // to the next agent that loads it, and none can fail loudly — the
        // author is sent to a command that is about to belong to a different
        // stage, and the loop still renders.
        // Every pattern states the POSITIVE commitment — `/review:debrief`
        // holds the slot immediately after `/review:create-pr`, and owns the
        // spec pass — never the absence of `/review:human`. A genuinely new
        // `/review:human` stage lands between debrief and suite shortly, so a
        // guard forbidding that name would have to be deleted the week it was
        // written.
        // Adjacency is spelled `[^`]` rather than `[^\n]` so a rewrapped chain
        // still matches, while an intervening stage — always backticked in
        // these sentences — cannot slip between the two ends.
        // Arrange
        $required = [
            '.claude/commands/review/suite.md' => [
                'the loop-position paragraph runs create-pr into `/review:debrief` and sends the reader there for the plain-language read' => '~`/review:create-pr`[^`]{0,40}→[^`]{0,40}`/review:debrief`.{0,400}Run\s+`/review:debrief`\s+first~s',
            ],
            '.claude/commands/review/process.md' => [
                'the closing-stage chain sentence runs `/review:create-pr` into `/review:debrief`' => '~`/review:create-pr`[^`]{0,40}→[^`]{0,40}`/review:debrief`~',
            ],
            '.claude/commands/review/run.md' => [
                'the `Loop:` line runs `/review:create-pr` into `/review:debrief`' => '~`/review:create-pr`[^`]{0,40}→[^`]{0,40}`/review:debrief`~',
                'the frontmatter `description:` the harness displays names debrief after create-pr' => '~^description:[^\n]*\bcreate-pr\s*→\s*debrief\b~m',
            ],
            '.claude/skills/plan-breakdown/SKILL.md' => [
                'the acceptance-criteria rule credits the spec pass to `/review:debrief`' => "~`/review:debrief`'s spec pass~",
            ],
        ];
        $sources = $requiredSources($required);

        // Act
        $missing = $missingAcrossFiles($sources, $required);

        // Assert
        expect($missing)->toBe([])
            ->and($sources->count())->toBe(4)
            ->and($sources->sum(fn (string $source): int => ToolkitFiles::lineCount($source)))->toBeGreaterThan(700);
    });

    it('routes through the debrief stage in the map skill', function () use ($mapSource): void {
        // The map is the router a human opens to find a stage, so a wrong name
        // there is wrong at exactly the moment someone is already lost. It
        // states the review chain twice — once as the flow diagram, once as
        // prose — and the two can go stale one at a time.
        // Arrange
        $source = $mapSource();
        $required = [
            'the main-flow diagram runs create-pr into debrief' => '~create-pr\s*─▶\s*debrief~',
            'the review chain prose runs `/review:create-pr` into `/review:debrief`' => '~`/review:create-pr`[^\n]*→\s*`/review:debrief`~',
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($source, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan(100);
    });
});

describe('human review stage', function () use ($reviewCommandSource, $withinPhase, $mapSource, $commandNames): void {
    it('tells the reviewer to put every point on a line', function () use ($reviewCommandSource, $withinPhase): void {
        // A submitted review carries line-anchored comments AND a body, and only
        // the first reaches the pipeline: `review-feedback-collector` parses a
        // review body for `/review:add`-shaped findings alone, so a person's prose
        // summary yields no items at all. Nothing shows the reviewer that. The
        // review submitted, GitHub renders the body, and the ingest reports zero —
        // so the one reader who could correct it concludes the pipeline lost their
        // work. Said once before the read it costs a sentence; unsaid it costs the
        // whole review.
        // The zero-item list is the same instruction from the other end. It names a
        // draft and a clean read, and a reviewer who wrote a body summary matches
        // neither. Both halves are scoped to the phase that owes them, because a
        // cause named in the wrong phase arrives after the read it was meant to
        // shape.
        // Arrange
        $source = $reviewCommandSource('human');
        $required = [
            'Phase 1 says a point written in the review body reaches no later stage' => $withinPhase(
                1,
                '\bbody\b',
            ),
            'the zero-item path names the review body as a third cause, and counts three' => $withinPhase(
                2,
                '\bbody\b',
                '(?i:\bthree\b)',
            ),
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($source, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan(30);
    });

    it('ships a human command carrying the stage own commitments', function () use ($reviewCommandSource, $withinPhase): void {
        // Every way this stage fails is silent. A file that announces the wrong
        // frontmatter `name` is unreachable under the name every router points
        // at, and the harness reports no error for a command nobody found. A
        // Phase 1 that prints no diff link, or that returns before the review is
        // submitted, hands the later stages a PR with nothing on it — the
        // collect comes back empty and the loop reads as a clean review. And the
        // reason a collect comes back empty is the one thing the reviewer cannot
        // see from Linear: a review left as a draft is never synced to GitHub,
        // so the pipeline has no way to know it exists. Unwritten, that leaves
        // the author staring at "0 items" with their own review on screen.
        // The two phase commitments are scoped to the phase that owes them, so
        // the same words elsewhere in the file cannot stand in for them.
        // Arrange
        $source = $reviewCommandSource('human');
        $required = [
            'the frontmatter names the command `review:human`' => '~^name:\s*review:human\s*$~m',
            'Phase 1 hands over the Linear diff link `linear.review/{owner}/{repo}/pull/{n}` and waits for a submitted review' => $withinPhase(
                1,
                'linear\.review/\{[^}\n]+\}/\{[^}\n]+\}/pull/\{[^}\n]+\}',
                '\bsubmit',
            ),
            'Phase 2 delegates the ingest to `/review:process --human-round`' => $withinPhase(
                2,
                '`?/review:process\s+--human-round',
            ),
            'the zero-item path names a review saved as a draft — never synced to GitHub, so the pipeline cannot see it' => '~\bdraft\b(?:(?!\n\n).){0,400}(?:GitHub|pipeline)~is',
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($source, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan(30);
    });

    it('names every command on disk in the map roster', function () use ($mapSource, $commandNames): void {
        // The map is the one page that claims to name the whole toolkit, and it
        // says so itself: a command that never lands here is invisible. Nothing
        // else can catch the omission — a command missing from the router still
        // runs perfectly for anyone who already knows it is there, so the only
        // person the gap reaches is the one who came to the map because they did
        // not. The count guards above cannot stand in for this: they compare a
        // number to a number, and a map that says 9 while naming 8 of them and
        // double-counting one passes every check there.
        // Arrange
        $source = $mapSource();
        $commands = $commandNames();

        // Act
        $unnamed = $commands
            ->reject(fn (string $command): bool => Str::contains($source, '/'.$command))
            ->values()
            ->all();

        // Assert
        expect($unnamed)->toBe([])
            ->and($commands->count())->toBeGreaterThan(5);
    });
});

describe('review run stage sequence', function () use (
    $reviewCommandSource,
    $stageHeadings,
    $withinStage,
    $contractSection,
    $requiredSources,
    $missingAcrossFiles,
): void {
    it('numbers its stage headings contiguously from zero', function () use ($reviewCommandSource, $stageHeadings): void {
        // Inserting a stage mid-pipeline renumbers every stage after it by hand,
        // and nothing downstream can tell that it went wrong: the orchestrator is
        // prose an agent walks top to bottom, so a duplicated `Stage 4` runs twice
        // under one name, a skipped `Stage 5` reads as a stage that was deleted on
        // purpose, and either way the loop finishes and reports success. The whole
        // run is asserted as one sequence rather than stage by stage so the
        // failure prints the numbering that is actually on disk.
        // Arrange
        $source = $reviewCommandSource('run');

        // Act
        $numbering = $stageHeadings($source)->pluck('number')->all();

        // Assert
        expect($numbering)->toBe([0, 1, 2, 3, 4, 5, 6])
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan(100);
    });

    it('defers stages one through five to the five pipeline commands in order', function () use ($reviewCommandSource, $stageHeadings): void {
        // A stage heading names the command the stage defers to, so the headings
        // ARE the pipeline order — and a renumber that leaves the titles where
        // they were produces a file that still numbers 0 through 6 cleanly while
        // running the human read after the engines it was inserted to precede.
        // The second half is the other failure: a heading may name a command that
        // is not on disk, which the harness answers by finding nothing to run and
        // continuing to the next stage.
        // Read in document order, never sorted — the order written is the order
        // the agent walks, and reordering it here would hide the defect. Stages 0
        // and 6 are deliberately command-less (Stage 0 invokes a skill, Stage 6's
        // mechanics live inline), so neither is asked for one.
        // Arrange
        $source = $reviewCommandSource('run');
        $headings = $stageHeadings($source);

        // Act
        $deferred = $headings
            ->whereBetween('number', [1, 5])
            ->map(fn (array $heading): string => preg_match('~^[a-z0-9-]+~', $heading['title'], $slug) === 1
                ? $slug[0]
                : $heading['title'])
            ->values();

        // Assert
        expect($deferred->all())->toBe(['create-pr', 'debrief', 'human', 'suite', 'process'])
            ->and($deferred
                ->reject(fn (string $command): bool => is_file(ToolkitFiles::path('.claude/commands/review/'.$command.'.md')))
                ->values()
                ->all())->toBe([])
            ->and($headings->count())->toBeGreaterThan(3);
    });

    it('runs debrief into human into suite everywhere the sequence is written', function () use ($requiredSources, $missingAcrossFiles): void {
        // The pipeline order is stated in four places across two files — the
        // orchestrator's `Loop:` line, the frontmatter `description:` the harness
        // renders in the command list, and the map's flow diagram plus its review
        // chain prose. A sequence written four times drifts in three of them, and
        // none of the three can fail loudly: each is prose a reader trusts, so a
        // stale copy teaches the pipeline it used to be rather than the one that
        // runs. The map is the worst of them, because a reader is only there
        // because they were already lost.
        // Every pattern states the POSITIVE end state — human sits between
        // debrief and suite — never the absence of the old adjacency, so a later
        // edit that drops the stage fails here instead of passing on a token that
        // happens to be gone.
        // Arrange
        $required = [
            '.claude/commands/review/run.md' => [
                'the `Loop:` line runs `/review:debrief` into `/review:human` into `/review:suite`' => '~^Loop:.{0,300}`/review:debrief`[^`]{0,40}→[^`]{0,40}`/review:human`[^`]{0,40}→[^`]{0,40}`/review:suite`~ms',
                'the frontmatter `description:` the harness displays names human between debrief and suite' => '~^description:[^\n]*\bdebrief\s*→\s*human\s*→\s*suite\b~m',
            ],
            '.claude/skills/map/SKILL.md' => [
                'the main-flow diagram runs debrief into human into suite' => '~debrief\s*─▶\s*human\s*─▶\s*suite~',
                'the review chain prose runs `/review:debrief` into `/review:human` into `/review:suite`' => '~`/review:debrief`.{0,200}→\s*`/review:human`.{0,200}→\s*`/review:suite`~s',
            ],
        ];
        $sources = $requiredSources($required);

        // Act
        $missing = $missingAcrossFiles($sources, $required);

        // Assert
        expect($missing)->toBe([])
            ->and($sources->count())->toBe(2)
            ->and($sources->sum(fn (string $source): int => ToolkitFiles::lineCount($source)))->toBeGreaterThan(200);
    });

    it('states why the human stage precedes the adversarial engines', function () use ($reviewCommandSource, $withinStage): void {
        // This stage's POSITION is the whole design, and nothing about the file
        // makes that visible: seven stages in a list read as interchangeable, so
        // the next person to tidy the loop moves the slow interactive one later
        // and every reason it sat here is lost with no test to fail. The three
        // reasons are asserted separately because they answer three different
        // objections, and losing one to an edit leaves the other two reading like
        // the complete case.
        // Scoped to Stage 3's own block: a rationale a reader meets two stages
        // later arrives after they have already decided to move it. The second
        // reason is tempered to its own bullet as well, because `already` is prose
        // in the bullet above it too — a pair of stage-wide lookaheads holds on
        // that other bullet's copy of the word, so the assertion could not fail for
        // the reason its name gives.
        // Arrange
        $source = $reviewCommandSource('run');
        $required = [
            'Stage 3 says findings the engines already posted would bias the human reader' => $withinStage(
                3,
                '\bbias(?:es|ed|ing)?\b',
                '\b(?:engine|bot)',
            ),
            'Stage 3 says the engines then review better code, because the structural objections are already fixed' => $withinStage(
                3,
                '^- \*\*(?=(?:(?!^- ).)*\bstructural\b)(?=(?:(?!^- ).)*\balready\b)',
            ),
            'Stage 3 says the suite covers this round of fixes, so the stage needs no delta pass of its own' => $withinStage(
                3,
                '\bdelta\b',
                '\bsuite\b',
                '\bcover',
            ),
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($source, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan(100);
    });

    it('points the defer-do-not-duplicate exception at the renumbered delta stage', function () use ($reviewCommandSource, $contractSection): void {
        // The Rules section is the only place the orchestrator explains itself,
        // and both of its stage references are to stages the insert renumbers. A
        // rule naming the wrong number is worse than a missing one: "Stage 5 is
        // the one exception — it has no command file of its own" now describes
        // `/review:process`, which has one, so the next agent to read it either
        // reimplements a stage that already exists or leaves the delta stage's
        // mechanics somewhere the file says they do not belong.
        // Each pattern is tempered to the bullet that owes it, because the two
        // bullets name the same stages and a section-wide match would let either
        // one stand in for the other.
        // Arrange
        $section = $contractSection($reviewCommandSource('run'), 'Rules');
        $required = [
            'the **Defer, don\'t duplicate.** exception names Stage 6 as the command-less stage' => '~\*\*Defer, don.t duplicate\.\*\*(?=(?:(?!^- \*\*).)*\bStage 6\b)~ms',
            'the every-commit-gets-reviewed rule has Stage 6 reviewing Stage 5\'s fixes' => '~\*\*Every commit the loop produces(?=(?:(?!^- \*\*).)*\bStage 6\b)(?=(?:(?!^- \*\*).)*\bStage 5\b)~ms',
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($section, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(Str::length($section))->toBeGreaterThan(200);
    });
});

describe('human round contract', function () use (
    $reviewCommandSource,
    $contractSection,
    $nearInParagraph,
    $withinPhase,
    $requiredSources,
    $missingAcrossFiles,
): void {
    it('documents the human-round flag in the command that is invoked with it', function () use ($reviewCommandSource, $contractSection, $missingAcrossFiles): void {
        // `/review:human` Phase 2 hands its whole ingest to
        // `/review:process --human-round`, and an undocumented flag is a no-op
        // rather than an error: `/review:process` reads an argument it was never
        // told about as nothing at all, runs the bot round it already knew how to
        // run, collects `isBot` items only, and reports zero — with the
        // reviewer's own comments sitting on the PR unread. Nothing on either
        // side reports the mismatch.
        // Both halves are asserted so the guard can never pass on one file alone:
        // the caller naming a flag the target does not document, and the target
        // documenting a flag no caller passes, are the same drift seen from two
        // ends. The process half is scoped to `## Input` — that is the section a
        // reader consults for the invocation, and a flag mentioned only in a
        // phase body is not documented.
        // Arrange
        $required = [
            '.claude/commands/review/process.md' => [
                'the `## Input` section documents the `--human-round` flag' => '~--human-round~',
            ],
            '.claude/commands/review/human.md' => [
                'Phase 2 invokes the ingest as `/review:process --human-round`' => '~/review:process\s+--human-round~',
            ],
        ];
        $sources = collect([
            '.claude/commands/review/process.md' => $contractSection($reviewCommandSource('process'), 'Input'),
            '.claude/commands/review/human.md' => $reviewCommandSource('human'),
        ]);

        // Act
        $missing = $missingAcrossFiles($sources, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(Str::length($sources->get('.claude/commands/review/process.md')))->toBeGreaterThan(150)
            ->and(ToolkitFiles::lineCount($sources->get('.claude/commands/review/human.md')))->toBeGreaterThan(30);
    });

    it('exempts human items from both fail-closed validators', function () use ($reviewCommandSource, $nearInParagraph): void {
        // Phase 1 step 1 already routes anything that did not come from our own
        // pipeline — "human reviewers, general comments" by name — to a validator
        // as external feedback, so the human round inherits that default unless
        // the exemption is written in. Both validators are fail-closed: they DROP
        // what they cannot confirm. A deliberate human read deleted by a machine
        // that could not verify it is the one failure this stage cannot have, and
        // it is invisible from both ends — the reviewer sees their comment absent
        // from the gate list, and the run reports a shorter list, not a loss.
        // The reason is asserted beside the rule, in the same paragraph: an
        // exemption with no stated cause reads as a special case, and a special
        // case is the first thing an editor tidying the file takes back out.
        // Arrange
        $source = $reviewCommandSource('process');
        $required = [
            'the human round exempts its items from `review-bug-validator`' => $nearInParagraph('(?i:exempt)', 'review-bug-validator'),
            'the human round exempts its items from `review-compliance-validator`' => $nearInParagraph('(?i:exempt)', 'review-compliance-validator'),
            'the exemption states why — a fail-closed validator drops what it cannot confirm' => $nearInParagraph('(?i:fail-closed)', '(?i:drop)'),
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($source, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan(300);
    });

    it('gives conversational items their own un-ranked bucket', function () use ($reviewCommandSource, $nearInParagraph): void {
        // `APPROVE`, `DISCUSS` and `SKIP` each assume the item is a CLAIM ABOUT
        // THE CODE, and most of what a human writes is not one — a question, an
        // observation, a judgment call. With no bucket of its own, a question is
        // filed under a bucket that answers it with a fixer dispatch or a "we
        // skipped this" reply, and the reviewer's question is never answered at
        // all. The three dispositions are asserted one by one because they carry
        // three different outcomes — an answer, a reply-and-resolve, a Phase 6
        // ticket offer — and losing one leaves the other two reading complete.
        // The tokens are matched case-SENSITIVELY: these are the literal group and
        // disposition names the gate prints, and the file already carries the
        // prose words `conversation` and `answer` in lowercase, which a
        // case-insensitive pattern would accept in their place.
        // Arrange
        $source = $reviewCommandSource('process');
        $required = [
            'the gate prints a `CONVERSATION` group' => '~\bCONVERSATION\b~',
            'the `ANSWER` disposition answers the item inline' => '~\bANSWER\b~',
            'the `ACKNOWLEDGE` disposition replies and resolves with no fixer' => '~\bACKNOWLEDGE\b~',
            'the `TICKET` disposition offers a ticket in the Phase 6 batch' => '~\bTICKET\b~',
            'the slot table carries an `Answer:` row' => '~^\|\s*`Answer:`\s*\|~m',
            'the `CONVERSATION` group states it carries no `[SEVERITY]` tag' => $nearInParagraph('CONVERSATION', '\bno\b(?:(?!\n\n).){0,120}(?i:severit)'),
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($source, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan(300);
    });

    it('carries a conversational item through the phases that branch on severity', function () use ($reviewCommandSource, $withinPhase): void {
        // `CONVERSATION` is the first item class the gate prints with no severity,
        // and every phase it passes through was written to branch on one. Phase 1
        // routes an out-of-scope item by tier, so an item carrying no tier matches
        // neither branch and reaches no list — dropped with no reply and no
        // resolve. Phase 2 then closes on a terminal set of three that no
        // conversational disposition is in, under an override grammar that cannot
        // name one, so `ANSWER`, `ACKNOWLEDGE` and `TICKET` end nothing and the
        // user cannot move an item between them. And an `ANSWER` escalated because
        // it implies a change has nowhere to print: `DISCUSS` sorts by severity,
        // which the item does not carry until the escalation assigns one.
        // Every one of those is silent in the same way — the run reports a list
        // short by exactly the items nobody handled, and the reviewer is the only
        // person who could notice.
        // Each pattern is scoped to the phase that owes it, and the escalation is
        // tempered to the `ANSWER` bullet: its two neighbours carry the same
        // dispositions, and a phase-wide match would let either stand in for it.
        // Arrange
        $source = $reviewCommandSource('process');
        $required = [
            'Phase 1 routes a `CONVERSATION` item onward whatever its scope mark' => $withinPhase(
                1,
                '\*\*Route by scope(?:(?!^\d+\.\s).)*\bCONVERSATION\b',
            ),
            'Phase 2 ends a conversational item as answered, acknowledged or ticketed' => $withinPhase(
                2,
                '(?i:\banswered\b)',
                '(?i:\backnowledged\b)',
                '(?i:\bticketed\b)',
            ),
            'the Phase 2 override grammar names the three conversational tokens beside approve and skip' => $withinPhase(
                2,
                '<(?=[^>\n]*\bapprove\b)(?=[^>\n]*\bskip\b)(?=[^>\n]*\banswer\b)(?=[^>\n]*\backnowledge\b)(?=[^>\n]*\bticket\b)[^>\n]*>',
            ),
            'an escalated `ANSWER` leaves `CONVERSATION` for `DISCUSS`, under the severity the change carries' => '~^- \*\*`ANSWER`\*\*(?=(?:(?!^- \*\*).)*\bCONVERSATION\b)(?=(?:(?!^- \*\*).)*\bDISCUSS\b)(?=(?:(?!^- \*\*).)*(?i:severit))~ms',
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($source, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan(300);
    });

    it('replies to a TICKET item once the user has ruled on the ticket', function () use ($reviewCommandSource, $withinPhase): void {
        // Phase 5 replies to every disposition but this one, and the gap is not a
        // missing sentence so much as a reply that cannot be written where the
        // others are: Phase 6 is where the user rules on the ticket batch, and it
        // runs after Phase 5 — so a reply drafted in place promises a ticket
        // nobody approved. The declined half is the one an editor drops as
        // redundant. A ticket the user turned down still owes the reviewer an
        // answer, and a reply that never comes reads as agreement.
        // Scoped to Phase 5 because that is the phase that owes the reply; the
        // ticket offer itself is Phase 6's and is asserted nowhere near here.
        // Arrange
        $source = $reviewCommandSource('process');
        $required = [
            'Phase 5 replies to a `TICKET` item' => $withinPhase(5, '\bTICKET\b'),
            'the reply waits for Phase 6, where the user rules on the ticket' => $withinPhase(5, '\bPhase 6\b'),
            'a ticket the user declined still gets its reply' => $withinPhase(5, '(?i:declin)'),
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($source, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan(300);
    });

    it('routes a human item it judges wrong to DISCUSS rather than a fixer', function () use ($reviewCommandSource, $nearInParagraph): void {
        // Unwritten, this is the failure with no trace at all. The pipeline reads
        // a human item it believes is wrong, has no rule for the disagreement,
        // and does the agreeable thing: it dispatches a fixer and the change
        // lands. Nobody is told a judgment was overruled by a machine that
        // disagreed with it, because the run reports the fix as an approval like
        // any other. `DISCUSS` already holds the gate, so the cost of routing it
        // there is one keystroke from the user — and the tradeoff has to be IN
        // the item's slots, or the user rules on a disagreement they cannot see.
        // All three are anchored to the same paragraph as the rule's own name, so
        // the rule survives as a rule instead of decaying into a `DISCUSS`
        // mention that no longer says why or what it withholds.
        // Arrange
        $source = $reviewCommandSource('process');
        $required = [
            'the disagreement rule marks the item `DISCUSS` and holds the gate' => $nearInParagraph('(?i:disagree)', '\bDISCUSS\b'),
            'the disagreement rule states the tradeoff in the item own slots' => $nearInParagraph('(?i:disagree)', '(?i:trade-?off)'),
            'the disagreement rule dispatches no fixer on a change it judges wrong' => $nearInParagraph('(?i:disagree)', '(?i:fixer)'),
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($source, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan(300);
    });

    it('writes down the inbound classification vocabulary', function () use ($reviewCommandSource, $nearInParagraph): void {
        // Classification is what decides whether a comment reaches a fixer, gets
        // an answer, or becomes a ticket, and an unwritten vocabulary makes that
        // decision a guess the run never shows its work for. Two clauses carry
        // the whole safety of the guess: an explicit prefix beats the inference,
        // so a reviewer who says `question:` is never overruled by a model that
        // read it as an issue; and an ambiguous item goes to `DISCUSS`, so the
        // guess that could go either way costs a question rather than a silent
        // fixer dispatch against code the reviewer never asked to change.
        // Each label is matched BACKTICKED and lowercase — that is the
        // Conventional Comments spelling, and it is also what keeps the guard
        // honest: the bare words `issue` and `question` are already prose in this
        // file, and would pass a plain word match on text that documents nothing.
        // Arrange
        $source = $reviewCommandSource('process');
        $required = [
            'the vocabulary is named as Conventional Comments' => '~(?i:conventional comments)~',
            'the `praise` label' => '~`praise:?`~',
            'the `nitpick` label' => '~`nitpick:?`~',
            'the `suggestion` label' => '~`suggestion:?`~',
            'the `issue` label' => '~`issue:?`~',
            'the `question` label' => '~`question:?`~',
            'the `thought` label' => '~`thought:?`~',
            'the `chore` label' => '~`chore:?`~',
            'an explicit prefix overrides the inferred classification' => $nearInParagraph('(?i:prefix)', '(?i:overrid)'),
            'an ambiguous item goes to `DISCUSS`' => $nearInParagraph('(?i:ambiguous)', '\bDISCUSS\b'),
        ];

        // Act
        $missing = ToolkitFiles::missingPatterns($source, $required);

        // Assert
        expect($missing)->toBe([])
            ->and(ToolkitFiles::lineCount($source))->toBeGreaterThan(300);
    });

    it('runs debrief into human in the last two chain sentences', function () use ($requiredSources, $missingAcrossFiles): void {
        // These two sentences are the last places the pipeline order is written
        // down, and both are read at the moment someone is deciding what to run
        // next: `/review:process` opens by naming the loop it closes, and
        // `/review:suite` opens by placing itself in it. A chain that still steps
        // straight from debrief to the engines teaches a reader the pipeline that
        // used to exist, and the human read is skipped by someone following the
        // file exactly as written — with no error anywhere, because every stage
        // named is real.
        // Both patterns state the POSITIVE end state — human holds the slot after
        // debrief — never the absence of the old adjacency, so a later edit that
        // drops the stage fails here rather than passing on a token that happens
        // to be gone. Adjacency is spelled `[^`]` so a rewrapped chain still
        // matches, while an intervening stage — always backticked in these
        // sentences — cannot slip between the two ends.
        // Arrange
        $required = [
            '.claude/commands/review/process.md' => [
                'the opening chain sentence runs `/review:create-pr` into `/review:debrief` into `/review:human`' => '~`/review:create-pr`[^`]{0,80}→[^`]{0,80}`/review:debrief`[^`]{0,80}→[^`]{0,80}`/review:human`~',
            ],
            '.claude/commands/review/suite.md' => [
                'the `Loop position:` sentence runs `/review:debrief` into `/review:human` into `/review:suite`' => '~^Loop position:.{0,300}`/review:debrief`[^`]{0,120}→[^`]{0,120}`/review:human`[^`]{0,120}→[^`]{0,120}`/review:suite`~ms',
            ],
        ];
        $sources = $requiredSources($required);

        // Act
        $missing = $missingAcrossFiles($sources, $required);

        // Assert
        expect($missing)->toBe([])
            ->and($sources->count())->toBe(2)
            ->and($sources->sum(fn (string $source): int => ToolkitFiles::lineCount($source)))->toBeGreaterThan(400);
    });
});
