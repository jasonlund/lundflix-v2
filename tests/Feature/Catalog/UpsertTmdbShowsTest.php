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
| upserter consumes, NOT a hand-fabricated array. TVDB is the source of truth
| for shows: this action is ENRICHMENT-ONLY. It matches an existing Show by the
| payload's nested external_ids.imdb_id (no top-level imdb_id key) against
| _imdb_id, fills _tmdb_* columns when found, and NEVER inserts a tmdb-only row.
|--------------------------------------------------------------------------
*/

it('enriches a matched show with _tmdb_* columns and stamps tmdb_synced_at', function (): void {
    // Arrange
    Show::factory()->withTvdb()->create(['_imdb_id' => 'tt0944947']);
    $payload = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);

    // Act
    resolve(UpsertTmdbShows::class)->handle([$payload]);

    // Assert
    $show = Show::query()->where('_imdb_id', 'tt0944947')->firstOrFail();
    expect($show->_tmdb_id)->toBe(1399)
        ->and($show->_tmdb_name)->toBe('Game of Thrones')
        ->and($show->tmdb_synced_at)->not->toBeNull()
        ->and(Show::query()->count())->toBe(1);
});

it('inserts nothing when no existing show matches the payload imdb id', function (): void {
    // Arrange
    $payload = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);

    // Act
    resolve(UpsertTmdbShows::class)->handle([$payload]);

    // Assert
    expect(Show::query()->count())->toBe(0);
});

it('leaves the matched show _imdb_id untouched on enrichment', function (): void {
    // Arrange
    Show::factory()->withTvdb()->create(['_imdb_id' => 'tt0944947']);
    $payload = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);

    // Act
    resolve(UpsertTmdbShows::class)->handle([$payload]);

    // Assert
    $show = Show::query()->where('_tmdb_id', 1399)->firstOrFail();
    expect($show->_imdb_id)->toBe('tt0944947');
});

it('stores _tmdb_* json fields raw, byte-for-byte the source json, on enrichment', function (): void {
    // Arrange
    Show::factory()->withTvdb()->create(['_imdb_id' => 'tt0944947']);
    $payload = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);

    // Act
    resolve(UpsertTmdbShows::class)->handle([$payload]);

    // Assert
    $genres = DB::table('shows')->where('_tmdb_id', 1399)->value('_tmdb_genres');
    $externalIds = DB::table('shows')->where('_tmdb_id', 1399)->value('_tmdb_external_ids');
    expect($genres)->toBe(json_encode($payload['genres']))
        ->and($externalIds)->toBe(json_encode($payload['external_ids']));
});
