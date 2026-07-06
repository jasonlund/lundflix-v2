<?php

declare(strict_types=1);

use App\Domains\Catalog\Exceptions\CorruptImdbDatasetArchive;
use App\Domains\Catalog\Services\ImdbDatasetService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Sleep;

/*
|--------------------------------------------------------------------------
| Fixtures: tests/Fixtures/Catalog/imdb/{title.ratings,title.empty}.tsv.gz
|--------------------------------------------------------------------------
| Byte-exact real slices of the live IMDb datasets, in their native wire
| format (.tsv.gz). The compressed files are opaque in diffs, so the curated
| contents are documented here.
|
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
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);

    resolve(ImdbDatasetService::class)->download();

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'title.ratings.tsv.gz'));
});

it('returns a temp path whose contents are the downloaded bytes', function (): void {
    $bytes = fixtureBytes('Catalog/imdb/title.ratings.tsv.gz');
    Http::fake(['*datasets.imdbws.com*' => Http::response($bytes)]);

    $path = resolve(ImdbDatasetService::class)->download();

    expect(file_exists($path))->toBeTrue();
    expect(file_get_contents($path))->toBe($bytes);

    @unlink($path);
});

it('removes the temp file when the download fails', function (): void {
    $tempFiles = fn () => glob(sys_get_temp_dir().'/imdb_*');
    Http::fake(['*datasets.imdbws.com*' => Http::response('', 500)]);
    $before = $tempFiles();

    try {
        resolve(ImdbDatasetService::class)->download();
    } catch (RequestException) {
        // the leak, not the throw, is under test
    }

    expect($tempFiles())->toBe($before);
});

it('leaves the temp file in place on success', function (): void {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);

    $path = resolve(ImdbDatasetService::class)->download();

    expect(file_exists($path))->toBeTrue();

    @unlink($path);
});

it('counts the title.ratings fixture data rows', function (): void {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download();

    $count = $service->count($path);

    expect($count)->toBe(4);

    @unlink($path);
});

it('throws a corrupt archive exception when count receives a non-gzip file', function (): void {
    Http::fake(['*datasets.imdbws.com*' => Http::response('this is not gzip data at all')]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download();

    expect(fn () => $service->count($path))->toThrow(CorruptImdbDatasetArchive::class);

    @unlink($path);
});

it('throws a corrupt archive exception when the gzip has valid magic but no header line', function (): void {
    // Valid gzip magic, empty body → gzgets() returns false on the header read.
    // Without the false-check this surfaces an opaque ValueError from
    // array_combine; we want the domain CorruptImdbDatasetArchive instead.
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.empty.tsv.gz'))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download();

    expect(fn () => $service->rows($path)->all())->toThrow(CorruptImdbDatasetArchive::class);

    @unlink($path);
});

it('ignores blank and trailing-newline lines', function (): void {
    $header = "tconst\taverageRating\tnumVotes";
    $row1 = "tt0133093\t8.7\t2252453";
    $row2 = "tt0137523\t8.8\t2615814";
    $tsv = $header."\n".$row1."\n"."\n".$row2."\n";
    Http::fake(['*datasets.imdbws.com*' => Http::response(gzencode($tsv))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download();

    $rows = $service->rows($path)->all();

    expect($rows)->toHaveCount(2);
    expect(collect($rows)->pluck('tconst')->filter()->all())->toEqualCanonicalizing(['tt0133093', 'tt0137523']);

    @unlink($path);
});

it('returns a lazy collection', function (): void {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download();

    $rows = $service->rows($path);

    expect($rows)->toBeInstanceOf(LazyCollection::class);

    @unlink($path);
});

it('parses lazily and stops reading once the consumer has taken enough', function (): void {
    // A poison (malformed) row placed AFTER the rows we take proves parsing is
    // on-demand: if rows() pre-parsed eagerly the malformed row would blow up
    // before take(2) ever returns. Stopping cleanly at 2 means it never read it.
    $header = "tconst\taverageRating\tnumVotes";
    $row1 = "tt0133093\t8.7\t2252453";
    $row2 = "tt0137523\t8.8\t2615814";
    $malformed = "tt0000000\ttoo few columns";
    $tsv = $header."\n".$row1."\n".$row2."\n".$malformed."\n";
    Http::fake(['*datasets.imdbws.com*' => Http::response(gzencode($tsv))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download();

    $rows = $service->rows($path)->take(2)->all();

    expect($rows)->toHaveCount(2);

    @unlink($path);
});

it('reads rows on demand surfacing a malformed row only when fully consumed', function (): void {
    // Mirror of the take(2) test: here full consumption reaches the malformed
    // short row, so array_combine(header, shortRow) mismatches column counts and
    // raises a ValueError (PHP 8.4). That this only blows up on full consumption
    // — not on take(2) above — is exactly what makes the early-stop meaningful.
    $header = "tconst\taverageRating\tnumVotes";
    $row1 = "tt0133093\t8.7\t2252453";
    $malformed = "tt0000000\ttoo few columns";
    $tsv = $header."\n".$row1."\n".$malformed."\n";
    Http::fake(['*datasets.imdbws.com*' => Http::response(gzencode($tsv))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download();

    expect(fn () => $service->rows($path)->all())->toThrow(ValueError::class);

    @unlink($path);
});

it('does not delete the file when the rows are consumed', function (): void {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download();

    $service->rows($path)->all();

    expect(file_exists($path))->toBeTrue();

    @unlink($path);
});

it('throws a corrupt archive exception when rows receives a non-gzip file', function (): void {
    Http::fake(['*datasets.imdbws.com*' => Http::response('this is not gzip data at all')]);
    $service = resolve(ImdbDatasetService::class);
    $path = $service->download();

    expect(fn () => $service->rows($path)->all())->toThrow(CorruptImdbDatasetArchive::class);

    @unlink($path);
});

it('attempts the download only the native retry count on a persistent failure (no double-retry)', function (): void {
    Sleep::fake();
    Http::fake(['*datasets.imdbws.com*' => Http::response('', 500)]);

    try {
        resolve(ImdbDatasetService::class)->download();
    } catch (RequestException) {
        // retry COUNT is under test, not the throw
    }

    Http::assertSentCount(3);
});
