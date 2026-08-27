<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Exceptions\CorruptImdbDatasetArchive;
use App\Domains\Catalog\Services\ImdbDatasetService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Fixtures: tests/Fixtures/Catalog/imdb/{title.basics,title.akas,
| title.ratings,title.empty}.tsv.gz
|--------------------------------------------------------------------------
| Byte-exact real slices of the live IMDb datasets, in their native wire
| format (.tsv.gz). The compressed files are opaque in diffs, so the curated
| contents are documented here.
|
| title.basics — 6 rows: tt0000001 (Carmencita, short), tt0064057
|   (isAdult=1), tt0133093 (The Matrix, 1999, 136 min, "Action,Sci-Fi"),
|   tt0137523 (Fight Club), tt0816692 (Interstellar) and tt0903747 (Breaking
|   Bad, tvSeries, startYear 2008 — the only row carrying an endYear, 2013).
|
| title.akas — 214 rows across 5 titles: tt0000001 (8), tt0007189 (5),
|   tt0133093 (67), tt0137523 (68), tt0816692 (66). Most rows carry a
|   single-value or \N types; tt0007189 ordering 5 is the multi-value case,
|   its attributes joining "reissue title" and "recut version" with the \x02
|   control character IMDb packs repeated values with inside one column.
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

describe('download() temp file handling', function (): void {
    it('requests the correct dataset url', function (): void {
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);

        resolve(ImdbDatasetService::class)->download(ImdbDataset::TitleRatings);

        Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), 'title.ratings.tsv.gz'));
    });

    it('returns a temp path whose contents are the downloaded bytes', function (): void {
        $bytes = fixtureBytes('Catalog/imdb/title.ratings.tsv.gz');
        Http::fake(['*datasets.imdbws.com*' => Http::response($bytes)]);

        $path = resolve(ImdbDatasetService::class)->download(ImdbDataset::TitleRatings);

        expect(file_exists($path))->toBeTrue();
        expect(file_get_contents($path))->toBe($bytes);

        @unlink($path);
    });

    it('removes the temp file when the download fails', function (): void {
        // The sink option the stub handler receives IS the tempnam() path download()
        // just created, so capturing it pins the one file under test — a glob over
        // the shared temp dir also matches files other processes leave behind.
        // Arrange
        Sleep::fake();
        $sinkPath = null;
        Http::fake(['*datasets.imdbws.com*' => function (Request $request, array $options) use (&$sinkPath) {
            $sinkPath = $options['sink'];

            return Http::response('', 500);
        }]);

        // Act
        try {
            resolve(ImdbDatasetService::class)->download(ImdbDataset::TitleRatings);
        } catch (RequestException) {
            // the leak, not the throw, is under test
        }

        // Assert
        expect($sinkPath)->toBeString();
        expect(file_exists($sinkPath))->toBeFalse();
    });

    it('leaves the temp file in place on success', function (): void {
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);

        $path = resolve(ImdbDatasetService::class)->download(ImdbDataset::TitleRatings);

        expect(file_exists($path))->toBeTrue();

        @unlink($path);
    });
});

describe('count() row counting', function (): void {
    it('counts the title.ratings fixture data rows', function (): void {
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);
        $service = resolve(ImdbDatasetService::class);
        $path = $service->download(ImdbDataset::TitleRatings);

        $count = $service->count($path);

        expect($count)->toBe(4);

        @unlink($path);
    });

    it('throws a corrupt archive exception when count receives a non-gzip file', function (): void {
        Http::fake(['*datasets.imdbws.com*' => Http::response('this is not gzip data at all')]);
        $service = resolve(ImdbDatasetService::class);
        $path = $service->download(ImdbDataset::TitleRatings);

        expect(fn () => $service->count($path))->toThrow(CorruptImdbDatasetArchive::class);

        @unlink($path);
    });
});

describe('rows() lazy parsing', function (): void {
    it('throws a corrupt archive exception when the gzip has valid magic but no header line', function (): void {
        // Valid gzip magic, empty body → gzgets() returns false on the header read.
        // Without the false-check this surfaces an opaque ValueError from
        // array_combine; we want the domain CorruptImdbDatasetArchive instead.
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.empty.tsv.gz'))]);
        $service = resolve(ImdbDatasetService::class);
        $path = $service->download(ImdbDataset::TitleRatings);

        expect(fn () => $service->rows($path, ImdbDataset::TitleRatings)->all())->toThrow(CorruptImdbDatasetArchive::class);

        @unlink($path);
    });

    it('ignores blank and trailing-newline lines', function (): void {
        $header = "tconst\taverageRating\tnumVotes";
        $row1 = "tt0133093\t8.7\t2252453";
        $row2 = "tt0137523\t8.8\t2615814";
        $tsv = $header."\n".$row1."\n"."\n".$row2."\n";
        Http::fake(['*datasets.imdbws.com*' => Http::response(gzencode($tsv))]);
        $service = resolve(ImdbDatasetService::class);
        $path = $service->download(ImdbDataset::TitleRatings);

        $rows = $service->rows($path, ImdbDataset::TitleRatings)->all();

        expect($rows)->toHaveCount(2);
        expect(collect($rows)->pluck('tconst')->filter()->all())->toEqualCanonicalizing(['tt0133093', 'tt0137523']);

        @unlink($path);
    });

    it('returns a lazy collection', function (): void {
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);
        $service = resolve(ImdbDatasetService::class);
        $path = $service->download(ImdbDataset::TitleRatings);

        $rows = $service->rows($path, ImdbDataset::TitleRatings);

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
        $path = $service->download(ImdbDataset::TitleRatings);

        $rows = $service->rows($path, ImdbDataset::TitleRatings)->take(2)->all();

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
        $path = $service->download(ImdbDataset::TitleRatings);

        expect(fn () => $service->rows($path, ImdbDataset::TitleRatings)->all())->toThrow(ValueError::class);

        @unlink($path);
    });

    it('does not delete the file when the rows are consumed', function (): void {
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);
        $service = resolve(ImdbDatasetService::class);
        $path = $service->download(ImdbDataset::TitleRatings);

        $service->rows($path, ImdbDataset::TitleRatings)->all();

        expect(file_exists($path))->toBeTrue();

        @unlink($path);
    });

    it('throws a corrupt archive exception when rows receives a non-gzip file', function (): void {
        Http::fake(['*datasets.imdbws.com*' => Http::response('this is not gzip data at all')]);
        $service = resolve(ImdbDatasetService::class);
        $path = $service->download(ImdbDataset::TitleRatings);

        expect(fn () => $service->rows($path, ImdbDataset::TitleRatings)->all())->toThrow(CorruptImdbDatasetArchive::class);

        @unlink($path);
    });
});

describe('download() retry', function (): void {
    it('attempts the download only the native retry count on a persistent failure (no double-retry)', function (): void {
        Sleep::fake();
        Http::fake(['*datasets.imdbws.com*' => Http::response('', 500)]);

        try {
            resolve(ImdbDatasetService::class)->download(ImdbDataset::TitleRatings);
        } catch (RequestException) {
            // retry COUNT is under test, not the throw
        }

        Http::assertSentCount(3);
    });
});

describe('download() dataset urls', function (): void {
    it('requests the title.basics url for the basics dataset', function (): void {
        // Arrange
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

        // Act
        resolve(ImdbDatasetService::class)->download(ImdbDataset::TitleBasics);

        // Assert
        Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), 'title.basics.tsv.gz'));
    });

    it('requests the title.akas url for the akas dataset', function (): void {
        // Arrange
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.akas.tsv.gz'))]);

        // Act
        resolve(ImdbDatasetService::class)->download(ImdbDataset::TitleAkas);

        // Assert
        Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), 'title.akas.tsv.gz'));
    });
});

describe('rows() column casts', function (): void {
    it('casts the title.basics year runtime and genre columns', function (): void {
        // Arrange
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
        $service = resolve(ImdbDatasetService::class);
        $path = $service->download(ImdbDataset::TitleBasics);

        // Act
        $rows = $service->rows($path, ImdbDataset::TitleBasics)->all();

        // Assert
        $breakingBad = collect($rows)->firstWhere('tconst', 'tt0903747');
        expect($breakingBad['startYear'])->toBe(2008);
        expect($breakingBad['endYear'])->toBe(2013);
        expect($breakingBad['runtimeMinutes'])->toBe(48);
        expect($breakingBad['genres'])->toBe(['Crime', 'Drama', 'Thriller']);

        @unlink($path);
    });

    it('splits multi-valued title.akas columns on the control character', function (): void {
        // IMDb packs several values into one akas column with a \x02 separator
        // instead of the comma it uses elsewhere, so these columns need a cast of
        // their own — and a single-value column still has to come back as a list.
        // Arrange
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.akas.tsv.gz'))]);
        $service = resolve(ImdbDatasetService::class);
        $path = $service->download(ImdbDataset::TitleAkas);

        // Act
        $rows = $service->rows($path, ImdbDataset::TitleAkas)->all();

        // Assert
        $akas = collect($rows)->where('titleId', 'tt0007189');
        expect($akas->first(fn (array $row): bool => $row['ordering'] === '5')['attributes'])
            ->toBe(['reissue title', 'recut version']);
        expect($akas->first(fn (array $row): bool => $row['ordering'] === '1')['types'])
            ->toBe(['original']);

        @unlink($path);
    });
});

describe('count() basics dataset', function (): void {
    it('counts the title.basics fixture data rows', function (): void {
        // Arrange
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
        $service = resolve(ImdbDatasetService::class);
        $path = $service->download(ImdbDataset::TitleBasics);

        // Act
        $count = $service->count($path);

        // Assert
        expect($count)->toBe(6);

        @unlink($path);
    });
});

describe('lastModified() dataset probe', function (): void {
    it('returns the raw last-modified header of the dataset', function (): void {
        // The header value is returned verbatim — never parsed into a date — because
        // callers compare it to a stored value with raw string equality.
        // Arrange
        Http::fake(['*datasets.imdbws.com*' => Http::response('', 200, ['Last-Modified' => 'Tue, 12 Aug 2026 01:02:03 GMT'])]);

        // Act
        $lastModified = resolve(ImdbDatasetService::class)->lastModified(ImdbDataset::TitleRatings);

        // Assert
        expect($lastModified)->toBe('Tue, 12 Aug 2026 01:02:03 GMT');
    });

    it('probes the dataset url with a head request', function (): void {
        // Arrange
        Http::fake(['*datasets.imdbws.com*' => Http::response('', 200, ['Last-Modified' => 'Tue, 12 Aug 2026 01:02:03 GMT'])]);

        // Act
        resolve(ImdbDatasetService::class)->lastModified(ImdbDataset::TitleRatings);

        // Assert
        Http::assertSent(fn ($request): bool => $request->method() === 'HEAD'
            && Str::endsWith((string) $request->url(), 'title.ratings.tsv.gz'));
    });

    it('returns null when the probe carries no last-modified header', function (): void {
        // Arrange
        Http::fake(['*datasets.imdbws.com*' => Http::response('', 200)]);

        // Act
        $lastModified = resolve(ImdbDatasetService::class)->lastModified(ImdbDataset::TitleRatings);

        // Assert
        expect($lastModified)->toBeNull();
    });

    it('returns null when the probe fails with a non-2xx response', function (): void {
        // Arrange
        Http::fake(['*datasets.imdbws.com*' => Http::response('', 500, ['Last-Modified' => 'Tue, 12 Aug 2026 01:02:03 GMT'])]);

        // Act
        $lastModified = resolve(ImdbDatasetService::class)->lastModified(ImdbDataset::TitleRatings);

        // Assert
        expect($lastModified)->toBeNull();
    });
});

describe('matchedRows() probe-admitted streaming', function (): void {
    it('yields only the probe-admitted rows cast identically to rows', function (): void {
        // Arrange
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
        $service = resolve(ImdbDatasetService::class);
        $path = $service->download(ImdbDataset::TitleBasics);
        $admitted = ['tt0133093' => true, 'tt0137523' => true];
        $existing = fn (array $ids): array => array_intersect_key($admitted, array_flip($ids));

        // Act
        $rows = $service->matchedRows($path, ImdbDataset::TitleBasics, $existing, 10)->all();

        // Assert
        $expected = $service->rows($path, ImdbDataset::TitleBasics)
            ->filter(fn (array $row): bool => array_key_exists($row['tconst'], $admitted))
            ->values()
            ->all();
        expect($rows)->toHaveCount(2);
        expect($rows)->toBe($expected);

        @unlink($path);
    });

    it('probes in batches of at most the batch size covering every scanned id', function (): void {
        // Arrange
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
        $service = resolve(ImdbDatasetService::class);
        $path = $service->download(ImdbDataset::TitleBasics);
        $probes = [];
        $existing = function (array $ids) use (&$probes): array {
            $probes[] = $ids;

            return [];
        };

        // Act
        $service->matchedRows($path, ImdbDataset::TitleBasics, $existing, 2)->all();

        // Assert
        expect($probes)->toHaveCount(3);
        expect(collect($probes)->map(fn (array $ids): int => count($ids))->max())->toBeLessThanOrEqual(2);
        expect(collect($probes)->flatten()->all())->toEqualCanonicalizing(
            ['tt0000001', 'tt0064057', 'tt0133093', 'tt0137523', 'tt0816692', 'tt0903747']
        );

        @unlink($path);
    });

    it('never splits one ids contiguous run across two probes', function (): void {
        // The akas fixture repeats each titleId over dozens of consecutive rows, so a
        // batch that closed on a raw row count instead of an id change would land the
        // same id in two probe calls — the run has to close the batch, not the count.
        // Arrange
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.akas.tsv.gz'))]);
        $service = resolve(ImdbDatasetService::class);
        $path = $service->download(ImdbDataset::TitleAkas);
        $probes = [];
        $existing = function (array $ids) use (&$probes): array {
            $probes[] = $ids;

            return [];
        };

        // Act
        $service->matchedRows($path, ImdbDataset::TitleAkas, $existing, 2)->all();

        // Assert
        $occurrences = collect($probes)
            ->flatMap(fn (array $ids): array => array_unique($ids))
            ->countBy()
            ->all();
        expect($occurrences)->toBe([
            'tt0000001' => 1,
            'tt0007189' => 1,
            'tt0133093' => 1,
            'tt0137523' => 1,
            'tt0816692' => 1,
        ]);

        @unlink($path);
    });

    it('discards an unmatched malformed line without parsing it', function (): void {
        // The malformed line is short a column, so mapRow()'s array_combine would raise
        // a ValueError on it (see the full-consumption test above). Surviving a full
        // consumption is therefore the proof that an unmatched line only ever gave up
        // its tconst — it was never split into columns at all.
        // Arrange
        $header = "tconst\taverageRating\tnumVotes";
        $row = "tt0133093\t8.7\t2252453";
        $malformed = "tt0000000\ttoo few columns";
        Http::fake(['*datasets.imdbws.com*' => Http::response(gzencode($header."\n".$row."\n".$malformed."\n"))]);
        $service = resolve(ImdbDatasetService::class);
        $path = $service->download(ImdbDataset::TitleRatings);
        $existing = fn (array $ids): array => array_intersect_key(['tt0133093' => true], array_flip($ids));

        // Act
        $rows = $service->matchedRows($path, ImdbDataset::TitleRatings, $existing, 10)->all();

        // Assert
        expect($rows)->toHaveCount(1);
        expect($rows[0]['tconst'])->toBe('tt0133093');

        @unlink($path);
    });

    it('reports the cumulative scanned data rows once per probe batch', function (): void {
        // Arrange
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
        $service = resolve(ImdbDatasetService::class);
        $path = $service->download(ImdbDataset::TitleBasics);
        $reported = [];
        $scanned = function (int $count) use (&$reported): void {
            $reported[] = $count;
        };

        // Act
        $service->matchedRows($path, ImdbDataset::TitleBasics, fn (array $ids): array => [], 2, $scanned)->all();

        // Assert
        expect($reported)->toBe([2, 4, 6]);

        @unlink($path);
    });
});
