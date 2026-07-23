<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Services;

use App\Domains\Common\Exceptions\PlexRequestFailed;
use App\Domains\Common\Services\PlexApiService;
use App\Domains\PlexLibrary\Exceptions\ConfiguredPlexServerUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final readonly class PlexLibraryService
{
    private const string PRODUCT_NAME = 'lundflix';

    private const int PAGE_SIZE = 200;

    public function __construct(private PlexApiService $plexApi) {}

    /**
     * @return array{uri: string, accessToken: string}
     */
    public function serverConnection(): array
    {
        $id = (string) config('services.plex.server_identifier');

        $server = $this->plexApi->getOnlineServers((string) config('services.plex.token'))
            ->first(fn (array $s): bool => $s['clientIdentifier'] === $id);

        if ($server === null) {
            throw ConfiguredPlexServerUnavailable::forIdentifier($id);
        }

        return ['uri' => $server['uri'], 'accessToken' => $server['accessToken']];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchSections(string $uri, string $token): array
    {
        $body = $this->get($uri, $token, '/library/sections')->json();

        return data_get($body, 'MediaContainer.Directory', []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchSectionItems(string $uri, string $token, string $sectionKey): array
    {
        $members = [];
        $start = 0;

        do {
            $body = $this->get($uri, $token, "/library/sections/{$sectionKey}/all", ['includeGuids' => 1], [
                'X-Plex-Container-Start' => (string) $start,
                'X-Plex-Container-Size' => (string) self::PAGE_SIZE,
            ])->json();

            $page = data_get($body, 'MediaContainer.Metadata', []);
            $totalSize = (int) data_get($body, 'MediaContainer.totalSize', 0);
            $members = array_merge($members, $page);

            // Advance by the count actually returned, not the page size — a page
            // can come back short, and the empty-page guard below then stops us.
            $start += count($page);
        } while ($start < $totalSize && $page !== []);

        return $members;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchShowChildren(string $uri, string $token, string $ratingKey): array
    {
        return $this->fetchMetadataRelation($uri, $token, $ratingKey, 'children');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchShowLeaves(string $uri, string $token, string $ratingKey): array
    {
        return $this->fetchMetadataRelation($uri, $token, $ratingKey, 'allLeaves');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchMetadataRelation(string $uri, string $token, string $ratingKey, string $relation): array
    {
        $body = $this->get($uri, $token, "/library/metadata/{$ratingKey}/{$relation}", ['includeGuids' => 1])->json();

        return data_get($body, 'MediaContainer.Metadata', []);
    }

    /**
     * Send a GET, mapping a raw transport-level failure that survives the global
     * retry middleware to the domain-typed {@see PlexRequestFailed}.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     */
    private function get(string $uri, string $token, string $path, array $query = [], array $headers = []): Response
    {
        $url = "{$uri}{$path}";

        try {
            return $this->request($token)->withHeaders($headers)->get($url, $query);
        } catch (ConnectionException) {
            throw PlexRequestFailed::for($url);
        }
    }

    private function request(string $token): PendingRequest
    {
        return Http::getFacadeRoot()->createPendingRequest()
            ->withHeaders([
                'X-Plex-Client-Identifier' => config('services.plex.client_identifier'),
                'X-Plex-Product' => self::PRODUCT_NAME,
                'X-Plex-Version' => '1.0.0',
                'X-Plex-Platform' => PHP_OS_FAMILY,
                'X-Plex-Device-Name' => self::PRODUCT_NAME,
                'X-Plex-Token' => $token,
            ])
            ->acceptJson();
    }
}
