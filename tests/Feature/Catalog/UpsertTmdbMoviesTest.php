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

it('maps the tmdb payload to _tmdb_* columns, stamps tmdb_synced_at, and returns 1', function (): void {
    // Arrange
    $payload = json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true);

    // Act
    $count = resolve(UpsertTmdbMovies::class)->handle([$payload]);

    // Assert
    expect($count)->toBe(1);
    $movie = Movie::query()->where('_tmdb_id', 603)->firstOrFail();
    expect($movie->_tmdb_id)->toBe(603)
        ->and($movie->_tmdb_title)->toBe('The Matrix')
        ->and($movie->_tmdb_original_title)->toBe('The Matrix')
        ->and($movie->_tmdb_status)->toBe('Released')
        ->and($movie->_tmdb_imdb_id)->toBe('tt0133093')
        ->and($movie->_tmdb_release_date->format('Y-m-d'))->toBe('1999-03-31')
        ->and($movie->tmdb_synced_at)->not->toBeNull();
});

it('stores json fields raw, byte-for-byte the source json', function (): void {
    // Arrange
    $payload = json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true);

    // Act
    resolve(UpsertTmdbMovies::class)->handle([$payload]);

    // Assert
    $genres = DB::table('movies')->where('_tmdb_id', 603)->value('_tmdb_genres');
    $collection = DB::table('movies')->where('_tmdb_id', 603)->value('_tmdb_belongs_to_collection');
    $releaseDates = DB::table('movies')->where('_tmdb_id', 603)->value('_tmdb_release_dates');
    expect($genres)->toBe(json_encode($payload['genres']))
        ->and($collection)->toBe(json_encode($payload['belongs_to_collection']))
        ->and($releaseDates)->toBe(json_encode($payload['release_dates']));
});

it('returns 0 and persists nothing for empty input', function (): void {
    // Arrange
    $payloads = [];

    // Act
    $count = resolve(UpsertTmdbMovies::class)->handle($payloads);

    // Assert
    expect($count)->toBe(0)
        ->and(Movie::query()->count())->toBe(0);
});

/**
 * Build a minimal-but-complete TMDB payload: only id / imdb_id / title carry the
 * dedupe-relevant values per test; the remaining keys are harmless filler so the
 * existing column mapper has every field it reads (keeping the failure on the
 * dedupe assertion, not a missing-key crash).
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

it('merges a tmdb payload onto an existing imdb row without clobbering imdb columns', function (): void {
    // Arrange
    $existing = Movie::factory()->create(['imdb_id' => 'tt0133093']);
    $originalTitle = $existing->title;
    $originalGenres = $existing->genres->all();
    $originalVotes = $existing->num_votes;

    // Act
    resolve(UpsertTmdbMovies::class)->handle([
        tmdbPayload(['id' => 603, 'imdb_id' => 'tt0133093', 'title' => 'TMDB Title']),
    ]);

    // Assert
    $fresh = Movie::query()->where('imdb_id', 'tt0133093')->firstOrFail();
    expect(Movie::query()->count())->toBe(1)
        ->and($fresh->_tmdb_id)->toBe(603)
        ->and($fresh->_tmdb_title)->toBe('TMDB Title')
        ->and($fresh->title)->toBe($originalTitle)
        ->and($fresh->genres->all())->toEqual($originalGenres)
        ->and($fresh->num_votes)->toBe($originalVotes);
});

it('inserts a tmdb-only row with null imdb_id when no existing imdb row matches', function (): void {
    // Arrange
    $payloads = [tmdbPayload(['id' => 700, 'imdb_id' => 'tt9999999', 'title' => 'X'])];

    // Act
    resolve(UpsertTmdbMovies::class)->handle($payloads);

    // Assert
    $movie = Movie::query()->where('_tmdb_id', 700)->firstOrFail();
    expect($movie->imdb_id)->toBeNull()
        ->and($movie->_tmdb_id)->toBe(700);
});

it('inserts a tmdb-only row when the payload has no imdb_id key', function (): void {
    // Arrange
    $payload = tmdbPayload(['id' => 701, 'title' => 'Y']);
    unset($payload['imdb_id']);

    // Act
    resolve(UpsertTmdbMovies::class)->handle([$payload]);

    // Assert
    $movie = Movie::query()->where('_tmdb_id', 701)->firstOrFail();
    expect($movie->imdb_id)->toBeNull()
        ->and($movie->_tmdb_id)->toBe(701);
});

it('does not duplicate a tmdb-only row when the same payload is re-run', function (): void {
    // Arrange
    $payloads = [tmdbPayload(['id' => 702, 'imdb_id' => 'tt5555555', 'title' => 'Z'])];
    resolve(UpsertTmdbMovies::class)->handle($payloads);

    // Act
    resolve(UpsertTmdbMovies::class)->handle($payloads);

    // Assert
    expect(Movie::query()->where('_tmdb_id', 702)->count())->toBe(1);
});
