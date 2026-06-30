<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\UpsertTmdbShows;
use App\Domains\Catalog\Models\Show;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Input payloads are decoded TMDB /tv/{id} responses, loaded byte-exact from
| the committed fixture tests/Fixtures/Catalog/tmdb/tv.json (a real TMDB API
| response for "Game of Thrones", id 1399) — the native wire shape the
| upserter consumes, NOT a hand-fabricated array. Unlike /movie, the IMDb id
| arrives NESTED under external_ids.imdb_id (no top-level imdb_id key); the
| tmdb-only-path tests reuse this real payload with targeted overrides.
|--------------------------------------------------------------------------
*/

it('maps the tv payload to _tmdb_* columns, stamps tmdb_synced_at, and returns 1', function (): void {
    // Arrange
    $payload = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);

    // Act
    $count = resolve(UpsertTmdbShows::class)->handle([$payload]);

    // Assert
    expect($count)->toBe(1);
    $show = Show::query()->where('_tmdb_id', 1399)->firstOrFail();
    expect($show->_tmdb_id)->toBe(1399)
        ->and($show->_tmdb_name)->toBe('Game of Thrones')
        ->and($show->_tmdb_original_name)->toBe('Game of Thrones')
        ->and($show->_tmdb_status)->toBe('Ended')
        ->and($show->_tmdb_first_air_date->format('Y-m-d'))->toBe('2011-04-17')
        ->and($show->tmdb_synced_at)->not->toBeNull();
});

it('stores json fields raw, byte-for-byte the source json', function (): void {
    // Arrange
    $payload = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);

    // Act
    resolve(UpsertTmdbShows::class)->handle([$payload]);

    // Assert
    $genres = DB::table('shows')->where('_tmdb_id', 1399)->value('_tmdb_genres');
    $externalIds = DB::table('shows')->where('_tmdb_id', 1399)->value('_tmdb_external_ids');
    expect($genres)->toBe(json_encode($payload['genres']))
        ->and($externalIds)->toBe(json_encode($payload['external_ids']));
});

it('merges a tv payload onto an existing imdb show via nested external_ids.imdb_id without clobbering imdb columns', function (): void {
    // Arrange
    $payload = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
    $existing = Show::factory()->create(['imdb_id' => 'tt0944947']);
    $originalTitle = $existing->title;
    $originalGenres = $existing->genres->all();
    $originalVotes = $existing->num_votes;

    // Act
    resolve(UpsertTmdbShows::class)->handle([$payload]);

    // Assert
    $fresh = Show::query()->where('imdb_id', 'tt0944947')->firstOrFail();
    expect(Show::query()->count())->toBe(1)
        ->and($fresh->_tmdb_id)->toBe(1399)
        ->and($fresh->_tmdb_name)->toBe('Game of Thrones')
        ->and($fresh->title)->toBe($originalTitle)
        ->and($fresh->genres->all())->toEqual($originalGenres)
        ->and($fresh->num_votes)->toBe($originalVotes);
});

it('inserts a tmdb-only show with null imdb_id when no existing imdb show matches', function (): void {
    // Arrange
    $payload = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
    $payload['id'] = 1234567;
    unset($payload['external_ids']['imdb_id']);

    // Act
    resolve(UpsertTmdbShows::class)->handle([$payload]);

    // Assert
    $show = Show::query()->where('_tmdb_id', 1234567)->firstOrFail();
    expect($show->imdb_id)->toBeNull()
        ->and($show->_tmdb_id)->toBe(1234567);
});

it('does not duplicate a tmdb-only show when the same payload is re-run', function (): void {
    // Arrange
    $payload = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
    $payload['id'] = 1234567;
    unset($payload['external_ids']['imdb_id']);
    resolve(UpsertTmdbShows::class)->handle([$payload]);

    // Act
    resolve(UpsertTmdbShows::class)->handle([$payload]);

    // Assert
    expect(Show::query()->where('_tmdb_id', 1234567)->count())->toBe(1);
});

it('returns 0 and persists nothing for empty input', function (): void {
    // Arrange
    $payloads = [];

    // Act
    $count = resolve(UpsertTmdbShows::class)->handle($payloads);

    // Assert
    expect($count)->toBe(0)
        ->and(Show::query()->count())->toBe(0);
});
