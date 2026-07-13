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
    $existing = Show::factory()->create(['_imdb_id' => 'tt0944947']);
    $originalVotes = $existing->_imdb_num_votes;
    $originalRating = $existing->_imdb_average_rating;

    // Act
    resolve(UpsertTmdbShows::class)->handle([$payload]);

    // Assert
    $fresh = Show::query()->where('_imdb_id', 'tt0944947')->firstOrFail();
    expect(Show::query()->count())->toBe(1)
        ->and($fresh->_tmdb_id)->toBe(1399)
        ->and($fresh->_tmdb_name)->toBe('Game of Thrones')
        ->and($fresh->_imdb_num_votes)->toBe($originalVotes)
        ->and($fresh->_imdb_average_rating)->toBe($originalRating);
});

it('merges a tv payload onto an existing tvdb-only row via external_ids.tvdb_id when imdb matches nothing', function (): void {
    // Arrange
    $payload = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
    unset($payload['external_ids']['imdb_id']);
    $existing = Show::factory()->withTvdb()->create(['_imdb_id' => null, '_tvdb_id' => 121361]);
    $originalTvdbName = $existing->_tvdb_name;

    // Act
    resolve(UpsertTmdbShows::class)->handle([$payload]);

    // Assert
    $fresh = Show::query()->where('_tvdb_id', 121361)->firstOrFail();
    expect(Show::query()->count())->toBe(1)
        ->and($fresh->_tmdb_id)->toBe(1399)
        ->and($fresh->_tvdb_id)->toBe(121361)
        ->and($fresh->_tvdb_name)->toBe($originalTvdbName);
});

it('inserts a source-only row rather than false-matching an unrelated row when all three ids miss', function (): void {
    // Arrange
    $payload = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
    $payload['id'] = 9876543;
    unset($payload['external_ids']['imdb_id']);
    $payload['external_ids']['tvdb_id'] = 8888888;
    $unrelated = Show::factory()->withTmdb()->withTvdb()->create([
        '_imdb_id' => 'tt1111111',
        '_tmdb_id' => 1111111,
        '_tvdb_id' => 2222222,
    ]);

    // Act
    resolve(UpsertTmdbShows::class)->handle([$payload]);

    // Assert
    $show = Show::query()->where('_tmdb_id', 9876543)->firstOrFail();
    expect(Show::query()->count())->toBe(2)
        ->and($show->getKey())->not->toBe($unrelated->getKey())
        ->and($show->_tmdb_id)->toBe(9876543)
        ->and($show->_imdb_id)->toBeNull();
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
    expect($show->_imdb_id)->toBeNull()
        ->and($show->_tmdb_id)->toBe(1234567);
});

it('seeds _imdb_id from nested external_ids.imdb_id on a fresh tmdb-only insert', function (): void {
    // Arrange
    $payload = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
    $payload['id'] = 1234567;
    $payload['external_ids']['imdb_id'] = 'tt0944947';

    // Act
    resolve(UpsertTmdbShows::class)->handle([$payload]);

    // Assert
    $show = Show::query()->where('_tmdb_id', 1234567)->firstOrFail();
    expect($show->_imdb_id)->toBe('tt0944947')
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

it('writes one last-wins row when two payloads in one batch share an imdb_id', function (): void {
    // Arrange
    $first = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
    $first['external_ids']['imdb_id'] = 'tt0944947';
    $last = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
    $last['external_ids']['imdb_id'] = 'tt0944947';
    $last['id'] = 7654321;
    $last['name'] = 'Winning Write';

    // Act
    resolve(UpsertTmdbShows::class)->handle([$first, $last]);

    // Assert
    $fresh = Show::query()->where('_tmdb_id', 7654321)->firstOrFail();
    expect(Show::query()->count())->toBe(1)
        ->and($fresh->_tmdb_id)->toBe(7654321)
        ->and($fresh->_tmdb_name)->toBe('Winning Write');
});

it('persists a blank TMDB first_air_date as null, not an empty string', function (): void {
    // Arrange
    $payload = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
    $payload['id'] = 603;
    $payload['first_air_date'] = '';

    // Act
    resolve(UpsertTmdbShows::class)->handle([$payload]);

    // Assert
    expect(DB::table('shows')->where('_tmdb_id', 603)->value('_tmdb_first_air_date'))->toBeNull();
});

it('persists the 0000-00-00 sentinel TMDB first_air_date as null', function (): void {
    // Arrange
    $payload = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
    $payload['id'] = 604;
    $payload['first_air_date'] = '0000-00-00';

    // Act
    resolve(UpsertTmdbShows::class)->handle([$payload]);

    // Assert
    expect(DB::table('shows')->where('_tmdb_id', 604)->value('_tmdb_first_air_date'))->toBeNull();
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
