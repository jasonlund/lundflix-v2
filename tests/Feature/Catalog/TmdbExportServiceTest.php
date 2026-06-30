<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\TmdbExport;
use App\Domains\Catalog\Exceptions\CorruptTmdbExportArchive;
use App\Domains\Catalog\Services\TmdbExportService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;

afterEach(fn () => Date::setTestNow());

it('requests the daily tv-series-ids export when asked', function (): void {
    // Arrange
    Date::setTestNow('2026-06-21');
    Http::fake(['*files.tmdb.org*' => Http::response(gzencode('{"id":1}'))]);
    $expectedFilename = 'tv_series_ids_'.now()->format('m_d_Y').'.json.gz';

    // Act
    $path = resolve(TmdbExportService::class)->download(TmdbExport::TvSeriesIds);

    // Assert
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), $expectedFilename));

    @unlink($path);
});

it('still requests the daily movie-ids export by default', function (): void {
    // Arrange
    Date::setTestNow('2026-06-21');
    Http::fake(['*files.tmdb.org*' => Http::response(gzencode('{"id":1}'))]);
    $expectedFilename = 'movie_ids_'.now()->format('m_d_Y').'.json.gz';

    // Act
    $path = resolve(TmdbExportService::class)->download(TmdbExport::MovieIds);

    // Assert
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), $expectedFilename));

    @unlink($path);
});

it('falls back to the prior day for tv-series-ids when today returns a 404', function (): void {
    // Arrange
    Date::setTestNow('2026-06-21');
    $todayFilename = 'tv_series_ids_'.now()->format('m_d_Y').'.json.gz';
    $yesterdayFilename = 'tv_series_ids_'.now()->subDay()->format('m_d_Y').'.json.gz';
    Http::fake([
        '*'.$todayFilename => Http::response('', 404),
        '*'.$yesterdayFilename => Http::response(gzencode('{"id":1}')),
    ]);

    // Act
    $path = resolve(TmdbExportService::class)->download(TmdbExport::TvSeriesIds);

    // Assert
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), $yesterdayFilename));
    expect($path)->not->toBe('');

    @unlink($path);
});

it('requests the daily movie-ids export for today', function (): void {
    Date::setTestNow('2026-06-21');
    Http::fake(['*files.tmdb.org*' => Http::response(gzencode('{"id":1}'))]);
    $expectedFilename = 'movie_ids_'.now()->format('m_d_Y').'.json.gz';

    $path = resolve(TmdbExportService::class)->download();

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), $expectedFilename));

    @unlink($path);
});

it('falls back to the prior day when today returns a 404', function (): void {
    Date::setTestNow('2026-06-21');
    $todayFilename = 'movie_ids_'.now()->format('m_d_Y').'.json.gz';
    $yesterdayFilename = 'movie_ids_'.now()->subDay()->format('m_d_Y').'.json.gz';
    Http::fake([
        '*'.$todayFilename => Http::response('', 404),
        '*'.$yesterdayFilename => Http::response(gzencode('{"id":1}')),
    ]);

    $path = resolve(TmdbExportService::class)->download();

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), $yesterdayFilename));
    expect($path)->not->toBe('');

    @unlink($path);
});

it('returns a temp path whose contents are the downloaded bytes', function (): void {
    $bytes = gzencode('{"id":603,"original_title":"The Matrix"}');
    Http::fake(['*files.tmdb.org*' => Http::response($bytes)]);

    $path = resolve(TmdbExportService::class)->download();

    expect(file_exists($path))->toBeTrue();
    expect(file_get_contents($path))->toBe($bytes);

    @unlink($path);
});

it('removes the temp file when the download fails', function (): void {
    $tempFiles = fn (): array => glob(sys_get_temp_dir().'/tmdb_*');
    Http::fake(['*files.tmdb.org*' => Http::response('', 500)]);
    $before = $tempFiles();

    try {
        resolve(TmdbExportService::class)->download();
    } catch (RequestException) {
        // the leak, not the throw, is under test
    }

    expect($tempFiles())->toBe($before);
});

it('leaves the temp file in place on success', function (): void {
    Http::fake(['*files.tmdb.org*' => Http::response(gzencode('{"id":1}'))]);

    $path = resolve(TmdbExportService::class)->download();

    expect(file_exists($path))->toBeTrue();

    @unlink($path);
});

/*
|--------------------------------------------------------------------------
| rows() — fixture: tests/Fixtures/Catalog/tmdb/movie_ids.json.gz
|--------------------------------------------------------------------------
| Gz JSONL export, 11 lines. Each line shape:
|   {"adult":bool,"id":int,"original_title":string,"popularity":float,"video":bool}
|
| 9 real adult:false rows (byte-exact from the live TMDB daily export, plus id
| 603 The Matrix appended for the ingestor slice):
|   ids 3924, 8773, 25449, 31975, 2, 3, 5, 6, 603  (id 31975 has "video":true).
|
| 2 SYNTHETIC injected lines that rows() must SKIP:
|   - id 9999990: "adult":true
|   - id 9999991: "adult":false plus an extra "softcore":true key.
| The real movie_ids export contains zero adult:true rows and has no "softcore"
| key at all, so these two lines are hand-injected — the skip behavior cannot be
| proven from byte-exact real data, which is the one sanctioned synthetic case.
|
| So rows() yields exactly 9 kept rows; ids 9999990 and 9999991 are dropped.
*/

it('yields one decoded object per kept JSONL line', function (): void {
    Http::fake(['*files.tmdb.org*' => Http::response(fixtureBytes('Catalog/tmdb/movie_ids.json.gz'))]);
    $service = resolve(TmdbExportService::class);
    $path = $service->download();

    $rows = $service->rows($path)->all();

    expect($rows)->toHaveCount(9);
    expect(collect($rows)->pluck('id')->all())->toContain(3924, 2);
    expect($rows[0])->toHaveKeys(['id', 'original_title', 'popularity', 'video']);

    @unlink($path);
});

it('skips adult and softcore rows', function (): void {
    Http::fake(['*files.tmdb.org*' => Http::response(fixtureBytes('Catalog/tmdb/movie_ids.json.gz'))]);
    $service = resolve(TmdbExportService::class);
    $path = $service->download();

    $ids = collect($service->rows($path)->all())->pluck('id')->all();

    expect($ids)->not->toContain(9999990);
    expect($ids)->not->toContain(9999991);
    expect($ids)->toContain(3924);

    @unlink($path);
});

it('ignores blank and trailing-newline lines', function (): void {
    $row1 = '{"adult":false,"id":11,"original_title":"Star Wars","popularity":50.0,"video":false}';
    $row2 = '{"adult":false,"id":12,"original_title":"Finding Nemo","popularity":40.0,"video":false}';
    $jsonl = $row1."\n"."\n".$row2."\n";
    Http::fake(['*files.tmdb.org*' => Http::response(gzencode($jsonl))]);
    $service = resolve(TmdbExportService::class);
    $path = $service->download();

    $rows = $service->rows($path)->all();

    expect($rows)->toHaveCount(2);
    expect(collect($rows)->pluck('id')->all())->toEqualCanonicalizing([11, 12]);

    @unlink($path);
});

it('returns a lazy collection', function (): void {
    $row1 = '{"adult":false,"id":11,"original_title":"Star Wars","popularity":50.0,"video":false}';
    $row2 = '{"adult":false,"id":12,"original_title":"Finding Nemo","popularity":40.0,"video":false}';
    $poison = 'not valid json {';
    $jsonl = $row1."\n".$row2."\n".$poison."\n";
    Http::fake(['*files.tmdb.org*' => Http::response(gzencode($jsonl))]);
    $service = resolve(TmdbExportService::class);
    $path = $service->download();

    $rows = $service->rows($path);

    expect($rows)->toBeInstanceOf(LazyCollection::class);

    @unlink($path);
});

it('parses lazily and stops reading before a poison line once enough is taken', function (): void {
    // The poison (non-JSON) line sits AFTER the two rows we take. If rows() parsed
    // eagerly it would choke on that line before take(2) could return; stopping
    // cleanly at 2 proves decode happens on demand, line by line.
    $row1 = '{"adult":false,"id":11,"original_title":"Star Wars","popularity":50.0,"video":false}';
    $row2 = '{"adult":false,"id":12,"original_title":"Finding Nemo","popularity":40.0,"video":false}';
    $poison = 'not valid json {';
    $jsonl = $row1."\n".$row2."\n".$poison."\n";
    Http::fake(['*files.tmdb.org*' => Http::response(gzencode($jsonl))]);
    $service = resolve(TmdbExportService::class);
    $path = $service->download();

    $rows = $service->rows($path)->take(2)->all();

    expect($rows)->toHaveCount(2);

    @unlink($path);
});

it('skips a malformed line mid-stream and yields the surrounding valid rows', function (): void {
    // A truncated/corrupt JSONL line decodes to null, which would throw a TypeError
    // against the array-typed isExcluded() and abort the whole stream. Fully consuming
    // past the bad line (no take() stopping short) proves one corrupt line is skipped
    // rather than killing the entire export.
    $row1 = '{"adult":false,"id":11,"original_title":"Star Wars","popularity":50.0,"video":false}';
    $poison = '{"adult":false,"id":12,"original_title":"Trunca';
    $row2 = '{"adult":false,"id":13,"original_title":"Finding Nemo","popularity":40.0,"video":false}';
    $jsonl = $row1."\n".$poison."\n".$row2."\n";
    Http::fake(['*files.tmdb.org*' => Http::response(gzencode($jsonl))]);
    $service = resolve(TmdbExportService::class);
    $path = $service->download();

    $rows = $service->rows($path)->all();

    expect(collect($rows)->pluck('id')->all())->toEqualCanonicalizing([11, 13]);

    @unlink($path);
});

it('throws a corrupt archive exception when rows receives a non-gzip body', function (): void {
    Http::fake(['*files.tmdb.org*' => Http::response('this is not gzip data at all')]);
    $service = resolve(TmdbExportService::class);
    $path = $service->download();

    expect(fn () => $service->rows($path)->all())->toThrow(CorruptTmdbExportArchive::class);

    @unlink($path);
});

it('counts only the kept (non-adult/non-softcore) data lines', function (): void {
    // The fixture has 11 JSONL lines; the synthetic adult (9999990) and softcore
    // (9999991) lines are dropped, leaving 9. count() must apply the SAME skip as
    // rows() so the progress total equals the number of rows actually yielded.
    Http::fake(['*files.tmdb.org*' => Http::response(fixtureBytes('Catalog/tmdb/movie_ids.json.gz'))]);
    $service = resolve(TmdbExportService::class);
    $path = $service->download();

    $count = $service->count($path);

    expect($count)->toBe(9);

    @unlink($path);
});

it('throws a corrupt archive exception when count receives a non-gzip body', function (): void {
    Http::fake(['*files.tmdb.org*' => Http::response('this is not gzip data at all')]);
    $service = resolve(TmdbExportService::class);
    $path = $service->download();

    expect(fn () => $service->count($path))->toThrow(CorruptTmdbExportArchive::class);

    @unlink($path);
});
