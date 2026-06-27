<?php

declare(strict_types=1);

use App\Domains\Catalog\Casts\ImdbGenres;
use App\Domains\Catalog\Enums\Genre;
use App\Domains\Catalog\Models\Movie;
use Illuminate\Support\Collection;
use Tests\TestCase;

// Constructing an Eloquent model boots it via the event dispatcher facade, which
// only exists once the framework is booted — so this Unit file opts into TestCase.
uses(TestCase::class);

it('returns an empty collection when getting a null value', function (): void {
    // Arrange
    $cast = new ImdbGenres;

    // Act
    $result = $cast->get(new Movie, '_imdb_genres', null, []);

    // Assert
    expect($result)->toBeInstanceOf(Collection::class)
        ->and($result->all())->toBe([]);
});

it('maps recognized raw IMDb genre strings to their Genre cases', function (): void {
    // Arrange
    $cast = new ImdbGenres;
    $json = json_encode(['Action', 'Sci-Fi', 'Film-Noir']);

    // Act
    $result = $cast->get(new Movie, '_imdb_genres', $json, []);

    // Assert
    expect($result->all())->toBe([Genre::Action, Genre::SciFi, Genre::FilmNoir]);
});

it('drops unrecognized raw strings while keeping recognized genres', function (): void {
    // Arrange
    $cast = new ImdbGenres;
    $json = json_encode(['Action', 'NotAGenre', 'Comedy']);

    // Act
    $result = $cast->get(new Movie, '_imdb_genres', $json, []);

    // Assert
    expect($result->all())->toBe([Genre::Action, Genre::Comedy]);
});

it('returns null when setting a null value', function (): void {
    // Arrange
    $cast = new ImdbGenres;

    // Act
    $result = $cast->set(new Movie, '_imdb_genres', null, []);

    // Assert
    expect($result)->toBeNull();
});

it('encodes a mix of Genre cases and plain strings to their normalized string values', function (): void {
    // Arrange
    $cast = new ImdbGenres;

    // Act
    $result = $cast->set(new Movie, '_imdb_genres', [Genre::Action, 'Comedy', Genre::SciFi], []);

    // Assert
    expect($result)->toBe(json_encode(['Action', 'Comedy', 'Sci-Fi']));
});
