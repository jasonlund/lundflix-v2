<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Exceptions\TvdbAuthenticationFailed;
use App\Domains\Catalog\Exceptions\TvdbRequestFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class TvdbApiService
{
    private const string BASE_URL = 'https://api4.thetvdb.com/v4';

    private const string JWT_CACHE_KEY = 'tvdb.jwt';

    /**
     * @return array<string, mixed>|null
     */
    public function series(int $id): ?array
    {
        return $this->detail('series', $id);
    }

    // === FLIX-160 seriesMany() pooled batch ===

    /**
     * Batch-fetch series "extended" details, one request per id via a connection
     * pool. Returns a map of input id to its raw payload (null for a 404),
     * preserving input order; a single id's 404 does not sink the others.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>|null>
     */
    public function seriesMany(array $ids): array
    {
        return $this->pooled($ids, fn (PendingRequest $request, int $id) => $request
            ->get("/series/{$id}/extended"));
    }

    /**
     * Batch-fetch one request per id, fanning out one {@see Http::pool} per
     * chunk from {@see chunkIds} so at most `concurrency` requests are in flight
     * at once; responses accumulate across chunks (each named after its id via
     * {@see configure}'s shared auth), then decode in input order. A single id's
     * 404 decodes to null without sinking its siblings.
     *
     * Request failures don't short-circuit the batch: both a connection-level
     * failure (a pool entry that comes back as a {@see \Throwable} instead of a
     * {@see Response}) and a response that stays failed after retries (e.g. a
     * persistent 5xx) are collected per-id, the rest are still decoded, and once
     * the loop completes any failed ids are surfaced together as a single
     * aggregate {@see TvdbRequestFailed::forIds}. A 404 still decodes to null (a
     * per-id miss, not a failure); a 401 throws immediately, since auth is fatal
     * for the whole batch rather than a per-id condition.
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

            if ($response->status() === 401) {
                throw TvdbAuthenticationFailed::invalidToken();
            }

            if ($response->failed() && ! $response->notFound()) {
                $failedIds[] = $id;

                continue;
            }

            $results[$id] = $this->decode($response);
        }

        if ($failedIds !== []) {
            throw TvdbRequestFailed::forIds($failedIds);
        }

        return $results;
    }

    /**
     * Split the input ids into ordered chunks sized by the configured
     * concurrency, so each {@see pooled} fan-out dispatches at most one chunk's
     * worth of concurrent requests. Order is preserved and the final chunk holds
     * the remainder.
     *
     * @template TKey of int|string
     *
     * @param  array<int, TKey>  $ids
     * @return array<int, array<int, TKey>>
     */
    private function chunkIds(array $ids): array
    {
        $size = (int) config('services.tvdb.concurrency');

        return array_chunk($ids, max(1, $size));
    }

    /**
     * Batch-resolve IMDb ids to their TheTVDB series, firing one
     * GET /search/remoteid/{tt} per unique id through the same pooled() seam and
     * returning a map of [imdbId => series entry|null] keyed in input order; each
     * value is the same filtered series entry the single-id resolveByImdbId()
     * returns (not the raw search-remoteid envelope).
     *
     * @param  array<int, string>  $imdbIds
     * @return array<string, array<string, mixed>|null>
     */
    public function resolveManyByImdbId(array $imdbIds): array
    {
        $envelopes = $this->pooled($imdbIds, fn (PendingRequest $request, string $imdbId) => $request
            ->get("/search/remoteid/{$imdbId}"));

        return array_map(
            fn (?array $envelope): ?array => $envelope === null ? null : $this->seriesResult($envelope['data'] ?? []),
            $envelopes,
        );
    }

    // === end FLIX-160 seriesMany() pooled batch ===

    /**
     * @return array<string, mixed>|null
     */
    public function episode(int $id): ?array
    {
        return $this->detail('episodes', $id);
    }

    /**
     * List a series' episodes for a season type, walking TheTVDB's top-level
     * `links.next` cursor until it is null and flattening every page's
     * `data.episodes` records in page order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function episodes(int $seriesId, string $seasonType = 'default'): array
    {
        $episodes = [];
        $next = "/series/{$seriesId}/episodes/{$seasonType}";

        while ($next !== null) {
            $page = $this->decode($this->get($next)) ?? [];
            $episodes = [...$episodes, ...($page['data']['episodes'] ?? [])];
            $next = $page['links']['next'] ?? null;
        }

        return $episodes;
    }

    // === FLIX-161 updates() feed — RED stub (no real logic yet) ===

    /**
     * List TheTVDB EntityUpdate records since a timestamp for an entity type,
     * walking the top-level `links.next` cursor until null and flattening every
     * page's `data` records in page order — full record shape preserved.
     *
     * @return array<int, array<string, mixed>>
     */
    public function updates(int $since, string $type): array
    {
        $updates = [];
        $next = "/updates?since={$since}&type={$type}";

        while ($next !== null) {
            $page = $this->decode($this->get($next)) ?? [];
            $updates = [...$updates, ...($page['data'] ?? [])];
            $next = $page['links']['next'] ?? null;
        }

        return $updates;
    }

    // === end FLIX-161 updates() feed stub ===

    /**
     * Resolve an IMDb id to its TheTVDB series via GET /search/remoteid/{tt},
     * returning the raw `data[]` element whose member is `series`, or null when
     * no element carries a `series` key (and on 404, where decode() yields null).
     *
     * @return array<string, mixed>|null
     */
    public function resolveByImdbId(string $imdbId): ?array
    {
        $body = $this->decode($this->get("/search/remoteid/{$imdbId}"));

        return $this->seriesResult($body['data'] ?? []);
    }

    /**
     * Select the series member out of a search remote-id result set: the first
     * `data[]` entry carrying a `series` key, or null when none do.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, mixed>|null
     */
    private function seriesResult(array $results): ?array
    {
        foreach ($results as $result) {
            if (array_key_exists('series', $result)) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Fetch a TheTVDB "extended" resource by id, returning the raw decoded body
     * or null when the resource does not exist.
     *
     * @return array<string, mixed>|null
     */
    private function detail(string $resource, int $id): ?array
    {
        return $this->decode($this->get("/{$resource}/{$id}/extended"));
    }

    /**
     * Decode a TheTVDB response: raw body, null on 404, or a typed failure. A
     * successful response whose body is undecodable (json() yields null) is an
     * error, not a "not found", so it throws rather than returning null.
     *
     * @return array<string, mixed>|null
     */
    private function decode(Response $response): ?array
    {
        if ($response->notFound()) {
            return null;
        }

        if ($response->failed()) {
            throw TvdbRequestFailed::for((string) $response->effectiveUri());
        }

        return $response->json() ?? throw TvdbRequestFailed::for((string) $response->effectiveUri());
    }

    /**
     * Send a GET through the cached JWT, transparently re-logging-in once on a
     * 401: drop the stale token so {@see token} misses and fetches a fresh JWT,
     * then retry the request. A persistent 401 is a credential failure.
     *
     * @throws TvdbAuthenticationFailed
     */
    private function get(string $path): Response
    {
        try {
            $response = $this->request()->get($path);

            if ($response->status() === 401) {
                Cache::forget(self::JWT_CACHE_KEY);
                $response = $this->request()->get($path);

                if ($response->status() === 401) {
                    throw TvdbAuthenticationFailed::invalidToken();
                }
            }

            return $response;
        } catch (ConnectionException) {
            throw TvdbRequestFailed::for(self::BASE_URL.$path);
        }
    }

    private function request(): PendingRequest
    {
        return $this->configure(Http::getFacadeRoot()->createPendingRequest());
    }

    /**
     * Apply the shared TheTVDB auth and headers to a pending request: the bearer
     * is the cached JWT obtained by exchanging the configured apikey via login.
     * Transient-retry is handled globally by the shared retry middleware.
     */
    private function configure(PendingRequest $request): PendingRequest
    {
        return $request->withToken($this->token())
            ->baseUrl(self::BASE_URL)
            ->acceptJson();
    }

    /**
     * Return the cached TheTVDB JWT, exchanging the apikey for a fresh one via
     * {@see login} on a miss and caching it long-lived (the JWT lives ~1 month).
     */
    private function token(): string
    {
        return Cache::remember(self::JWT_CACHE_KEY, now()->addDays(27), fn (): string => $this->login());
    }

    /**
     * Exchange the configured apikey for a JWT via POST /login, returning the
     * token from the response. Sent without a bearer (we have none yet), so it
     * bypasses {@see configure} to avoid recursing through {@see token}.
     */
    private function login(): string
    {
        return Http::asJson()
            ->post(self::BASE_URL.'/login', ['apikey' => config('services.tvdb.key')])
            ->json('data.token');
    }
}
