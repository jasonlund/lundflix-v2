<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Exceptions\TmdbAuthenticationFailed;
use App\Domains\Catalog\Exceptions\TmdbRequestFailed;
use App\Domains\Catalog\Services\Concerns\PooledIdFailed;
use App\Domains\Catalog\Services\Concerns\PoolsIdBatches;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class TmdbApiService
{
    use PoolsIdBatches;

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
     * Batch /movie/{id} keyed by movie id. See {@see pooled} for the shared
     * order/404/failure contract.
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
     * Batch /tv/{id} keyed by tv id. See {@see pooled} for the shared
     * order/404/failure contract.
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
     * Batch /find/{imdbId} keyed by IMDb id. See {@see pooled} for the shared
     * order/404/failure contract.
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

    private function poolConcurrency(): int
    {
        return (int) config('services.tmdb.concurrency');
    }

    /**
     * Per-id pooled decision for TMDB: a persistent non-404, non-401 failure is
     * collected per-id (signalled via {@see PooledIdFailed}); a 401 flows to
     * {@see decode}, which throws {@see TmdbAuthenticationFailed} immediately
     * (auth is fatal for the whole batch); an undecodable 200 flows to
     * {@see decode}, which throws {@see TmdbRequestFailed} immediately — a
     * decode error is not aggregated.
     *
     * @return array<string, mixed>|null
     */
    private function resolvePooled(Response $response): ?array
    {
        if ($response->failed() && ! $response->notFound() && $response->status() !== 401) {
            throw new PooledIdFailed;
        }

        return $this->decode($response);
    }

    /**
     * @param  array<int, int|string>  $failedIds
     */
    private function pooledFailure(array $failedIds): Throwable
    {
        return TmdbRequestFailed::forIds($failedIds);
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
     * same typed failure the batch ({@see pooled}) paths raise. Retries are
     * applied globally by the registered retry middleware; a
     * {@see ConnectionException} that survives those retries propagates raw, so
     * it is caught here.
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
     * Apply the shared TMDB auth and headers to a pending request (used both
     * for single calls and pooled batch requests). Retries are applied
     * globally by the registered retry middleware, not here.
     */
    private function configure(PendingRequest $request): PendingRequest
    {
        return $request->withToken(config('services.tmdb.token'))
            ->baseUrl(self::BASE_URL)
            ->acceptJson();
    }
}
