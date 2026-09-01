<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Drift guard for the local-development tooling config this repo commits:
 * `solo.yml`, `.mcp.json`, and the `.laborforest/workflows/*.yaml` lifecycle files.
 *
 * Every one of these is read by an external tool (Solo, the MCP client, the
 * LaborForest app) and by nothing inside this repo, so drift is silent: a process
 * added from Solo's UI, an MCP server registered on one machine only, or a file
 * left untracked all behave perfectly for the person who made the change and
 * reach nobody else. The tests below pin the decisions that have to survive a
 * fresh checkout.
 *
 * Scope limit, deliberate: the tracking test asks git whether a path is tracked
 * and nothing more, so a tracked file whose working-tree edits are never
 * committed still passes. Its path list names only the files this branch newly
 * commits — the ones previously reachable through a machine-local
 * `.git/info/exclude` line. `.mcp.json` is absent because it has been tracked
 * since FLIX-192 and was never at risk of that; its contents are pinned by the
 * server assertion below instead.
 */

/** Repo-root paths whose contents must reach every checkout. */
$committedToolingPaths = [
    'solo.yml',
    '.laborforest/workflows/up.yaml',
    '.laborforest/workflows/down.yaml',
    '.laborforest/workflows/refresh.yaml',
];

/**
 * The repo root.
 *
 * The Unit suite doesn't boot the app container, so resolve it from this file's
 * location rather than base_path(). One level deeper than the guards in
 * tests/Unit, hence 3.
 */
$repoRoot = fn (): string => dirname(__DIR__, 3);

/**
 * `solo.yml`'s process map, keyed by process name.
 *
 * @return array<string, array<string, mixed>>
 */
$soloProcesses = function () use ($repoRoot): array {
    $config = (array) Yaml::parseFile($repoRoot().'/solo.yml');

    return collect((array) ($config['processes'] ?? []))
        ->filter(fn (mixed $process): bool => is_array($process))
        ->all();
};

/**
 * `.mcp.json`'s server map, keyed by server name.
 *
 * @return array<string, mixed>
 */
$mcpServers = function () use ($repoRoot): array {
    $config = (array) json_decode((string) file_get_contents($repoRoot().'/.mcp.json'), true);

    return (array) ($config['mcpServers'] ?? []);
};

describe('solo.yml processes', function () use ($soloProcesses): void {
    it('declares exactly the four development processes', function () use ($soloProcesses): void {
        // Solo rewrites this file whenever a process is added or removed in its
        // UI, so the set is pinned exactly — a stray local process reaches every
        // checkout the moment the rewritten file is committed.
        // Arrange
        $expected = ['Horizon', 'npm:dev', 'Queue', 'Pint'];

        // Act
        $declared = collect($soloProcesses())->keys()->sort()->values()->all();

        // Assert
        expect($declared)->toBe(collect($expected)->sort()->values()->all());
    });

    it('commits no process that disables permission prompts', function () use ($soloProcesses): void {
        // Solo's own local database carries agent processes launched with
        // permission prompts disabled. They stay local by decision: a committed
        // one is inherited silently by every future checkout, where nobody chose
        // it and nothing announces it.
        // Arrange
        $processes = $soloProcesses();

        // Act
        $unprompted = collect($processes)
            ->filter(fn (array $process): bool => Str::contains(
                (string) ($process['command'] ?? ''),
                '--dangerously-skip-permissions'
            ))
            ->keys()
            ->all();

        // Assert
        expect($unprompted)->toBe([]);
    });
});

describe('.mcp.json servers', function () use ($mcpServers): void {
    // Only servers whose command is true on every checkout belong in a committed,
    // project-scoped file. `php artisan boost:mcp` is repo-relative and qualifies.
    // Solo's server is a macOS app bundle path — machine-local, so it belongs in a
    // user-scoped Claude config, not here.
    it('registers only servers that resolve on any checkout', function () use ($mcpServers): void {
        // Arrange
        // the committed file is the whole input; reading it is the act

        // Act
        $registered = collect($mcpServers())->keys()->sort()->values()->all();

        // Assert
        expect($registered)->toBe(['laravel-boost']);
    });
});

describe('version control', function () use ($repoRoot, $committedToolingPaths): void {
    it('tracks every committed tooling file in git', function () use ($repoRoot, $committedToolingPaths): void {
        // A machine-local ignore rule (.gitignore, or .git/info/exclude, which no
        // checkout can see) keeps these files working locally while they reach
        // nobody. Ask git what it actually tracks rather than whether the file
        // exists on this disk.
        // Arrange
        $process = new Process(['git', 'ls-files', '-z', '--', ...$committedToolingPaths], $repoRoot());

        // Act
        $process->run();

        // Assert
        $tracked = collect(explode("\0", $process->getOutput()))->filter()->values()->all();
        $untracked = collect($committedToolingPaths)->reject(fn (string $path): bool => in_array($path, $tracked, true))->values()->all();
        expect($untracked)->toBe([]);
    });
});
