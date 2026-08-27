<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Support\BulkCaseUpdate;
use Illuminate\Database\Connection;
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

/**
 * The compiled `update` statement and the bindings actually handed to PDO —
 * i.e. post `prepareBindingsForUpdate()` (grammar-specific) and post
 * `Builder::cleanBindings()` (which drops the Expression values). Timestamps
 * are rendered as strings so the list can be compared to literals.
 *
 * @return array{sql: string, bindings: list<mixed>}
 */
function preparedBulkUpdate(?Connection $connection = null): array
{
    $entry = collect(($connection ?? DB::connection())->getQueryLog())
        ->firstWhere(fn (array $entry): bool => Str::startsWith((string) $entry['query'], 'update'));

    return [
        'sql' => (string) ($entry['query'] ?? ''),
        'bindings' => collect($entry['bindings'] ?? [])
            ->map(fn (mixed $binding): mixed => $binding instanceof DateTimeInterface
                ? $binding->format('Y-m-d H:i:s')
                : $binding)
            ->all(),
    ];
}

/**
 * A connection that compiles through MySqlGrammar with no MySQL server: the
 * configured `mysql` connection with its PDO pointed at the suite's in-memory
 * sqlite handle. sqlite accepts the statement MySqlGrammar emits here (backtick
 * quoting included), so the write really runs and its prepared bindings are
 * recorded — the MySQL-side alignment check an sqlite-only suite otherwise
 * cannot make, and which until now existed only as a hand-run `pretend()`.
 * Sharing the default connection's PDO also keeps the write inside
 * RefreshDatabase's transaction; `afterEach` purges the borrowed handle.
 */
function mysqlGrammarConnection(): Connection
{
    $connection = DB::connection('mysql');
    $connection->setPdo(DB::connection()->getPdo());
    $connection->enableQueryLog();

    return $connection;
}

afterEach(fn () => DB::purge('mysql'));

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

    it('keeps a pre-existing join binding ahead of the appended CASE bindings', function (): void {
        // The stronger half of the test above: not just that the join's binding
        // survives, but that it survives *in place*, with the SET bindings added
        // after it. `bindings['join'] = $setBindings` (assignment) drops it, and
        // prepending the SET bindings would shift it — both leave the update
        // binding a value into the wrong placeholder.
        // Arrange
        $this->freezeTime();
        $movie = Movie::factory()->create(['_imdb_id' => 'tt0000001', '_imdb_numVotes' => 100]);
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
            [$movie->_imdb_id => ['_imdb_numVotes' => 2252453]],
            ['_imdb_numVotes'],
        );

        // Assert
        expect(preparedBulkUpdate()['bindings'])->toBe([
            -98765,
            'tt0000001', 2252453,
            now()->format('Y-m-d H:i:s'),
            'tt0000001',
        ]);
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

describe('handle() binding alignment per grammar', function (): void {
    it('binds the CASE and updated_at placeholders before the WHERE ones under the sqlite grammar', function (): void {
        // SQLiteGrammar overrides prepareBindingsForUpdate() to emit the update's
        // *value* bindings first — ahead of the join slot, which it no longer
        // excepts — where every other grammar emits the join slot first. Passing
        // updated_at as a plain value therefore lands its binding in the leading
        // value slot while its placeholder is last in the SET clause: the vote
        // count is written into updated_at (the FLIX-276 corruption, `1970-01-27`).
        // Keeping it an Expression('?') leaves the value slot empty, so both
        // grammars produce the same order — SET, in SET-clause order, then WHERE.
        // Arrange
        $this->freezeTime();
        $first = Movie::factory()->create(['_imdb_id' => 'tt0000001', '_imdb_numVotes' => 1]);
        $second = Movie::factory()->create(['_imdb_id' => 'tt0000002', '_imdb_numVotes' => 2]);
        DB::enableQueryLog();

        // Act
        resolve(BulkCaseUpdate::class)->handle(
            Movie::query(),
            [
                $first->_imdb_id => ['_imdb_numVotes' => 2252453],
                $second->_imdb_id => ['_imdb_numVotes' => 987654],
            ],
            ['_imdb_numVotes'],
        );

        // Assert
        $update = preparedBulkUpdate();
        expect($update['sql'])->toBe(
            'update "movies" set "_imdb_numVotes" = CASE _imdb_id WHEN ? THEN ? WHEN ? THEN ? END, '
            .'"updated_at" = ? where "_imdb_id" in (?, ?)'
        )
            ->and($update['bindings'])->toBe([
                'tt0000001', 2252453,
                'tt0000002', 987654,
                now()->format('Y-m-d H:i:s'),
                'tt0000001', 'tt0000002',
            ]);
    });

    it('binds the CASE and updated_at placeholders before the WHERE ones under the MySQL grammar', function (): void {
        // The production grammar, which the sqlite suite never compiles: the
        // alignment above was verified once by hand and never committed, so this
        // is that verification. MySqlGrammar keeps the join slot first and the
        // value slot after it, the mirror image of sqlite — only an empty value
        // slot satisfies both. The write executes, so a misalignment shows up as
        // a corrupted row as well as a reordered binding list.
        // Arrange
        $this->freezeTime();
        $mysql = mysqlGrammarConnection();
        $first = Movie::factory()->create(['_imdb_id' => 'tt0000001', '_imdb_numVotes' => 1]);
        $second = Movie::factory()->create(['_imdb_id' => 'tt0000002', '_imdb_numVotes' => 2]);

        // Act
        resolve(BulkCaseUpdate::class)->handle(
            Movie::on('mysql'),
            [
                $first->_imdb_id => ['_imdb_numVotes' => 2252453],
                $second->_imdb_id => ['_imdb_numVotes' => 987654],
            ],
            ['_imdb_numVotes'],
        );

        // Assert
        $update = preparedBulkUpdate($mysql);
        expect($update['sql'])->toBe(
            'update `movies` set `_imdb_numVotes` = CASE _imdb_id WHEN ? THEN ? WHEN ? THEN ? END, '
            .'`updated_at` = ? where `_imdb_id` in (?, ?)'
        )
            ->and($update['bindings'])->toBe([
                'tt0000001', 2252453,
                'tt0000002', 987654,
                now()->format('Y-m-d H:i:s'),
                'tt0000001', 'tt0000002',
            ])
            ->and(Movie::query()->find($first->id)->_imdb_numVotes)->toBe(2252453)
            ->and(Movie::query()->find($second->id)->_imdb_numVotes)->toBe(987654)
            ->and(Movie::query()->find($first->id)->updated_at->format('Y-m-d H:i:s'))->toBe(now()->format('Y-m-d H:i:s'));
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

describe('handle() updated_at watermark', function (): void {
    it('advances updated_at on every row it writes', function (): void {
        // `updated_at` is the watermark the end-of-job reindex selects on
        // (`where('updated_at', '>=', $watermark)`), so a bulk write that leaves it
        // stale makes every row it touched invisible to the reindex.
        // Arrange
        $this->freezeTime();
        $stale = '2020-01-01 00:00:00';
        $first = Movie::factory()->create(['_imdb_numVotes' => 1]);
        $second = Movie::factory()->create(['_imdb_numVotes' => 2]);
        Movie::query()->whereKey([$first->id, $second->id])->toBase()->update(['updated_at' => $stale]);

        // Act
        resolve(BulkCaseUpdate::class)->handle(
            Movie::query(),
            [
                $first->_imdb_id => ['_imdb_numVotes' => 2252453],
                $second->_imdb_id => ['_imdb_numVotes' => 987654],
            ],
            ['_imdb_numVotes'],
        );

        // The stale precondition must differ from the frozen now, or the assertion
        // below passes without the write having touched anything.
        // Assert
        expect($stale)->not->toBe(now()->toDateTimeString())
            ->and(Movie::query()->find($first->id)->updated_at->toDateTimeString())->toBe(now()->toDateTimeString())
            ->and(Movie::query()->find($second->id)->updated_at->toDateTimeString())->toBe(now()->toDateTimeString());
    });

    it('leaves updated_at stale on a row it does not write', function (): void {
        // The watermark only holds if the write is precise: bumping unmatched rows
        // would drag untouched titles into every reindex.
        // Arrange
        $this->freezeTime();
        $stale = '2020-01-01 00:00:00';
        $written = Movie::factory()->create(['_imdb_numVotes' => 1]);
        $unmatched = Movie::factory()->create(['_imdb_numVotes' => 2]);
        Movie::query()->whereKey([$written->id, $unmatched->id])->toBase()->update(['updated_at' => $stale]);

        // Act
        resolve(BulkCaseUpdate::class)->handle(
            Movie::query(),
            [$written->_imdb_id => ['_imdb_numVotes' => 2252453]],
            ['_imdb_numVotes'],
        );

        // Assert
        expect(Movie::query()->find($written->id)->updated_at->toDateTimeString())->toBe(now()->toDateTimeString())
            ->and(Movie::query()->find($unmatched->id)->updated_at->toDateTimeString())->toBe($stale);
    });
});
