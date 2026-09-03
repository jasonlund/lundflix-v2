<?php

declare(strict_types=1);

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
 * Three parts, all static: the **roster** (which agent files exist, and who
 * names them), the **contract** (which sections of
 * `.claude/skills/review-pipeline/SKILL.md` survive, and what they say), and the
 * **inventory** (whether `.claude/skills/map/SKILL.md` — the router a human opens
 * to find a file — counts the files that are actually on disk).
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
 * The committed `/review:claude` command, read from disk.
 */
$reviewCommandSource = fn (): string => ToolkitFiles::read('.claude/commands/review/claude.md');

/**
 * One `## ` section of a markdown source, from its heading to the next one.
 */
$contractSection = function (string $source, string $heading): string {
    $pattern = sprintf('~^##\s+%s\s*$.*?(?=^##\s|\z)~ms', preg_quote($heading, '~'));

    return preg_match($pattern, $source, $matches) === 1 ? $matches[0] : '';
};

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
            ->and(preg_match('~stay silent[^.]{0,400}pre-existing~is', $reviewCommandSource()))->toBe(1)
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
