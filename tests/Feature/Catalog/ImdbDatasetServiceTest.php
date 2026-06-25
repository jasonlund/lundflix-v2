<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Exceptions\CorruptImdbDatasetArchive;
use App\Domains\Catalog\Services\ImdbDatasetService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Sleep;

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
| title.ratings — 4 rows (unfiltered). First row: tt0133093 (8.7 / 2252453).
|
| title.empty — synthetic: valid gzip magic, empty body (gzencode('')). Stands
|   in for a truncated/empty download where the header line is missing; cannot
|   exist as real IMDb data, so it lives as a committed synthetic fixture.
|
| Tests that need malformed/synthetic input (blank lines, non-gzip bodies,
| HTTP errors) build their own bytes inline — such input cannot exist in real
| IMDb data.
*/

it('requests the correct dataset url', function (): void {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    resolve(ImdbDatasetService::class)->download(ImdbDataset::TitleBasics);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'title.basics.tsv.gz'));
});

it('returns a temp path whose contents are the downloaded bytes', function (): void {
    $bytes = fixtureBytes('Catalog/imdb/title.basics.tsv.gz');
    Http::fake(['*datasets.imdbws.com*' => Http::response($bytes)]);

    $path = resolve(ImdbDatasetService::class)->download(ImdbDataset::TitleBasics);

    expect(file_exists($path))->toBeTrue();
    expect(file_get_contents($path))->toBe($bytes);

    @unlink($path);
});

it('removes the temp file when the download fails', function (): void {
    $tempFiles = fn () => glob(sys_get_temp_dir().'/imdb_*');
    Http::fake(['*datasets.imdbws.com*' => Http::response('', 500)]);
    $before = $tempFiles();

    try {
        resolve(ImdbDatasetService::class)->download(ImdbDataset::TitleBasics);
    } catch (RequestException) {
        // the leak, not the throw, is under test
    }

    expect($tempFiles())->toBe($before);
});

it('leaves the temp file in place on success', function (): void {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    $path = resolve(ImdbDatasetService::class)->download(ImdbDataset::TitleBasics);

    expect(file_exists($path))->toBeTrue();

    @unlink($path);
});

it('counts only the post-includes() kept rows for title.basics', function (): void {
    // The fixture has 18 non-blank data rows; ImdbDataset::TitleBasics filtering
    // keeps 13. count() must apply the SAME includes() filter as rows() so the
    // progress total equals the number of advances (honest 100%).
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download(ImdbDataset::TitleBasics);

    $count = $service->count($path, ImdbDataset::TitleBasics);

    expect($count)->toBe(13);

    @unlink($path);
});

it('counts the title.ratings fixture data rows (includes() keeps all)', function (): void {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download(ImdbDataset::TitleRatings);

    $count = $service->count($path, ImdbDataset::TitleRatings);

    expect($count)->toBe(4);

    @unlink($path);
});

it('throws a corrupt archive exception when count receives a non-gzip file', function (): void {
    Http::fake(['*datasets.imdbws.com*' => Http::response('this is not gzip data at all')]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download(ImdbDataset::TitleBasics);

    expect(fn () => $service->count($path, ImdbDataset::TitleBasics))->toThrow(CorruptImdbDatasetArchive::class);

    @unlink($path);
});

it('throws a corrupt archive exception when the gzip has valid magic but no header line', function (): void {
    // Valid gzip magic, empty body → gzgets() returns false on the header read.
    // Without the false-check this surfaces an opaque ValueError from
    // array_combine; we want the domain CorruptImdbDatasetArchive instead.
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.empty.tsv.gz'))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download(ImdbDataset::TitleBasics);

    expect(fn () => $service->rows($path, ImdbDataset::TitleBasics)->all())->toThrow(CorruptImdbDatasetArchive::class);

    @unlink($path);
});

it('skips the header and yields one entry per kept data row', function (): void {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download(ImdbDataset::TitleBasics);

    $rows = $service->rows($path, ImdbDataset::TitleBasics)->all();

    expect($rows)->toHaveCount(13);
    expect(collect($rows)->pluck('tconst')->all())->not->toContain('tconst');

    @unlink($path);
});

it('shapes a kept row with header keys and typed casts', function (): void {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download(ImdbDataset::TitleBasics);

    $row = $service->rows($path, ImdbDataset::TitleBasics)->first();

    expect($row)->toHaveKeys(['tconst', 'primaryTitle', 'startYear', 'endYear', 'runtimeMinutes', 'genres']);
    expect($row)->not->toHaveKey('isAdult');
    expect($row['tconst'])->toBe('tt0133093');
    expect($row['primaryTitle'])->toBe('The Matrix');
    expect($row['startYear'])->toBe(1999);
    expect($row['runtimeMinutes'])->toBe(136);
    expect($row['genres'])->toBe(['Action', 'Sci-Fi']);

    @unlink($path);
});

it('keeps every allowed title type', function (): void {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download(ImdbDataset::TitleBasics);

    $tconsts = collect($service->rows($path, ImdbDataset::TitleBasics)->all())->pluck('tconst')->all();

    expect($tconsts)->toContain('tt0133093', 'tt0030298', 'tt0000001', 'tt0060178', 'tt0066435', 'tt0030138', 'tt0047766');

    @unlink($path);
});

it('excludes disallowed title types', function (): void {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download(ImdbDataset::TitleBasics);

    $tconsts = collect($service->rows($path, ImdbDataset::TitleBasics)->all())->pluck('tconst')->all();

    expect($tconsts)->not->toContain('tt0031458', 'tt0029270', 'tt0084376', 'tt15258334');

    @unlink($path);
});

it('excludes adult titles', function (): void {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download(ImdbDataset::TitleBasics);

    $tconsts = collect($service->rows($path, ImdbDataset::TitleBasics)->all())->pluck('tconst')->all();

    expect($tconsts)->toContain('tt0133093');
    expect($tconsts)->not->toContain('tt0064057');

    @unlink($path);
});

it('leaves \N numeric fields as null instead of casting them', function (): void {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download(ImdbDataset::TitleBasics);

    $row = collect($service->rows($path, ImdbDataset::TitleBasics)->all())->firstWhere('tconst', 'tt0000615');

    expect($row['endYear'])->toBe(null);
    expect($row['runtimeMinutes'])->toBe(null);
    expect($row['startYear'])->toBe(1907);

    @unlink($path);
});

it('leaves a \N genres column as null instead of exploding it', function (): void {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download(ImdbDataset::TitleBasics);

    $row = collect($service->rows($path, ImdbDataset::TitleBasics)->all())->firstWhere('tconst', 'tt0000502');

    expect($row['genres'])->toBe(null);

    @unlink($path);
});

it('leaves a \N startYear as null', function (): void {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download(ImdbDataset::TitleBasics);

    $row = collect($service->rows($path, ImdbDataset::TitleBasics)->all())->firstWhere('tconst', 'tt0063362');

    expect($row['startYear'])->toBe(null);
    expect($row['runtimeMinutes'])->toBe(82);

    @unlink($path);
});

it('casts a fully populated row including a single-genre list', function (): void {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download(ImdbDataset::TitleBasics);

    $row = collect($service->rows($path, ImdbDataset::TitleBasics)->all())->firstWhere('tconst', 'tt0038276');

    expect($row['startYear'])->toBe(1946);
    expect($row['endYear'])->toBe(1955);
    expect($row['runtimeMinutes'])->toBe(15);
    expect($row['genres'])->toBe(['Talk-Show']);

    @unlink($path);
});

it('ignores blank and trailing-newline lines', function (): void {
    $header = "tconst\ttitleType\tprimaryTitle\toriginalTitle\tisAdult\tstartYear\tendYear\truntimeMinutes\tgenres";
    $row1 = "tt0133093\tmovie\tThe Matrix\tThe Matrix\t0\t1999\t\\N\t136\tAction,Sci-Fi";
    $row2 = "tt0137523\tmovie\tFight Club\tFight Club\t0\t1999\t\\N\t139\tDrama";
    $tsv = $header."\n".$row1."\n"."\n".$row2."\n";
    Http::fake(['*datasets.imdbws.com*' => Http::response(gzencode($tsv))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download(ImdbDataset::TitleBasics);

    $rows = $service->rows($path, ImdbDataset::TitleBasics)->all();

    expect($rows)->toHaveCount(2);
    expect(collect($rows)->pluck('tconst')->filter()->all())->toEqualCanonicalizing(['tt0133093', 'tt0137523']);

    @unlink($path);
});

it('returns a lazy collection', function (): void {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download(ImdbDataset::TitleBasics);

    $rows = $service->rows($path, ImdbDataset::TitleBasics);

    expect($rows)->toBeInstanceOf(LazyCollection::class);

    @unlink($path);
});

it('parses lazily and stops reading once the consumer has taken enough', function (): void {
    // A poison (malformed) row placed AFTER the rows we take proves parsing is
    // on-demand: if rows() pre-parsed eagerly the malformed row would blow up
    // before take(2) ever returns. Stopping cleanly at 2 means it never read it.
    $header = "tconst\ttitleType\tprimaryTitle\toriginalTitle\tisAdult\tstartYear\tendYear\truntimeMinutes\tgenres";
    $row1 = "tt0133093\tmovie\tThe Matrix\tThe Matrix\t0\t1999\t\\N\t136\tAction,Sci-Fi";
    $row2 = "tt0137523\tmovie\tFight Club\tFight Club\t0\t1999\t\\N\t139\tDrama";
    $malformed = "tt0000000\tmovie\ttoo few columns";
    $tsv = $header."\n".$row1."\n".$row2."\n".$malformed."\n";
    Http::fake(['*datasets.imdbws.com*' => Http::response(gzencode($tsv))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download(ImdbDataset::TitleBasics);

    $rows = $service->rows($path, ImdbDataset::TitleBasics)->take(2)->all();

    expect($rows)->toHaveCount(2);

    @unlink($path);
});

it('reads rows on demand surfacing a malformed row only when fully consumed', function (): void {
    // Mirror of the take(2) test: here full consumption reaches the malformed
    // short row, so array_combine(header, shortRow) mismatches column counts and
    // raises a ValueError (PHP 8.4). That this only blows up on full consumption
    // — not on take(2) above — is exactly what makes the early-stop meaningful.
    $header = "tconst\ttitleType\tprimaryTitle\toriginalTitle\tisAdult\tstartYear\tendYear\truntimeMinutes\tgenres";
    $row1 = "tt0133093\tmovie\tThe Matrix\tThe Matrix\t0\t1999\t\\N\t136\tAction,Sci-Fi";
    $malformed = "tt0000000\tmovie\ttoo few columns";
    $tsv = $header."\n".$row1."\n".$malformed."\n";
    Http::fake(['*datasets.imdbws.com*' => Http::response(gzencode($tsv))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download(ImdbDataset::TitleBasics);

    expect(fn () => $service->rows($path, ImdbDataset::TitleBasics)->all())->toThrow(ValueError::class);

    @unlink($path);
});

it('does not delete the file when the rows are consumed', function (): void {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download(ImdbDataset::TitleBasics);

    $service->rows($path, ImdbDataset::TitleBasics)->all();

    expect(file_exists($path))->toBeTrue();

    @unlink($path);
});

it('throws a corrupt archive exception when rows receives a non-gzip file', function (): void {
    Http::fake(['*datasets.imdbws.com*' => Http::response('this is not gzip data at all')]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download(ImdbDataset::TitleBasics);

    expect(fn () => $service->rows($path, ImdbDataset::TitleBasics)->all())->toThrow(CorruptImdbDatasetArchive::class);

    @unlink($path);
});

it('attempts the download only the native retry count on a persistent failure (no double-retry)', function (): void {
    Sleep::fake();
    Http::fake(['*datasets.imdbws.com*' => Http::response('', 500)]);

    try {
        resolve(ImdbDatasetService::class)->download(ImdbDataset::TitleBasics);
    } catch (RequestException) {
        // retry COUNT is under test, not the throw
    }

    Http::assertSentCount(3);
});
