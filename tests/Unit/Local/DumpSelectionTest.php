<?php

declare(strict_types=1);

use App\Domains\Local\Database\DumpSelection;

it('restricts media to the movie branch via a bounded top-N subquery', function (): void {
    // Arrange
    // parent selection caps movies to the top 40000 by popularity and no shows
    $movieLimit = 40000;

    // Act
    $where = DumpSelection::mediaWhere($movieLimit, 0);

    // Assert
    expect($where)
        ->toContain("mediable_type = 'movie'")
        ->toContain('mediable_id IN (SELECT')
        ->toContain('FROM movies')
        ->toContain('ORDER BY _tmdb_popularity DESC, id DESC')
        ->toContain('LIMIT 40000')
        ->not->toContain("'show'")
        ->not->toContain('IN (1');
});

it('restricts media to the show branch via a bounded top-N subquery', function (): void {
    // Arrange
    // parent selection caps shows to the top 30000 by popularity and no movies
    $showLimit = 30000;

    // Act
    $where = DumpSelection::mediaWhere(0, $showLimit);

    // Assert
    expect($where)
        ->toContain("mediable_type = 'show'")
        ->toContain('mediable_id IN (SELECT')
        ->toContain('FROM shows')
        ->toContain('ORDER BY _tmdb_popularity DESC, id DESC')
        ->toContain('LIMIT 30000')
        ->not->toContain("'movie'")
        ->not->toContain('IN (1');
});

it('restricts seasons to the show branch via a bounded top-N subquery', function (): void {
    // Arrange
    // parent selection caps shows to the top 30000 by popularity
    $showLimit = 30000;

    // Act
    $where = DumpSelection::seasonsWhere($showLimit);

    // Assert
    expect($where)
        ->toContain('show_id IN (SELECT')
        ->toContain('FROM shows')
        ->toContain('ORDER BY _tmdb_popularity DESC, id DESC')
        ->toContain('LIMIT 30000')
        ->not->toContain('IN (1');
});

it('matches no season rows when no shows are selected', function (): void {
    // Arrange
    // no shows selected, so the child restriction must exclude every row
    // (an unrestricted WHERE would dump the entire child table)

    // Act
    $where = DumpSelection::seasonsWhere(0);

    // Assert
    expect($where)->toContain('1=0');
});

it('matches no media rows when no parents are selected', function (): void {
    // Arrange
    // no parents selected, so the child restriction must exclude every row
    // (an unrestricted WHERE would dump the entire child table)

    // Act
    $where = DumpSelection::mediaWhere(0, 0);

    // Assert
    expect($where)->toContain('1=0');
});
