<?php

declare(strict_types=1);

namespace App\Domains\Common\Services;

use App\Domains\Common\Data\PlexAccount;
use App\Domains\Common\Data\PlexPin;
use App\Domains\Common\Data\PlexServerConnection;
use App\Domains\Common\Exceptions\PlexAuthenticationFailed;
use App\Domains\Common\Exceptions\PlexRequestFailed;
use App\Domains\Common\Exceptions\PlexServerIdentifierMissing;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final readonly class PlexApiService
{
    private const string CLIENTS_HOST = 'https://clients.plex.tv/api/v2';

    private const string USER_HOST = 'https://plex.tv/api/v2';

    private const string AUTH_HOST = 'https://app.plex.tv/auth';

    private const string PRODUCT_NAME = 'lundflix';

    public function createPin(): PlexPin
    {
        $url = self::CLIENTS_HOST.'/pins?strong=true';

        try {
            $response = $this->request()->post($url);
        } catch (ConnectionException) {
            throw PlexRequestFailed::for($url);
        }

        $body = $this->decode($response);

        return new PlexPin(
            $body['id'] ?? null,
            $body['code'] ?? null,
        );
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

    public function getUserInfo(string $token): PlexAccount
    {
        $body = $this->decode($this->get(self::USER_HOST.'/user', $token)) ?? [];

        return new PlexAccount(
            $body['id'] ?? null,
            $body['uuid'] ?? null,
            $body['username'] ?? null,
            $body['email'] ?? null,
            $body['thumb'] ?? null,
        );
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
     * @return Collection<int, PlexServerConnection>
     */
    public function getOnlineServers(string $token): Collection
    {
        return $this->getUserResources($token)
            ->filter(fn (array $r): bool => ($r['provides'] ?? '') === 'server' && ($r['presence'] ?? false) === true)
            ->map(fn (array $resource): ?PlexServerConnection => $this->toServerConnection($resource))
            ->filter()
            ->values();
    }

    /**
     * Project a discovery resource, or null when none of its connections is
     * reachable: {@see PlexServerConnection::$uri} is non-nullable, so a server
     * with no usable uri is dropped rather than constructed.
     *
     * @param  array<string, mixed>  $resource
     */
    private function toServerConnection(array $resource): ?PlexServerConnection
    {
        $uri = $this->selectBestConnection($resource['connections'] ?? []);

        if ($uri === null) {
            return null;
        }

        return new PlexServerConnection(
            name: $resource['name'],
            clientIdentifier: $resource['clientIdentifier'],
            accessToken: $resource['accessToken'],
            owned: $resource['owned'],
            uri: $uri,
        );
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
        return $candidates->first(fn (array $c): bool => Str::startsWith((string) ($c['uri'] ?? ''), 'https://'))
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

        return is_string($host) && Str::startsWith($host, 'relay.');
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

        if (! is_string($host) || ! Str::contains($host, 'plex.direct')) {
            return false;
        }

        $firstLabel = explode('.', $host)[0];

        // A genuine .direct IPv6 first label is the hex groups with colons
        // replaced by dashes (e.g. 2001-db8--1). Require the whole label to be
        // hex-and-dashes so a stray hex letter (deadbox) doesn't false-positive;
        // keep the dash-encoded IPv4-octet guard so 1-2-3-4 stays IPv4-class.
        return ! preg_match('/^\d+(?:-\d+){3}$/', $firstLabel) && (bool) preg_match('/^[0-9a-f]{1,4}(?:-[0-9a-f]{0,4})+$/i', $firstLabel);
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
