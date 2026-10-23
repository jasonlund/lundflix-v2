<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\PlexLibrary\Contracts\ReportsPresence;
use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexMovie;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The kind/id pairs a returned collection carries, sorted so no comparison
 * depends on the order a batch happened to resolve in.
 *
 * @param  Collection<int, UnitRef>  $refs
 * @return list<array{0: string, 1: int}>
 */
function sortedUnitPairs(Collection $refs): array
{
    $pairs = $refs->map(fn (UnitRef $ref): array => [$ref->kind->value, $ref->id])->all();

    sort($pairs);

    return array_values($pairs);
}

describe('present() batched presence', function (): void {
    it('returns exactly the units the server holds from a mixed batch', function (): void {
        // Arrange
        $mirroredMovie = Movie::factory()->create(['_tmdb_id' => 550]);
        PlexMovie::factory()->create(['_tmdb_id' => 550]);
        $absentMovie = Movie::factory()->create(['_tmdb_id' => 680]);
        $show = Show::factory()->withTvdb()->create(['_tvdb_id' => 121361]);
        $mirroredEpisode = Episode::factory()->for($show)->create([
            '_tvdb_id' => 3254641,
            '_tvdb_seasonNumber' => 1,
            '_tvdb_number' => 1,
        ]);
        PlexEpisode::factory()->create(['_tvdb_id' => 3254641]);
        $absentEpisode = Episode::factory()->for($show)->create([
            '_tvdb_id' => 3254642,
            '_tvdb_seasonNumber' => 1,
            '_tvdb_number' => 2,
        ]);

        // Act
        $present = resolve(ReportsPresence::class)->present([
            new UnitRef(UnitKind::Movie, $mirroredMovie->id),
            new UnitRef(UnitKind::Movie, $absentMovie->id),
            new UnitRef(UnitKind::Episode, $mirroredEpisode->id),
            new UnitRef(UnitKind::Episode, $absentEpisode->id),
        ]);

        // Assert
        $pairs = sortedUnitPairs($present);
        expect($pairs)->toBe([
            ['episode', $mirroredEpisode->id],
            ['movie', $mirroredMovie->id],
        ]);
        expect($pairs)->not->toContain(['movie', $absentMovie->id]);
        expect($pairs)->not->toContain(['episode', $absentEpisode->id]);
    });

    it('returns nothing and reads no table for an empty batch', function (): void {
        // The log is enabled and flushed last, so the arranged rows above are
        // never counted as reads the batch made.
        // Arrange
        $movie = Movie::factory()->create(['_tmdb_id' => 550]);
        PlexMovie::factory()->create(['_tmdb_id' => $movie->_tmdb_id]);
        DB::enableQueryLog();
        DB::flushQueryLog();

        // Act
        $present = resolve(ReportsPresence::class)->present([]);

        // Assert
        expect($present)->toBeEmpty();
        expect(DB::getQueryLog())->toBe([]);
    });

    it('resolves a large mixed batch within a bounded number of queries', function (): void {
        // Arrange
        $refs = [];
        $expected = [];

        foreach (range(0, 9) as $index) {
            $movie = Movie::factory()->create(['_tmdb_id' => 1000 + $index]);
            $refs[] = new UnitRef(UnitKind::Movie, $movie->id);

            if ($index % 2 === 0) {
                PlexMovie::factory()->create(['_tmdb_id' => 1000 + $index]);
                $expected[] = ['movie', $movie->id];
            }
        }

        foreach (range(0, 9) as $index) {
            $episode = Episode::factory()->create([
                '_tvdb_id' => 2000 + $index,
                '_tvdb_seasonNumber' => 1,
                '_tvdb_number' => $index + 1,
            ]);
            $refs[] = new UnitRef(UnitKind::Episode, $episode->id);

            if ($index % 2 === 0) {
                PlexEpisode::factory()->create(['_tvdb_id' => 2000 + $index]);
                $expected[] = ['episode', $episode->id];
            }
        }

        sort($expected);
        DB::enableQueryLog();
        DB::flushQueryLog();

        // Act
        $present = resolve(ReportsPresence::class)->present($refs);

        // The acquire sweep checks every queued acquisition on every scheduled
        // run, so a per-unit query is the difference between one round trip and
        // thousands — this is the one place these tests reach past the interface,
        // and the bound is deliberately loose rather than exact so a refactor may
        // shift work between the movie and episode reads without failing.
        // Assert
        expect(sortedUnitPairs($present))->toBe($expected);
        expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(2);
    });
});
