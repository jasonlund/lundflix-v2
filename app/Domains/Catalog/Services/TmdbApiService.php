<?php

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Exceptions\TmdbRequestFailed;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class TmdbApiService
{
    private const BASE_URL = 'https://api.themoviedb.org/3';

    /**
     * @return array<string, mixed>|null
     */
    public function movie(int $id): ?array
    {
        return $this->detail('movie', $id, 'release_dates,images');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function tv(int $id): ?array
    {
        return $this->detail('tv', $id, 'images,external_ids,content_ratings');
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
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        $response = $this->request()->get('/configuration');

        return $this->decode($response)
            ?? throw TmdbRequestFailed::for((string) $response->effectiveUri());
    }

    /**
     * Fetch a TMDB detail resource by id, returning the raw decoded body or
     * null when the resource does not exist.
     *
     * @return array<string, mixed>|null
     */
    private function detail(string $resource, int $id, string $append): ?array
    {
        $response = $this->request()->get("/{$resource}/{$id}", [
            'append_to_response' => $append,
            'include_image_language' => 'en,null',
        ]);

        return $this->decode($response);
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
        return Http::withToken(config('services.tmdb.token'))
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
