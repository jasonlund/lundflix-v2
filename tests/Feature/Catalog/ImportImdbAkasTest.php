<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\ImportImdbAkas;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use Illuminate\Support\Facades\DB;

/**
 * The batches below are hand-picked in-memory copies of real `title.akas` rows
 * from the committed byte-exact capture `tests/Fixtures/Catalog/imdb/title.akas.tsv.gz`
 * — tt0000001 Carmencita, tt0007189 Plain Jane, tt0133093 The Matrix, tt0137523
 * Fight Club, tt0816692 Interstellar — after ImdbDatasetService's casts (`\N` =>
 * null, `types`/`attributes` => lists split on the \x02 packing char). Every other
 * field stays the raw string IMDb ships, `ordering` and `isOriginalTitle` included.
 * tt0007189 ordering 5 is the capture's only multi-value row. The full titles run
 * 66-68 rows each; only the leading few are carried here, since this slice
 * exercises the action against an in-memory batch rather than a streamed dataset.
 * The capture holds no series rows, so the show case reuses a real movie's rows —
 * the action matches on `_imdb_id` alone and never reads the title type.
 */
it('writes the aka array onto a matched movie', function (): void {
    // Arrange
    $movie = Movie::factory()->create();

    // Act
    resolve(ImportImdbAkas::class)->handle([
        $movie->_imdb_id => [
            ['titleId' => $movie->_imdb_id, 'ordering' => '1', 'title' => 'The Matrix', 'region' => null, 'language' => null, 'types' => ['original'], 'attributes' => null, 'isOriginalTitle' => '1'],
            ['titleId' => $movie->_imdb_id, 'ordering' => '2', 'title' => 'Matrix', 'region' => 'AR', 'language' => null, 'types' => ['imdbDisplay'], 'attributes' => null, 'isOriginalTitle' => '0'],
            ['titleId' => $movie->_imdb_id, 'ordering' => '3', 'title' => 'Matrix', 'region' => 'AT', 'language' => null, 'types' => ['imdbDisplay'], 'attributes' => null, 'isOriginalTitle' => '0'],
        ],
    ]);

    // Assert
    expect(Movie::query()->find($movie->id)->_imdb_akas)->toBe([
        ['ordering' => '1', 'title' => 'The Matrix', 'region' => null, 'language' => null, 'types' => ['original'], 'attributes' => null, 'isOriginalTitle' => '1'],
        ['ordering' => '2', 'title' => 'Matrix', 'region' => 'AR', 'language' => null, 'types' => ['imdbDisplay'], 'attributes' => null, 'isOriginalTitle' => '0'],
        ['ordering' => '3', 'title' => 'Matrix', 'region' => 'AT', 'language' => null, 'types' => ['imdbDisplay'], 'attributes' => null, 'isOriginalTitle' => '0'],
    ]);
});

it('writes the aka array onto a matched show', function (): void {
    // Arrange
    $show = Show::factory()->create();

    // Act
    resolve(ImportImdbAkas::class)->handle([
        $show->_imdb_id => [
            ['titleId' => $show->_imdb_id, 'ordering' => '1', 'title' => 'Interstellar', 'region' => null, 'language' => null, 'types' => ['original'], 'attributes' => null, 'isOriginalTitle' => '1'],
            ['titleId' => $show->_imdb_id, 'ordering' => '2', 'title' => 'Interstellar', 'region' => 'AU', 'language' => null, 'types' => ['imdbDisplay'], 'attributes' => null, 'isOriginalTitle' => '0'],
            ['titleId' => $show->_imdb_id, 'ordering' => '30', 'title' => 'Interestelar', 'region' => 'ES', 'language' => null, 'types' => null, 'attributes' => ['alternative spelling'], 'isOriginalTitle' => '0'],
        ],
    ]);

    // Assert
    expect(Show::query()->find($show->id)->_imdb_akas)->toBe([
        ['ordering' => '1', 'title' => 'Interstellar', 'region' => null, 'language' => null, 'types' => ['original'], 'attributes' => null, 'isOriginalTitle' => '1'],
        ['ordering' => '2', 'title' => 'Interstellar', 'region' => 'AU', 'language' => null, 'types' => ['imdbDisplay'], 'attributes' => null, 'isOriginalTitle' => '0'],
        ['ordering' => '30', 'title' => 'Interestelar', 'region' => 'ES', 'language' => null, 'types' => null, 'attributes' => ['alternative spelling'], 'isOriginalTitle' => '0'],
    ]);
});

it('omits the redundant titleId from every stored aka row', function (): void {
    // Arrange
    $movie = Movie::factory()->create();

    // Act
    resolve(ImportImdbAkas::class)->handle([
        $movie->_imdb_id => [
            ['titleId' => $movie->_imdb_id, 'ordering' => '1', 'title' => 'Fight Club', 'region' => null, 'language' => null, 'types' => ['original'], 'attributes' => null, 'isOriginalTitle' => '1'],
            ['titleId' => $movie->_imdb_id, 'ordering' => '13', 'title' => 'Fight Club', 'region' => 'IE', 'language' => 'en', 'types' => ['imdbDisplay'], 'attributes' => null, 'isOriginalTitle' => '0'],
        ],
    ]);

    // The stored column is read raw as well as through the cast: the id is the
    // batch key, so repeating it on all 66-68 rows of a title is dead weight.
    // Assert
    $stored = json_decode((string) DB::table('movies')->where('id', $movie->id)->value('_imdb_akas'), true);
    expect($stored)->toBeArray()->toHaveCount(2)
        ->and($stored[0])->not->toHaveKey('titleId')
        ->and($stored[1])->not->toHaveKey('titleId')
        ->and(Movie::query()->find($movie->id)->_imdb_akas[0])->not->toHaveKey('titleId');
});

it('preserves the multi-value fields as lists and the numeric fields as raw strings', function (): void {
    // Arrange
    $movie = Movie::factory()->create();

    // Act
    resolve(ImportImdbAkas::class)->handle([
        $movie->_imdb_id => [
            ['titleId' => $movie->_imdb_id, 'ordering' => '1', 'title' => 'Plain Jane', 'region' => null, 'language' => null, 'types' => ['original'], 'attributes' => null, 'isOriginalTitle' => '1'],
            ['titleId' => $movie->_imdb_id, 'ordering' => '5', 'title' => 'The Hick', 'region' => 'US', 'language' => null, 'types' => null, 'attributes' => ['reissue title', 'recut version'], 'isOriginalTitle' => '0'],
        ],
    ]);

    // A bulk CASE update writes raw SQL past the model's casts, so the raw column
    // is decoded too — a comma-joined string can't pass as a list, and a json
    // number can't pass as IMDb's raw "1"/"0" string.
    // Assert
    $stored = json_decode((string) DB::table('movies')->where('id', $movie->id)->value('_imdb_akas'), true);
    expect($stored)->toBeArray()
        ->and($stored[0]['types'])->toBe(['original'])
        ->and($stored[1]['attributes'])->toBe(['reissue title', 'recut version'])
        ->and($stored[1]['types'])->toBeNull()
        ->and($stored[1]['ordering'])->toBe('5')
        ->and($stored[1]['isOriginalTitle'])->toBe('0')
        ->and(Movie::query()->find($movie->id)->_imdb_akas[1])->toBe([
            'ordering' => '5', 'title' => 'The Hick', 'region' => 'US', 'language' => null, 'types' => null, 'attributes' => ['reissue title', 'recut version'], 'isOriginalTitle' => '0',
        ]);
});

/**
 * Synthetic input, per the fixture convention: the committed capture is valid
 * UTF-8 throughout, so a malformed byte sequence can only be hand-constructed.
 * `"\xE9"` is a bare Latin-1 e-acute — a valid byte on its own in Latin-1, but
 * an orphaned continuation byte as UTF-8, which is what a mis-encoded upstream
 * row ships. Unlike the other ingests, akas arrive as raw bytes split out of a
 * gzip TSV stream, never round-tripped through a json decode, so nothing
 * upstream has already vouched for their encoding.
 */
it('substitutes invalid utf-8 bytes instead of writing an empty column', function (): void {
    // Arrange
    $movie = Movie::factory()->create();

    // Act
    resolve(ImportImdbAkas::class)->handle([
        $movie->_imdb_id => [
            ['titleId' => $movie->_imdb_id, 'ordering' => '1', 'title' => "Cr\xE9puscule", 'region' => 'FR', 'language' => null, 'types' => ['imdbDisplay'], 'attributes' => null, 'isOriginalTitle' => '0'],
        ],
    ]);

    // An unguarded json_encode returns false on invalid UTF-8, and `(string) false`
    // is '' — which a native MySQL json column rejects (error 3140), failing the
    // one CASE update that carries the whole batch. The test DB is sqlite and
    // accepts '', so the observable guard is the stored value's well-formedness.
    // Assert
    $raw = (string) DB::table('movies')->where('id', $movie->id)->value('_imdb_akas');
    expect($raw)->not->toBe('')
        ->and(json_decode($raw, true))->toBeArray()->toHaveCount(1)
        ->and(Movie::query()->find($movie->id)->_imdb_akas[0]['title'])->toBe("Cr\u{FFFD}puscule");
});

it('inserts nothing for a titleId with no matching title', function (): void {
    // Arrange
    $movie = Movie::factory()->create();

    // Act
    $result = resolve(ImportImdbAkas::class)->handle([
        $movie->_imdb_id => [
            ['titleId' => $movie->_imdb_id, 'ordering' => '1', 'title' => 'The Matrix', 'region' => null, 'language' => null, 'types' => ['original'], 'attributes' => null, 'isOriginalTitle' => '1'],
        ],
        'tt0000001' => [
            ['titleId' => 'tt0000001', 'ordering' => '1', 'title' => 'Carmencita', 'region' => null, 'language' => null, 'types' => ['original'], 'attributes' => null, 'isOriginalTitle' => '1'],
            ['titleId' => 'tt0000001', 'ordering' => '2', 'title' => 'Carmencita', 'region' => 'DE', 'language' => null, 'types' => null, 'attributes' => ['literal title'], 'isOriginalTitle' => '0'],
        ],
    ]);

    // Assert
    expect(Movie::query()->count())->toBe(1)
        ->and(Show::query()->count())->toBe(0)
        ->and(Movie::query()->where('_imdb_id', 'tt0000001')->exists())->toBeFalse()
        ->and($result)->toBe(['movies' => 1, 'shows' => 0])
        ->and(Movie::query()->find($movie->id)->_imdb_akas)->toBeArray()->toHaveCount(1);
});
