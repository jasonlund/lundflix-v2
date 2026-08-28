<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Services;

use App\Domains\Common\Data\PlexServerConnection;
use App\Domains\Common\Exceptions\PlexAuthenticationFailed;
use App\Domains\Common\Exceptions\PlexRequestFailed;
use App\Domains\Common\Services\PlexApiService;
use App\Domains\PlexLibrary\Exceptions\ConfiguredPlexServerUnavailable;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final readonly class PlexLibraryService
{
    private const string PRODUCT_NAME = 'lundflix';

    private const int PAGE_SIZE = 200;

    public function __construct(private PlexApiService $plexApi) {}

    public function serverConnection(): PlexServerConnection
    {
        $id = (string) config('services.plex.server_identifier');

        $server = $this->plexApi->getOnlineServers((string) config('services.plex.token'))
            ->first(fn (PlexServerConnection $s): bool => $s->clientIdentifier === $id);

        if ($server === null) {
            throw ConfiguredPlexServerUnavailable::forIdentifier($id);
        }

        return $server;
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
     * @return Generator<int, list<array<string, mixed>>>
     */
    public function fetchSectionItems(string $uri, string $token, string $sectionKey): Generator
    {
        yield from $this->fetchPagedMetadata($uri, $token, "/library/sections/{$sectionKey}/all");
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
        return $this->materializePagedMetadata($uri, $token, "/library/metadata/{$ratingKey}/{$relation}");
    }

    /**
     * One X-Plex-Container-Start/Size page per yield, nothing requested until the
     * consumer pulls. The walk MUST run to the end of the container: a truncated
     * list reads to the reconcilers as "everything else is gone" and authorizes a
     * hard delete. An empty page ends the walk without being yielded.
     *
     * @return Generator<int, list<array<string, mixed>>>
     */
    private function fetchPagedMetadata(string $uri, string $token, string $path): Generator
    {
        $start = 0;

        do {
            $body = $this->get($uri, $token, $path, ['includeGuids' => 1], [
                'X-Plex-Container-Start' => (string) $start,
                'X-Plex-Container-Size' => (string) self::PAGE_SIZE,
            ])->json();

            $page = data_get($body, 'MediaContainer.Metadata', []);
            $totalSize = (int) data_get($body, 'MediaContainer.totalSize', 0);

            if ($page === []) {
                return;
            }

            yield $page;

            // Advance by the count actually returned, not the page size — a page
            // can come back short, and the empty-page guard above then stops us.
            $start += count($page);
        } while ($start < $totalSize);
    }

    /**
     * The bounded path: a single show's children/leaves fit in memory whole.
     *
     * @return list<array<string, mixed>>
     */
    private function materializePagedMetadata(string $uri, string $token, string $path): array
    {
        return collect($this->fetchPagedMetadata($uri, $token, $path))->flatten(1)->all();
    }

    /**
     * Send a GET, mapping every failure — a raw transport-level one that survives
     * the global retry middleware, and an unsuccessful HTTP status — to a
     * domain-typed exception, so no caller can mistake a failure for an answer.
     * An unthrown failure decodes to an empty MediaContainer, which the
     * reconcilers treat as a confirmed-empty server and answer by pruning every
     * local row. Status mapping follows {@see PlexApiService::decode()}: a 401 is
     * the distinct {@see PlexAuthenticationFailed}, anything else failed is
     * {@see PlexRequestFailed}. A 404 throws here rather than reading as a
     * definitive miss — a vanished section or show must not authorize a delete.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     */
    private function get(string $uri, string $token, string $path, array $query = [], array $headers = []): Response
    {
        $url = "{$uri}{$path}";

        try {
            $response = $this->request($token)->withHeaders($headers)->get($url, $query);
        } catch (ConnectionException) {
            throw PlexRequestFailed::for($url);
        }

        if ($response->status() === 401) {
            throw PlexAuthenticationFailed::invalidToken();
        }

        if ($response->failed()) {
            throw PlexRequestFailed::for($url);
        }

        return $response;
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
