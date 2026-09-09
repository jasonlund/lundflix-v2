<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Process;
use Tests\Support\ToolkitFiles;

/**
 * Reachability guard for the `/`-command surface under `.claude/commands/`.
 *
 * A command is prose the harness loads by name, so every way it can fail to
 * reach a reader is silent. It can be untracked — working perfectly for its
 * author behind a machine-local `.git/info/exclude` line and existing for
 * nobody else. It can declare a front-matter `name:` that no longer matches the
 * path it is addressed by, so the harness runs it under one name while the file
 * describes itself under another. Or it can simply go unmentioned in
 * `/map`, which is the router: a command absent from it is invisible, no matter
 * how well it works.
 *
 * Scope, deliberate: nothing here judges whether a command's procedure is
 * correct — only that the file reaches every checkout and can be found. The
 * procedure is proven by running it, not by scanning it.
 *
 * The tracking list names only the paths this branch newly commits. The other
 * command files have been tracked since they were written and were never at
 * risk of that; the two rules below cover all of them generically instead.
 *
 * NB: every test carries a non-vacuous floor — the scanned set is really
 * populated, the read file is really substantial — because a mistyped path or
 * glob yields an empty set, which reads identically to a clean sweep.
 */

/** Repo-root paths whose contents must reach every checkout. */
$newlyCommittedCommandPaths = [
    '.claude/commands/worktree/up.md',
    '.claude/commands/worktree/down.md',
];

/**
 * Every command file under `.claude/commands`, paired with the slash command
 * its path addresses it by and the `name:` its own front matter declares.
 *
 * The path is the address — `commands/review/create-pr.md` is run as
 * `review:create-pr` — so the derivation is path-driven and works at any depth.
 *
 * Front matter only: an unanchored `name:` would also match prose in the body,
 * which several commands contain.
 *
 * @return list<array{path: string, command: string, declared: ?string}>
 */
$scanCommands = function (): array {
    $directory = ToolkitFiles::path('.claude/commands');

    return collect((new Finder)->files()->in($directory)->name('*.md')->sortByName())
        ->map(function (SplFileInfo $file) use ($directory): array {
            $relative = Str::replace($directory.'/', '', (string) $file->getRealPath());
            $contents = (string) file_get_contents((string) $file->getRealPath());

            $declared = preg_match('/\A---\R(.*?)\R---/s', $contents, $block) === 1
                && preg_match('/^name:\s*(\S+)\s*$/m', $block[1], $name) === 1
                ? $name[1]
                : null;

            return [
                'path' => '.claude/commands/'.$relative,
                'command' => Str::replace('/', ':', Str::replace('.md', '', $relative)),
                'declared' => $declared,
            ];
        })
        ->values()
        ->all();
};

describe('command version control', function () use ($newlyCommittedCommandPaths): void {
    it('tracks the worktree lifecycle commands in git', function () use ($newlyCommittedCommandPaths): void {
        // Ask git what it actually tracks rather than whether the file exists on
        // this disk: a machine-local ignore rule (.gitignore, or
        // .git/info/exclude, which no checkout can see) leaves the command
        // working for its author and reaching nobody.
        // Arrange
        $process = new Process(['git', 'ls-files', '-z', '--', ...$newlyCommittedCommandPaths], ToolkitFiles::path());

        // Act
        $process->run();

        // Assert
        $tracked = collect(explode("\0", $process->getOutput()))->filter()->values()->all();
        expect(collect($newlyCommittedCommandPaths)
            ->reject(fn (string $path): bool => in_array($path, $tracked, true))
            ->map(fn (string $path): string => sprintf('%s  →  not tracked in git', $path))
            ->values()
            ->all())->toBe([]);
    });
});

describe('command front matter', function () use ($scanCommands): void {
    it('declares a front-matter name matching the slash command its path addresses', function () use ($scanCommands): void {
        // Nothing in the toolchain reconciles the two, so a moved or renamed file
        // keeps loading under its new path while describing itself under the old
        // name — and the mismatch surfaces only to whoever reads the wrong one.
        // Arrange
        $commands = $scanCommands();

        // Act
        $mismatched = collect($commands)
            ->reject(fn (array $c): bool => $c['declared'] === $c['command'])
            ->map(fn (array $c): string => sprintf('%s declares "%s", expected "%s"', $c['path'], $c['declared'] ?? '<none>', $c['command']))
            ->values()
            ->all();

        // Assert
        expect($mismatched)->toBe([])
            ->and($commands)->not->toBeEmpty()
            ->and(count($commands))->toBeGreaterThan(5);
    });
});

describe('/map coverage', function () use ($scanCommands): void {
    it('names every command in the /map router', function () use ($scanCommands): void {
        // /map is the one place a reader goes to find out what exists, so a
        // command missing from it is invisible however well it works — the exact
        // failure that left the worktree lifecycle unfindable.
        // Arrange
        $commands = $scanCommands();
        $map = ToolkitFiles::read('.claude/skills/map/SKILL.md');

        // Act
        $unlisted = collect($commands)
            ->reject(fn (array $c): bool => Str::contains($map, $c['command']))
            ->map(fn (array $c): string => sprintf('%s  →  /%s is not named in /map', $c['path'], $c['command']))
            ->values()
            ->all();

        // Assert
        expect($unlisted)->toBe([])
            ->and($commands)->not->toBeEmpty()
            ->and(count($commands))->toBeGreaterThan(5)
            ->and(ToolkitFiles::lineCount($map))->toBeGreaterThan(50);
    });
});
