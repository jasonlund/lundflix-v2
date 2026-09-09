<?php

declare(strict_types=1);

use App\Domains\Local\Support\BranchName;

describe('derive() ticket ids', function (): void {
    it('joins one lowercased ticket id to a title slug cut at the last whole word within 20 characters', function (): void {
        // Arrange
        // `consolidate-the` is 15 characters; adding `-laborforest` would pass 20
        $title = 'Consolidate the LaborForest + Solo worktree lifecycle into an invocable skill';

        // Act
        $branch = BranchName::derive(['FLIX-303'], $title);

        // Assert
        expect($branch)->toBe('flix-303-consolidate-the');
    });

    it('keeps every ticket id, in order, for a multi-ticket branch', function (): void {
        // Arrange
        $title = 'Consolidate the lifecycle';

        // Act
        $branch = BranchName::derive(['FLIX-303', 'FLIX-302'], $title);

        // Assert
        expect($branch)->toBe('flix-303-flix-302-consolidate-the');
    });
});

describe('derive() title budget', function (): void {
    it('leaves a title already inside the budget intact, with no trailing hyphen', function (): void {
        // Arrange
        // `sync-the-tmdb-movies` is exactly 20 characters — the whole slug survives
        $title = 'Sync the TMDB movies';

        // Act
        $branch = BranchName::derive(['FLIX-100'], $title);

        // Assert
        expect($branch)
            ->toBe('flix-100-sync-the-tmdb-movies')
            ->not->toEndWith('-');
    });

    it("keeps the whole last word when the slug's first separator past the budget sits exactly one character out", function (): void {
        // Arrange
        // slugs to `sync-the-tmdb-movies-again`, whose first 20 characters are `sync-the-tmdb-movies`
        // and whose 21st character is the `-` before `again`
        $title = 'Sync the TMDB movies again';

        // Act
        $branch = BranchName::derive(['FLIX-100'], $title);

        // Assert
        expect($branch)->toBe('flix-100-sync-the-tmdb-movies');
    });

    it('hard-cuts a first word longer than 20 characters rather than yielding an empty slug', function (): void {
        // Arrange
        // `antidisestablishmentarianism` is 28 characters, so there is no word boundary to cut at
        $title = 'Antidisestablishmentarianism explained';

        // Act
        $branch = BranchName::derive(['FLIX-303'], $title);

        // Assert
        expect($branch)->toBe('flix-303-antidisestablishment');
    });
});

describe('derive() title slugging', function (): void {
    it('drops punctuation when slugging', function (): void {
        // Arrange
        $title = 'LaborForest + Solo lifecycle';

        // Act
        $branch = BranchName::derive(['FLIX-303'], $title);

        // Assert
        expect($branch)->toBe('flix-303-laborforest-solo');
    });
});
