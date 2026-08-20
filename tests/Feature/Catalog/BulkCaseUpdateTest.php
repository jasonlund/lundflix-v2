<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Support\BulkCaseUpdate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Count the `update` statements captured in the query log, so a bulk write can
 * be told apart from a per-row loop.
 */
function loggedUpdateCount(): int
{
    return collect(DB::getQueryLog())
        ->filter(fn (array $entry): bool => Str::startsWith((string) $entry['query'], 'update'))
        ->count();
}

describe('handle() bulk write', function (): void {
    it('writes one column across several rows in a single update', function (): void {
        // Arrange
        $first = Movie::factory()->create(['_imdb_numVotes' => 1]);
        $second = Movie::factory()->create(['_imdb_numVotes' => 2]);
        $third = Movie::factory()->create(['_imdb_numVotes' => 3]);
        DB::enableQueryLog();

        // Act
        resolve(BulkCaseUpdate::class)->handle(
            Movie::query(),
            [
                $first->_imdb_id => ['_imdb_numVotes' => 2252453],
                $second->_imdb_id => ['_imdb_numVotes' => 987654],
                $third->_imdb_id => ['_imdb_numVotes' => 42],
            ],
            ['_imdb_numVotes'],
        );

        // Assert
        expect(Movie::query()->find($first->id)->_imdb_numVotes)->toBe(2252453)
            ->and(Movie::query()->find($second->id)->_imdb_numVotes)->toBe(987654)
            ->and(Movie::query()->find($third->id)->_imdb_numVotes)->toBe(42)
            ->and(loggedUpdateCount())->toBe(1);
    });

    it('writes several columns in one update, each row getting its own values', function (): void {
        // Arrange
        $first = Movie::factory()->create(['_imdb_numVotes' => 1, '_imdb_averageRating' => 1.0]);
        $second = Movie::factory()->create(['_imdb_numVotes' => 2, '_imdb_averageRating' => 2.0]);
        DB::enableQueryLog();

        // Act
        resolve(BulkCaseUpdate::class)->handle(
            Movie::query(),
            [
                $first->_imdb_id => ['_imdb_numVotes' => 2252453, '_imdb_averageRating' => 8.7],
                $second->_imdb_id => ['_imdb_numVotes' => 987654, '_imdb_averageRating' => 9.2],
            ],
            ['_imdb_numVotes', '_imdb_averageRating'],
        );

        // Assert
        $freshFirst = Movie::query()->find($first->id);
        $freshSecond = Movie::query()->find($second->id);
        expect($freshFirst->_imdb_numVotes)->toBe(2252453)
            ->and($freshFirst->_imdb_averageRating)->toBe(8.7)
            ->and($freshSecond->_imdb_numVotes)->toBe(987654)
            ->and($freshSecond->_imdb_averageRating)->toBe(9.2)
            ->and(loggedUpdateCount())->toBe(1);
    });
});

describe('handle() query scopes and bindings', function (): void {
    it('appends CASE bindings to pre-existing join bindings instead of replacing them', function (): void {
        // A query that already carries a parameterised join, so the 'join' binding
        // slot is non-empty before the CASE bindings are assigned. Assigning over it
        // (`bindings['join'] = $case...`) drops the join binding entirely; a future
        // join/global-scope on Movie would then have its binding silently swallowed,
        // shifting every placeholder. The CASE bindings must be appended to it.
        // Arrange
        $movie = Movie::factory()->create(['_imdb_numVotes' => 100, '_imdb_averageRating' => 1.0]);
        $scopedQuery = Movie::query()->joinSub(
            DB::table('movies')->select('id as scoped_id')->where('_imdb_numVotes', '>', -98765),
            'scoped',
            'movies.id',
            '=',
            'scoped.scoped_id',
        );
        DB::enableQueryLog();

        // Act
        resolve(BulkCaseUpdate::class)->handle(
            $scopedQuery,
            [$movie->_imdb_id => ['_imdb_numVotes' => 2252453, '_imdb_averageRating' => 8.7]],
            ['_imdb_numVotes', '_imdb_averageRating'],
        );

        // The join's own binding (-98765) survives in the executed update.
        // Assert
        $updateLog = collect(DB::getQueryLog())
            ->firstWhere(fn (array $entry): bool => Str::startsWith((string) $entry['query'], 'update'));
        expect($updateLog['bindings'] ?? [])->toContain(-98765);
    });

    it('applies a global scope on the query to the update itself', function (): void {
        // A scope registered on the builder constrains which ids are selected, so it
        // must constrain the write too — `getQuery()` skips applyScopes() and would
        // drop the scope's WHERE (and its binding) from the executed update.
        // Arrange
        $movie = Movie::factory()->create(['_imdb_numVotes' => 100]);
        $scopedQuery = Movie::query()->withGlobalScope(
            'positive_votes',
            fn (Builder $query): Builder => $query->where('_imdb_numVotes', '>', -98765),
        );
        DB::enableQueryLog();

        // Act
        resolve(BulkCaseUpdate::class)->handle(
            $scopedQuery,
            [$movie->_imdb_id => ['_imdb_numVotes' => 2252453]],
            ['_imdb_numVotes'],
        );

        // Assert
        $updateLog = collect(DB::getQueryLog())
            ->firstWhere(fn (array $entry): bool => Str::startsWith((string) $entry['query'], 'update'));
        expect($updateLog['bindings'] ?? [])->toContain(-98765)
            ->and(Movie::query()->find($movie->id)->_imdb_numVotes)->toBe(2252453);
    });
});

describe('handle() matched ids', function (): void {
    it('returns the matched imdb ids', function (): void {
        // Arrange
        $first = Movie::factory()->create(['_imdb_numVotes' => 1]);
        $second = Movie::factory()->create(['_imdb_numVotes' => 2]);

        // Act
        $matchedIds = resolve(BulkCaseUpdate::class)->handle(
            Movie::query(),
            [
                $first->_imdb_id => ['_imdb_numVotes' => 2252453],
                $second->_imdb_id => ['_imdb_numVotes' => 987654],
                'tt9999999' => ['_imdb_numVotes' => 50],
            ],
            ['_imdb_numVotes'],
        );

        // Assert
        expect($matchedIds)->toEqualCanonicalizing([$first->_imdb_id, $second->_imdb_id]);
    });

    it('issues no update and returns no ids when nothing matches', function (): void {
        // Arrange
        $movie = Movie::factory()->create(['_imdb_numVotes' => 100]);
        DB::enableQueryLog();

        // Act
        $matchedIds = resolve(BulkCaseUpdate::class)->handle(
            Movie::query(),
            ['tt9999999' => ['_imdb_numVotes' => 50]],
            ['_imdb_numVotes'],
        );

        // Assert
        expect($matchedIds)->toBe([])
            ->and(loggedUpdateCount())->toBe(0)
            ->and(Movie::query()->find($movie->id)->_imdb_numVotes)->toBe(100);
    });
});
