<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\ReconcileImdbOnlyShows;
use App\Domains\Catalog\Exceptions\TmdbShowCrosswalkCollision;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Services\TmdbApiService;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| ReconcileImdbOnlyShows resolves the /find tmdb id for a chunk's imdb-only
| rows and stamps each resolved id onto its row, scoped to its PK. It does not
| hydrate — it returns the resolved `_tmdb_id` set the caller then hydrates,
| alongside whether any lookup failed outright.
|
| /find responses are byte-exact real captures:
| find_tv_by_imdb.json — real /find/tt0903747; tv_results[0].id 1396.
| find_by_imdb.json — real /find/tt0133093; tv_results EMPTY → row stays TVDB-only.
|--------------------------------------------------------------------------
*/

function fakeReconcileFind(): void
{
    Http::fake([
        '*/find/tt0903747*' => Http::response(fixtureBytes('Catalog/tmdb/find_tv_by_imdb.json')),
        '*/find/tt0133093*' => Http::response(fixtureBytes('Catalog/tmdb/find_by_imdb.json')),
    ]);
}

describe('handle() tmdb id resolution', function (): void {
    it('stamps an imdb-only row with its resolved tmdb id and returns it', function (): void {
        // Arrange
        fakeReconcileFind();
        $show = Show::factory()->withTvdb()->create(['_imdb_id' => 'tt0903747', '_tmdb_id' => null]);

        // Act
        $resolved = resolve(ReconcileImdbOnlyShows::class)->handle(collect([$show]), resolve(TmdbApiService::class));

        // Assert
        expect($resolved->resolvedIds)->toBe([1396]);
        expect($resolved->failed)->toBeFalse();
        expect($show->fresh()->_tmdb_id)->toBe(1396);
    });

    it('leaves an imdb-only row TVDB-only when /find returns no tv results', function (): void {
        // Arrange
        fakeReconcileFind();
        $show = Show::factory()->withTvdb()->create(['_imdb_id' => 'tt0133093', '_tmdb_id' => null]);

        // Act
        $resolved = resolve(ReconcileImdbOnlyShows::class)->handle(collect([$show]), resolve(TmdbApiService::class));

        // Assert
        expect($resolved->resolvedIds)->toBe([]);
        expect($show->fresh()->_tmdb_id)->toBeNull();
    });

    it('flags the chunk failed when /find never answers for an imdb id', function (): void {
        // A pooled id whose request fails outright is dropped from the result map, and
        // that short map is the only per-id failure signal TMDB gives. The flag has to
        // carry it out, or the caller defers a chunk an outage merely never answered.
        // Arrange
        Exceptions::fake();
        Http::fake(['*/find/tt0903747*' => Http::response('', 500)]);
        $show = Show::factory()->withTvdb()->create(['_imdb_id' => 'tt0903747', '_tmdb_id' => null]);

        // Act
        $resolved = resolve(ReconcileImdbOnlyShows::class)->handle(collect([$show]), resolve(TmdbApiService::class));

        // Assert
        expect($resolved->failed)->toBeTrue();
        expect($resolved->resolvedIds)->toBe([]);
        expect($show->fresh()->_tmdb_id)->toBeNull();
    });

    it('leaves the chunk unflagged when /find answers 404 for an imdb id', function (): void {
        // The counterpart guard: a 404 is an answer, so the id stays in the map as null
        // and the chunk is NOT failed — flagging it would make an unknown imdb id look
        // like an outage and block the caller from ever deferring the row.
        // Arrange
        Http::fake(['*/find/tt0903747*' => Http::response('', 404)]);
        $show = Show::factory()->withTvdb()->create(['_imdb_id' => 'tt0903747', '_tmdb_id' => null]);

        // Act
        $resolved = resolve(ReconcileImdbOnlyShows::class)->handle(collect([$show]), resolve(TmdbApiService::class));

        // Assert
        expect($resolved->failed)->toBeFalse();
        expect($resolved->resolvedIds)->toBe([]);
        expect($show->fresh()->_tmdb_id)->toBeNull();
    });

    it('reports a collision and leaves the row null when the resolved id already belongs to another row', function (): void {
        // Arrange
        Exceptions::fake();
        fakeReconcileFind();
        Show::factory()->withTvdb()->create(['_tmdb_id' => 1396]);
        $imdbOnly = Show::factory()->withTvdb()->create(['_imdb_id' => 'tt0903747', '_tmdb_id' => null]);

        // Act
        $resolved = resolve(ReconcileImdbOnlyShows::class)->handle(collect([$imdbOnly]), resolve(TmdbApiService::class));

        // Assert
        expect($resolved->resolvedIds)->toBe([]);
        expect($imdbOnly->fresh()->_tmdb_id)->toBeNull();
        Exceptions::assertReported(TmdbShowCrosswalkCollision::class);
    });
});
