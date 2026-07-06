<?php

declare(strict_types=1);

namespace App\Domains\Download\Services;

use App\Domains\Download\Data\DownloadResult;
use App\Domains\Download\Enums\Category;
use App\Domains\Download\Enums\Codec;
use App\Domains\Download\Enums\Quality;
use App\Domains\Download\Exceptions\DownloadRequestFailed;
use App\Domains\Download\Exceptions\InvalidDownloadCredentials;
use App\Domains\Download\Settings\DownloadSettings;
use App\Domains\Download\Support\RequestThrottle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\DomCrawler\Crawler;

final class DownloadSearchService
{
    private const string BASE_URL = 'https://iptorrents.com';

    /**
     * App-owned disk dir the fetched download file is written under. Named
     * `downloads/` (not the banned "torrent" word) per the FLIX-132 naming mandate.
     */
    private const string STORAGE_DIR = 'downloads/';

    /**
     * The download-title cell within a result row: `table#torrents` also holds a
     * header row and interstitial rows, so a real result is identified by an
     * anchor into `/t/{id}` inside its `td.al` name cell.
     */
    private const string TITLE_LINK = 'td.al a[href^="/t/"]';

    /**
     * @param  list<Category>  $categories
     * @return Collection<int, DownloadResult>
     */
    public function search(string $imdbIdOrTerm, array $categories = []): Collection
    {
        // Build the query string ourselves so spaces encode as `+` (Guzzle would
        // emit `%20`) and the empty-valued category markers (`72=`) survive.
        $queryString = http_build_query(['q' => $imdbIdOrTerm] + $this->categoryMarkers($categories));

        $response = $this->get('/t?'.$queryString);

        return $this->sortResults($this->parseResults($response->body(), $imdbIdOrTerm));
    }

    /**
     * Rank a parsed result set highest-preference first, per decision 6:
     * (1) quality via Quality::priority() DESC — a null quality has no priority,
     * so a PHP_INT_MIN sentinel sinks unrecognized-quality rows last; (2) non-rar
     * before rar (isRar ASC); (3) availability DESC.
     *
     * Null-quality rows are KEPT (sorted last), not filtered — this service is a
     * sorted-set contract; callers decide what to drop.
     *
     * @param  Collection<int, DownloadResult>  $results
     * @return Collection<int, DownloadResult>
     */
    private function sortResults(Collection $results): Collection
    {
        return $results
            ->sort(fn (DownloadResult $a, DownloadResult $b): int => ($b->quality?->priority() ?? PHP_INT_MIN) <=> ($a->quality?->priority() ?? PHP_INT_MIN)
                ?: $a->isRar <=> $b->isRar
                ?: $b->availability <=> $a->availability)
            ->values();
    }

    /**
     * Turn the requested categories (empty → the non-adult defaults) into the
     * empty-valued query markers the source expects (`72=`), keyed by category
     * value so they merge into the search query.
     *
     * @param  list<Category>  $categories
     * @return array<string, string>
     */
    private function categoryMarkers(array $categories): array
    {
        $resolved = $categories === [] ? Category::defaults() : $categories;

        // Belt-and-suspenders: defaults() already excludes adult, but an
        // explicitly-passed adult category must never reach the wire.
        $resolved = array_filter($resolved, fn (Category $c): bool => ! $c->isAdult());

        return array_fill_keys(array_map(fn (Category $c): string => $c->value, $resolved), '');
    }

    public function fetchImdbId(int $downloadId): ?string
    {
        $response = $this->get('/t/'.$downloadId);

        $link = (new Crawler($response->body()))
            ->filter('a[href*="imdb.com/title/"]')
            ->first();

        if ($link->count() === 0) {
            return null;
        }

        if (preg_match('/tt\d+/', (string) $link->attr('href'), $match) !== 1) {
            return null;
        }

        return $match[0];
    }

    public function download(int $downloadId, string $filename): string
    {
        $response = $this->get('/download.php/'.$downloadId.'/'.$filename);
        $path = self::STORAGE_DIR.$filename;
        Storage::put($path, $response->body());

        return $path;
    }

    /**
     * @return Collection<int, DownloadResult>
     */
    private function parseResults(string $html, string $query): Collection
    {
        $table = (new Crawler($html))->filter('table#torrents');

        // A genuine zero-result page still renders the `table#torrents` node
        // (header row, no result rows); an ABSENT node instead means the source
        // markup drifted / the scraper broke, which an empty Collection would
        // otherwise hide as a legitimate no-hits search — so log it, loudly.
        if ($table->count() === 0) {
            Log::warning('Download search results table (table#torrents) missing — likely IPTorrents markup drift or scraper break.', ['query' => $query]);

            return collect();
        }

        $rows = $table
            ->filter('tr')
            ->reduce(fn (Crawler $row): bool => $row->filter(self::TITLE_LINK)->count() > 0);

        // A single markup-drifted row (ad, pinned, short) must skip — not abort the
        // whole parse — so parseRow nulls a bad row and we drop the nulls.
        return collect($rows->each(fn (Crawler $row): ?DownloadResult => $this->parseRow($row)))
            ->filter()
            ->values();
    }

    private function parseRow(Crawler $row): ?DownloadResult
    {
        $anchor = $row->filter(self::TITLE_LINK)->first();
        $cells = $row->filter('td');

        // Guard the two things a drifted row can break: an id-less `/t/` href
        // (preg_match miss → undefined $idMatch[1]) and a short row whose size /
        // availability cells don't exist (empty-Crawler ->text() throws).
        if (preg_match('#/t/(\d+)#', (string) $anchor->attr('href'), $idMatch) !== 1) {
            return null;
        }

        if ($cells->count() < 7) {
            return null;
        }

        $name = trim($anchor->text());

        return new DownloadResult(
            downloadId: (int) $idMatch[1],
            name: $name,
            quality: Quality::fromName($name),
            codec: Codec::fromName($name),
            availability: $this->availabilityFrom($cells->eq(6)->text()),
            sizeBytes: $this->bytesFromSize($cells->eq(5)->text()),
            isRar: preg_match('/no[\s._-]*rar/i', $name) === 0,
        );
    }

    /**
     * Parse an availability (seeders) cell into a count. The source renders
     * thousands with commas (`1,024`) and an unknown count as `-`; strip the
     * separators and treat a non-numeric cell as a deliberate 0, rather than
     * letting a naive `(int)"1,024"` silently truncate to 1.
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
     * Send a single configured GET, mapping a connection-level failure, an
     * unauthenticated login page (a 200 whose body carries the sign-in form),
     * and a failed response onto the domain's typed failures. The login marker
     * is checked before {@see Response::failed()} because the download source serves the
     * login page with a 200 status.
     *
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query = []): Response
    {
        // Let RateLimitExceeded from await() propagate — the caller must see it.
        $throttle = resolve(RequestThrottle::class);
        $throttle->await();

        try {
            // Forwarding an empty array as Guzzle's `query` overwrites (wipes) a
            // query string already baked into `$path`, so pass only when non-empty.
            $response = $query === []
                ? $this->request()->get($path)
                : $this->request()->get($path, $query);
        } catch (ConnectionException) {
            throw DownloadRequestFailed::for(self::BASE_URL.$path);
        }

        // A 429 is checked before the login/failed mapping: back off (honoring
        // Retry-After when numeric) so the throttle spaces the next request.
        if ($response->status() === 429) {
            $retryAfter = $response->header('Retry-After');
            $throttle->backoff(is_numeric($retryAfter) ? (int) $retryAfter : null);

            throw DownloadRequestFailed::for((string) $response->effectiveUri());
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

        // This service owns the sole 429/Retry-After backoff + RequestThrottle
        // past-cap valve for its own calls (see get()), so the global blind-retry
        // middleware (HttpClientServiceProvider's GuzzleRetryMiddleware) must not
        // also retry 429/5xx/timeout on this path — stacked backoff would space
        // requests twice and reach the throttle valve later than get() implies.
        // caseyamcl's GuzzleRetryMiddleware reads the per-request `retry_enabled`
        // option; false disables all its retry for this request only, leaving the
        // global seam active for every other domain.
        return $request->baseUrl(self::BASE_URL)
            ->withOptions(['retry_enabled' => false])
            ->withHeaders(['Cookie' => "uid={$uid}; pass={$pass}"]);
    }

    /**
     * Coalesce the uid/pass credential: an operator-set setting (rotated via
     * Filament) wins when non-empty, else fall back to the env/config value —
     * so env is the zero-config default while runtime rotation still overrides.
     *
     * @return array{uid: string, pass: string}
     */
    private function credentials(DownloadSettings $settings): array
    {
        return [
            'uid' => $settings->uid !== '' ? $settings->uid : (string) config('services.downloads.uid'),
            'pass' => $settings->pass !== '' ? $settings->pass : (string) config('services.downloads.pass'),
        ];
    }
}
