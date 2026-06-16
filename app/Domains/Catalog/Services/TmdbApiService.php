<?php

namespace App\Domains\Catalog\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

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

        if ($response->notFound()) {
            return null;
        }

        return $response->json();
    }

    private function request(): PendingRequest
    {
        return Http::withToken(config('services.tmdb.token'))
            ->baseUrl(self::BASE_URL)
            ->acceptJson();
    }
}
