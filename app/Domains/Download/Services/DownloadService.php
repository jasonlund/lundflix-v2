<?php

declare(strict_types=1);

namespace App\Domains\Download\Services;

use App\Domains\Download\Data\DownloadFile;
use App\Domains\Download\Data\DownloadItem;
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
use App\Domains\Download\Settings\DownloadSettings;
use App\Domains\Download\Support\RequestThrottle;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DomCrawler\Crawler;

final class DownloadService
{
    private const string BASE_URL = 'https://iptorrents.com';

    /**
     * App-owned disk dir the fetched download file is written under.
     */
    private const string STORAGE_DIR = 'downloads/';

    /**
     * The download-title cell within a result row: `table#torrents` also holds a
     * header row and interstitial rows, so a real result is identified by an
     * anchor into `/t/{id}` inside its `td.al` name cell.
     */
    private const string TITLE_LINK = 'td.al a[href^="/t/"]';

    public function download(int $downloadId, string $filename): string
    {
        $response = $this->get('/download.php/'.$downloadId.'/'.$filename);
        $path = self::STORAGE_DIR.$filename;

        // Storage::put returns false on a failed write — never report the path as
        // stored when the bytes never landed on disk.
        if (Storage::put($path, $response->body()) === false) {
            throw DownloadRequestFailed::for($path);
        }

        return $path;
    }

    /**
     * Fetch the mother-category RSS feed and map each <item> to a DownloadResult.
     * The feed URL is the download source's raw, `;`-separated form sent verbatim
     * (not percent-encoded), so the path is built as a literal string rather than
     * a query array — Guzzle would otherwise encode the `;` separators.
     *
     * @return Collection<int, DownloadResult>
     */
    public function rss(Category $category): Collection
    {
        $settings = resolve(DownloadSettings::class);
        ['uid' => $uid, 'rss_key' => $rssKey] = $this->credentials($settings, forRss: true);

        $response = $this->get('/t.rss?u='.$uid.';tp='.$rssKey.';'.$category->value);

        return collect((new Crawler($response->body()))->filterXPath('//item'))
            ->map(fn (\DOMNode $node, int $index): ?DownloadResult => $this->parseRssItem(new Crawler($node), $index))
            ->filter()
            ->values();
    }

    /**
     * Fetch one no-query HTML listing page and return a DownloadPage carrying the
     * parsed results plus the page/lastPage cursor. The path is the download
     * source's `/t?<categoryValue>=&p=<page>` browse form.
     */
    public function index(Category $category, int $page = 1): DownloadPage
    {
        $response = $this->get('/t?'.$category->value.'=&p='.$page);
        $html = $response->body();

        return new DownloadPage(
            results: $this->parseResults($html, $category, $page),
            page: $page,
            lastPage: $this->lastPageFrom($html, $category, $page),
        );
    }

    /**
     * Fetch ONE HTML detail page (`/t/<id>`) and map its release fields, size, and
     * peer availability/demand into a DownloadItem. When $withFiles is true, issue a
     * SECOND request to `/t/<id>/files` and attach the parsed file list; otherwise
     * files stays null.
     */
    public function item(int $id, bool $withFiles = false): DownloadItem
    {
        $crawler = new Crawler($this->get('/t/'.$id)->body());

        $heading = $crawler->filter('h2');
        if ($heading->count() === 0) {
            throw DownloadDetailPageIncomplete::forDetailPage($id, 'name heading');
        }
        $name = trim($heading->first()->text());

        // The detail page carries several download links (this item plus related
        // releases), so scope to the anchor whose path is keyed to the current id.
        $downloadAnchor = $crawler->filter('a[href*="download.php/'.$id.'/"]');
        if ($downloadAnchor->count() === 0) {
            throw DownloadDetailPageIncomplete::forDetailPage($id, 'download link');
        }
        $filename = $this->downloadFilenameFrom((string) $downloadAnchor->first()->attr('href'));

        preg_match('/Size:\s*([0-9.]+\s*[KMGT]?B)/', $crawler->text(), $sizeMatch);

        $peer = $crawler->filter('a.peer');
        if ($peer->count() === 0) {
            throw DownloadDetailPageIncomplete::forDetailPage($id, 'availability block');
        }

        // Strip thousands separators BEFORE extracting so a comma can never split
        // one figure (`1,024`) into two. The two numbers are, IN ORDER,
        // availability then demand (the source's obfuscated up/down counts) —
        // never re-sort.
        preg_match_all('/\d+/', str_replace(',', '', $peer->first()->text()), $peerMatch);

        return new DownloadItem(
            id: $id,
            name: $name,
            filename: $filename,
            quality: Quality::fromName($name),
            codec: Codec::fromName($name),
            source: Source::fromName($name),
            releaseTag: ReleaseTag::fromName($name),
            isRar: $this->isRarRelease($name),
            sizeBytes: $this->bytesFromSize($sizeMatch[1] ?? '0 B'),
            availability: (int) ($peerMatch[0][0] ?? 0),
            demand: (int) ($peerMatch[0][1] ?? 0),
            files: $withFiles ? $this->parseFiles($id) : null,
        );
    }

    /**
     * Fetch the `/t/<id>/files` list and map each Name/Size row to a DownloadFile.
     * The file table carries a header `<th>Name</th><th>Size</th>` row; header and
     * any non-file row lack the two `<td>` cells and are skipped.
     *
     * @return Collection<int, DownloadFile>
     */
    private function parseFiles(int $id): Collection
    {
        $rows = (new Crawler($this->get('/t/'.$id.'/files')->body()))->filter('table.t1 tr');

        return collect($rows->each(function (Crawler $row): ?DownloadFile {
            $cells = $row->filter('td');

            if ($cells->count() < 2) {
                return null;
            }

            return new DownloadFile(
                name: trim($cells->eq(0)->text()),
                sizeBytes: $this->bytesFromSize($cells->eq(1)->text()),
            );
        }))->filter()->values();
    }

    /**
     * @return Collection<int, DownloadResult>
     */
    private function parseResults(string $html, Category $category, int $page): Collection
    {
        $table = (new Crawler($html))->filter('table#torrents');

        // An ABSENT `table#torrents` node means the source markup drifted / the
        // scraper broke, which an empty Collection would otherwise hide as a
        // legitimate no-hits page — so log it, loudly.
        if ($table->count() === 0) {
            Log::warning('Download listing results table (table#torrents) missing — likely download-source markup drift or scraper break.', [
                'category' => $category->value,
                'page' => $page,
            ]);

            return collect();
        }

        $rows = $table
            ->filter('tr')
            ->reduce(fn (Crawler $row): bool => $row->filter(self::TITLE_LINK)->count() > 0);

        return collect($rows->each(fn (Crawler $row): ?DownloadResult => $this->parseRow($row)))
            ->filter()
            ->values();
    }

    private function parseRow(Crawler $row): ?DownloadResult
    {
        $anchor = $row->filter(self::TITLE_LINK)->first();
        $cells = $row->filter('td');

        if (preg_match('#/t/(\d+)#', (string) $anchor->attr('href'), $idMatch) !== 1) {
            return null;
        }

        if ($cells->count() < 9) {
            return null;
        }

        // A row whose download anchor drifted away can't yield a filename; skip it
        // (like the sibling guards) so one bad row can't abort the whole page, and
        // log it loudly so the drift is visible.
        $downloadAnchor = $row->filter('a[href^="/download.php/"]');
        if ($downloadAnchor->count() === 0) {
            Log::warning('Download listing row missing its download anchor — likely download-source markup drift or scraper break.', [
                'downloadId' => (int) $idMatch[1],
            ]);

            return null;
        }

        return $this->resultFromName(
            name: trim($anchor->text()),
            filename: $this->downloadFilenameFrom((string) $downloadAnchor->first()->attr('href')),
            downloadId: (int) $idMatch[1],
            sizeBytes: $this->bytesFromSize($cells->eq(5)->text()),
            availability: $this->availabilityFrom($cells->eq(7)->text()),
        );
    }

    /**
     * Extract the download filename from a `/download.php/{id}/{filename}.{ext}`
     * path or enclosure URL: drop any query string, take the basename after the
     * final `/`, strip the trailing file extension, and url-decode.
     *
     * The extension is stripped because it is a constant the DB need not repeat,
     * and the value is url-decoded so the RSS feed's `%20` and the HTML index's
     * literal spaces canonicalize to a single stored filename across both parsers.
     */
    private function downloadFilenameFrom(string $pathOrUrl): string
    {
        $path = strtok($pathOrUrl, '?');
        $basename = basename((string) $path);
        $withoutExtension = preg_replace('/\.[^.\/]+$/', '', $basename);

        return urldecode((string) $withoutExtension);
    }

    /**
     * Parse the highest pagination page number from the `;p=<n>#torrents` links.
     * An ABSENT set of pagination links means the source markup drifted — log it
     * and fall back to the current page.
     */
    private function lastPageFrom(string $html, Category $category, int $page): int
    {
        if (preg_match_all('/;p=(\d+)#torrents/', $html, $matches) === 0) {
            Log::warning('Download listing pagination links (;p=<n>#torrents) missing — likely download-source markup drift or scraper break.', [
                'category' => $category->value,
                'page' => $page,
            ]);

            return $page;
        }

        return max(array_map(intval(...), $matches[1]));
    }

    /**
     * Parse an availability cell into a count. The source renders thousands with
     * commas (`1,024`) and an unknown count as `-`; strip the separators and
     * treat a non-numeric cell as a deliberate 0.
     */
    private function availabilityFrom(string $raw): int
    {
        $digits = preg_replace('/[^\d]/', '', trim($raw));

        return $digits === '' || $digits === null ? 0 : (int) $digits;
    }

    /**
     * Parse a binary (1024-based) size like "7.91 GB" into whole bytes.
     */
    private function bytesFromSize(string $raw): int
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $raw));

        [$value, $unit] = explode(' ', $normalized) + [1 => 'B'];

        $exponent = match (strtoupper($unit)) {
            'KB' => 1,
            'MB' => 2,
            'GB' => 3,
            'TB' => 4,
            default => 0,
        };

        return (int) round((float) $value * 1024 ** $exponent);
    }

    /**
     * Map ONE feed `<item>` to a DownloadResult, isolating its parse: a missing
     * child node or an unparseable pubDate throws while reading this item, which
     * would otherwise sink the whole feed's eager map. Catch it, skip the item
     * (return null so the caller's ->filter() drops it), and log the drift loudly
     * so one malformed item can't lose the rest of the feed.
     */
    private function parseRssItem(Crawler $item, int $index): ?DownloadResult
    {
        try {
            preg_match('#/t/(\d+)#', $item->filterXPath('.//guid')->text(), $guidMatch);
            // Thousands are comma-grouped (`S:1,024`); capture the grouped run and
            // strip the separators so the comma can't truncate the count.
            preg_match('/\(S:([\d,]+)/', $item->filterXPath('.//description')->text(), $availabilityMatch);
            $length = $item->filterXPath('.//enclosure')->attr('length') ?? '0';

            return $this->resultFromName(
                name: trim($item->filterXPath('.//title')->text()),
                filename: $this->downloadFilenameFrom((string) $item->filterXPath('.//enclosure')->attr('url')),
                downloadId: (int) ($guidMatch[1] ?? 0),
                sizeBytes: (int) $length,
                availability: (int) str_replace(',', '', $availabilityMatch[1] ?? '0'),
                publishedAt: CarbonImmutable::parse($item->filterXPath('.//pubDate')->text()),
            );
        } catch (\Throwable $e) {
            Log::warning('Download RSS item skipped — likely download-source markup drift or scraper break.', [
                'index' => $index,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build a DownloadResult from a release name, deriving quality/codec/source/
     * releaseTag and the isRar flag from the name itself. Shared by the RSS and
     * HTML-listing parsers, which differ only in how they source the id, size,
     * availability, and publish time.
     */
    private function resultFromName(
        string $name,
        string $filename,
        int $downloadId,
        int $sizeBytes,
        int $availability,
        ?CarbonImmutable $publishedAt = null,
    ): DownloadResult {
        return new DownloadResult(
            downloadId: $downloadId,
            name: $name,
            filename: $filename,
            quality: Quality::fromName($name),
            codec: Codec::fromName($name),
            source: Source::fromName($name),
            releaseTag: ReleaseTag::fromName($name),
            availability: $availability,
            sizeBytes: $sizeBytes,
            isRar: $this->isRarRelease($name),
            publishedAt: $publishedAt,
        );
    }

    /**
     * A release is assumed rar'd UNLESS its name explicitly carries a "no rar"
     * token (`NORAR`, `no.rar`, `no-rar`, …) — the absence of the marker means
     * rar'd, per the established rule shared by the RSS, listing, and detail parsers.
     */
    private function isRarRelease(string $name): bool
    {
        return preg_match('/no[\s._-]*rar/i', $name) === 0;
    }

    /**
     * Send a single configured GET, mapping a connection-level failure, an
     * unauthenticated login page (a 200 whose body carries the sign-in form),
     * and a failed response onto the domain's typed failures. The login marker
     * is checked before {@see Response::failed()} because the download source serves the
     * login page with a 200 status.
     */
    private function get(string $path): Response
    {
        // Let RateLimitExceeded from await() propagate — the caller must see it.
        $throttle = resolve(RequestThrottle::class);
        $throttle->await();

        try {
            $response = $this->request()->get($path);
        } catch (ConnectionException) {
            throw DownloadRequestFailed::for(self::BASE_URL.$path);
        }

        if (str_contains($response->body(), 'do-login.php')) {
            throw InvalidDownloadCredentials::loginPageReturned();
        }

        if ($response->failed()) {
            throw DownloadRequestFailed::for((string) $response->effectiveUri());
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        return $this->configure(Http::getFacadeRoot()->createPendingRequest());
    }

    /**
     * Apply the base URL and the uid/pass session cookie built from
     * {@see DownloadSettings} — sent verbatim as `Cookie: uid=<uid>; pass=<pass>`.
     */
    private function configure(PendingRequest $request): PendingRequest
    {
        $settings = resolve(DownloadSettings::class);
        ['uid' => $uid, 'pass' => $pass] = $this->credentials($settings);

        // Defer to the app's global guzzle-retry middleware, but scope it to this
        // request: cap it at a single retry and log a warning whenever a 429 is
        // seen so throttling by the download source is visible in the logs.
        return $request->baseUrl(self::BASE_URL)
            ->withOptions([
                'max_retry_attempts' => 1,
                'on_retry_callback' => function (int $retryCount, float $delayTimeout, RequestInterface $request, array $options, ?ResponseInterface $response = null): void {
                    $this->logRetryWarning($request, $response);
                },
            ])
            ->withHeaders(['Cookie' => "uid={$uid}; pass={$pass}"]);
    }

    /**
     * Log a warning when the guzzle-retry middleware retries a 429, carrying the
     * request URL and status so throttling by the download source is traceable.
     */
    private function logRetryWarning(RequestInterface $request, ?ResponseInterface $response): void
    {
        if ($response instanceof ResponseInterface && $response->getStatusCode() === 429) {
            Log::warning('Download request throttled (429) — retrying.', [
                'url' => (string) $request->getUri(),
                'status' => $response->getStatusCode(),
            ]);
        }
    }

    /**
     * Coalesce the uid/pass credential as an ATOMIC PAIR — uid and pass are
     * never drawn from different sources. If EITHER stored half is non-empty the
     * operator has begun configuring credentials (rotated via Filament), so the
     * stored pair is used verbatim even if the other half is blank; a blank half
     * then surfaces the misconfiguration predictably instead of being silently
     * masked by a mismatched env value (a stored uid paired with a stale env pass
     * fails auth with only a generic error). Only when BOTH stored halves are
     * empty does env/config supply the pair — preserving the fresh-env zero-config
     * default (both blank → env, which authenticates).
     *
     * $forRss returns the feed key paired with uid instead of pass. The rss_key
     * keeps its OWN stored-else-env fallback, independent of the uid/pass pair, so
     * a blank stored rss_key falls through to config even when uid/pass are stored.
     *
     * @return array{uid: string, pass: string}|array{uid: string, rss_key: string}
     */
    private function credentials(DownloadSettings $settings, bool $forRss = false): array
    {
        $useStored = $settings->uid !== '' || $settings->pass !== '';
        $uid = $useStored ? $settings->uid : (string) config('services.downloads.uid');

        if ($forRss) {
            return [
                'uid' => $uid,
                'rss_key' => $settings->rss_key !== ''
                    ? $settings->rss_key
                    : (string) config('services.downloads.rss_key'),
            ];
        }

        return [
            'uid' => $uid,
            'pass' => $useStored ? $settings->pass : (string) config('services.downloads.pass'),
        ];
    }
}
