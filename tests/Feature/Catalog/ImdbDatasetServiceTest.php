<?php

use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Exceptions\CorruptImdbDatasetArchive;
use App\Domains\Catalog\Services\ImdbDatasetService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;

/*
|--------------------------------------------------------------------------
| Fixtures: tests/Fixtures/Catalog/imdb/{title.basics,title.ratings}.tsv.gz
|--------------------------------------------------------------------------
| Byte-exact real slices of the live IMDb datasets, in their native wire
| format (.tsv.gz). The compressed files are opaque in diffs, so the curated
| contents are documented here.
|
| title.basics — 18 data rows: 13 kept, 5 excluded by ImdbDataset filtering.
|   First kept row: tt0133093 (The Matrix).
|   Excluded:
|     tt0064057  isAdult=1                       (adult)
|     tt0031458  tvEpisode                        (disallowed type)
|     tt0029270  tvShort                          (disallowed type)
|     tt0084376  videoGame                        (disallowed type)
|     tt15258334 tvPilot                          (disallowed type)
|   Edge cases among kept rows:
|     tt0000502  \N genres                        (genres -> null)
|     tt0000615  \N endYear AND \N runtimeMinutes (both -> null, startYear 1907)
|     tt0060178  \N runtimeMinutes
|     tt0047766  real endYear (1955)
|     tt0063362  \N startYear (-> null), runtimeMinutes 82
|     tt0038276  fully populated: endYear 1955, runtimeMinutes 15, single-genre ['Talk-Show']
|
| title.ratings — 4 rows (unfiltered). First row: tt0133093 (8.7 / 2252453).
|
| Tests that need malformed/synthetic input (blank lines, non-gzip bodies,
| HTTP errors) build their own bytes inline — such input cannot exist in real
| IMDb data.
*/

it('requests the correct dataset url', function () {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    app(ImdbDatasetService::class)->rows(ImdbDataset::TitleBasics)->all();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'title.basics.tsv.gz'));
});

it('returns a lazy collection', function () {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    $rows = app(ImdbDatasetService::class)->rows(ImdbDataset::TitleBasics);

    expect($rows)->toBeInstanceOf(LazyCollection::class);
});

it('skips the header and yields one entry per data row', function () {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    $rows = app(ImdbDatasetService::class)->rows(ImdbDataset::TitleBasics)->all();

    expect($rows)->toHaveCount(13);
    expect(collect($rows)->pluck('tconst')->all())->not->toContain('tconst');
});

it('shapes a kept row with header keys and typed casts', function () {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    $row = app(ImdbDatasetService::class)->rows(ImdbDataset::TitleBasics)->first();

    expect($row)->toHaveKeys(['tconst', 'primaryTitle', 'isAdult', 'startYear', 'endYear', 'runtimeMinutes', 'genres']);
    expect($row['tconst'])->toBe('tt0133093');
    expect($row['primaryTitle'])->toBe('The Matrix');
    expect($row['isAdult'])->toBe(false);
    expect($row['startYear'])->toBe(1999);
    expect($row['runtimeMinutes'])->toBe(136);
    expect($row['genres'])->toBe(['Action', 'Sci-Fi']);
});

it('keeps every allowed title type', function () {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    $tconsts = collect(app(ImdbDatasetService::class)->rows(ImdbDataset::TitleBasics)->all())->pluck('tconst')->all();

    // one representative per allowed type: movie, tvMovie, short, tvSpecial, video, tvSeries, tvMiniSeries
    expect($tconsts)->toContain('tt0133093', 'tt0030298', 'tt0000001', 'tt0060178', 'tt0066435', 'tt0030138', 'tt0047766');
});

it('excludes disallowed title types', function () {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    $tconsts = collect(app(ImdbDatasetService::class)->rows(ImdbDataset::TitleBasics)->all())->pluck('tconst')->all();

    expect($tconsts)->not->toContain('tt0031458', 'tt0029270', 'tt0084376', 'tt15258334');
});

it('excludes adult titles', function () {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    $tconsts = collect(app(ImdbDatasetService::class)->rows(ImdbDataset::TitleBasics)->all())->pluck('tconst')->all();

    expect($tconsts)->toContain('tt0133093');
    expect($tconsts)->not->toContain('tt0064057');
});

it('leaves \N numeric fields as null instead of casting them', function () {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    $row = collect(app(ImdbDatasetService::class)->rows(ImdbDataset::TitleBasics)->all())->firstWhere('tconst', 'tt0000615');

    expect($row['endYear'])->toBe(null);
    expect($row['runtimeMinutes'])->toBe(null);
    expect($row['startYear'])->toBe(1907);
});

it('leaves a \N genres column as null instead of exploding it', function () {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    $row = collect(app(ImdbDatasetService::class)->rows(ImdbDataset::TitleBasics)->all())->firstWhere('tconst', 'tt0000502');

    expect($row['genres'])->toBe(null);
});

it('leaves a \N startYear as null', function () {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    $row = collect(app(ImdbDatasetService::class)->rows(ImdbDataset::TitleBasics)->all())->firstWhere('tconst', 'tt0063362');

    expect($row['startYear'])->toBe(null);
    expect($row['runtimeMinutes'])->toBe(82);
});

it('casts a fully populated row including a single-genre list', function () {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    $row = collect(app(ImdbDatasetService::class)->rows(ImdbDataset::TitleBasics)->all())->firstWhere('tconst', 'tt0038276');

    expect($row['startYear'])->toBe(1946);
    expect($row['endYear'])->toBe(1955);
    expect($row['runtimeMinutes'])->toBe(15);
    expect($row['genres'])->toBe(['Talk-Show']);
});

it('shapes a ratings row with header keys and typed casts', function () {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);

    $row = app(ImdbDatasetService::class)->rows(ImdbDataset::TitleRatings)->first();

    expect($row)->toHaveKeys(['tconst', 'averageRating', 'numVotes']);
    expect($row['tconst'])->toBe('tt0133093');
    expect($row['averageRating'])->toBe(8.7);
    expect($row['numVotes'])->toBe(2252453);
});

it('keeps every ratings row (unfiltered) and requests the ratings url', function () {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);

    $rows = app(ImdbDatasetService::class)->rows(ImdbDataset::TitleRatings)->all();

    expect($rows)->toHaveCount(4);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'title.ratings.tsv.gz'));
});

it('throws a request exception when the download is not successful', function () {
    Http::fake(['*datasets.imdbws.com*' => Http::response('', 500)]);

    $act = fn () => app(ImdbDatasetService::class)->rows(ImdbDataset::TitleBasics)->all();

    expect($act)->toThrow(RequestException::class);
});

it('ignores blank and trailing-newline lines', function () {
    $header = "tconst\ttitleType\tprimaryTitle\toriginalTitle\tisAdult\tstartYear\tendYear\truntimeMinutes\tgenres";
    $row1 = "tt0133093\tmovie\tThe Matrix\tThe Matrix\t0\t1999\t\\N\t136\tAction,Sci-Fi";
    $row2 = "tt0137523\tmovie\tFight Club\tFight Club\t0\t1999\t\\N\t139\tDrama";
    $tsv = $header."\n".$row1."\n"."\n".$row2."\n";
    Http::fake(['*datasets.imdbws.com*' => Http::response(gzencode($tsv))]);

    $rows = app(ImdbDatasetService::class)->rows(ImdbDataset::TitleBasics)->all();

    expect($rows)->toHaveCount(2);
    expect(collect($rows)->pluck('tconst')->filter()->all())->toEqualCanonicalizing(['tt0133093', 'tt0137523']);
});

it('parses lazily and stops reading once the consumer has taken enough', function () {
    // Synthetic body: a malformed row (too few columns) cannot exist in real IMDb
    // data. Placed AFTER the rows the consumer takes, it proves on-demand parsing —
    // if rows() were eager it would parse the poison row and array_combine() would
    // throw before take() ever ran.
    $header = "tconst\ttitleType\tprimaryTitle\toriginalTitle\tisAdult\tstartYear\tendYear\truntimeMinutes\tgenres";
    $row1 = "tt0133093\tmovie\tThe Matrix\tThe Matrix\t0\t1999\t\\N\t136\tAction,Sci-Fi";
    $row2 = "tt0137523\tmovie\tFight Club\tFight Club\t0\t1999\t\\N\t139\tDrama";
    $malformed = "tt0000000\tmovie\ttoo few columns";
    $tsv = $header."\n".$row1."\n".$row2."\n".$malformed."\n";
    Http::fake(['*datasets.imdbws.com*' => Http::response(gzencode($tsv))]);

    $rows = app(ImdbDatasetService::class)->rows(ImdbDataset::TitleBasics)->take(2)->all();

    expect($rows)->toHaveCount(2);
});

it('reads rows on demand, surfacing a malformed row only when fully consumed', function () {
    // The mirror of the test above: when the consumer reaches the poison row,
    // array_combine(header, shortRow) throws ValueError (PHP 8.4). Proving the
    // throw happens on full consumption is what makes the early-termination
    // (no-throw) above meaningful.
    $header = "tconst\ttitleType\tprimaryTitle\toriginalTitle\tisAdult\tstartYear\tendYear\truntimeMinutes\tgenres";
    $row1 = "tt0133093\tmovie\tThe Matrix\tThe Matrix\t0\t1999\t\\N\t136\tAction,Sci-Fi";
    $malformed = "tt0000000\tmovie\ttoo few columns";
    $tsv = $header."\n".$row1."\n".$malformed."\n";
    Http::fake(['*datasets.imdbws.com*' => Http::response(gzencode($tsv))]);

    $act = fn () => app(ImdbDatasetService::class)->rows(ImdbDataset::TitleBasics)->all();

    expect($act)->toThrow(ValueError::class);
});

it('throws an imdb dataset exception when the body is not valid gzip', function () {
    Http::fake(['*datasets.imdbws.com*' => Http::response('this is not gzip data at all')]);

    $act = fn () => app(ImdbDatasetService::class)->rows(ImdbDataset::TitleBasics)->all();

    expect($act)->toThrow(CorruptImdbDatasetArchive::class);
});
