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

    /**
     * Batch-fetch each series' "extended" detail through {@see pooled} — map of
     * id → raw payload (null on 404), input order preserved.
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
     * Batch one request per id via {@see Http::pool}, fanned out in {@see chunkIds}
     * chunks so at most `concurrency` are in flight; responses decode in input
     * order. A 404 decodes to null (per-id miss). A 401 throws immediately — auth
     * is fatal for the whole batch. Connection failures, post-retry response
     * failures (e.g. persistent 5xx), and undecodable 200s don't short-circuit:
     * collected per-id and surfaced together as one {@see TvdbRequestFailed::forIds}.
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
                Cache::forget(self::JWT_CACHE_KEY);

                throw TvdbAuthenticationFailed::invalidToken();
            }

            if ($response->failed() && ! $response->notFound()) {
                $failedIds[] = $id;

                continue;
            }

            try {
                $results[$id] = $this->decode($response);
            } catch (TvdbRequestFailed) {
                $failedIds[] = $id;
            }
        }

        if ($failedIds !== []) {
            throw TvdbRequestFailed::forIds($failedIds);
        }

        return $results;
    }

    /**
     * Split ids into ordered chunks of `concurrency` (min 1), so each
     * {@see pooled} fan-out dispatches at most one chunk concurrently.
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

    /**
     * Fetch one page of TheTVDB's `/series` listing — the page's `data[]` of
     * BASE series records (not extended), or [] past the end. Deliberately
     * page-at-a-time: unlike {@see episodes}/{@see updates} it does NOT walk
     * `links.next`; the caller advances the page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allSeries(int $page = 0): array
    {
        return ($this->decode($this->get("/series?page={$page}")) ?? [])['data'] ?? [];
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
     *
     * A transport failure or a failed (e.g. 5xx) /login response is a request
     * failure, not a credential failure: it surfaces as {@see TvdbRequestFailed}
     * for the /login URI. {@see TvdbAuthenticationFailed::noUsableToken} is
     * reserved for a successful response whose body carries no usable token —
     * the only case that genuinely implicates services.tvdb.key.
     */
    private function login(): string
    {
        try {
            $response = Http::asJson()
                ->post(self::BASE_URL.'/login', ['apikey' => config('services.tvdb.key')]);
        } catch (ConnectionException) {
            throw TvdbRequestFailed::for(self::BASE_URL.'/login');
        }

        if ($response->failed()) {
            throw TvdbRequestFailed::for(self::BASE_URL.'/login');
        }

        $token = $response->json('data.token');

        if (! is_string($token) || $token === '') {
            throw TvdbAuthenticationFailed::noUsableToken();
        }

        return $token;
    }
}
