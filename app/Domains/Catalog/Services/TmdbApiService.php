<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Exceptions\TmdbAuthenticationFailed;
use App\Domains\Catalog\Exceptions\TmdbRequestFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class TmdbApiService
{
    private const string BASE_URL = 'https://api.themoviedb.org/3';

    private const string MOVIE_APPEND = 'release_dates,images';

    private const string TV_APPEND = 'images,external_ids,content_ratings';

    /**
     * @return array<string, mixed>|null
     */
    public function movie(int $id): ?array
    {
        return $this->detail('movie', $id, self::MOVIE_APPEND);
    }

    /**
     * Batch-fetch movie details, one request per id via a single connection
     * pool. Returns a map of input id to its raw payload (null for a 404),
     * preserving the input order; a single id's 404 does not sink the others.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>|null>
     */
    public function movies(array $ids): array
    {
        return $this->pooled($ids, fn (PendingRequest $request, int $id) => $request
            ->get("/movie/{$id}", $this->detailQuery(self::MOVIE_APPEND)));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function tv(int $id): ?array
    {
        return $this->detail('tv', $id, self::TV_APPEND);
    }

    /**
     * Batch-fetch tv details, one request per id via a single connection pool.
     * Returns a map of input id to its raw payload (null for a 404), preserving
     * the input order; a single id's 404 does not sink the others.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>|null>
     */
    public function tvShows(array $ids): array
    {
        return $this->pooled($ids, fn (PendingRequest $request, int $id) => $request
            ->get("/tv/{$id}", $this->detailQuery(self::TV_APPEND)));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByImdbId(string $imdbId): ?array
    {
        $response = $this->get("/find/{$imdbId}", [
            'external_source' => 'imdb_id',
        ]);

        return $this->decode($response);
    }

    /**
     * Batch-resolve IMDb ids, one /find request per id via a single connection
     * pool. Returns a map of input IMDb id to its raw /find payload (null for a
     * 404), preserving input order; a single id's 404 does not sink the others.
     *
     * @param  array<int, string>  $imdbIds
     * @return array<string, array<string, mixed>|null>
     */
    public function findManyByImdbId(array $imdbIds): array
    {
        return $this->pooled($imdbIds, fn (PendingRequest $request, string $imdbId) => $request
            ->get("/find/{$imdbId}", ['external_source' => 'imdb_id']));
    }

    /**
     * @return array<int>
     */
    public function changedMovieIds(?string $start = null, ?string $end = null): array
    {
        return $this->changedIds('/movie/changes', $start, $end);
    }

    /**
     * @return array<int>
     */
    public function changedTvIds(?string $start = null, ?string $end = null): array
    {
        return $this->changedIds('/tv/changes', $start, $end);
    }

    /**
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        $response = $this->get('/configuration');

        return $this->decode($response)
            ?? throw TmdbRequestFailed::for((string) $response->effectiveUri());
    }

    /**
     * Batch-fetch one request per id, fanning out one {@see Http::pool} per
     * chunk from {@see chunkIds} so at most `concurrency` requests are in flight
     * at once; responses accumulate across chunks (each named after its id via
     * {@see configure}'s shared auth/retry), then decode in input order. A
     * single id's 404 decodes to null without sinking its siblings.
     *
     * Request failures don't short-circuit the batch: both a connection-level
     * failure (a pool entry that comes back as a {@see Throwable} instead of a
     * {@see Response}) and a response that stays failed after retries (e.g. a
     * persistent 5xx) are collected per-id, the rest are still decoded, and once
     * the loop completes any failed ids are surfaced together as a single
     * aggregate {@see TmdbRequestFailed::forIds}. A 404 still decodes to null (a
     * per-id miss, not a failure); a 401 still throws immediately, since auth is
     * fatal for the whole batch rather than a per-id condition.
     *
     * @template TKey of int|string
     *
     * @param  array<int, TKey>  $ids
     * @param  callable(PendingRequest, TKey): Response  $build
     * @return array<TKey, array<string, mixed>|null>
     */
    private function pooled(array $ids, callable $build): array
    {
        $ids = array_values(array_unique($ids));

        $responses = [];

        foreach ($this->chunkIds($ids) as $chunk) {
            $responses += Http::pool(fn (Pool $pool): array => array_map(
                fn (int|string $id) => $build($this->configure($pool->as((string) $id)), $id),
                $chunk,
            ));
        }

        $results = [];
        $failedIds = [];

        foreach ($ids as $id) {
            $response = $responses[(string) $id];

            if (! $response instanceof Response) {
                $failedIds[] = $id;

                continue;
            }

            if ($response->failed() && ! $response->notFound() && $response->status() !== 401) {
                $failedIds[] = $id;

                continue;
            }

            $results[$id] = $this->decode($response);
        }

        if ($failedIds !== []) {
            throw TmdbRequestFailed::forIds($failedIds);
        }

        return $results;
    }

    /**
     * Split the input ids into ordered chunks sized by the configured
     * concurrency, so each {@see pooled} fan-out dispatches at most one
     * chunk's worth of concurrent requests. Order is preserved and the final
     * chunk holds the remainder.
     *
     * @template TKey of int|string
     *
     * @param  array<int, TKey>  $ids
     * @return array<int, array<int, TKey>>
     */
    private function chunkIds(array $ids): array
    {
        $size = (int) config('services.tmdb.concurrency');

        return array_chunk($ids, max(1, $size));
    }

    /**
     * Fetch a TMDB detail resource by id, returning the raw decoded body or
     * null when the resource does not exist.
     *
     * @return array<string, mixed>|null
     */
    private function detail(string $resource, int $id, string $append): ?array
    {
        $response = $this->get("/{$resource}/{$id}", $this->detailQuery($append));

        return $this->decode($response);
    }

    /**
     * Build the query string for a TMDB detail request: the appended
     * sub-resources plus the shared English/no-language image filter. Shared by
     * the single ({@see detail}) and pooled ({@see movies}) request paths.
     *
     * @return array{append_to_response: string, include_image_language: string}
     */
    private function detailQuery(string $append): array
    {
        return [
            'append_to_response' => $append,
            'include_image_language' => 'en,null',
        ];
    }

    /**
     * Page through a TMDB "changes" feed, collecting every changed id across
     * all pages into a flat, de-duplicated list of ints.
     *
     * @return array<int>
     */
    private function changedIds(string $path, ?string $start, ?string $end): array
    {
        $ids = [];
        $page = 1;
        $totalPages = 1;

        do {
            $response = $this->get($path, [
                'start_date' => $start,
                'end_date' => $end,
                'page' => $page,
            ]);

            if ($response->notFound()) {
                throw TmdbRequestFailed::for((string) $response->effectiveUri());
            }

            $body = $this->decode($response) ?? [];

            foreach ($body['results'] ?? [] as $result) {
                $ids[] = (int) $result['id'];
            }

            $totalPages = (int) ($body['total_pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages);

        return array_values(array_unique($ids));
    }

    /**
     * Decode a TMDB response: return the raw body, null on 404, or throw on a
     * failed (401 auth / other) response. A successful response whose body is
     * undecodable (json() yields null) is itself an error, not a "not found",
     * so it throws {@see TmdbRequestFailed} rather than returning null.
     *
     * @return array<string, mixed>|null
     */
    private function decode(Response $response): ?array
    {
        if ($response->notFound()) {
            return null;
        }

        if ($response->status() === 401) {
            throw TmdbAuthenticationFailed::invalidToken();
        }

        if ($response->failed()) {
            throw TmdbRequestFailed::for((string) $response->effectiveUri());
        }

        return $response->json() ?? throw TmdbRequestFailed::for((string) $response->effectiveUri());
    }

    /**
     * Perform a single configured GET, normalizing a post-retry connection
     * failure into a {@see TmdbRequestFailed} so single-request callers see the
     * same typed failure the batch ({@see pooled}) paths raise. `retry(...,
     * throw: false)` suppresses a failed *response* but still lets a
     * {@see ConnectionException} propagate raw, so it is caught here.
     *
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query = []): Response
    {
        try {
            return $this->request()->get($path, $query);
        } catch (ConnectionException) {
            throw TmdbRequestFailed::for(self::BASE_URL.$path);
        }
    }

    private function request(): PendingRequest
    {
        return $this->configure(Http::getFacadeRoot()->createPendingRequest());
    }

    /**
     * Apply the shared TMDB auth, headers, and retry policy to a pending
     * request (used both for single calls and pooled batch requests).
     */
    private function configure(PendingRequest $request): PendingRequest
    {
        return $request->withToken(config('services.tmdb.token'))
            ->baseUrl(self::BASE_URL)
            ->acceptJson()
            ->retry(2, $this->retryDelay(...), $this->shouldRetry(...), throw: false);
    }

    /**
     * Delay before a retry, in milliseconds: honor the server's Retry-After
     * header (seconds) on a 429/503 when present, otherwise fall back to a
     * 1000ms base delay. Laravel's retry closure returns milliseconds.
     */
    private function retryDelay(int $attempt, Throwable $exception): int
    {
        if ($exception instanceof RequestException) {
            $retryAfter = $exception->response->header('Retry-After');

            if (is_numeric($retryAfter)) {
                return (int) $retryAfter * 1000;
            }
        }

        return 1000;
    }

    /**
     * Retry connection errors and transient HTTP failures (429, 5xx), but not
     * a definitive response such as a 404.
     */
    private function shouldRetry(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException) {
            return true;
        }

        $status = $exception->response->status();

        return $status === 429 || $status >= 500;
    }
}
