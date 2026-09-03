<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tests\Support\ToolkitFiles;

/**
 * Drift guard for the `.claude/` agent toolkit: the commit trailer it instructs
 * agents to write carries no model version stamp, and every review/hunter agent
 * pins the model its review phase is meant to run on.
 *
 * File reading, line splitting and the agent-roster sweep come from
 * `Tests\Support\ToolkitFiles`, shared with the other toolkit guards.
 *
 * NB: the offending trailer shape lives in a PHP string literal (never in a `//`
 * comment) so widening the scan can never make this file its own offender; the
 * scan is scoped strictly to `.claude/` for the same reason.
 */

/**
 * The `model:` value an agent declares in its frontmatter, or null when absent.
 */
$declaredModel = function (string $agent): ?string {
    $lines = ToolkitFiles::splitLines(ToolkitFiles::read('.claude/agents/'.$agent.'.md'));

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

describe('.claude/ toolkit commit trailers', function (): void {
    it('carries no model-stamped co-author trailer anywhere under .claude/', function (): void {
        // The bare `Co-Authored-By: Claude <noreply@anthropic.com>` is the
        // drift-free form we want; anything wedged between the name and the address
        // is a version stamp that rots the moment the model changes. Matched
        // case-insensitively because history also carries `Co-authored-by`.
        // Arrange
        $stamped = '#co-authored-by:\s*Claude\s+[^<]+<noreply@anthropic\.com>#i';
        $lines = ToolkitFiles::scanLines(
            (new Finder)->files()->in(ToolkitFiles::path('.claude'))->name('*.md'),
        );

        // Act
        $offenders = collect($lines)
            ->filter(fn (array $l): bool => preg_match($stamped, $l['text']) === 1)
            ->map(fn (array $l): string => sprintf('%s:%d  %s', $l['file'], $l['line'], Str::trim($l['text'])))
            ->values()
            ->all();

        // Assert
        expect($offenders)->toBe([]);
    });
});

describe('agent frontmatter model pinning', function () use ($declaredModel): void {
    it('runs every write-side agent on the session model', function () use ($declaredModel): void {
        // These agents produce code the session owns, so pinning one would hand part
        // of the work to a model the session never chose.
        // Arrange
        $agents = [
            'review-fixer',
            'tdd-test-writer',
            'tdd-implementer',
            'tdd-refactorer',
        ];

        // Act
        $declared = array_combine($agents, array_map($declaredModel, $agents));

        // Assert
        expect($declared)->toBe(array_fill_keys($agents, 'inherit'));
    });

    it('pins the review triage agents to the haiku alias', function () use ($declaredModel): void {
        // Triage is mechanical — a skip/no-skip call and a diff summary — so it runs
        // on the cheapest tier. The bare alias, never a dated id, so the tier tracks
        // the current Haiku instead of a retired snapshot.
        // Arrange
        $agents = [
            'review-skip-check',
            'review-summarizer',
        ];

        // Act
        $declared = array_combine($agents, array_map($declaredModel, $agents));

        // Assert
        expect($declared)->toBe(array_fill_keys($agents, 'haiku'));
    });

    it('pins the compliance agents and the CLI wrapper to the sonnet alias', function () use ($declaredModel): void {
        // Convention compliance is pattern matching against a written rule set —
        // more than triage, but it never has to reason about the session's own work,
        // so it does not follow the session model. `coderabbit-reviewer` sits on the
        // same tier for a weaker reason: it shells a CLI and reshapes the output.
        // The bare alias, never a dated id, so the pin tracks the current Sonnet.
        // Arrange
        $agents = [
            'review-compliance',
            'review-compliance-validator',
            'coderabbit-reviewer',
        ];

        // Act
        $declared = array_combine($agents, array_map($declaredModel, $agents));

        // Assert
        expect($declared)->toBe(array_fill_keys($agents, 'sonnet'));
    });

    it('runs the bug agents on the session model', function () use ($declaredModel): void {
        // Finding a real defect is the hardest judgement in the pipeline, so these
        // follow whatever model the session runs rather than capping themselves at a
        // tier the session did not choose.
        // Arrange
        $agents = [
            'review-bug-hunter',
            'review-bug-validator',
        ];

        // Act
        $declared = array_combine($agents, array_map($declaredModel, $agents));

        // Assert
        expect($declared)->toBe(array_fill_keys($agents, 'inherit'));
    });

    it('declares a permitted model on every agent file, including ones added later', function () use ($declaredModel): void {
        // The tests above name today's agents, so a *newly added* agent file is
        // guarded by nothing. This sweeps the directory instead: rules 1–3 of Model
        // Selection in `.claude/skills/review-pipeline/SKILL.md` admit these three
        // values and no others, and rule 2 bars a dated model id outright.
        // A missing `model:` key is an offender too, not an exemption — an unpinned
        // agent silently takes the harness default, which is the drift the policy
        // exists to stop, and `inherit` is how a role opts into the session model
        // deliberately.
        // The count floor keeps the sweep honest: a roster that resolves nothing
        // reports an empty offender list forever, which reads identically to a clean
        // directory.
        // Arrange
        $permitted = ['haiku', 'sonnet', 'inherit'];
        $agents = ToolkitFiles::agentNames();

        // Act
        $offenders = $agents
            ->reject(fn (string $agent): bool => in_array($declaredModel($agent), $permitted, true))
            ->map(fn (string $agent): string => sprintf(
                '.claude/agents/%s.md  declares model: %s',
                $agent,
                $declaredModel($agent) ?? '(none)',
            ))
            ->values()
            ->all();

        // Assert
        expect($offenders)->toBe([])
            ->and($agents->count())->toBeGreaterThan(5);
    });

    it('declares no dated model id on any agent file', function () use ($declaredModel): void {
        // A dated snapshot (`claude-haiku-4-5-20251001`) pins a role to a build that
        // is eventually retired, and the failure is silent — the harness just stops
        // dispatching. Only the bare aliases are allowed to appear, so no `model:`
        // value may carry a date suffix.
        // The floor is two-part on purpose. `toBeGreaterThan(5)` catches a roster
        // that resolved nothing; matching the file count catches the subtler vacuity
        // this particular scan invites — a file with no `model:` key contributes no
        // value to match against, so it would pass the date check by having nothing
        // to check.
        // Arrange
        $dated = '#-\d{8}$#';
        $agents = ToolkitFiles::agentNames();

        // Act
        $declared = $agents->mapWithKeys(fn (string $agent): array => [$agent => $declaredModel($agent)])->filter();

        // Assert
        expect($declared->filter(fn (string $model): bool => preg_match($dated, $model) === 1)->keys()->all())->toBe([])
            ->and($declared->count())->toBeGreaterThan(5)
            ->and($declared->count())->toBe($agents->count());
    });
});
