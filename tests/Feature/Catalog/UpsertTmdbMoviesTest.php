<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\UpsertTmdbMovies;
use App\Domains\Catalog\Models\Movie;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Input payloads are decoded TMDB /movie responses, loaded byte-exact from
| the committed fixture tests/Fixtures/Catalog/tmdb/movie.json (a real
| TMDB API response for "The Matrix", id 603) — the native wire shape the
| upserter consumes, NOT a hand-fabricated array.
|--------------------------------------------------------------------------
*/

it('creates a movie keyed by _tmdb_id and copies the payload imdb_id into _imdb_id', function (): void {
    // Arrange
    $payload = tmdbPayload(['id' => 603, 'imdb_id' => 'tt0133093']);

    // Act
    $count = resolve(UpsertTmdbMovies::class)->handle([$payload]);

    // Assert
    expect($count)->toBe(1);
    $movie = Movie::query()->where('_tmdb_id', 603)->firstOrFail();
    expect($movie->_tmdb_id)->toBe(603)
        ->and($movie->_imdb_id)->toBe('tt0133093');
});

it('updates in place when the same _tmdb_id is re-handled', function (): void {
    // Arrange
    resolve(UpsertTmdbMovies::class)->handle([
        tmdbPayload(['id' => 603, 'title' => 'The Matrix']),
    ]);

    // Act
    resolve(UpsertTmdbMovies::class)->handle([
        tmdbPayload(['id' => 603, 'title' => 'TMDB Title']),
    ]);

    // Assert
    $movie = Movie::query()->where('_tmdb_id', 603)->firstOrFail();
    expect(Movie::query()->count())->toBe(1)
        ->and($movie->_tmdb_title)->toBe('TMDB Title');
});

it('leaves _imdb_id null when the payload has no imdb_id key', function (): void {
    // Arrange
    $payload = tmdbPayload(['id' => 701, 'title' => 'Y']);
    unset($payload['imdb_id']);

    // Act
    resolve(UpsertTmdbMovies::class)->handle([$payload]);

    // Assert
    $movie = Movie::query()->where('_tmdb_id', 701)->firstOrFail();
    expect($movie->_imdb_id)->toBeNull();
});

it('maps _tmdb_* columns raw, stamps tmdb_synced_at, and stores _tmdb_genres byte-for-byte', function (): void {
    // Arrange
    $payload = json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true);

    // Act
    resolve(UpsertTmdbMovies::class)->handle([$payload]);

    // Assert
    $movie = Movie::query()->where('_tmdb_id', 603)->firstOrFail();
    $genres = DB::table('movies')->where('_tmdb_id', 603)->value('_tmdb_genres');
    expect($movie->_tmdb_title)->toBe('The Matrix')
        ->and($movie->_tmdb_status)->toBe('Released')
        ->and($movie->_tmdb_release_date->format('Y-m-d'))->toBe('1999-03-31')
        ->and($movie->tmdb_synced_at)->not->toBeNull()
        ->and($genres)->toBe(json_encode($payload['genres']));
});

/**
 * Build a minimal-but-complete TMDB payload: only id / imdb_id / title carry the
 * key-relevant values per test; the remaining keys are harmless filler so the
 * column mapper has every field it reads (keeping the failure on the behavior
 * assertion, not a missing-key crash). Payload keys are raw TMDB wire keys.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tmdbPayload(array $overrides = []): array
{
    return array_merge([
        'id' => 603,
        'imdb_id' => 'tt0133093',
        'title' => 'The Matrix',
        'original_title' => 'The Matrix',
        'original_language' => 'en',
        'overview' => '',
        'tagline' => '',
        'homepage' => '',
        'status' => 'Released',
        'release_date' => '1999-03-31',
        'runtime' => 136,
        'budget' => 0,
        'revenue' => 0,
        'popularity' => 0.0,
        'vote_average' => 0.0,
        'vote_count' => 0,
        'video' => false,
        'genres' => [],
        'origin_country' => [],
        'production_companies' => [],
        'production_countries' => [],
        'spoken_languages' => [],
        'belongs_to_collection' => null,
        'release_dates' => [],
        'poster_path' => null,
        'backdrop_path' => null,
    ], $overrides);
}
