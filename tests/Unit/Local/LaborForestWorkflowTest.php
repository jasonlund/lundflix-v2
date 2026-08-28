<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Drift guard for the `.laborforest/workflows/*.yaml` worktree lifecycle files.
 *
 * The workflows are consumed by an external macOS app, so nothing in this repo
 * runs them and nothing here notices when they rot. Their destructive steps —
 * dropping the workspace database, unlinking the Herd site, deleting the Solo
 * project — are separated from the primary checkout's `lundflix` database by a
 * single `if:` string. A dropped or mistyped guard is invisible until an `lf`
 * run destroys the primary. These tests are the only thing that can see it.
 *
 * Steps are therefore identified by WHAT THEY DO (their `run` content), never by
 * position: matching on an array index would let a later insertion slide an
 * unguarded destructive step past a green suite.
 *
 * Scope limit, deliberate: the `down` sweep is an allowlist of three named
 * destructive acts, not a classifier of destructiveness. A step of a fourth kind
 * — an `rm -rf`, a second unlink — is neither checked nor reported, and only
 * `down` is swept at all (`up` is checked for its nested `refresh` call alone).
 * So a green run is evidence that these three acts are guarded, never that they
 * are the only ones present. Adding a destructive step means adding its matcher.
 */

/** The guard every destructive step must carry, verbatim. */
$primaryGuard = 'test "{{ WORKSPACE_DIR }}" != "{{ PROJECT_PRIMARY_DIR }}"';

/**
 * The parsed contents of one workflow file.
 *
 * @return array<string, mixed>
 */
$workflow = function (string $name): array {
    // The Unit suite doesn't boot the app container, so resolve the repo root
    // from this file's location rather than base_path(). One level deeper than
    // the guards in tests/Unit, hence 3.
    $root = dirname(__DIR__, 3);

    return (array) Yaml::parseFile($root.'/.laborforest/workflows/'.$name.'.yaml');
};

/**
 * A workflow's step maps, in declaration order.
 *
 * @param  array<string, mixed>  $workflow
 * @return list<array<string, mixed>>
 */
$stepsOf = fn (array $workflow): array => collect((array) ($workflow['steps'] ?? []))
    ->filter(fn (mixed $step): bool => is_array($step))
    ->values()
    ->all();

describe('workflow declarations', function () use ($workflow): void {
    it('declares each workflow with the status transition it performs', function () use ($workflow): void {
        // `ready` and `suspended` are the only declarable statuses, so the table
        // below is the whole lifecycle: up wakes a suspended workspace, refresh
        // is idempotent on a ready one, down puts it back to sleep.
        // Arrange
        $names = ['up', 'refresh', 'down'];

        // Act
        $declared = collect($names)
            ->mapWithKeys(fn (string $name): array => [$name => [
                'resource_type' => $workflow($name)['resource_type'] ?? null,
                'require_status' => $workflow($name)['require_status'] ?? null,
                'ending_status' => $workflow($name)['ending_status'] ?? null,
                'sort_order' => $workflow($name)['sort_order'] ?? null,
            ]])
            ->all();

        // Assert
        expect($declared)->toBe([
            'up' => [
                'resource_type' => 'workflow',
                'require_status' => 'suspended',
                'ending_status' => 'ready',
                'sort_order' => 0,
            ],
            'refresh' => [
                'resource_type' => 'workflow',
                'require_status' => 'ready',
                'ending_status' => 'ready',
                'sort_order' => 1,
            ],
            'down' => [
                'resource_type' => 'workflow',
                'require_status' => 'ready',
                'ending_status' => 'suspended',
                'sort_order' => 100,
            ],
        ]);
    });
});

describe('primary-checkout guard', function () use ($workflow, $stepsOf, $primaryGuard): void {
    it('guards the nested refresh call in up against the primary checkout', function () use ($workflow, $stepsOf, $primaryGuard): void {
        // Arrange
        $steps = $stepsOf($workflow('up'));

        // Act
        $refreshCall = collect($steps)->first(fn (array $step): bool => ($step['type'] ?? null) === 'workflow'
            && ($step['run'] ?? null) === 'refresh');

        // Assert
        expect($refreshCall)->not->toBeNull()
            ->and($refreshCall['if'] ?? null)->toBe($primaryGuard);
    });

    it('guards every destructive step in down against the primary checkout', function () use ($workflow, $stepsOf, $primaryGuard): void {
        // Each destructive act is recognised by what its `run` does, so a step
        // added later is judged on its content rather than its position.
        // Arrange
        $steps = $stepsOf($workflow('down'));
        $destructive = [
            'database drop' => fn (string $run): bool => preg_match('/\bDROP\s+DATABASE\b/i', $run) === 1,
            'herd unlink' => fn (string $run): bool => preg_match('/\bherd\s+unlink\b/i', $run) === 1,
        ];

        // Act
        $report = collect($destructive)
            ->map(function (Closure $matches) use ($steps, $primaryGuard): array {
                $hits = collect($steps)->filter(fn (array $step): bool => $matches((string) ($step['run'] ?? '')));

                return [
                    'present' => $hits->isNotEmpty(),
                    'unguarded' => $hits
                        ->reject(fn (array $step): bool => ($step['if'] ?? null) === $primaryGuard)
                        ->map(fn (array $step): string => (string) ($step['run'] ?? ''))
                        ->values()
                        ->all(),
                ];
            })
            ->all();

        // Assert
        expect($report)->toBe([
            'database drop' => ['present' => true, 'unguarded' => []],
            'herd unlink' => ['present' => true, 'unguarded' => []],
        ]);
    });
});

describe('up.yaml step ordering', function () use ($workflow, $stepsOf): void {
    // A fresh worktree has no vendor/, so `php artisan` cannot run until Composer
    // has. This ordering was wrong on the first real `lf run up`: step 4 died with
    // "Failed opening required .../vendor/autoload.php" and aborted the other ten.
    // Nothing else can catch it — `lf validate` exits 0 regardless, and every other
    // guard here matches steps by content precisely so that position never matters.
    // Ordering is the one property that genuinely is positional.
    it('installs Composer dependencies before the first step that runs artisan', function () use ($workflow, $stepsOf): void {
        // Arrange
        $runs = collect($stepsOf($workflow('up')))->map(fn (array $step): string => (string) ($step['run'] ?? ''))->values();

        // Act
        $position = [
            'composer install' => $runs->search(fn (string $run): bool => Str::contains($run, 'composer install')),
            'first artisan' => $runs->search(fn (string $run): bool => Str::contains($run, 'php artisan')),
        ];

        // Assert
        expect($position['composer install'])->toBeInt()
            ->and($position['first artisan'])->toBeInt()
            ->and($position['composer install'])->toBeLessThan($position['first artisan']);
    });
});

describe('up.yaml env derivation', function () use ($workflow, $stepsOf): void {
    it('derives the workspace env through the artisan command rather than an inline sed', function () use ($workflow, $stepsOf): void {
        // Arrange
        $runs = collect($stepsOf($workflow('up')))->map(fn (array $step): string => (string) ($step['run'] ?? ''));

        // Act
        $derivation = [
            'lf:workspace-env' => $runs->contains(fn (string $run): bool => Str::contains($run, 'lf:workspace-env')),
            'inline sed' => $runs->contains(fn (string $run): bool => preg_match('/\bsed\b/', $run) === 1),
        ];

        // Assert
        expect($derivation)->toBe([
            'lf:workspace-env' => true,
            'inline sed' => false,
        ]);
    });
});
