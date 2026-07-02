<?php

declare(strict_types=1);

namespace App\Domains\Download\Services;

use App\Domains\Download\Data\DownloadResult;
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
use Illuminate\Support\Facades\Storage;
use Symfony\Component\DomCrawler\Crawler;

final class DownloadSearchService
{
    private const string BASE_URL = 'https://iptorrents.com';

    /**
     * The download-title cell within a result row: `table#torrents` also holds a
     * header row and interstitial rows, so a real result is identified by an
     * anchor into `/t/{id}` inside its `td.al` name cell.
     */
    private const string TITLE_LINK = 'td.al a[href^="/t/"]';

    /**
     * Download source movie category ids — riding the `/t` query as empty-valued
     * params (`&100=`) scopes the search to movies.
     *
     * @var list<int>
     */
    private const array MOVIE_CATEGORIES = [6, 7, 20, 38, 48, 54, 62, 68, 77, 87, 89, 90, 96, 100, 101];

    /**
     * @return Collection<int, DownloadResult>
     */
    public function search(string $query, int $page = 1): Collection
    {
        $response = $this->get('/t', ['q' => $query, 'p' => $page]);

        return $this->parseResults($response->body());
    }

    /**
     * @return Collection<int, DownloadResult>
     */
    public function searchMovieByImdbId(string $imdbId): Collection
    {
        return $this->searchMovies($imdbId);
    }

    /**
     * @return Collection<int, DownloadResult>
     */
    public function searchMovieByTitle(string $title, int $year): Collection
    {
        return $this->searchMovies($title.' '.$year);
    }

    /**
     * @return Collection<int, DownloadResult>
     */
    private function searchMovies(string $query): Collection
    {
        $categories = array_fill_keys(array_map(strval(...), self::MOVIE_CATEGORIES), '');

        // Build the query string ourselves so spaces encode as `+` (Guzzle would
        // emit `%20`) and the empty-valued category markers (`100=`) survive.
        $queryString = http_build_query(['q' => $query, 'p' => 1] + $categories);

        $response = $this->get('/t?'.$queryString);

        return $this->parseResults($response->body());
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
        $path = 'torrents/'.$filename;
        Storage::put($path, $response->body());

        return $path;
    }

    /**
     * @return Collection<int, DownloadResult>
     */
    private function parseResults(string $html): Collection
    {
        $rows = (new Crawler($html))
            ->filter('table#torrents tr')
            ->reduce(fn (Crawler $row): bool => $row->filter(self::TITLE_LINK)->count() > 0);

        return collect($rows->each(fn (Crawler $row): DownloadResult => $this->parseRow($row)));
    }

    private function parseRow(Crawler $row): DownloadResult
    {
        $anchor = $row->filter(self::TITLE_LINK)->first();
        $name = trim($anchor->text());
        $cells = $row->filter('td');

        preg_match('#/t/(\d+)#', (string) $anchor->attr('href'), $idMatch);

        return new DownloadResult(
            downloadId: (int) $idMatch[1],
            name: $name,
            quality: Quality::fromName($name),
            codec: Codec::fromName($name),
            availability: (int) trim($cells->eq(6)->text()),
            sizeBytes: $this->bytesFromSize($cells->eq(5)->text()),
            isRar: stripos($name, 'norar') === false,
        );
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

        return $request->baseUrl(self::BASE_URL)
            ->withHeaders(['Cookie' => "uid={$settings->uid}; pass={$settings->pass}"]);
    }
}
