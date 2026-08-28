<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * A skill is addressed by its directory name, but declares a `name:` in its own
 * front matter. Nothing in the toolchain reconciles the two, so a directory
 * rename that misses the front matter leaves a skill that still loads under the
 * new name while describing itself under the old one.
 *
 * @return list<array{skill: string, declared: ?string}>
 */
$scanSkills = function (): array {
    // The Unit suite doesn't boot the app container, so resolve the repo root
    // from this file's location rather than base_path().
    $root = dirname(__DIR__, 2);

    $skills = [];

    foreach ((new Finder)->directories()->in($root.'/.claude/skills')->depth(0)->sortByName() as $directory) {
        $manifest = $directory->getRealPath().'/SKILL.md';

        // Front matter only: an unanchored `name:` would also match prose in the
        // body, which several skills contain.
        $declared = null;

        if (is_file($manifest)) {
            $contents = (string) file_get_contents($manifest);
            $declared = preg_match('/\A---\R(.*?)\R---/s', $contents, $block) === 1
                && preg_match('/^name:\s*(\S+)\s*$/m', $block[1], $name) === 1
                ? $name[1]
                : null;
        }

        $skills[] = [
            'skill' => $directory->getFilename(),
            'declared' => $declared,
        ];
    }

    return $skills;
};

describe('skill front matter', function () use ($scanSkills): void {
    it('gives every skill directory a SKILL.md', function () use ($scanSkills): void {
        // Arrange
        $skills = $scanSkills();

        // Act
        $missing = array_values(array_map(
            fn (array $s): string => $s['skill'],
            array_filter(
                $skills,
                fn (array $s): bool => ! is_file(dirname(__DIR__, 2)."/.claude/skills/{$s['skill']}/SKILL.md"),
            ),
        ));

        // Assert
        expect($missing)->toBe([])
            ->and($skills)->not->toBeEmpty();
    });

    it('declares a front-matter name matching the skill directory', function () use ($scanSkills): void {
        // Arrange
        $skills = $scanSkills();

        // Act
        $mismatched = array_values(array_map(
            fn (array $s): string => sprintf('%s declares "%s"', $s['skill'], $s['declared'] ?? '<none>'),
            array_filter($skills, fn (array $s): bool => $s['declared'] !== $s['skill']),
        ));

        // Assert
        expect($mismatched)->toBe([]);
    });
});
