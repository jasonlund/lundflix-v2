<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures (byte-exact real TMDB slices)
|--------------------------------------------------------------------------
| tests/Fixtures/Catalog/tmdb/movie_ids.json.gz — gz JSONL daily export, rows
|   shaped {adult, id, original_title, popularity, video}. Carries id 603
|   (The Matrix) at popularity 50.0 and original_title "The Matrix", video false,
|   alongside the other real export ids (2, 3, 5, 6, 3924, 8773, 25449, 31975)
|   and two hand-authored adult/softcore rows (9999990, 9999991) — the one
|   synthetic pair, for rows the kept slice cannot supply.
| tests/Fixtures/Catalog/tmdb/tv_series_ids.json.gz — the series export, rows
|   shaped {id, original_name, popularity} (no adult/video/original_title key at
|   all). Carries id 1396 (Breaking Bad) at popularity 155.148, plus ids 1–6,
|   456, 1399, 1668 and 2316.
|
| Only the two export URLs are faked, and only files.tmdb.org serves them. Stray
| requests are globally prevented in Feature tests, so any call this leg makes to
| api.themoviedb.org fails the test where it is made — which is the assertion that
| the refresh reads popularity from the exports and never pays for a detail fetch.
| Widening the fake to '*' would silently discard that guarantee.
*/

function fakeTmdbPopularityExports(): void
{
    Http::fake([
        '*movie_ids*' => Http::response(fixtureBytes('Catalog/tmdb/movie_ids.json.gz')),
        '*tv_series_ids*' => Http::response(fixtureBytes('Catalog/tmdb/tv_series_ids.json.gz')),
    ]);
}

describe('catalog:refresh-popularity exported rows', function (): void {
    it('refreshes the popularity of a movie the export lists', function (): void {
        // Arrange
        fakeTmdbPopularityExports();
        $movie = Movie::factory()->withTmdb()->create([
            '_tmdb_id' => 603,
            '_tmdb_popularity' => 1.5,
        ]);

        // Act
        $this->artisan('catalog:refresh-popularity');

        // Assert
        expect(Movie::query()->find($movie->id)->_tmdb_popularity)->toBe(50.0);
    });

    it('refreshes the popularity of a show the export lists', function (): void {
        // Arrange
        fakeTmdbPopularityExports();
        $show = Show::factory()->withTmdb()->create([
            '_tmdb_id' => 1396,
            '_tmdb_popularity' => 1.5,
        ]);

        // Act
        $this->artisan('catalog:refresh-popularity');

        // Assert
        expect(Show::query()->find($show->id)->_tmdb_popularity)->toBe(155.148);
    });

    it('leaves the popularity of a movie the export omits untouched', function (): void {
        // 777777 appears in neither export. The listed sibling is arranged in the
        // same test on purpose: without it, a run that refreshed nothing at all
        // would satisfy the "untouched" half and pass.
        // Arrange
        fakeTmdbPopularityExports();
        $unlisted = Movie::factory()->withTmdb()->create([
            '_tmdb_id' => 777777,
            '_tmdb_popularity' => 7.25,
        ]);
        $listed = Movie::factory()->withTmdb()->create([
            '_tmdb_id' => 603,
            '_tmdb_popularity' => 1.5,
        ]);

        // Act
        $this->artisan('catalog:refresh-popularity');

        // Assert
        expect(Movie::query()->find($listed->id)->_tmdb_popularity)->toBe(50.0)
            ->and(Movie::query()->find($unlisted->id)->_tmdb_popularity)->toBe(7.25);
    });
});

describe('catalog:refresh-popularity write scope', function (): void {
    it('keeps updated_at stale on a row whose popularity it refreshes', function (): void {
        // `updated_at` is the watermark the end-of-job reindex selects on, and this
        // leg rewrites one number across the whole catalog — stamping every row
        // would drag the entire catalog through the search engine on every run.
        // A fresh row's updated_at is already the frozen now, so the stale value has
        // to be forced on before the act or the assertion passes either way.
        // Arrange
        $this->freezeTime();
        fakeTmdbPopularityExports();
        $stale = '2020-01-01 00:00:00';
        $movie = Movie::factory()->withTmdb()->create([
            '_tmdb_id' => 603,
            '_tmdb_popularity' => 1.5,
        ]);
        Movie::query()->whereKey($movie->id)->toBase()->update(['updated_at' => $stale]);

        // Act
        $this->artisan('catalog:refresh-popularity');

        // The stale precondition must differ from the frozen now, and the popularity
        // must really have been written — a no-op run satisfies the updated_at
        // assertion on its own.
        // Assert
        $fresh = Movie::query()->find($movie->id);
        expect($stale)->not->toBe(now()->toDateTimeString())
            ->and($fresh->updated_at->toDateTimeString())->toBe($stale)
            ->and($fresh->_tmdb_popularity)->toBe(50.0);
    });

    it('writes no _tmdb column other than the popularity', function (): void {
        // The export row for 603 carries `video: false` and
        // `original_title: "The Matrix"`, both deliberately contradicting the held
        // values below: a leg that upserted the whole export row would overwrite
        // them, where a popularity-only write cannot. (`movies` has no _tmdb_adult
        // column, so the export's adult flag has nowhere to land.)
        // Arrange
        fakeTmdbPopularityExports();
        $movie = Movie::factory()->withTmdb()->create([
            '_tmdb_id' => 603,
            '_tmdb_popularity' => 1.5,
            '_tmdb_video' => true,
            '_tmdb_original_title' => 'Held original title',
        ]);

        // Act
        $this->artisan('catalog:refresh-popularity');

        // Assert
        $fresh = Movie::query()->find($movie->id);
        expect($fresh->_tmdb_popularity)->toBe(50.0)
            ->and($fresh->_tmdb_video)->toBeTrue()
            ->and($fresh->_tmdb_original_title)->toBe('Held original title');
    });
});

describe('catalog:refresh-popularity output', function (): void {
    // The committed exports match a couple of rows — orders of magnitude below any
    // sane heartbeat interval — so crossing a boundary needs a synthetic export
    // real data cannot supply: 1001 `{"id":N,"popularity":…}` rows, one past the
    // 1000 boundary, against 1001 held movies that carry those very ids so every
    // row matches. The series export is faked empty so the movie leg alone owns
    // the running total.
    it('beats the running refreshed total at each heartbeat boundary', function (): void {
        // Arrange
        $held = Movie::factory()->count(1001)->make()
            ->values()
            ->map(fn (Movie $movie, int $index): array => [...$movie->getAttributes(), '_tmdb_id' => $index + 1])
            ->all();
        Movie::insert($held);
        $exported = array_map(
            static fn (int $id): string => json_encode(['id' => $id, 'popularity' => 9.5]),
            range(1, 1001),
        );
        Http::fake([
            '*movie_ids*' => Http::response(gzencode(implode("\n", $exported))),
            '*tv_series_ids*' => Http::response(gzencode('')),
        ]);

        // Act
        Artisan::call('catalog:refresh-popularity');

        // Assert
        expect(Artisan::output())->toContain('  [tmdb popularity 1000]');
    });

    it('closes with the exact refreshed total on a run shorter than one boundary', function (): void {
        // The committed exports list 603 and 1396, so exactly two held rows refresh
        // — a total that never crosses a boundary, and so is reported only by the
        // closing flush. Both legs are arranged because the total accumulates
        // across them into one figure.
        // Arrange
        fakeTmdbPopularityExports();
        Movie::factory()->withTmdb()->create(['_tmdb_id' => 603]);
        Show::factory()->withTmdb()->create(['_tmdb_id' => 1396]);

        // Act
        Artisan::call('catalog:refresh-popularity');

        // Assert
        expect(Artisan::output())->toContain('  [tmdb popularity 2]');
    });

    it('closes with a final Done. line', function (): void {
        // Arrange
        fakeTmdbPopularityExports();

        // Act
        Artisan::call('catalog:refresh-popularity');

        // Assert
        expect(Str::trim(Artisan::output()))->toEndWith('Done.');
    });
});
