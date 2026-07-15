<?php

declare(strict_types=1);

use App\Domains\Download\Data\DownloadFile;
use App\Domains\Download\Data\DownloadPage;
use App\Domains\Download\Data\DownloadResult;
use App\Domains\Download\Enums\Category;
use App\Domains\Download\Enums\Codec;
use App\Domains\Download\Enums\Quality;
use App\Domains\Download\Enums\ReleaseTag;
use App\Domains\Download\Enums\Source;
use App\Domains\Download\Exceptions\DownloadDetailPageIncomplete;
use App\Domains\Download\Exceptions\DownloadRequestFailed;
use App\Domains\Download\Exceptions\InvalidDownloadCredentials;
use App\Domains\Download\Exceptions\RateLimitExceeded;
use App\Domains\Download\Services\DownloadService;
use App\Domains\Download\Settings\DownloadSettings;
use App\Domains\Download\Support\RequestThrottle;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| DownloadService — request/auth/error contract slice
|--------------------------------------------------------------------------
| Mirrors the Catalog Tvdb/Tmdb HTTP-service tests. This slice covers ONLY
| the request the crawler sends and how failures map to typed exceptions.
| The uid/pass cookie is built from DownloadSettings and sent as the literal
| `Cookie: uid=<uid>; pass=<pass>`. It is exercised through download(), the
| only public request path on the service today.
|
| Fixture (byte-exact real capture):
|   tests/Fixtures/Download/downloads/login.html — the download source sign-in
|   page returned when the cookie is unauthenticated; its login-form marker
|   is the string `do-login.php`.
*/

it('sends the uid/pass cookie from DownloadSettings', function (): void {
    // Arrange
    Storage::fake();
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response('ok', 200)]);

    // Act
    resolve(DownloadService::class)->download(7537888, 'x.bin');

    // Assert
    Http::assertSent(fn ($request) => $request->hasHeader('Cookie', 'uid=cookie-uid; pass=cookie-pass'));
});

it('falls back to the env/config credential when settings are blank', function (): void {
    // Arrange
    // no operator value stored → settings resolve to empty; env DOWNLOADS_UID/PASS is test-uid/test-pass
    Storage::fake();
    Http::fake(['*' => Http::response('ok', 200)]);

    // Act
    resolve(DownloadService::class)->download(7537888, 'x.bin');

    // Assert
    Http::assertSent(fn ($request) => $request->hasHeader('Cookie', 'uid=test-uid; pass=test-pass'));
});

it('prefers a stored setting over the env credential', function (): void {
    // Arrange
    Storage::fake();
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'op-uid';
    $settings->pass = 'op-pass';
    $settings->save();
    Http::fake(['*' => Http::response('ok', 200)]);

    // Act
    resolve(DownloadService::class)->download(7537888, 'x.bin');

    // Assert
    Http::assertSent(fn ($request) => $request->hasHeader('Cookie', 'uid=op-uid; pass=op-pass'));
});

it('takes the stored pair verbatim when only one stored half is set', function (): void {
    // Arrange
    // operator has begun configuring: stored uid filled, stored pass still blank —
    // the pair must come wholly from storage (blank pass), never the env pass
    Storage::fake();
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'op-uid';
    $settings->pass = '';
    $settings->save();
    Http::fake(['*' => Http::response('ok', 200)]);

    // Act
    resolve(DownloadService::class)->download(7537888, 'x.bin');

    // Assert
    Http::assertSent(fn ($request) => $request->hasHeader('Cookie', 'uid=op-uid; pass='));
});

it('throws InvalidDownloadCredentials when the response is the login page', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/login.html'), 200)]);

    // Act & Assert
    expect(fn () => resolve(DownloadService::class)->download(7537888, 'x.bin'))->toThrow(InvalidDownloadCredentials::class);
});

it('throws DownloadRequestFailed on a non-2xx response', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response('', 500)]);

    // Act & Assert
    expect(fn () => resolve(DownloadService::class)->download(7537888, 'x.bin'))->toThrow(DownloadRequestFailed::class);
});

/*
|--------------------------------------------------------------------------
| DownloadService — download slice
|--------------------------------------------------------------------------
| Fetches the download file for a downloadId from the source's `/download.php`
| endpoint and writes it to the default disk under downloads/, returning the
| stored path. Ground truth is the byte-exact real capture:
|
|   tests/Fixtures/Download/downloads/sample.bin — real bencoded
|     download file bytes.
*/

it('stores the download bytes and returns the path', function (): void {
    // Arrange
    Storage::fake();
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/sample.bin'), 200)]);

    // Act
    $path = resolve(DownloadService::class)->download(7537888, 'the-matrix-reloaded.bin');

    // Assert
    expect($path)->toBe('downloads/the-matrix-reloaded.bin');
    Storage::disk()->assertExists('downloads/the-matrix-reloaded.bin');
    expect(Storage::disk()->get('downloads/the-matrix-reloaded.bin'))->toBe(fixtureBytes('Download/downloads/sample.bin'));
});

it('requests the correct download URL', function (): void {
    // Arrange
    Storage::fake();
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/sample.bin'), 200)]);

    // Act
    resolve(DownloadService::class)->download(7537888, 'x.bin');

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
    expect(fn () => resolve(DownloadService::class)->download(7537888, 'x.bin'))->toThrow(DownloadRequestFailed::class);
});

it('maps a failed disk write to DownloadRequestFailed', function (): void {
    // Arrange
    // Storage::put returns false when the write fails; the path must NOT be reported as success
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response('data', 200)]);
    Storage::shouldReceive('put')->once()->andReturnFalse();

    // Act & Assert
    expect(fn () => resolve(DownloadService::class)->download(7537888, 'x.bin'))->toThrow(DownloadRequestFailed::class);
});

/*
|--------------------------------------------------------------------------
| DownloadService — parse→download filename round-trip slice
|--------------------------------------------------------------------------
| The filename a parser stores on a result is extensionless (the parser strips
| the trailing extension). Feeding that exact stored name back into download()
| must reproduce it verbatim in the outbound `/download.php/<id>/<filename>`
| path — the value round-trips unchanged, never re-extended or mangled. Parse
| the real Movies listing (index_movies_p1.html), take the id-7563851 row's
| filename, and drive download() with it.
*/

it('round-trips a parser-produced filename unchanged into the download request path', function (): void {
    // Arrange
    Storage::fake();
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake([
        '*/download.php/*' => Http::response(fixtureBytes('Download/downloads/sample.bin'), 200),
        '*' => Http::response(fixtureBytes('Download/downloads/index_movies_p1.html'), 200),
    ]);
    $service = resolve(DownloadService::class);
    $row = $service->index(Category::Movies)->results->firstWhere('downloadId', 7563851);

    // Act
    $service->download($row->downloadId, $row->filename);

    // Assert
    Http::assertSent(fn ($request): bool => str_ends_with((string) $request->url(), '/download.php/'.$row->downloadId.'/'.$row->filename));
});

/*
|--------------------------------------------------------------------------
| DownloadService — global-middleware retry slice
|--------------------------------------------------------------------------
| The service no longer owns a bespoke 429/backoff branch: it defers to the
| app's global guzzle-retry middleware (HttpClientServiceProvider's
| GuzzleRetryMiddleware), tuned per-request to at most ONE retry, and logs a
| warning whenever a 429 is seen. The global middleware DOES run under
| Http::fake (proven by tests/Feature/Http/HttpRetryMiddlewareTest.php), so a
| faked 429->200 sequence exercises the real retry; Retry-After is pinned to 0
| and phpunit.xml sets HTTP_RETRY_MULTIPLIER=0 so the suite stays sleep-free.
|
| The throttle only spaces the outbound request (one await() per download()),
| so a 429 no longer pushes the slot cursor — the retry lives inside guzzle,
| below the single await. The throttle's own spacing/timing lives in
| tests/Feature/Download/RequestThrottleTest.php.
*/

it('retries a transient 429 through to the eventual 200', function (): void {
    // Arrange
    Storage::fake();
    Cache::flush();
    Sleep::fake();
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::sequence()
        ->push('', 429, ['Retry-After' => '0'])
        ->push(fixtureBytes('Download/downloads/sample.bin'), 200)]);

    // Act
    $path = resolve(DownloadService::class)->download(7537888, 'x.bin');

    // Assert
    expect($path)->toBe('downloads/x.bin');
    Http::assertSentCount(2);
});

it('logs a warning for the retried 429 including the URL and status', function (): void {
    // Arrange
    Storage::fake();
    Cache::flush();
    Sleep::fake();
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::sequence()
        ->push('', 429, ['Retry-After' => '0'])
        ->push(fixtureBytes('Download/downloads/sample.bin'), 200)]);
    $spy = Log::spy();

    // Act
    resolve(DownloadService::class)->download(7537888, 'x.bin');

    // Assert
    $spy->shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
        $values = collect($context)->map(fn ($value): string => (string) $value);

        return $message !== ''
            && $values->contains(fn (string $value): bool => str_contains($value, '/download.php/'))
            && $values->contains(fn (string $value): bool => str_contains($value, '429'));
    });
});

it('caps at one retry on a persistent 429', function (): void {
    // Arrange
    Storage::fake();
    Cache::flush();
    Sleep::fake();
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response('', 429, ['Retry-After' => '0'])]);

    // Act
    $thrown = null;
    try {
        resolve(DownloadService::class)->download(7537888, 'x.bin');
    } catch (DownloadRequestFailed $e) {
        $thrown = $e;
    }

    // Assert
    expect($thrown)->toBeInstanceOf(DownloadRequestFailed::class);
    Http::assertSentCount(2);
});

it('caps at one retry on a persistent 500', function (): void {
    // Arrange
    Storage::fake();
    Cache::flush();
    Sleep::fake();
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response('', 500)]);

    // Act
    $thrown = null;
    try {
        resolve(DownloadService::class)->download(7537888, 'x.bin');
    } catch (DownloadRequestFailed $e) {
        $thrown = $e;
    }

    // Assert
    expect($thrown)->toBeInstanceOf(DownloadRequestFailed::class);
    Http::assertSentCount(2);
});

it('does not push the throttle slot cursor on a 429', function (): void {
    // Arrange
    Storage::fake();
    Cache::flush();
    Sleep::fake();
    $this->freezeTime();
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    Http::fake(['*' => Http::response('', 429)]);
    $now = now()->getTimestampMs();

    // Act
    rescue(fn () => resolve(DownloadService::class)->download(7537888, 'x.bin'));

    // Assert
    $advance = (int) Cache::get('download:request-throttle:next-slot') - $now;
    expect($advance)->toBeGreaterThanOrEqual(100)->toBeLessThanOrEqual(250);
});

it('awaits the throttle before issuing a request', function (): void {
    // Arrange
    Storage::fake();
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'cookie-uid';
    $settings->pass = 'cookie-pass';
    $settings->save();
    // RequestThrottle is final, so Mockery proxies a partial mock of a real
    // instance (the container binds it), which the service resolves via app().
    $throttle = Mockery::mock(new RequestThrottle);
    $throttle->shouldReceive('await')->once();
    $this->app->instance(RequestThrottle::class, $throttle);
    Http::fake(['*' => Http::response('ok', 200)]);

    // Act
    $path = resolve(DownloadService::class)->download(7537888, 'x.bin');

    // Assert
    expect($path)->toBe('downloads/x.bin');
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
    $throttle->shouldReceive('await')->andThrow(RateLimitExceeded::fromLockContention(new LockTimeoutException));
    $this->app->instance(RequestThrottle::class, $throttle);
    Http::fake(['*' => Http::response('ok', 200)]);

    // Act & Assert
    expect(fn () => resolve(DownloadService::class)->download(7537888, 'x.bin'))->toThrow(RateLimitExceeded::class);
});

/*
|--------------------------------------------------------------------------
| DownloadService — rss() feed slice
|--------------------------------------------------------------------------
| rss(Category) requests the mother-category RSS feed with the stored uid and
| rss_key and maps each <item> to a DownloadResult (now carrying an optional
| publishedAt parsed from the item's pubDate). The feed URL is the download
| source's raw, `;`-separated form sent verbatim (not percent-encoded):
|   /t.rss?u=<uid>;tp=<rss_key>;<categoryValue>
| The rss_key falls back to config('services.downloads.rss_key') only when the
| stored value is blank.
|
| Fixtures (byte-exact real captures, 100 items each):
|   tests/Fixtures/Download/downloads/rss_movies.xml — Movies feed (cat 72)
|   tests/Fixtures/Download/downloads/rss_tv.xml      — TV feed (cat 73)
|
| Pinned Movies item (read from rss_movies.xml):
|   title    The Mummy 2026 UHD 1080p BluRay DoVi HDR10 DDP 7 1 x265-SPHD
|   guid     /t/7563849  (downloadId 7563849)
|   pubDate  Mon, 13 Jul 2026 17:48:43 +0000
|   descr    18.6 GB; Movie/HD/Bluray (S:9 L:23)   (availability = 9)
|   length   19980788888  (sizeBytes)
|   → Quality::P1080, Codec::Hevc (x265), Source::BluRay, ReleaseTag::None,
|     isRar true (no NORAR token → assumed rar'd, per the established rule).
*/

it('requests the Movies feed URL verbatim', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->rss_key = 'rsskey123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/rss_movies.xml'), 200)]);

    // Act
    resolve(DownloadService::class)->rss(Category::Movies);

    // Assert
    Http::assertSent(fn ($request): bool => str_ends_with((string) $request->url(), '/t.rss?u=u123;tp=rsskey123;72'));
});

it('requests the TV feed URL', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->rss_key = 'rsskey123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/rss_tv.xml'), 200)]);

    // Act
    resolve(DownloadService::class)->rss(Category::Tv);

    // Assert
    Http::assertSent(fn ($request): bool => str_ends_with((string) $request->url(), ';tp=rsskey123;73'));
});

it('maps an item to a DownloadResult', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->rss_key = 'rsskey123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/rss_movies.xml'), 200)]);

    // Act
    $result = resolve(DownloadService::class)->rss(Category::Movies)->firstWhere('downloadId', 7563849);

    // Assert
    expect($result->downloadId)->toBe(7563849)
        ->and($result->name)->toBe('The Mummy 2026 UHD 1080p BluRay DoVi HDR10 DDP 7 1 x265-SPHD')
        ->and($result->quality)->toBe(Quality::P1080)
        ->and($result->codec)->toBe(Codec::Hevc)
        ->and($result->source)->toBe(Source::BluRay)
        ->and($result->releaseTag)->toBe(ReleaseTag::None)
        ->and($result->isRar)->toBeTrue()
        ->and($result->sizeBytes)->toBe(19980788888)
        ->and($result->availability)->toBe(9)
        ->and($result->publishedAt->equalTo(CarbonImmutable::parse('Mon, 13 Jul 2026 17:48:43 +0000')))->toBeTrue();
});

it('carries the demand from the L-count on an rss item', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->rss_key = 'rsskey123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/rss_movies.xml'), 200)]);

    // Act
    $result = resolve(DownloadService::class)->rss(Category::Movies)->firstWhere('downloadId', 7563849);

    // Assert
    expect($result->demand)->toBe(23);
});

it('carries the subcategory from the description label on an rss item', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->rss_key = 'rsskey123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/rss_movies.xml'), 200)]);

    // Act
    $result = resolve(DownloadService::class)->rss(Category::Movies)->firstWhere('downloadId', 7563849);

    // Assert
    expect($result->subcategory)->toBe('Movie/HD/Bluray');
});

it('carries the download filename on an rss item', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->rss_key = 'rsskey123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/rss_movies.xml'), 200)]);

    // Act
    $result = resolve(DownloadService::class)->rss(Category::Movies)->firstWhere('downloadId', 7563851);

    // Assert
    expect($result->filename)->toBe('The.Crying.Game.1992.COMPLETE.UHD.BLURAY-B0MBARDiERS');
});

it('url-decodes the download filename on an rss item', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->rss_key = 'rsskey123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/rss_tv.xml'), 200)]);

    // Act
    $result = resolve(DownloadService::class)->rss(Category::Tv)->firstWhere('downloadId', 7563850);

    // Assert
    expect($result->filename)->toBe('R a M S09E08 720p 10bit WEBRip 2CH x265 HEVC-PSA');
});

it('falls back to the config rss_key when the stored value is blank', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->rss_key = '';
    $settings->save();
    config(['services.downloads.rss_key' => 'env-rss']);
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/rss_movies.xml'), 200)]);

    // Act
    resolve(DownloadService::class)->rss(Category::Movies);

    // Assert
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'u=u123')
        && str_contains((string) $request->url(), 'tp=env-rss')
        && str_contains((string) $request->url(), ';72'));
});

/*
|--------------------------------------------------------------------------
| DownloadService — rss() per-item resilience slice
|--------------------------------------------------------------------------
| One malformed <item> (a missing child node or an unparseable pubDate) must
| not sink the whole ~100-item feed: the bad item is dropped and warned, the
| rest survive. Availability is read from the description's `(S:<n>` figure,
| whose thousands are comma-grouped like the HTML path — the comma must not
| truncate the count.
|
| Fixture (synthetic drift, based on rss_movies.xml structure):
|   tests/Fixtures/Download/downloads/rss_movies_drift.xml — one good item
|     (id 9001, description `(S:1,024 L:23)`) + one item with an unparseable
|     pubDate (id 9002).
*/

it('skips a malformed rss item and warns while the rest of the feed survives', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->rss_key = 'rsskey123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/rss_movies_drift.xml'), 200)]);
    Log::shouldReceive('warning')->once();

    // Act
    $results = resolve(DownloadService::class)->rss(Category::Movies);

    // Assert
    expect($results)->toHaveCount(1)
        ->and($results->firstWhere('downloadId', 9001))->not->toBeNull()
        ->and($results->firstWhere('downloadId', 9002))->toBeNull();
});

it('parses a comma-grouped S-count on an rss item', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->rss_key = 'rsskey123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/rss_movies_drift.xml'), 200)]);
    Log::shouldReceive('warning');

    // Act
    $result = resolve(DownloadService::class)->rss(Category::Movies)->firstWhere('downloadId', 9001);

    // Assert
    expect($result->availability)->toBe(1024);
});

/*
|--------------------------------------------------------------------------
| DownloadService — index() browse slice
|--------------------------------------------------------------------------
| index(Category, page) fetches ONE no-query HTML listing page from the
| download source and returns a DownloadPage carrying the parsed results plus
| the page/lastPage cursor. The request path is `/t?<categoryValue>=&p=<page>`
| (default page 1), so Movies → `/t?72=&p=1`, TV → `/t?73=&p=1`.
|
| Fixtures (byte-exact real captures, verified via the real Symfony Crawler):
|   tests/Fixtures/Download/downloads/index_movies_p1.html — Movies page 1
|   tests/Fixtures/Download/downloads/index_movies_p2.html — Movies page 2
|   tests/Fixtures/Download/downloads/index_tv_p1.html      — TV page 1
|   tests/Fixtures/Download/downloads/index_movies_p1_no_pagination.html
|     — a page whose result table is intact but which carries no `;p=` links
|   tests/Fixtures/Download/downloads/index_movies_p1_no_table.html
|     — a page missing table#torrents entirely
|
| VERIFIED column mapping (index_movies_p1.html): table#torrents holds 50 data
| rows, each matched by `td.al a[href^="/t/"]` and carrying 9 <td> cells:
|   - name anchor in td.al (a.b.hv); downloadId from its /t/(\d+) href.
|   - eq(5) = size text (e.g. `81.8 GB`)   → sizeBytes
|   - eq(7) = availability cell             → availability
|   - eq(8) = demand cell                   (unused)
| Max pagination link is `;p=6865#torrents` → lastPage 6865.
|
| Pinned first row (id 7563851):
|   name  The Crying Game 1992 COMPLETE UHD BLURAY-B0MBARDiERS
|   size  81.8 GB   → sizeBytes (int) round(81.8 * 1024 ** 3)
|   avail 1         → availability 1
|   → Source::BluRay, Quality null, ReleaseTag::None, isRar true, publishedAt
|     null (the listing carries no per-row date).
*/

it('parses a listing page into 50 DownloadResults defaulting to page 1', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/index_movies_p1.html'), 200)]);

    // Act
    $page = resolve(DownloadService::class)->index(Category::Movies);

    // Assert
    $row = $page->results->firstWhere('downloadId', 7563851);
    expect($page->results)->toBeInstanceOf(Collection::class)
        ->and($page->results)->toHaveCount(50)
        ->and($page->results->every(fn ($result): bool => $result instanceof DownloadResult))->toBeTrue()
        ->and($row->name)->toBe('The Crying Game 1992 COMPLETE UHD BLURAY-B0MBARDiERS')
        ->and($row->source)->toBe(Source::BluRay)
        ->and($row->quality)->toBeNull()
        ->and($row->releaseTag)->toBe(ReleaseTag::None)
        ->and($row->isRar)->toBeTrue()
        ->and($row->sizeBytes)->toBe((int) round(81.8 * 1024 ** 3))
        ->and($row->availability)->toBe(1)
        ->and($row->publishedAt)->toBeNull()
        ->and($page->page)->toBe(1);
});

it('carries the download filename on a listing row', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/index_movies_p1.html'), 200)]);

    // Act
    $row = resolve(DownloadService::class)->index(Category::Movies)->results->firstWhere('downloadId', 7563851);

    // Assert
    expect($row->filename)->toBe('The.Crying.Game.1992.COMPLETE.UHD.BLURAY-B0MBARDiERS');
});

it('carries the demand distinct from availability on a listing row', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/index_movies_p1.html'), 200)]);

    // Act
    $row = resolve(DownloadService::class)->index(Category::Movies)->results->firstWhere('downloadId', 7563851);

    // Assert
    expect($row->demand)->toBe(11)->and($row->availability)->toBe(1);
});

it('carries the subcategory from the row category image on a listing row', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/index_movies_p1.html'), 200)]);

    // Act
    $row = resolve(DownloadService::class)->index(Category::Movies)->results->firstWhere('downloadId', 7563851);

    // Assert
    expect($row->subcategory)->toBe('Movie/BD-R');
});

it('carries the uploader from the row sub-line on a listing row', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/index_movies_p1.html'), 200)]);

    // Act
    $row = resolve(DownloadService::class)->index(Category::Movies)->results->firstWhere('downloadId', 7563851);

    // Assert
    expect($row->uploader)->toBe('TvTeam');
});

it('requests the /t?72=&p=2 page and reports page 2', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/index_movies_p2.html'), 200)]);

    // Act
    $page = resolve(DownloadService::class)->index(Category::Movies, 2);

    // Assert
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/t?72=&p=2'));
    expect($page->page)->toBe(2);
});

it('parses the lastPage from the pagination links', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/index_movies_p1.html'), 200)]);

    // Act
    $page = resolve(DownloadService::class)->index(Category::Movies);

    // Assert
    expect($page->lastPage)->toBe(6865);
});

it('requests the /t?73= page for the TV category', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/index_tv_p1.html'), 200)]);

    // Act
    resolve(DownloadService::class)->index(Category::Tv);

    // Assert
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '73='));
});

it('warns and falls back lastPage to the current page when pagination links are missing', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/index_movies_p1_no_pagination.html'), 200)]);
    Log::shouldReceive('warning')->once();

    // Act
    $page = resolve(DownloadService::class)->index(Category::Movies);

    // Assert
    expect($page->lastPage)->toBe(1)
        ->and($page->results)->toHaveCount(50);
});

it('warns and returns an empty page when the results table is missing', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/index_movies_p1_no_table.html'), 200)]);
    Log::shouldReceive('warning')->once();

    // Act
    $page = resolve(DownloadService::class)->index(Category::Movies);

    // Assert
    expect($page)->toBeInstanceOf(DownloadPage::class)
        ->and($page->results)->toBeInstanceOf(Collection::class)
        ->and($page->results)->toHaveCount(0);
});

/*
|--------------------------------------------------------------------------
| DownloadService — index() row-resilience slice
|--------------------------------------------------------------------------
| A single drifted row must not abort the whole page parse: a row missing its
| download anchor is skipped+warned like the sibling guards (short row, no id),
| while the rest of the page still parses. availabilityFrom() strips thousands
| separators and treats a non-numeric `-` cell as a deliberate 0.
|
| Fixtures (synthetic drift, based on index_movies_p1.html row structure):
|   index_movies_p1_missing_download_anchor.html — 2 good rows (ids 2000, 2002)
|     + 1 row with a title link but no download anchor (id 2001).
|   index_movies_p1_availability_drift.html — a `1,024` availability row
|     (id 3000), a `-` availability row (id 3001), and a short <9-cell row
|     (id 3002).
*/

it('skips a row missing its download anchor and warns while the rest of the page parses', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/index_movies_p1_missing_download_anchor.html'), 200)]);
    Log::shouldReceive('warning')->once();

    // Act
    $page = resolve(DownloadService::class)->index(Category::Movies);

    // Assert
    expect($page->results)->toHaveCount(2)
        ->and($page->results->firstWhere('downloadId', 2000))->not->toBeNull()
        ->and($page->results->firstWhere('downloadId', 2001))->toBeNull()
        ->and($page->results->firstWhere('downloadId', 2002))->not->toBeNull();
});

it('skips a short row and parses comma-grouped and dash availability cells', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/index_movies_p1_availability_drift.html'), 200)]);

    // Act
    $page = resolve(DownloadService::class)->index(Category::Movies);

    // Assert
    expect($page->results)->toHaveCount(2)
        ->and($page->results->firstWhere('downloadId', 3002))->toBeNull()
        ->and($page->results->firstWhere('downloadId', 3000)->availability)->toBe(1024)
        ->and($page->results->firstWhere('downloadId', 3001)->availability)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| DownloadService — item() detail slice
|--------------------------------------------------------------------------
| item(int $id, bool $withFiles = false) fetches ONE HTML detail page from
| the download source (`/t/<id>`) and returns a DownloadResult carrying the
| release fields derived from the page's <h2> name (quality/codec/source/
| releaseTag/isRar) plus the parsed size and the .peer availability/demand.
|
| SCOPE NOTE (rescoped from FLIX-212): imdb/tmdb/title/year/rating/genres are
| DROPPED from this slice — the DownloadResult carries NO such fields and the
| tests below assert none of them. Only the release/name-derived fields, the
| size, and the peer counts are in scope.
|
| .peer availability/demand mapping: the detail page's `a.peer` block holds
| two numbers — the fa-angle-double-up (availability) count → availability, and
| the fa-angle-double-down (demand) count → demand.
|
| files: null unless withFiles is true. When withFiles is true, item() issues
| a SECOND request to `/t/<id>/files` and maps its Name/Size table rows to a
| Collection<DownloadFile>.
|
| Fixtures (byte-exact real captures, verified via the real Symfony Crawler):
|   tests/Fixtures/Download/downloads/detail.html    — Movie detail, id 7537888
|     <h2>  The Matrix Reloaded 2003 1080p MA WEB-DL H 264 DDP5 1-HHWEB
|     Size  7.91 GB   → (int) round(7.91 * 1024 ** 3)
|     .peer 56 up / 0 down   → availability 56, demand 0
|     → Quality::P1080, Codec::X264, Source::WebDl, ReleaseTag::None, isRar true.
|   tests/Fixtures/Download/downloads/detail_tv.html — TV detail, id 7563850
|     <h2>  R a M S09E08 720p 10bit WEBRip 2CH x265 HEVC-PSA
|     Size  114.22 MB → (int) round(114.22 * 1024 ** 2)
|     .peer 4 up / 1 down    → availability 4, demand 1
|     → Quality::P720, Codec::Hevc, Source::WebRip, ReleaseTag::None, isRar true.
|   tests/Fixtures/Download/downloads/files.html     — files list for 7537888
|     2 rows: …-HHWEB.mkv (7.91 GB) and …-HHWEB.mkv.nfo (809 B).
*/

it('parses a movie detail into a DownloadResult', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/detail.html'), 200)]);

    // Act
    $item = resolve(DownloadService::class)->item(7537888);

    // Assert
    expect($item)->toBeInstanceOf(DownloadResult::class)
        ->and($item->downloadId)->toBe(7537888)
        ->and($item->name)->toBe('The Matrix Reloaded 2003 1080p MA WEB-DL H 264 DDP5 1-HHWEB')
        ->and($item->filename)->toBe('The Matrix Reloaded 2003 1080p MA WEB-DL H 264 DDP5 1-HHWEB')
        ->and($item->quality)->toBe(Quality::P1080)
        ->and($item->codec)->toBe(Codec::X264)
        ->and($item->source)->toBe(Source::WebDl)
        ->and($item->releaseTag)->toBe(ReleaseTag::None)
        ->and($item->isRar)->toBeTrue()
        ->and($item->sizeBytes)->toBe((int) round(7.91 * 1024 ** 3))
        ->and($item->availability)->toBe(56)
        ->and($item->demand)->toBe(0)
        ->and($item->files)->toBeNull();
});

it('item() sets the subcategory from a movie detail page', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/detail.html'), 200)]);

    // Act
    $item = resolve(DownloadService::class)->item(7537888);

    // Assert
    expect($item->subcategory)->toBe('Movie/HD/Bluray');
});

it('item() sets the uploader from a movie detail page', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/detail.html'), 200)]);

    // Act
    $item = resolve(DownloadService::class)->item(7537888);

    // Assert
    expect($item->uploader)->toBe('Lama');
});

it('item() sets the imdbId from a movie detail page', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/detail.html'), 200)]);

    // Act
    $item = resolve(DownloadService::class)->item(7537888);

    // Assert
    expect($item->imdbId)->toBe('tt0234215');
});

it('item() sets the tmdbId from a movie detail page', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/detail.html'), 200)]);

    // Act
    $item = resolve(DownloadService::class)->item(7537888);

    // Assert
    expect($item->tmdbId)->toBe(604);
});

it('item() sets the publishedAt from a movie detail page', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/detail.html'), 200)]);

    // Act
    $item = resolve(DownloadService::class)->item(7537888);

    // Assert
    expect($item->publishedAt->equalTo(CarbonImmutable::parse('Wednesday, July 1, 2026 at 12:23am')))->toBeTrue();
});

it('parses a TV detail into a DownloadResult media-agnostically', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/detail_tv.html'), 200)]);

    // Act
    $item = resolve(DownloadService::class)->item(7563850);

    // Assert
    expect($item->name)->toBe('R a M S09E08 720p 10bit WEBRip 2CH x265 HEVC-PSA')
        ->and($item->filename)->toBe('R a M S09E08 720p 10bit WEBRip 2CH x265 HEVC-PSA')
        ->and($item->quality)->toBe(Quality::P720)
        ->and($item->codec)->toBe(Codec::Hevc)
        ->and($item->source)->toBe(Source::WebRip)
        ->and($item->sizeBytes)->toBe((int) round(114.22 * 1024 ** 2))
        ->and($item->availability)->toBe(4)
        ->and($item->demand)->toBe(1);
});

it('fetches and maps the file list when withFiles is true', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake([
        '*/t/7537888/files' => Http::response(fixtureBytes('Download/downloads/files.html'), 200),
        '*' => Http::response(fixtureBytes('Download/downloads/detail.html'), 200),
    ]);

    // Act
    $item = resolve(DownloadService::class)->item(7537888, withFiles: true);

    // Assert
    $mkv = $item->files->firstWhere('name', 'The Matrix Reloaded 2003 1080p MA WEB-DL H 264 DDP5 1-HHWEB.mkv');
    $nfo = $item->files->firstWhere('name', 'The Matrix Reloaded 2003 1080p MA WEB-DL H 264 DDP5 1-HHWEB.mkv.nfo');
    expect($item->files)->toBeInstanceOf(Collection::class)
        ->and($item->files)->toHaveCount(2)
        ->and($item->files->every(fn ($file): bool => $file instanceof DownloadFile))->toBeTrue()
        ->and($mkv->sizeBytes)->toBe((int) round(7.91 * 1024 ** 3))
        ->and($nfo->sizeBytes)->toBe(809);
    Http::assertSentCount(2);
});

it('sends one request and leaves files null when withFiles defaults to false', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/detail.html'), 200)]);

    // Act
    $item = resolve(DownloadService::class)->item(7537888);

    // Assert
    expect($item->files)->toBeNull();
    Http::assertSentCount(1);
});

it('throws when a detail page is missing its required nodes', function (): void {
    // Arrange
    // a 200 stub (pulled/restricted page) carrying none of the required detail nodes
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/detail_stub.html'), 200)]);

    // Act & Assert
    expect(fn () => resolve(DownloadService::class)->item(7537888))->toThrow(DownloadDetailPageIncomplete::class);
});

it('parses a comma-grouped availability figure on a detail page', function (): void {
    // Arrange
    // the `a.peer` up-count is `1,024`; the comma must not split it into 1 and 024
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/detail_high_availability.html'), 200)]);

    // Act
    $item = resolve(DownloadService::class)->item(7537888);

    // Assert
    expect($item->availability)->toBe(1024)
        ->and($item->demand)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| DownloadService — item() description slice
|--------------------------------------------------------------------------
| item() fills description (a DownloadDescription VO of html + screenshots)
| from the detail page's readme blockquote. When the page has no blockquote
| the description stays null and item() still returns (name-derived fields
| survive), so a missing readme is degradation, not a hard failure.
|
| Fixtures:
|   detail.html — byte-exact real capture; its readme blockquote carries the
|     `Title : The Matrix Reloaded` text and three lookpic screenshot URLs.
|   detail_no_readme.html — synthetic drift from detail.html: both
|     <blockquote> blocks stripped, everything else (h2, download anchor,
|     a.peer) intact, so item() still returns with a null description.
*/

it('sets the description screenshots from a movie detail page', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/detail.html'), 200)]);

    // Act
    $item = resolve(DownloadService::class)->item(7537888);

    // Assert
    expect($item->description->screenshots)->toBe([
        'https://lookpic.com/cdn/i2/s/TheMatrixReloaded20031080pMAWEBDLH264DDP51HHWEBmkv07012026022312-001.jpg',
        'https://lookpic.com/cdn/i2/s/TheMatrixReloaded20031080pMAWEBDLH264DDP51HHWEBmkv07012026022312-002.jpg',
        'https://lookpic.com/cdn/i2/s/TheMatrixReloaded20031080pMAWEBDLH264DDP51HHWEBmkv07012026022312-003.jpg',
    ]);
});

it('sets the description html from a movie detail page', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/detail.html'), 200)]);

    // Act
    $item = resolve(DownloadService::class)->item(7537888);

    // Assert
    expect($item->description->html)->toContain('Title : The Matrix Reloaded')
        ->and($item->description->html)->toContain('<br>');
});

it('leaves the description null when the detail page has no readme', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->pass = 'p123';
    $settings->save();
    Http::fake(['*' => Http::response(fixtureBytes('Download/downloads/detail_no_readme.html'), 200)]);

    // Act
    $item = resolve(DownloadService::class)->item(7537888);

    // Assert
    expect($item->description)->toBeNull()
        ->and($item->name)->toBe('The Matrix Reloaded 2003 1080p MA WEB-DL H 264 DDP5 1-HHWEB');
});
