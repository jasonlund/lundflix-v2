<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

describe('lf:branch-name derivation', function (): void {
    it('prints the branch derived from one ticket id and the title', function (): void {
        // Arrange
        $title = 'Consolidate the LaborForest + Solo worktree lifecycle into an invocable skill';

        // Act & Assert
        $this->artisan('lf:branch-name', ['tickets' => 'FLIX-303', 'title' => $title])
            ->expectsOutput('flix-303-consolidate-the')
            ->assertSuccessful();
    });

    it('keeps every id of a comma-separated list, in order', function (): void {
        // Arrange
        $title = 'Consolidate the lifecycle';

        // Act & Assert
        $this->artisan('lf:branch-name', ['tickets' => 'FLIX-303,FLIX-302', 'title' => $title])
            ->expectsOutput('flix-303-flix-302-consolidate-the')
            ->assertSuccessful();
    });
});

describe('lf:branch-name output contract', function (): void {
    // The one command in the repo exempt from the phase-line/`Done.` console
    // convention: /worktree:up captures this through $(php artisan lf:branch-name …),
    // so any extra line lands inside the branch name. Equality on the whole output,
    // never containment — a containment check passes against a run that also printed
    // a heartbeat, which is the exact regression this test exists to catch.
    it('prints the bare branch and nothing else', function (): void {
        // Arrange
        $title = 'Consolidate the LaborForest + Solo worktree lifecycle into an invocable skill';

        // Act
        Artisan::call('lf:branch-name', ['tickets' => 'FLIX-303', 'title' => $title]);

        // Assert
        expect(Str::trim(Artisan::output()))->toBe('flix-303-consolidate-the');
    });
});
