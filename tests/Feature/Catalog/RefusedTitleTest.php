<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;

describe('movie refusal flags', function (): void {
    it('refuses a movie flagged by any single refusal column', function (string $column): void {
        // Arrange
        $movie = Movie::factory()->create([$column => true]);

        // Act
        $refused = $movie->isRefused();

        // Assert
        expect($refused)->toBeTrue();
    })->with([
        'imdb adult' => '_imdb_isAdult',
        'tmdb adult' => '_tmdb_adult',
        'tmdb softcore' => '_tmdb_softcore',
        'tmdb promo' => '_tmdb_video',
    ]);

    it('does not refuse a movie carrying none of the refusal flags', function (): void {
        // Arrange
        $movie = Movie::factory()->create([
            '_imdb_isAdult' => false,
            '_tmdb_adult' => false,
            '_tmdb_softcore' => false,
            '_tmdb_video' => false,
        ]);

        // Act
        $refused = $movie->isRefused();

        // Assert
        expect($refused)->toBeFalse();
    });

    // The clean row keeps the factory's unset flags, so this also pins that a
    // null flag reads as unknown rather than refused and survives the scope.
    it('excludes refused movies from a notRefused query', function (): void {
        // Arrange
        $clean = Movie::factory()->create();
        Movie::factory()->create(['_tmdb_video' => true]);
        Movie::factory()->create(['_imdb_isAdult' => true]);

        // Act
        $ids = Movie::query()->notRefused()->pluck('id');

        // Assert
        expect($ids->all())->toBe([$clean->id]);
    });
});

describe('show refusal flags', function (): void {
    it('refuses a show flagged adult by either source', function (string $column): void {
        // Arrange
        $show = Show::factory()->create([$column => true]);

        // Act
        $refused = $show->isRefused();

        // Assert
        expect($refused)->toBeTrue();
    })->with([
        'imdb adult' => '_imdb_isAdult',
        'tmdb adult' => '_tmdb_adult',
        'tmdb softcore' => '_tmdb_softcore',
    ]);

    // As on movies, the clean row's flags stay unset so null must read as
    // unknown, not refused.
    it('excludes refused shows from a notRefused query', function (): void {
        // Arrange
        $clean = Show::factory()->create();
        Show::factory()->create(['_tmdb_adult' => true]);
        Show::factory()->create(['_tmdb_softcore' => true]);

        // Act
        $ids = Show::query()->notRefused()->pluck('id');

        // Assert
        expect($ids->all())->toBe([$clean->id]);
    });
});

describe('shouldBeSearchable() refusal filter', function (): void {
    it('keeps a movie searchable while nothing refuses it', function (array $attributes): void {
        // Arrange
        $movie = Movie::factory()->create($attributes);

        // Act
        $searchable = $movie->shouldBeSearchable();

        // Assert
        expect($searchable)->toBeTrue();
    })->with([
        'flags answered false' => [[
            '_imdb_isAdult' => false,
            '_tmdb_adult' => false,
            '_tmdb_softcore' => false,
            '_tmdb_video' => false,
        ]],
        // A title nobody has classified yet is unknown, not refused — it has to
        // stay findable rather than fall out of search on a missing flag.
        'flags unknown' => [[]],
    ]);

    it('drops a refused movie out of the searchable set', function (string $column): void {
        // Arrange
        $movie = Movie::factory()->create([$column => true]);

        // Act
        $searchable = $movie->shouldBeSearchable();

        // Assert
        expect($searchable)->toBeFalse();
    })->with([
        'imdb adult' => '_imdb_isAdult',
        'tmdb adult' => '_tmdb_adult',
        'tmdb softcore' => '_tmdb_softcore',
        'tmdb promo' => '_tmdb_video',
    ]);

    it('drops a refused show out of the searchable set', function (string $column): void {
        // Arrange
        $show = Show::factory()->create([$column => true]);

        // Act
        $searchable = $show->shouldBeSearchable();

        // Assert
        expect($searchable)->toBeFalse();
    })->with([
        'imdb adult' => '_imdb_isAdult',
        'tmdb adult' => '_tmdb_adult',
        'tmdb softcore' => '_tmdb_softcore',
    ]);
});
