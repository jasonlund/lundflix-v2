<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Drift guard for the `.laborforest/workflows/*.yaml` worktree lifecycle files.
 *
 * The workflows are consumed by an external macOS app, so nothing in this repo
 * runs them and nothing here notices when they rot. Their destructive steps —
 * dropping the workspace database, unlinking the Herd site, and every step of
 * `refresh`, which reseeds from scratch — are separated from the primary
 * checkout's `lundflix` database by a single `if:` string. A dropped or mistyped
 * guard is invisible until an `lf` run destroys the primary. These tests are the
 * only thing that can see it.
 *
 * Steps are therefore identified by WHAT THEY DO (their `run` content), never by
 * position: matching on an array index would let a later insertion slide an
 * unguarded destructive step past a green suite. Ordering assertions are the one
 * exception, and they too locate their steps by content before comparing index.
 *
 * Scope limit, deliberate: the destructive sweeps are allowlists of named acts —
 * two in `down`, one in `up` — not a classifier of destructiveness. A step of an
 * unlisted kind — an `rm -rf`, a second unlink — is neither checked nor
 * reported. (`refresh` is swept wholesale instead, every step needing the guard;
 * `up` is additionally checked for its nested `refresh` call.) So a green run is
 * evidence that the listed acts are guarded, never that they are the only ones
 * present. Adding a destructive step to either file means adding its matcher.
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

/**
 * A workflow's `run` strings, in declaration order — the index is the step's
 * position, which the ordering assertions compare.
 *
 * @param  array<string, mixed>  $workflow
 * @return Collection<int, string>
 */
$runsOf = fn (array $workflow): Collection => collect($stepsOf($workflow))
    ->map(fn (array $step): string => (string) ($step['run'] ?? ''))
    ->values();

/**
 * The destructive acts each workflow performs, keyed by workflow then by act —
 * the `run` matchers behind the identify-by-content rule above.
 *
 * Listed per workflow rather than as one shared set because each sweep asserts
 * `present => true` for every act it names: a shared set would demand every act
 * appear in every file.
 *
 * @var array<string, array<string, Closure(string): bool>>
 */
$destructiveActs = [
    'up' => [
        'workspace sync' => fn (string $run): bool => Str::contains($run, 'lf:workspace-sync'),
    ],
    'down' => [
        'database drop' => fn (string $run): bool => preg_match('/\bDROP\s+DATABASE\b/i', $run) === 1,
        'herd unlink' => fn (string $run): bool => preg_match('/\bherd\s+unlink\b/i', $run) === 1,
    ],
];

/**
 * For each named act: whether the workflow still performs it, and the `run` of
 * every step performing it without the primary-checkout guard.
 *
 * @param  list<array<string, mixed>>  $steps
 * @param  array<string, Closure(string): bool>  $acts
 * @return array<string, array{present: bool, unguarded: list<string>}>
 */
$guardAudit = (fn (array $steps, array $acts): array => collect($acts)
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
    ->all());

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

describe('primary-checkout guard', function () use ($workflow, $stepsOf, $primaryGuard, $destructiveActs, $guardAudit): void {
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

    // `refresh` declares `require_status: ready` and the primary checkout is
    // ready, so it is independently runnable there — `up`'s guard on the nested
    // call does not travel with the file. Every step needs its own.
    it('guards every step in refresh against the primary checkout', function () use ($workflow, $stepsOf, $primaryGuard): void {
        // Arrange
        $steps = $stepsOf($workflow('refresh'));

        // Act
        $unguarded = collect($steps)
            ->reject(fn (array $step): bool => ($step['if'] ?? null) === $primaryGuard)
            ->map(fn (array $step): string => (string) ($step['run'] ?? ''))
            ->values()
            ->all();

        // Assert
        expect($steps)->not->toBeEmpty()
            ->and($unguarded)->toBe([]);
    });

    // The fast-forward deletes files, so it belongs to the same class as down's
    // two acts: run in the primary checkout it would clear `.laborforest/` there
    // and drag the primary's own working tree onto origin/main.
    it('guards every destructive step in up against the primary checkout', function () use ($workflow, $stepsOf, $destructiveActs, $guardAudit): void {
        // Arrange
        $steps = $stepsOf($workflow('up'));

        // Act
        $report = $guardAudit($steps, $destructiveActs['up']);

        // Assert
        expect($report)->toBe([
            'workspace sync' => ['present' => true, 'unguarded' => []],
        ]);
    });

    it('guards every destructive step in down against the primary checkout', function () use ($workflow, $stepsOf, $destructiveActs, $guardAudit): void {
        // Arrange
        $steps = $stepsOf($workflow('down'));

        // Act
        $report = $guardAudit($steps, $destructiveActs['down']);

        // Assert
        expect($report)->toBe([
            'database drop' => ['present' => true, 'unguarded' => []],
            'herd unlink' => ['present' => true, 'unguarded' => []],
        ]);
    });
});

describe('down.yaml failure tolerance', function () use ($workflow, $stepsOf, $destructiveActs): void {
    // LaborForest runs each step under `set -eu` and forces the workspace to
    // ERROR when one exits non-zero — and it offers Remove only on a suspended
    // workspace, so a teardown that aborts leaves the worktree undeletable. An
    // already-unlinked site or an unreachable MySQL is enough to trigger it.
    it('lets every destructive step fail without aborting the run', function () use ($workflow, $stepsOf, $destructiveActs): void {
        // Arrange
        $steps = $stepsOf($workflow('down'));
        $tolerates = fn (string $run): bool => preg_match('/\|\|\s*(true\b|echo\b)/', $run) === 1;

        // Act
        $report = collect($destructiveActs['down'])
            ->map(function (Closure $matches) use ($steps, $tolerates): array {
                $hits = collect($steps)->filter(fn (array $step): bool => $matches((string) ($step['run'] ?? '')));

                return [
                    'present' => $hits->isNotEmpty(),
                    'aborts the run' => $hits
                        ->map(fn (array $step): string => (string) ($step['run'] ?? ''))
                        ->reject($tolerates)
                        ->values()
                        ->all(),
                ];
            })
            ->all();

        // Assert
        expect($report)->toBe([
            'database drop' => ['present' => true, 'aborts the run' => []],
            'herd unlink' => ['present' => true, 'aborts the run' => []],
        ]);
    });
});

describe('down.yaml step ordering', function () use ($workflow, $runsOf): void {
    // `up` copies the primary's .env verbatim and only rewrites it four steps
    // later, so an `up` that aborted in between leaves DB_DATABASE=lundflix and
    // LF_SITE=lundflix-v2 in a worktree's .env. Every guard here compares
    // directories and so cannot see a stale name. LaborForest re-reads .env per
    // step, so re-deriving before the destructive steps is what makes the
    // {{ ENV_* }} they interpolate name the workspace's own resources.
    it('derives the workspace env before either destructive step', function () use ($workflow, $runsOf): void {
        // Arrange
        $runs = $runsOf($workflow('down'));

        // Act
        $position = [
            'derive env' => $runs->search(fn (string $run): bool => Str::contains($run, 'lf:workspace-env')),
            'database drop' => $runs->search(fn (string $run): bool => preg_match('/\bDROP\s+DATABASE\b/i', $run) === 1),
            'herd unlink' => $runs->search(fn (string $run): bool => preg_match('/\bherd\s+unlink\b/i', $run) === 1),
        ];

        // Assert
        expect($position['derive env'])->toBeInt()
            ->and($position['database drop'])->toBeInt()
            ->and($position['herd unlink'])->toBeInt()
            ->and($position['derive env'])->toBeLessThan($position['database drop'])
            ->and($position['derive env'])->toBeLessThan($position['herd unlink']);
    });
});

describe('up.yaml step ordering', function () use ($workflow, $runsOf): void {
    // A fresh worktree has no vendor/, so `php artisan` cannot run until Composer
    // has. This ordering was wrong on the first real `lf run up`: step 4 died with
    // "Failed opening required .../vendor/autoload.php" and aborted the other ten.
    // Nothing else can catch it — `lf validate` exits 0 regardless, and every other
    // guard here matches steps by content precisely so that position never matters.
    // Ordering is the one property that genuinely is positional.
    //
    // Only the WORKSPACE'S OWN artisan is bound by this: a step invoking the
    // primary checkout's binary (`php "{{ PROJECT_PRIMARY_DIR }}/artisan" …`)
    // boots against a vendor/ that already exists, so it may — and must — precede
    // Composer. Matching the bare literal `php artisan` would let exactly that
    // form slip past unseen, leaving the guard green and meaningless.
    it('installs Composer dependencies before the first step that runs the workspace\'s own artisan', function () use ($workflow, $runsOf): void {
        // Arrange
        $runs = $runsOf($workflow('up'));

        // Act
        $position = [
            'composer install' => $runs->search(fn (string $run): bool => Str::contains($run, 'composer install')),
            'first workspace artisan' => $runs->search(fn (string $run): bool => Str::contains($run, 'artisan')
                && ! Str::contains($run, 'PROJECT_PRIMARY_DIR')),
        ];

        // Assert
        expect($position['composer install'])->toBeInt()
            ->and($position['first workspace artisan'])->toBeInt()
            ->and($position['composer install'])->toBeLessThan($position['first workspace artisan']);
    });

    // The other half of that trade. Composer cannot move ahead of the
    // fast-forward — it would resolve the stale worktree's composer.lock — so an
    // artisan step genuinely does have to run before vendor/ exists, and the only
    // artisan that can boot there is the primary checkout's.
    it('runs any artisan step that precedes Composer install through the primary checkout\'s artisan', function () use ($workflow, $runsOf): void {
        // Arrange
        $runs = $runsOf($workflow('up'));

        // Act
        $beforeComposer = $runs
            ->take((int) $runs->search(fn (string $run): bool => Str::contains($run, 'composer install')))
            ->filter(fn (string $run): bool => Str::contains($run, 'artisan'));

        $report = [
            'runs artisan before Composer install' => $beforeComposer->isNotEmpty(),
            'not through the primary checkout' => $beforeComposer
                ->reject(fn (string $run): bool => Str::contains($run, '{{ PROJECT_PRIMARY_DIR }}'))
                ->values()
                ->all(),
        ];

        // Assert
        expect($report)->toBe([
            'runs artisan before Composer install' => true,
            'not through the primary checkout' => [],
        ]);
    });

    // Until `lf:workspace-env` runs, .env is still the primary's verbatim copy,
    // so both later steps would interpolate the primary's values: the database
    // step would target `lundflix`, and `herd link --secure lundflix-v2` would
    // re-point the primary's own site at this worktree.
    it('derives the workspace env before creating the database and linking the Herd site', function () use ($workflow, $runsOf): void {
        // Arrange
        $runs = $runsOf($workflow('up'));

        // Act
        $position = [
            'derive env' => $runs->search(fn (string $run): bool => Str::contains($run, 'lf:workspace-env')),
            'create database' => $runs->search(fn (string $run): bool => preg_match('/\bCREATE\s+DATABASE\b/i', $run) === 1),
            'herd link' => $runs->search(fn (string $run): bool => preg_match('/\bherd\s+link\b/i', $run) === 1),
        ];

        // Assert
        expect($position['derive env'])->toBeInt()
            ->and($position['create database'])->toBeInt()
            ->and($position['herd link'])->toBeInt()
            ->and($position['derive env'])->toBeLessThan($position['create database'])
            ->and($position['derive env'])->toBeLessThan($position['herd link']);
    });
});

describe('mysql invocation', function () use ($workflow, $stepsOf): void {
    // `App\Domains\Local\Database\MysqlConnection` sets the rule for this same
    // domain: a `-p` flag puts the password in the process table, so it travels
    // out-of-band in MYSQL_PWD. An empty MYSQL_PWD behaves like no password,
    // which also retires the `$([[ -z … ]] && … )` branch the flag form needed —
    // computation inside a workflow bash string, which nothing can test.
    it('passes the MySQL password through the environment rather than argv', function () use ($workflow, $stepsOf): void {
        // Arrange
        $runs = collect(['up', 'down'])
            ->flatMap(fn (string $name): array => $stepsOf($workflow($name)))
            ->map(fn (array $step): string => (string) ($step['run'] ?? ''))
            ->filter(fn (string $run): bool => preg_match('/\bmysql\b/', $run) === 1)
            ->values();

        // Act
        $offenders = [
            'password on argv' => $runs->reject(fn (string $run): bool => Str::startsWith($run, 'MYSQL_PWD='))->values()->all(),
            'embedded computation' => $runs->filter(fn (string $run): bool => Str::contains($run, '$('))->values()->all(),
        ];

        // Assert
        expect($runs)->toHaveCount(2)
            ->and($offenders)->toBe([
                'password on argv' => [],
                'embedded computation' => [],
            ]);
    });
});

describe('up.yaml fast-forward', function () use ($workflow, $stepsOf): void {
    // A bare `git merge --ff-only` aborts every run on a fresh worktree:
    // LaborForest seeds `.laborforest/workflows/*.yaml` and
    // `.laborforest/ignored/.gitignore` as UNTRACKED files, those same paths are
    // TRACKED on origin/main, and --ff-only refuses to clobber them (FLIX-302).
    // The skip check, the clearing of those seeded paths and the merge all move
    // into `lf:workspace-sync`, where the Feature suite can actually run them —
    // which also retires the `test "$(git rev-list …)"` condition, computation
    // inside a workflow string that nothing in this repo can test.
    //
    // The workspace argument is counted separately because it is the half the
    // design rests on: the step runs the PRIMARY checkout's artisan, so
    // base_path() in that process is the primary's tree. Dropped or mistyped, the
    // command still runs — against the primary — and a count of invocations alone
    // stays green while the fast-forward silently acts on the wrong worktree.
    it('fast-forwards through the artisan command rather than an inline merge', function () use ($workflow, $stepsOf): void {
        // Arrange
        $steps = collect($stepsOf($workflow('up')));

        // Act
        $report = [
            'lf:workspace-sync steps' => $steps
                ->filter(fn (array $step): bool => Str::contains((string) ($step['run'] ?? ''), 'lf:workspace-sync'))
                ->count(),
            'sync steps passing the workspace dir' => $steps
                ->filter(fn (array $step): bool => Str::contains((string) ($step['run'] ?? ''), 'lf:workspace-sync')
                    && Str::contains((string) ($step['run'] ?? ''), '{{ WORKSPACE_DIR }}'))
                ->count(),
            'inline merge' => $steps
                ->contains(fn (array $step): bool => Str::contains((string) ($step['run'] ?? ''), 'git merge')),
            'computed conditions' => $steps
                ->filter(fn (array $step): bool => Str::contains((string) ($step['if'] ?? ''), '$(')
                    || Str::contains((string) ($step['unless'] ?? ''), '$('))
                ->map(fn (array $step): string => (string) ($step['name'] ?? ''))
                ->values()
                ->all(),
        ];

        // Assert
        expect($report)->toBe([
            'lf:workspace-sync steps' => 1,
            'sync steps passing the workspace dir' => 1,
            'inline merge' => false,
            'computed conditions' => [],
        ]);
    });
});

describe('up.yaml env derivation', function () use ($workflow, $runsOf): void {
    it('derives the workspace env through the artisan command rather than an inline sed', function () use ($workflow, $runsOf): void {
        // Arrange
        $runs = $runsOf($workflow('up'));

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
