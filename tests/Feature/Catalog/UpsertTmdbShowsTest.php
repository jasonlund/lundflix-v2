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
| upserter consumes, NOT a hand-fabricated array. UpsertTmdbShows is
| update-only: it hydrates the `_tmdb_*` columns (plus tmdb_synced_at) onto a
| shows row already matched by `_tmdb_id`, and never writes the TVDB-owned
| `_imdb_id` / `_tvdb_id`. Every test arranges the matching row first because
| the locked write is `Model::upsert($rows, ['_tmdb_id'], <_tmdb_* + stamp>)`.
|--------------------------------------------------------------------------
*/

describe('handle() column hydration', function (): void {
    it('updates an existing _tmdb_id row\'s _tmdb_* columns, stamps tmdb_synced_at, and returns 1', function (): void {
        // Arrange
        $payload = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
        Show::factory()->create(['_tmdb_id' => 1399]);

        // Act
        $count = resolve(UpsertTmdbShows::class)->handle([$payload]);

        // Assert
        expect($count)->toBe(1)
            ->and(Show::query()->count())->toBe(1);
        $show = Show::query()->where('_tmdb_id', 1399)->firstOrFail();
        expect($show->_tmdb_name)->toBe('Game of Thrones')
            ->and($show->_tmdb_original_name)->toBe('Game of Thrones')
            ->and($show->_tmdb_status)->toBe('Ended')
            ->and($show->_tmdb_first_air_date->format('Y-m-d'))->toBe('2011-04-17')
            ->and($show->tmdb_synced_at)->not->toBeNull();
    });

    it('leaves _imdb_id and _tvdb_id untouched while hydrating the _tmdb_* columns', function (): void {
        // Arrange
        $payload = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
        Show::factory()->create(['_tmdb_id' => 1399, '_imdb_id' => 'tt-original', '_tvdb_id' => 999]);

        // Act
        resolve(UpsertTmdbShows::class)->handle([$payload]);

        // Assert
        $fresh = Show::query()->where('_tmdb_id', 1399)->firstOrFail();
        expect($fresh->_imdb_id)->toBe('tt-original')
            ->and($fresh->_tvdb_id)->toBe(999)
            ->and($fresh->_tmdb_name)->toBe('Game of Thrones');
    });

    it('stores json fields raw, byte-for-byte the source json', function (): void {
        // Arrange
        $payload = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
        Show::factory()->create(['_tmdb_id' => 1399]);

        // Act
        resolve(UpsertTmdbShows::class)->handle([$payload]);

        // Assert
        $genres = DB::table('shows')->where('_tmdb_id', 1399)->value('_tmdb_genres');
        $externalIds = DB::table('shows')->where('_tmdb_id', 1399)->value('_tmdb_external_ids');
        expect($genres)->toBe(json_encode($payload['genres']))
            ->and($externalIds)->toBe(json_encode($payload['external_ids']));
    });
});

describe('handle() first_air_date sentinels', function (): void {
    it('persists a blank TMDB first_air_date as null, not an empty string', function (): void {
        // Arrange
        $payload = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
        $payload['id'] = 603;
        $payload['first_air_date'] = '';
        Show::factory()->create(['_tmdb_id' => 603]);

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
        Show::factory()->create(['_tmdb_id' => 604]);

        // Act
        resolve(UpsertTmdbShows::class)->handle([$payload]);

        // Assert
        expect(DB::table('shows')->where('_tmdb_id', 604)->value('_tmdb_first_air_date'))->toBeNull();
    });
});

describe('handle() malformed native id', function (): void {
    it('drops a payload whose native id is malformed, writing no row and no QueryException', function (): void {
        // Arrange
        $payload = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
        $payload['id'] = 12.5;

        // Act
        $count = resolve(UpsertTmdbShows::class)->handle([$payload]);

        // Assert
        expect($count)->toBe(0)
            ->and(Show::query()->count())->toBe(0);
    });

    it('drops only the malformed-native-id payload, upserting the valid one', function (): void {
        // Arrange
        $good = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
        $bad = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
        $bad['id'] = 12.5;
        Show::factory()->create(['_tmdb_id' => 1399]);

        // Act
        $count = resolve(UpsertTmdbShows::class)->handle([$good, $bad]);

        // Assert
        expect($count)->toBe(1)
            ->and(Show::query()->pluck('_tmdb_id')->all())->toBe([1399]);
    });
});

describe('handle() empty input', function (): void {
    it('returns 0 and persists nothing for empty input', function (): void {
        // Arrange
        $payloads = [];

        // Act
        $count = resolve(UpsertTmdbShows::class)->handle($payloads);

        // Assert
        expect($count)->toBe(0)
            ->and(Show::query()->count())->toBe(0);
    });
});
