<?php

declare(strict_types=1);

namespace App\Domains\Common\Services;

use App\Domains\Common\Exceptions\PlexAuthenticationFailed;
use App\Domains\Common\Exceptions\PlexRequestFailed;
use App\Domains\Common\Exceptions\PlexServerIdentifierMissing;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

final class PlexApiService
{
    private const string CLIENTS_HOST = 'https://clients.plex.tv/api/v2';

    private const string USER_HOST = 'https://plex.tv/api/v2';

    private const string AUTH_HOST = 'https://app.plex.tv/auth';

    private const string METADATA_HOST = 'https://metadata.provider.plex.tv';

    private const string PRODUCT_NAME = 'lundflix';

    private const int POOL_CONNECT_TIMEOUT = 5;

    private const int POOL_REQUEST_TIMEOUT = 10;

    /**
     * @return array{id: int, code: string}
     */
    public function createPin(): array
    {
        $url = self::CLIENTS_HOST.'/pins?strong=true';

        try {
            $response = $this->request()->post($url);
        } catch (ConnectionException) {
            throw PlexRequestFailed::for($url);
        }

        $body = $this->decode($response);

        return [
            'id' => $body['id'] ?? null,
            'code' => $body['code'] ?? null,
        ];
    }

    public function getTokenFromPin(int $pinId): ?string
    {
        $body = $this->decode($this->get(self::CLIENTS_HOST."/pins/{$pinId}"));

        return $body['authToken'] ?? null;
    }

    public function getAuthUrl(string $code, string $forwardUrl): string
    {
        $params = http_build_query([
            'clientID' => config('services.plex.client_identifier'),
            'code' => $code,
            'forwardUrl' => $forwardUrl,
            'context[device][product]' => self::PRODUCT_NAME,
        ]);

        return self::AUTH_HOST.'#?'.$params;
    }

    /**
     * @return array{id: int|null, uuid: string|null, username: string|null, email: string|null, thumb: string|null}
     */
    public function getUserInfo(string $token): array
    {
        $body = $this->decode($this->get(self::USER_HOST.'/user', $token)) ?? [];

        return [
            'id' => $body['id'] ?? null,
            'uuid' => $body['uuid'] ?? null,
            'username' => $body['username'] ?? null,
            'email' => $body['email'] ?? null,
            'thumb' => $body['thumb'] ?? null,
        ];
    }

    /**
     * @return Collection<int, mixed>
     */
    public function getFriends(string $token): Collection
    {
        $body = $this->decode($this->get(self::CLIENTS_HOST.'/friends', $token));

        return collect($body ?? []);
    }

    /**
     * @return Collection<int, mixed>
     */
    public function getUserResources(string $token): Collection
    {
        return collect($this->decode($this->get(self::CLIENTS_HOST.'/resources', $token, [
            'includeHttps' => 1,
            'includeRelay' => 1,
            'includeIPv6' => 1,
        ])) ?? []);
    }

    public function hasServerAccess(string $token): bool
    {
        $serverId = (string) config('services.plex.server_identifier');

        if ($serverId === '') {
            throw PlexServerIdentifierMissing::notConfigured();
        }

        return $this->getUserResources($token)->contains(fn (array $resource): bool => ($resource['clientIdentifier'] ?? null) === $serverId
            && ($resource['provides'] ?? null) === 'server');
    }

    /**
     * @return Collection<int, mixed>
     */
    public function getOnlineServers(string $token): Collection
    {
        return $this->getUserResources($token)
            ->filter(fn (array $r): bool => ($r['provides'] ?? '') === 'server' && ($r['presence'] ?? false) === true)
            ->map(fn (array $r): array => [
                'name' => $r['name'],
                'clientIdentifier' => $r['clientIdentifier'],
                'accessToken' => $r['accessToken'],
                'owned' => $r['owned'],
                'uri' => $this->selectBestConnection($r['connections'] ?? []),
            ])
            ->filter(fn (array $s): bool => $s['uri'] !== null)
            ->values();
    }

    /**
     * Select the best reachable connection URI, preferring non-local direct
     * IPv4, then direct IPv6, then relay.
     *
     * @param  array<int, array<string, mixed>>  $connections
     */
    private function selectBestConnection(array $connections): ?string
    {
        $nonLocal = collect($connections)->filter(fn (array $c): bool => ! ($c['local'] ?? false));

        $directIpv4 = $this->preferHttps($nonLocal->filter(fn (array $c): bool => ! $this->isRelayConnection($c) && ! $this->isIpv6Connection($c)));
        $directIpv6 = $this->preferHttps($nonLocal->filter(fn (array $c): bool => ! $this->isRelayConnection($c) && $this->isIpv6Connection($c)));
        $relay = $this->preferHttps($nonLocal->filter(fn (array $c): bool => $this->isRelayConnection($c)));

        return $directIpv4['uri'] ?? $directIpv6['uri'] ?? $relay['uri'] ?? null;
    }

    /**
     * Pick a connection from a single class (already filtered), preferring a
     * secure https:// uri over an earlier plain http:// one; fall back to the
     * first of the class when none are https.
     *
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>|null
     */
    private function preferHttps(Collection $candidates): ?array
    {
        return $candidates->first(fn (array $c): bool => str_starts_with((string) ($c['uri'] ?? ''), 'https://'))
            ?? $candidates->first();
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function isRelayConnection(array $connection): bool
    {
        if (array_key_exists('relay', $connection)) {
            return (bool) $connection['relay'];
        }

        $host = parse_url($connection['uri'] ?? '', PHP_URL_HOST);

        return is_string($host) && str_starts_with($host, 'relay.');
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function isIpv6Connection(array $connection): bool
    {
        if (array_key_exists('IPv6', $connection)) {
            return (bool) $connection['IPv6'];
        }

        $host = parse_url($connection['uri'] ?? '', PHP_URL_HOST);

        if (! is_string($host) || ! str_contains($host, 'plex.direct')) {
            return false;
        }

        $firstLabel = explode('.', $host)[0];

        // A genuine .direct IPv6 first label is the hex groups with colons
        // replaced by dashes (e.g. 2001-db8--1). Require the whole label to be
        // hex-and-dashes so a stray hex letter (deadbox) doesn't false-positive;
        // keep the dash-encoded IPv4-octet guard so 1-2-3-4 stays IPv4-class.
        return ! preg_match('/^\d+(?:-\d+){3}$/', $firstLabel) && (bool) preg_match('/^[0-9a-f]{1,4}(?:-[0-9a-f]{0,4})+$/i', $firstLabel);
    }

    public function resolvePlexGuid(string $token, string $externalGuid, int $type): ?string
    {
        $body = $this->decode($this->get(self::METADATA_HOST.'/library/metadata/matches', $token, [
            'type' => $type,
            'guid' => $externalGuid,
        ]));

        return data_get($body, 'MediaContainer.Metadata.0.guid');
    }

    /**
     * @return Collection<int, mixed>
     */
    public function searchByExternalId(string $token, string $externalGuid, int $type): Collection
    {
        $plexGuid = $this->resolvePlexGuid($token, $externalGuid, $type);

        if (! $plexGuid) {
            return collect();
        }

        $servers = $this->getOnlineServers($token);

        if ($servers->isEmpty()) {
            return collect();
        }

        // Query all servers concurrently to find which have the content.
        $searchResponses = $this->poolByServer(
            $servers,
            fn (PendingRequest $request, array $server) => $request->get($server['uri'].'/library/all', ['guid' => $plexGuid]),
        );

        $matched = $servers
            ->map(function (array $server) use ($searchResponses): ?array {
                $response = $searchResponses[$server['clientIdentifier']] ?? null;

                if ($this->poolResponseFailed($response)) {
                    return null;
                }

                $match = $response->json('MediaContainer.Metadata.0');

                return $match ? [...$server, 'match' => $match] : null;
            })
            ->filter()
            ->values();

        if ($matched->isEmpty()) {
            return $matched;
        }

        // Fetch full detail metadata for each match concurrently.
        $detailResponses = $this->poolByServer(
            $matched,
            fn (PendingRequest $request, array $server) => $request->get($server['uri']."/library/metadata/{$server['match']['ratingKey']}"),
        );

        return $matched->map(function (array $server) use ($detailResponses): array {
            $response = $detailResponses[$server['clientIdentifier']] ?? null;

            if (! $this->poolResponseFailed($response)) {
                $detail = $response->json('MediaContainer.Metadata.0');
                if ($detail) {
                    $server['match'] = $detail;
                }
            }

            return $server;
        });
    }

    /**
     * Fan one request per online server out concurrently via {@see Http::pool},
     * keyed by clientIdentifier so callers correlate each response back to its
     * server. Each request is pre-configured with that server's access token;
     * `$build` only supplies the per-server URL. Per-server tolerance (a failed
     * or absent response) stays at the call site — servers differ in reachability
     * and a miss on one must not sink the rest.
     *
     * @param  Collection<int, array<string, mixed>>  $servers
     * @param  callable(PendingRequest, array<string, mixed>): Response  $build
     * @return array<string, Response|\Throwable>
     */
    private function poolByServer(Collection $servers, callable $build): array
    {
        return Http::pool(fn (Pool $pool): array => $servers->map(
            // Some user servers are expected unreachable; cap each and skip the
            // global retry so a dead server fails fast (once) instead of 3×.
            fn (array $server) => $build(
                $this->configure($pool->as($server['clientIdentifier']), $server['accessToken'])
                    ->connectTimeout(self::POOL_CONNECT_TIMEOUT)
                    ->timeout(self::POOL_REQUEST_TIMEOUT)
                    ->withOptions(['retry_enabled' => false]),
                $server,
            ),
        )->all());
    }

    /**
     * True when a pooled response is unusable: absent, a captured transport
     * exception, or a failed HTTP status.
     */
    private function poolResponseFailed(Response|\Throwable|null $response): bool
    {
        return ! $response instanceof Response || $response->failed();
    }

    /**
     * @return Collection<int, mixed>
     */
    public function searchShowWithEpisodes(string $token, string $externalGuid): Collection
    {
        $serversWithShow = $this->searchByExternalId($token, $externalGuid, 2);

        if ($serversWithShow->isEmpty()) {
            return collect();
        }

        $responses = $this->poolByServer(
            $serversWithShow,
            fn (PendingRequest $request, array $server) => $request->get($server['uri']."/library/metadata/{$server['match']['ratingKey']}/allLeaves"),
        );

        return $serversWithShow->map(function (array $server) use ($responses): array {
            $response = $responses[$server['clientIdentifier']] ?? null;

            $episodes = $this->poolResponseFailed($response)
                ? []
                : collect($response->json('MediaContainer.Metadata') ?? [])
                    ->map(fn (array $ep): array => $this->mapEpisode($ep))
                    ->all();

            return [
                'name' => $server['name'],
                'clientIdentifier' => $server['clientIdentifier'],
                'owned' => $server['owned'],
                'uri' => $server['uri'],
                'show' => [
                    'title' => $server['match']['title'],
                    'year' => $server['match']['year'] ?? null,
                    'ratingKey' => $server['match']['ratingKey'],
                ],
                'episodes' => $episodes,
            ];
        });
    }

    /**
     * Reduce a raw Plex episode (a `MediaContainer.Metadata` entry) to the
     * episode shape this service exposes. Wire values stay verbatim — Plex
     * sends ratingKey as a string and we keep it that way.
     *
     * @param  array<string, mixed>  $ep
     * @return array<string, mixed>
     */
    private function mapEpisode(array $ep): array
    {
        return [
            'season' => $ep['parentIndex'] ?? 0,
            'episode' => $ep['index'] ?? 0,
            'title' => $ep['title'] ?? 'Unknown',
            'ratingKey' => $ep['ratingKey'] ?? '',
            'duration' => $ep['duration'] ?? null,
            'videoResolution' => $ep['Media'][0]['videoResolution'] ?? null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentlyAdded(string $uri, string $accessToken, int $limit = 50): array
    {
        if ($uri === '' || $accessToken === '') {
            return [];
        }

        $sections = data_get($this->decode($this->get($uri.'/library/sections', $accessToken)), 'MediaContainer.Directory', []);

        $items = [];

        foreach ($sections as $section) {
            $key = $section['key'] ?? null;
            $type = $section['type'] ?? null;

            if (! $key || ! in_array($type, ['movie', 'show'], true)) {
                continue;
            }

            $params = [
                'X-Plex-Container-Start' => 0,
                'X-Plex-Container-Size' => $limit,
            ];

            if ($type === 'show') {
                // Plex type 4 = episode: a show section's recentlyAdded returns
                // individual episodes rather than series, matching how new content lands.
                $params['type'] = 4;
            }

            $metadata = data_get($this->decode($this->get($uri."/library/sections/{$key}/recentlyAdded", $accessToken, $params)), 'MediaContainer.Metadata', []);

            $items = array_merge($items, $metadata);
        }

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchLibraryMetadata(string $uri, string $accessToken, string $ratingKey): ?array
    {
        if ($uri === '' || $accessToken === '') {
            return null;
        }

        return data_get($this->decode($this->get($uri."/library/metadata/{$ratingKey}", $accessToken)), 'MediaContainer.Metadata.0');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchEpisodesForShow(string $uri, string $accessToken, string $showRatingKey): array
    {
        if ($uri === '' || $accessToken === '') {
            return [];
        }

        return data_get($this->decode($this->get($uri."/library/metadata/{$showRatingKey}/allLeaves", $accessToken)), 'MediaContainer.Metadata', []);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, int|string>
     */
    public function extractExternalIdentifiers(array $metadata): array
    {
        $guids = collect($metadata['Guid'] ?? [])
            ->pluck('id')
            ->filter(fn (mixed $guid): bool => is_string($guid) && $guid !== '')
            ->values();

        foreach (['guid', 'parentGuid', 'grandparentGuid'] as $field) {
            $guid = $metadata[$field] ?? null;

            if (is_string($guid) && $guid !== '') {
                $guids->push($guid);
            }
        }

        $identifiers = [];

        // First-wins: Guid[] is iterated before the top-level guid/parentGuid/
        // grandparentGuid fields, so the entity's own ids beat a parent/show id
        // that shares the same scheme (e.g. an episode's imdb:// over its show's).
        foreach ($guids->unique() as $guid) {
            if (! isset($identifiers['imdb']) && str_starts_with((string) $guid, 'imdb://')) {
                $identifiers['imdb'] = substr((string) $guid, strlen('imdb://'));
            }

            if (str_starts_with((string) $guid, 'tmdb://')) {
                $remainder = substr((string) $guid, strlen('tmdb://'));

                if ($remainder !== '' && ctype_digit($remainder)) {
                    $identifiers['tmdb'] ??= (int) $remainder;
                }
            }

            if (str_starts_with((string) $guid, 'tvdb://')) {
                $remainder = substr((string) $guid, strlen('tvdb://'));

                if ($remainder !== '' && ctype_digit($remainder)) {
                    $identifiers['tvdb'] ??= (int) $remainder;
                }
            }

            if (! isset($identifiers['plex']) && str_starts_with((string) $guid, 'plex://')) {
                $identifiers['plex'] = $guid;
            }
        }

        return $identifiers;
    }

    /**
     * Send a GET through {@see configure}, mapping a transport-level failure
     * past retries to a typed {@see PlexRequestFailed}.
     */
    private function get(string $url, ?string $token = null, array $query = []): Response
    {
        try {
            return $this->request($token)->get($url, $query);
        } catch (ConnectionException) {
            throw PlexRequestFailed::for($url);
        }
    }

    /**
     * Decode a Plex response: a 401 throws {@see PlexAuthenticationFailed}, a 404
     * is a definitive miss (null), any other failure maps to {@see PlexRequestFailed}.
     *
     * @return array<string, mixed>|null
     */
    private function decode(Response $response): ?array
    {
        if ($response->status() === 401) {
            throw PlexAuthenticationFailed::invalidToken();
        }

        if ($response->notFound()) {
            return null;
        }

        if ($response->failed()) {
            throw PlexRequestFailed::for((string) $response->effectiveUri());
        }

        return $response->json();
    }

    private function request(?string $token = null): PendingRequest
    {
        return $this->configure(Http::getFacadeRoot()->createPendingRequest(), $token);
    }

    /**
     * Apply the shared X-Plex identity headers to a pending request, adding the
     * X-Plex-Token only when a token is present. Transient-retry is handled
     * globally by the shared retry middleware.
     */
    private function configure(PendingRequest $request, ?string $token = null): PendingRequest
    {
        $headers = [
            'X-Plex-Client-Identifier' => config('services.plex.client_identifier'),
            'X-Plex-Product' => self::PRODUCT_NAME,
            'X-Plex-Version' => '1.0.0',
            'X-Plex-Platform' => PHP_OS_FAMILY,
            'X-Plex-Device-Name' => self::PRODUCT_NAME,
        ];

        if ($token !== null) {
            $headers['X-Plex-Token'] = $token;
        }

        return $request->withHeaders($headers)->acceptJson();
    }
}
