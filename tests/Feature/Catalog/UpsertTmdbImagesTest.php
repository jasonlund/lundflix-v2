<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\UpsertTmdbImages;
use App\Domains\Catalog\Enums\ArtworkType;
use App\Domains\Catalog\Models\Media;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use Illuminate\Support\Facades\DB;

/**
 * A poster payload of $count entries, each with a unique path under $prefix.
 *
 * Synthetic on purpose: the real captures below fix their own image counts, and
 * these cases are about volume and cost rather than wire shape.
 *
 * @return array{posters: list<array<string, mixed>>}
 */
function tmdbPosterPayload(string $prefix, int $count): array
{
    return ['posters' => collect(range(1, $count))
        ->map(fn (int $n): array => [
            'file_path' => "/{$prefix}-{$n}.jpg",
            // Zero votes on the tail entries: nothing may gate on popularity.
            'vote_average' => $n > $count - 5 ? 0.0 : 5.0,
            'vote_count' => $n > $count - 5 ? 0 : 12,
        ])
        ->all()];
}

describe('handle() tmdb image upsert', function (): void {
    it('maps each image to a typed active media row with raw attrs', function (): void {
        // Arrange
        $movie = Movie::factory()->withTmdb()->create();
        $decoded = json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true);
        $images = $decoded['images'];

        // Act
        $count = (new UpsertTmdbImages)->handle(
            collect([$movie->_tmdb_id => $movie]),
            [$movie->_tmdb_id => $images],
        );

        // Assert
        expect($movie->media()->where('type', ArtworkType::Poster)->count())->toBe(126)
            ->and($movie->media()->where('type', ArtworkType::Backdrop)->count())->toBe(87)
            ->and($movie->media()->where('type', ArtworkType::Logo)->count())->toBe(16)
            ->and($count)->toBe(229);

        $this->assertDatabaseCount('media', 229);
        $this->assertDatabaseHas('media', [
            'mediable_type' => $movie->getMorphClass(),
            'mediable_id' => $movie->id,
            'type' => 'poster',
            'is_active' => true,
            '_tmdb_file_path' => '/aOIuZAjPaRIE6CMzbazvcHuHXDc.jpg',
            '_tmdb_iso_639_1' => 'en',
            '_tmdb_iso_3166_1' => 'US',
            '_tmdb_vote_average' => 6.2,
            '_tmdb_vote_count' => 35,
            '_tmdb_width' => 2000,
            '_tmdb_height' => 3000,
            '_tmdb_aspect_ratio' => 0.667,
        ]);
        expect($movie->media()->where('is_active', true)->count())->toBe(229);
    });

    it('writes every title of a batch in one call, each against its own id', function (): void {
        // Arrange
        $first = Movie::factory()->withTmdb()->create();
        $second = Movie::factory()->withTmdb()->create();
        $images = [
            $first->_tmdb_id => ['posters' => [['file_path' => '/first-poster.jpg']]],
            $second->_tmdb_id => [
                'posters' => [['file_path' => '/second-poster.jpg']],
                'backdrops' => [['file_path' => '/second-backdrop.jpg']],
            ],
        ];

        // Act
        $count = (new UpsertTmdbImages)->handle(
            collect([$first->_tmdb_id => $first, $second->_tmdb_id => $second]),
            $images,
        );

        // Assert
        $this->assertDatabaseHas('media', [
            'mediable_type' => $first->getMorphClass(),
            'mediable_id' => $first->id,
            'type' => 'poster',
            'is_active' => true,
            '_tmdb_file_path' => '/first-poster.jpg',
        ]);
        $this->assertDatabaseHas('media', [
            'mediable_type' => $second->getMorphClass(),
            'mediable_id' => $second->id,
            'type' => 'backdrop',
            'is_active' => true,
            '_tmdb_file_path' => '/second-backdrop.jpg',
        ]);
        expect($first->media()->count())->toBe(1)
            ->and($second->media()->count())->toBe(2)
            ->and($count)->toBe(3);
    });

    it('stores every image in a payload — no cap, no popularity gate', function (): void {
        // Arrange
        $movie = Movie::factory()->withTmdb()->create();
        $images = tmdbPosterPayload('deep', 60);

        // Act
        $count = (new UpsertTmdbImages)->handle(
            collect([$movie->_tmdb_id => $movie]),
            [$movie->_tmdb_id => $images],
        );

        // Assert
        $this->assertDatabaseCount('media', 60);
        $this->assertDatabaseHas('media', [
            'mediable_id' => $movie->id,
            '_tmdb_file_path' => '/deep-60.jpg',
            '_tmdb_vote_count' => 0,
            'is_active' => true,
        ]);
        expect($movie->media()->where('type', ArtworkType::Poster)->count())->toBe(60)
            ->and($count)->toBe(60);
    });

    it('empty images block is a no-op', function (): void {
        // Arrange
        $movie = Movie::factory()->withTmdb()->create();

        // Act
        $count = (new UpsertTmdbImages)->handle(collect([$movie->_tmdb_id => $movie]), []);

        // Assert
        $this->assertDatabaseCount('media', 0);
        expect($count)->toBe(0);
    });

    it('deactivates stale managed-type art no longer in the payload', function (): void {
        // Arrange
        $movie = Movie::factory()->withTmdb()->create();
        Media::factory()->create([
            'mediable_id' => $movie->id,
            'mediable_type' => $movie->getMorphClass(),
            'type' => ArtworkType::Poster,
            'is_active' => true,
            '_tmdb_file_path' => '/STALE-not-in-payload.jpg',
        ]);
        $images = json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true)['images'];

        // Act
        (new UpsertTmdbImages)->handle(
            collect([$movie->_tmdb_id => $movie]),
            [$movie->_tmdb_id => $images],
        );

        // Assert
        $this->assertDatabaseHas('media', [
            'mediable_id' => $movie->id,
            '_tmdb_file_path' => '/STALE-not-in-payload.jpg',
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('media', [
            'mediable_id' => $movie->id,
            '_tmdb_file_path' => '/aOIuZAjPaRIE6CMzbazvcHuHXDc.jpg',
            'is_active' => true,
        ]);
    });

    it('leaves active art on titles outside the batch alone', function (): void {
        // Arrange
        $batched = Movie::factory()->withTmdb()->create();
        $otherMovie = Movie::factory()->withTmdb()->create();
        $otherShow = Show::factory()->withTmdb()->create();
        Media::factory()->create([
            'mediable_id' => $otherMovie->id,
            'mediable_type' => $otherMovie->getMorphClass(),
            'type' => ArtworkType::Poster,
            'is_active' => true,
            '_tmdb_file_path' => '/OTHER-MOVIE-poster.jpg',
        ]);
        Media::factory()->create([
            'mediable_id' => $otherShow->id,
            'mediable_type' => $otherShow->getMorphClass(),
            'type' => ArtworkType::Backdrop,
            'is_active' => true,
            '_tmdb_file_path' => '/OTHER-SHOW-backdrop.jpg',
        ]);
        $images = json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true)['images'];

        // Act
        (new UpsertTmdbImages)->handle(
            collect([$batched->_tmdb_id => $batched]),
            [$batched->_tmdb_id => $images],
        );

        // Assert
        $this->assertDatabaseHas('media', [
            'mediable_type' => $otherMovie->getMorphClass(),
            'mediable_id' => $otherMovie->id,
            '_tmdb_file_path' => '/OTHER-MOVIE-poster.jpg',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('media', [
            'mediable_type' => $otherShow->getMorphClass(),
            'mediable_id' => $otherShow->id,
            '_tmdb_file_path' => '/OTHER-SHOW-backdrop.jpg',
            'is_active' => true,
        ]);
        expect($otherMovie->media()->where('is_active', true)->count())->toBe(1)
            ->and($otherShow->media()->where('is_active', true)->count())->toBe(1);
    });

    it('is idempotent on re-run — no duplicate rows and a stable active set', function (): void {
        // Arrange
        $movie = Movie::factory()->withTmdb()->create();
        $images = json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true)['images'];

        // Act
        $action = new UpsertTmdbImages;
        $action->handle(collect([$movie->_tmdb_id => $movie]), [$movie->_tmdb_id => $images]);
        $action->handle(collect([$movie->_tmdb_id => $movie]), [$movie->_tmdb_id => $images]);

        // Assert
        $this->assertDatabaseCount('media', 229);
        expect(Media::where('is_active', true)->count())->toBe(229);
    });

    it('skips images missing a file_path instead of creating a null-path row', function (): void {
        // Arrange
        $movie = Movie::factory()->withTmdb()->create();
        $images = [
            'posters' => [
                ['file_path' => '/valid-poster.jpg', 'vote_average' => 5.0],
                ['vote_average' => 7.0],
                ['vote_average' => 9.0],
            ],
        ];

        // Act
        $count = (new UpsertTmdbImages)->handle(
            collect([$movie->_tmdb_id => $movie]),
            [$movie->_tmdb_id => $images],
        );

        // Assert
        $this->assertDatabaseCount('media', 1);
        $this->assertDatabaseHas('media', [
            'mediable_id' => $movie->id,
            '_tmdb_file_path' => '/valid-poster.jpg',
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('media', ['_tmdb_file_path' => null]);
        expect($count)->toBe(1);
    });

    it('reactivates a previously-inactive file_path that reappears in the payload', function (): void {
        // Arrange
        $movie = Movie::factory()->withTmdb()->create();
        Media::factory()->create([
            'mediable_id' => $movie->id,
            'mediable_type' => $movie->getMorphClass(),
            'type' => ArtworkType::Poster,
            'is_active' => false,
            '_tmdb_file_path' => '/aOIuZAjPaRIE6CMzbazvcHuHXDc.jpg',
        ]);
        $images = json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true)['images'];

        // Act
        (new UpsertTmdbImages)->handle(
            collect([$movie->_tmdb_id => $movie]),
            [$movie->_tmdb_id => $images],
        );

        // Assert
        $this->assertDatabaseHas('media', [
            'mediable_id' => $movie->id,
            '_tmdb_file_path' => '/aOIuZAjPaRIE6CMzbazvcHuHXDc.jpg',
            'is_active' => true,
        ]);
        expect($movie->media()->where('_tmdb_file_path', '/aOIuZAjPaRIE6CMzbazvcHuHXDc.jpg')->count())->toBe(1);
    });

    it('persists tmdb images against a show into active media rows', function (): void {
        // Arrange
        $show = Show::factory()->withTmdb()->create();
        $images = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true)['images'];

        // Act
        $count = (new UpsertTmdbImages)->handle(
            collect([$show->_tmdb_id => $show]),
            [$show->_tmdb_id => $images],
        );

        // Assert
        expect($show->media()->where('type', ArtworkType::Poster)->count())->toBe(207)
            ->and($show->media()->where('type', ArtworkType::Backdrop)->count())->toBe(423)
            ->and($show->media()->where('type', ArtworkType::Logo)->count())->toBe(13)
            ->and($count)->toBe(643);

        $this->assertDatabaseHas('media', [
            'mediable_type' => $show->getMorphClass(),
            'mediable_id' => $show->id,
            'type' => 'poster',
            'is_active' => true,
            '_tmdb_file_path' => '/1XS1oqL89opfnbLl8WnZY1O1uJx.jpg',
        ]);
    });
});

describe('handle() statement cost', function (): void {
    it('costs statements per batch, not per image', function (): void {
        // Arrange
        $statementsFor = function (int $titleCount): int {
            $titles = Movie::factory()->withTmdb()->count($titleCount)->create()->keyBy('_tmdb_id');
            $images = $titles->keys()
                ->mapWithKeys(fn (int $tmdbId): array => [$tmdbId => tmdbPosterPayload((string) $tmdbId, 30)])
                ->all();

            $statements = 0;
            DB::listen(function () use (&$statements): void {
                $statements++;
            });

            (new UpsertTmdbImages)->handle($titles, $images);

            return $statements;
        };

        // Act
        $twoTitles = $statementsFor(2);
        $fourTitles = $statementsFor(4);

        // Assert
        // The larger run carries 60 more images. A per-image write costs at least one
        // statement each, so a growth under that floor is what proves the cost tracks
        // the batch of titles rather than the artwork inside it.
        expect($fourTitles - $twoTitles)->toBeLessThan(60)
            ->and(Media::count())->toBe(180);
    });
});
