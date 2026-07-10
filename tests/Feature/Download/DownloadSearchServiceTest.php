<?php

declare(strict_types=1);

use App\Domains\Download\Data\DownloadResult;
use App\Domains\Download\Enums\Category;
use App\Domains\Download\Enums\Codec;
use App\Domains\Download\Enums\Quality;
use App\Domains\Download\Enums\ReleaseTag;
use App\Domains\Download\Enums\Source;
use App\Domains\Download\Exceptions\DownloadRequestFailed;
use App\Domains\Download\Exceptions\InvalidDownloadCredentials;
use App\Domains\Download\Exceptions\RateLimitExceeded;
use App\Domains\Download\Services\DownloadSearchService;
use App\Domains\Download\Settings\DownloadSettings;
use App\Domains\Download\Support\RequestThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| DownloadSearchService — request/auth/error contract slice
|--------------------------------------------------------------------------
| Mirrors the Catalog Tvdb/Tmdb HTTP-service tests. This slice covers ONLY
| the request the crawler sends and how failures map to typed exceptions —
| NOT result parsing (later slice). The uid/pass cookie is built from
| DownloadSettings and sent as the literal `Cookie: uid=<uid>; pass=<pass>`.
|
| Fixture (byte-exact real capture):
|   tests/Fixtures/Download/downloads/login.html — the download source sign-in
|   page returned when the cookie is unauthenticated; its login-form marker
|   is the string `do-login.php`.
*/

it('sends the uid/pass cookie from DownloadSettings', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response('ok', 200)]);

    // Act
    // an 'ok' body isn't parseable yet, so the act may throw; the assertion
    // is about what was SENT, so let the request fire and swallow the throw
    rescue(fn () => resolve(DownloadSearchService::class)->search('anything'));

    // Assert
    Http::assertSent(fn ($request) => $request->hasHeader('Cookie', 'uid=cookie-uid; pass=cookie-pass'));
});

it('falls back to the env/config credential when settings are blank', function (): void {
    // Arrange
    // no operator value stored → settings resolve to empty; env DOWNLOADS_UID/PASS is test-uid/test-pass
    Http::fake(['*' => Http::response('ok', 200)]);

    // Act
    rescue(fn () => resolve(DownloadSearchService::class)->search('anything'));

    // Assert
    Http::assertSent(fn ($request) => $request->hasHeader('Cookie', 'uid=test-uid; pass=test-pass'));
});

it('prefers a stored setting over the env credential', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'op-uid';
    $settings->pass = 'op-pass';
    $settings->save();
    Http::fake(['*' => Http::response('ok', 200)]);

    // Act
    rescue(fn () => resolve(DownloadSearchService::class)->search('anything'));

    // Assert
    Http::assertSent(fn ($request) => $request->hasHeader('Cookie', 'uid=op-uid; pass=op-pass'));
});

it('throws InvalidDownloadCredentials when the response is the login page', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/login.html'), 200)]);

    // Act & Assert
    expect(fn () => resolve(DownloadSearchService::class)->search('x'))->toThrow(InvalidDownloadCredentials::class);
});

it('throws DownloadRequestFailed on a non-2xx response', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response('', 500)]);

    // Act & Assert
    expect(fn () => resolve(DownloadSearchService::class)->search('x'))->toThrow(DownloadRequestFailed::class);
});

/*
|--------------------------------------------------------------------------
| DownloadSearchService — parseResults slice
|--------------------------------------------------------------------------
| This slice crawls the `table#torrents` rows of a real download source search
| page into DownloadResult objects. Ground truth is verified against the
| byte-exact fixtures below (secret-scrubbed real captures):
|
|   tests/Fixtures/Download/downloads/search_movie.html — 50 "The Matrix"
|     result rows, none carrying the NORAR token (every row isRar=true).
|     Row 0 is downloadId 7537888 "The Matrix Reloaded 2003 1080p MA WEB-DL
|     H 264 DDP5 1-HHWEB" (1080p, H 264 → X264, 103 availability, "7.91 GB").
|     Row downloadId 6763844 carries an X265 token (→ Hevc); row 6705710 is
|     720p x264.
|   tests/Fixtures/Download/downloads/search_norar.html — carries every real
|     "no rar" form the tracker emits, each flipping isRar=false: bracketed
|     [NORAR] (downloadId 6024830 "Monk S02 1080p BluRay x264-BORDURE [NORAR]"),
|     mixed-case bracketed [NoRAR] (downloadId 4140603 "The Flight Attendant S01
|     1080p WEB H264-Mixed[NoRAR]"), and a dashed group suffix -NoRAR
|     (downloadId 3223054 "Need.For.Speed.Shift.2.Unleashed.RF.XBOX360-NoRAR").
|     A row with no such tag stays isRar=true (asserted via search_movie above).
*/

it('returns a Collection of 50 DownloadResult', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_movie.html'), 200)]);

    // Act
    $results = resolve(DownloadSearchService::class)->search('matrix');

    // Assert
    expect($results)->toBeInstanceOf(Collection::class)
        ->toHaveCount(50)
        ->each->toBeInstanceOf(DownloadResult::class);
});

it('maps every field of a result row', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_movie.html'), 200)]);

    // Act
    $result = resolve(DownloadSearchService::class)->search('matrix')->firstWhere('downloadId', 7537888);

    // Assert
    expect($result->downloadId)->toBe(7537888)
        ->and($result->name)->toBe('The Matrix Reloaded 2003 1080p MA WEB-DL H 264 DDP5 1-HHWEB')
        ->and($result->quality)->toBe(Quality::P1080)
        ->and($result->codec)->toBe(Codec::X264)
        ->and($result->availability)->toBe(103)
        ->and($result->sizeBytes)->toBe((int) round(7.91 * 1024 ** 3))
        ->and($result->isRar)->toBeTrue();
});

it('maps an X265 release to Codec::Hevc', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_movie.html'), 200)]);

    // Act
    $result = resolve(DownloadSearchService::class)->search('matrix')->firstWhere('downloadId', 6763844);

    // Assert
    expect($result->codec)->toBe(Codec::Hevc);
});

it('maps a WEB-DL release to Source::WebDl', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_movie.html'), 200)]);

    // Act
    $result = resolve(DownloadSearchService::class)->search('matrix')->firstWhere('downloadId', 7537888);

    // Assert
    expect($result->source)->toBe(Source::WebDl);
});

it('maps a WEBRip release to Source::WebRip', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_movie.html'), 200)]);

    // Act
    // downloadId 7270124 "The Matrix 1999 1080p WEBRip DDP7 1 x264-ZoroSenpai"
    $result = resolve(DownloadSearchService::class)->search('matrix')->firstWhere('downloadId', 7270124);

    // Assert
    expect($result->source)->toBe(Source::WebRip);
});

it('maps a BluRay release to Source::BluRay', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_movie.html'), 200)]);

    // Act
    // downloadId 7288096 "The Matrix 1999 4K Remaster 1080p BluRay DTS x264-Geek"
    $result = resolve(DownloadSearchService::class)->search('matrix')->firstWhere('downloadId', 7288096);

    // Assert
    expect($result->source)->toBe(Source::BluRay);
});

it('maps a 720p release to Quality::P720', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_movie.html'), 200)]);

    // Act
    $result = resolve(DownloadSearchService::class)->search('matrix')->firstWhere('downloadId', 6705710);

    // Assert
    expect($result->quality)->toBe(Quality::P720);
});

it('maps a PROPER release to ReleaseTag::Proper', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_movie.html'), 200)]);

    // Act
    // downloadId 6982976 "The Matrix Revolutions 2003 PROPER 1080p UHD BluRay DD 7 1 x264-LoRD"
    $result = resolve(DownloadSearchService::class)->search('matrix')->firstWhere('downloadId', 6982976);

    // Assert
    expect($result->releaseTag)->toBe(ReleaseTag::Proper);
});

// REPACK has no real row in search_movie.html, so parse-side Repack is unit-covered
// only (ReleaseTagTest); fabricating a REPACK fixture row is disallowed.
it('maps a release with neither PROPER nor REPACK to ReleaseTag::None', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_movie.html'), 200)]);

    // Act
    // downloadId 7537888 "The Matrix Reloaded 2003 1080p MA WEB-DL H 264 DDP5 1-HHWEB"
    $result = resolve(DownloadSearchService::class)->search('matrix')->firstWhere('downloadId', 7537888);

    // Assert
    expect($result->releaseTag)->toBe(ReleaseTag::None);
});

it('flags a bracketed [NORAR] release as not rar\'d', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_norar.html'), 200)]);

    // Act
    $result = resolve(DownloadSearchService::class)->search('norar')->firstWhere('downloadId', 6024830);

    // Assert
    expect($result->isRar)->toBeFalse();
});

it('flags a mixed-case [NoRAR] release as not rar\'d', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_norar.html'), 200)]);

    // Act
    $result = resolve(DownloadSearchService::class)->search('norar')->firstWhere('downloadId', 4140603);

    // Assert
    expect($result->isRar)->toBeFalse();
});

it('flags a dashed -NoRAR group-suffix release as not rar\'d', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_norar.html'), 200)]);

    // Act
    $result = resolve(DownloadSearchService::class)->search('norar')->firstWhere('downloadId', 3223054);

    // Assert
    expect($result->isRar)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| DownloadSearchService — search() sorted results slice
|--------------------------------------------------------------------------
| search() returns EVERY parsed `table#torrents` row (nothing filtered — a
| null-quality row is KEPT) ordered by a 6-tier chain, each tier deciding ONLY
| when all higher tiers tie:
|   quality → source → codec → releaseTag → isRar → availability.
| Quality ranks 720p > 1080p > unrecognized LAST (the ladder deliberately ranks
| 720p above 1080p); a null-quality row sinks to the end but is never dropped.
|
| The quality/availability tiers use search_norar.html (search_movie's 50 result
| rows are all 1080p/720p — its 2160p and other rows live in the sidebar
| `table#topTables`, never the results). The source/codec/releaseTag tiers use
| search_movie.html, whose result rows are entirely rar (isRar ties across the
| whole page). Each of those three anchor PAIRS ties on every tier ABOVE the one
| it isolates AND is chosen so the LOWEST tie-break, availability, currently
| orders the pair the WRONG way — so under the pre-chain sort
| (quality → isRar → availability) the asserted order is inverted, and ONLY the
| new tier can produce it. Availability values are from the byte-exact fixture.
|
|   quality (search_norar.html): 720p 3794673, 1080p 4114060, null 4979363 — a
|     correct sort reorders them 720p < 1080p < null, inverting source order.
|   source (search_movie.html): 6690919 BluRay avail 106 ("...Remastered BluRay
|     1080p ... x264-hallowed") before 6788230 WebDl avail 189 ("The Matrix
|     Reloaded 2003 1080p PTV WEB-DL ... H 264-PiRaTeS") — both 1080p/X264/None,
|     so quality/codec/releaseTag tie; availability alone would rank the WebDl row
|     first (189 > 106), so only source (BluRay > WebDl) explains BluRay first.
|   codec (search_movie.html): 6666741 Hevc avail 134 ("...1080p UHD BluRay ...
|     x265-HiDt") before 7165734 X264 avail 146 ("...Remastered 1080p BluRay
|     x264-OFT") — both 1080p/BluRay/None, so quality/source tie; availability
|     alone would rank the X264 row first (146 > 134), so only codec (Hevc > X264)
|     explains Hevc first.
|   releaseTag (search_movie.html): 6982976 Proper avail 290 ("...PROPER 1080p
|     UHD BluRay ... x264-LoRD") before 7288096 None avail 463 ("The Matrix 1999
|     4K Remaster 1080p BluRay ... x264-Geek") — both 1080p/BluRay/X264, so
|     quality/source/codec tie; availability alone would rank the None row first
|     (463 > 290), so only releaseTag (Proper > None) explains Proper first.
|   availability (search_norar.html): two 1080p rows 4664952 avail 11312 and
|     4708249 avail 145 — the higher-availability row sorts first.
|
|   isRar (search_rar_mixed.html = search_movie + one injected non-rar row): the
|     injected 4676917 is retagged "...1080p WEB-DL h264-DiRT[NORAR]" so it parses
|     1080p/WebDl/X264/None/NON-rar (avail 43), tying the real rar row 7537888
|     "...1080p MA WEB-DL H 264..." (avail 103) on quality/source/codec/releaseTag.
|     Availability DISAGREES with isRar here (103 > 43 would rank the rar row first),
|     so only the isRar tier (non-rar > rar) can put 4676917 ahead — that
|     disagreement is what isolates the tier from availability below it.
*/

it('keeps the null-quality row in the result set', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_norar.html'), 200)]);

    // Act
    $results = resolve(DownloadSearchService::class)->search('norar');

    // Assert
    expect($results->firstWhere('downloadId', 4979363))->not->toBeNull();
});

it('orders by quality with unrecognized quality last', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_norar.html'), 200)]);

    // Act
    $results = resolve(DownloadSearchService::class)->search('norar');

    // Assert
    $positionOf = fn (int $id): int|false => $results->search(fn (DownloadResult $r): bool => $r->downloadId === $id);
    expect($positionOf(3794673))->toBeLessThan($positionOf(4114060))
        ->and($positionOf(4114060))->toBeLessThan($positionOf(4979363));
});

it('orders by availability descending at equal quality', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_norar.html'), 200)]);

    // Act
    $results = resolve(DownloadSearchService::class)->search('norar');

    // Assert
    $positionOf = fn (int $id): int|false => $results->search(fn (DownloadResult $r): bool => $r->downloadId === $id);
    expect($positionOf(4664952))->toBeLessThan($positionOf(4708249));
});

// source tier (2): 6690919 BluRay and 6788230 WebDl both parse 1080p/X264/None
// (all search_movie rows are rar, so isRar ties too), leaving quality as the only
// higher tier — and it ties. The BluRay row has LOWER availability (106 < 189), so
// the pre-chain availability tie-break ranks the WebDl row first; only source
// (BluRay > WebDl) can put the BluRay row ahead.
it('orders by source at equal quality', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_movie.html'), 200)]);

    // Act
    $results = resolve(DownloadSearchService::class)->search('matrix');

    // Assert
    $positionOf = fn (int $id): int|false => $results->search(fn (DownloadResult $r): bool => $r->downloadId === $id);
    expect($positionOf(6690919))->toBeLessThan($positionOf(6788230));
});

// codec tier (3): 6666741 Hevc and 7165734 X264 both parse 1080p/BluRay/None (all
// rar), so quality and source tie above. The Hevc row has LOWER availability
// (134 < 146), so the pre-chain availability tie-break ranks the X264 row first;
// only codec (Hevc > X264) can put the Hevc row ahead.
it('orders by codec at equal quality and source', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_movie.html'), 200)]);

    // Act
    $results = resolve(DownloadSearchService::class)->search('matrix');

    // Assert
    $positionOf = fn (int $id): int|false => $results->search(fn (DownloadResult $r): bool => $r->downloadId === $id);
    expect($positionOf(6666741))->toBeLessThan($positionOf(7165734));
});

// releaseTag tier (4): 6982976 Proper and 7288096 None both parse 1080p/BluRay/X264
// (all rar), so quality/source/codec all tie above. The Proper row has LOWER
// availability (290 < 463), so the pre-chain availability tie-break ranks the None
// row first; only releaseTag (Proper > None) can put the Proper row ahead.
it('orders by releaseTag at equal quality, source, and codec', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_movie.html'), 200)]);

    // Act
    $results = resolve(DownloadSearchService::class)->search('matrix');

    // Assert
    $positionOf = fn (int $id): int|false => $results->search(fn (DownloadResult $r): bool => $r->downloadId === $id);
    expect($positionOf(6982976))->toBeLessThan($positionOf(7288096));
});

// isRar tier (5): retagged 4676917 (non-rar, avail 43) and real 7537888 (rar,
// avail 103) both parse 1080p/WebDl/X264/None, so quality/source/codec/releaseTag
// all tie above. The non-rar row has LOWER availability (43 < 103), so the
// availability tier below would rank the rar row first; only isRar (non-rar > rar)
// can put 4676917 ahead — proving this assertion isolates isRar, not availability.
it('orders a non-rar release before a rar release at equal quality, source, codec, and tag', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_rar_mixed.html'), 200)]);

    // Act
    $results = resolve(DownloadSearchService::class)->search('matrix');

    // Assert
    $positionOf = fn (int $id): int|false => $results->search(fn (DownloadResult $r): bool => $r->downloadId === $id);
    expect($positionOf(4676917))->toBeLessThan($positionOf(7537888));
});

/*
|--------------------------------------------------------------------------
| DownloadSearchService — parseResults resilience slice
|--------------------------------------------------------------------------
| A single markup-drifted row (an ad/pinned/short row that carries a `/t/`
| name anchor but lacks the size/availability cells) must be SKIPPED, not
| abort the whole parse. Ground truth:
|
|   tests/Fixtures/Download/downloads/search_malformed_row.html — search_movie
|     with one extra 2-cell "Featured Ad Row" (downloadId 9999999) injected
|     after row 0; the 50 real rows are otherwise untouched.
|   tests/Fixtures/Download/downloads/search_availability.html — search_movie
|     with row 0's availability cell rewritten "103" → "1,024" and row 6763844's
|     rewritten to "-".
*/

it('skips a short/ad row and still returns the valid results', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_malformed_row.html'), 200)]);

    // Act
    $results = resolve(DownloadSearchService::class)->search('matrix');

    // Assert
    expect($results)->toHaveCount(50)
        ->and($results->firstWhere('downloadId', 9999999))->toBeNull()
        ->and($results->firstWhere('downloadId', 7537888))->not->toBeNull();
});

it('parses a comma-grouped availability as its full integer', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_availability.html'), 200)]);

    // Act
    $result = resolve(DownloadSearchService::class)->search('matrix')->firstWhere('downloadId', 7537888);

    // Assert
    expect($result->availability)->toBe(1024);
});

it('falls back to 0 for a non-numeric availability cell', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_availability.html'), 200)]);

    // Act
    $result = resolve(DownloadSearchService::class)->search('matrix')->firstWhere('downloadId', 6763844);

    // Assert
    expect($result->availability)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| DownloadSearchService — missing-table observability slice
|--------------------------------------------------------------------------
| The `table#torrents` node is the parse anchor. When it is ABSENT (the
| tracker's markup drifted / the scraper broke — maintenance/CAPTCHA/429 are
| caught upstream), parseResults still returns an empty Collection, so it is
| indistinguishable from a genuine zero-result page. To keep the failure
| observable, a missing table logs a warning (carrying the query) BEFORE
| returning empty; a genuine zero-result page (table present, no rows) stays
| SILENT. Ground truth:
|
|   tests/Fixtures/Download/downloads/search_missing_table.html — search_movie
|     with only the results table's `id="torrents"` renamed to `id="results"`,
|     so the `table#torrents` selector misses; the 50 rows are otherwise intact.
*/

it('warns and returns empty when the results table is absent', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['query'] === 'matrix');
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_missing_table.html'), 200)]);

    // Act
    $results = resolve(DownloadSearchService::class)->search('matrix');

    // Assert
    expect($results)->toBeInstanceOf(Collection::class)->toBeEmpty();
});

it('returns empty silently for a genuine zero-result page', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Log::shouldReceive('warning')->never();
    $emptyTable = '<html><body><table id="torrents" class="t1"><thead><tr><th>x</th></tr></thead></table></body></html>';
    Http::fake(['*' => Http::response($emptyTable, 200)]);

    // Act
    $results = resolve(DownloadSearchService::class)->search('nothing here');

    // Assert
    expect($results)->toBeInstanceOf(Collection::class)->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| DownloadSearchService — fetchImdbId slice
|--------------------------------------------------------------------------
| Crawls a download DETAIL page for its IMDb link, returning the tt-id or
| null. Ground truth is verified against the byte-exact fixtures below
| (secret-scrubbed real captures):
|
|   tests/Fixtures/Download/downloads/detail.html — detail page for
|     downloadId 7537888, carrying the IMDb link
|     https://www.imdb.com/title/tt0234215/.
|   tests/Fixtures/Download/downloads/detail_no_imdb.html — real ebook
|     detail page (downloadId 5567474) with NO IMDb link.
|   tests/Fixtures/Download/downloads/detail_bad_imdb_href.html — detail.html
|     with the IMDb anchor present but its href rewritten to
|     imdb.com/title/unknown/ (no tt-id), so the anchor matches but the tt-id
|     regex misses.
*/

it('returns the IMDb id from the detail page', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/detail.html'), 200)]);

    // Act
    $id = resolve(DownloadSearchService::class)->fetchImdbId(7537888);

    // Assert
    expect($id)->toBe('tt0234215');
});

it('returns null when the detail page has no IMDb link', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/detail_no_imdb.html'), 200)]);

    // Act
    $id = resolve(DownloadSearchService::class)->fetchImdbId(5567474);

    // Assert
    expect($id)->toBeNull();
});

it('returns null when the IMDb href carries no tt-id', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/detail_bad_imdb_href.html'), 200)]);

    // Act
    $id = resolve(DownloadSearchService::class)->fetchImdbId(7537888);

    // Assert
    expect($id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| DownloadSearchService — search(term, categories) request wiring slice
|--------------------------------------------------------------------------
| One search() entry point sends `q=<term>` plus each RESOLVED category id as an
| empty-valued marker on the `/t` query (`&72=`; 72 is Movies). An empty
| categories arg falls back to Category::defaults() (every non-adult case), so a
| default search carries 72= but never the adult 88= (Xxx). An adult category
| passed explicitly is always stripped. These assert only what is SENT — the
| parse path (returns-50) is covered by the parseResults slice above.
|
| Reuses the byte-exact fixture:
|   tests/Fixtures/Download/downloads/search_movie.html — 50 result rows.
*/

it('empty categories → sends default category ids and strips adult', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_movie.html'), 200)]);

    // Act
    resolve(DownloadSearchService::class)->search('matrix');

    // Assert
    Http::assertSent(function ($request): bool {
        $url = $request->url();

        return str_contains($url, 'q=matrix') && str_contains($url, '72=') && ! str_contains($url, '88=');
    });
});

it('scopes to an explicit category and no unrelated parent', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_movie.html'), 200)]);

    // Act
    resolve(DownloadSearchService::class)->search('matrix', [Category::Movies]);

    // Assert
    Http::assertSent(function ($request): bool {
        $url = $request->url();

        return str_contains($url, '72=') && ! str_contains($url, '73=');
    });
});

it('strips an adult category passed alongside a normal one', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_movie.html'), 200)]);

    // Act
    resolve(DownloadSearchService::class)->search('matrix', [Category::Movies, Category::Xxx]);

    // Assert
    Http::assertSent(function ($request): bool {
        $url = $request->url();

        return str_contains($url, '72=') && ! str_contains($url, '88=');
    });
});

it('sends an imdb-id term on the query unencoded', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_movie.html'), 200)]);

    // Act
    resolve(DownloadSearchService::class)->search('tt0234215');

    // Assert
    Http::assertSent(function ($request): bool {
        $url = $request->url();

        return str_contains($url, 'q=tt0234215');
    });
});

/*
|--------------------------------------------------------------------------
| DownloadSearchService — download slice
|--------------------------------------------------------------------------
| Fetches the .torrent for a downloadId from the download source's `/download.php`
| endpoint and writes it to the default disk under downloads/, returning the
| stored path. Ground truth is the byte-exact real capture:
|
|   tests/Fixtures/Download/downloads/sample.torrent — real bencoded
|     download file bytes.
*/

it('stores the download bytes and returns the path', function (): void {
    // Arrange
    Storage::fake();
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/sample.torrent'), 200)]);

    // Act
    $path = resolve(DownloadSearchService::class)->download(7537888, 'the-matrix-reloaded.torrent');

    // Assert
    expect($path)->toBe('downloads/the-matrix-reloaded.torrent');
    Storage::disk()->assertExists('downloads/the-matrix-reloaded.torrent');
    expect(Storage::disk()->get('downloads/the-matrix-reloaded.torrent'))->toBe(fixtureBytes('Download/downloads/sample.torrent'));
});

it('requests the correct download URL', function (): void {
    // Arrange
    Storage::fake();
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/sample.torrent'), 200)]);

    // Act
    resolve(DownloadSearchService::class)->download(7537888, 'x.torrent');

    // Assert
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/download.php/7537888/'));
});

it('maps a failed download to DownloadRequestFailed', function (): void {
    // Arrange
    Storage::fake();
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response('', 500)]);

    // Act & Assert
    expect(fn () => resolve(DownloadSearchService::class)->download(7537888, 'x.torrent'))->toThrow(DownloadRequestFailed::class);
});

/*
|--------------------------------------------------------------------------
| DownloadSearchService — throttle wiring + 429 backoff slice
|--------------------------------------------------------------------------
| Proves the service spaces its request through RequestThrottle and reacts
| to a 429 by backing off. The throttle's real spacing/timing lives in
| tests/Feature/Download/RequestThrottleTest.php and is unfakeable inside a
| single request, so here the throttle is MOCKED in the container — the
| collaborator invocation (await() before the request, backoff($retryAfter)
| on a 429) is the only observable, and Mockery verifies it on teardown.
|
| The service also opts its call path out of the global guzzle-retry middleware
| (configure() sets the per-request `retry_enabled => false`) so it is the SOLE
| owner of 429/backoff — otherwise the global middleware would retry a 429 twice
| BEFORE the get() backoff branch below ever runs, stacking backoff. That
| composition is NOT behavior-verifiable here: Http::fake() bypasses the guzzle
| handler stack, so the retry middleware never runs under fakes (documented
| project constraint). The 429 tests below therefore exercise the service's OWN
| branch under Http::fake; the opt-out itself is pinned by the why-comment in
| configure(), mirroring ImdbDatasetService/PlexApiService.
*/

it('awaits the throttle before issuing a request', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    // RequestThrottle is final, so Mockery proxies a partial mock of a real
    // instance (the container binds it), which the service resolves via app().
    $throttle = Mockery::mock(new RequestThrottle);
    $throttle->shouldReceive('await')->once();
    $throttle->shouldReceive('backoff')->never();
    $this->app->instance(RequestThrottle::class, $throttle);
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/search_movie.html'), 200)]);

    // Act
    $results = resolve(DownloadSearchService::class)->search('x');

    // Assert
    expect($results)->toBeInstanceOf(Collection::class)->toHaveCount(50);
});

it('on 429 it backs off with the Retry-After then fails', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    // RequestThrottle is final, so Mockery proxies a partial mock of a real
    // instance (the container binds it), which the service resolves via app().
    $throttle = Mockery::mock(new RequestThrottle);
    $throttle->shouldReceive('await')->zeroOrMoreTimes();
    $throttle->shouldReceive('backoff')->with(30)->once();
    $this->app->instance(RequestThrottle::class, $throttle);
    Http::fake(['*' => Http::response('', 429, ['Retry-After' => '30'])]);

    // Act & Assert
    expect(fn () => resolve(DownloadSearchService::class)->search('x'))->toThrow(DownloadRequestFailed::class);
});

it('on 429 without a numeric Retry-After it backs off with null', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    // RequestThrottle is final, so Mockery proxies a partial mock of a real
    // instance (the container binds it), which the service resolves via app().
    $throttle = Mockery::mock(new RequestThrottle);
    $throttle->shouldReceive('await')->zeroOrMoreTimes();
    $throttle->shouldReceive('backoff')->with(null)->once();
    $this->app->instance(RequestThrottle::class, $throttle);
    Http::fake(['*' => Http::response('', 429)]);

    // Act & Assert
    expect(fn () => resolve(DownloadSearchService::class)->search('x'))->toThrow(DownloadRequestFailed::class);
});

it('propagates RateLimitExceeded from the throttle', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    // RequestThrottle is final, so Mockery proxies a partial mock of a real
    // instance (the container binds it), which the service resolves via app().
    $throttle = Mockery::mock(new RequestThrottle);
    $throttle->shouldReceive('await')->andThrow(RateLimitExceeded::afterWaiting(31000));
    $this->app->instance(RequestThrottle::class, $throttle);
    Http::fake(['*' => Http::response('ok', 200)]);

    // Act & Assert
    expect(fn () => resolve(DownloadSearchService::class)->search('x'))->toThrow(RateLimitExceeded::class);
});
