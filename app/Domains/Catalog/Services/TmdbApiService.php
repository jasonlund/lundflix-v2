<?php

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Exceptions\TmdbRequestFailed;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class TmdbApiService
{
    private const BASE_URL = 'https://api.themoviedb.org/3';

    private const MOVIE_APPEND = 'release_dates,images';

    private const TV_APPEND = 'images,external_ids,content_ratings';

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
        $response = $this->request()->get("/find/{$imdbId}", [
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
        $response = $this->request()->get('/configuration');

        return $this->decode($response)
            ?? throw TmdbRequestFailed::for((string) $response->effectiveUri());
    }

    /**
     * Batch-fetch one request per id via a single connection pool, then re-key
     * the responses by input id and decode each. Each id is dispatched through
     * {@see configure} (shared auth/retry) and named after its id, so the loop
     * can pair every input id with its response in input order; a single id's
     * 404 decodes to null without sinking the others.
     *
     * @template TKey of int|string
     *
     * @param  array<int, TKey>  $ids
     * @param  callable(PendingRequest, TKey): Response  $build
     * @return array<TKey, array<string, mixed>|null>
     */
    private function pooled(array $ids, callable $build): array
    {
        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (int|string $id) => $build($this->configure($pool->as((string) $id)), $id),
            $ids,
        ));

        $results = [];

        foreach ($ids as $id) {
            $results[$id] = $this->decode($responses[(string) $id]);
        }

        return $results;
    }

    /**
     * Fetch a TMDB detail resource by id, returning the raw decoded body or
     * null when the resource does not exist.
     *
     * @return array<string, mixed>|null
     */
    private function detail(string $resource, int $id, string $append): ?array
    {
        $response = $this->request()->get("/{$resource}/{$id}", $this->detailQuery($append));

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
            $body = $this->decode($this->request()->get($path, [
                'start_date' => $start,
                'end_date' => $end,
                'page' => $page,
            ])) ?? [];

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
     * failed (401 auth / other) response.
     *
     * @return array<string, mixed>|null
     */
    private function decode(Response $response): ?array
    {
        if ($response->notFound()) {
            return null;
        }

        if ($response->status() === 401) {
            throw TmdbRequestFailed::authFailed();
        }

        if ($response->failed()) {
            throw TmdbRequestFailed::for((string) $response->effectiveUri());
        }

        return $response->json();
    }

    private function request(): PendingRequest
    {
        return $this->configure(Http::baseUrl(self::BASE_URL));
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
            ->retry(2, config('services.tmdb.retry_delay'), $this->shouldRetry(...), throw: false);
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
