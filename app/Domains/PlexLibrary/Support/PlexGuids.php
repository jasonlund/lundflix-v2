<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Support;

use App\Domains\Catalog\Support\SourceId;
use Illuminate\Support\Str;

final class PlexGuids
{
    /**
     * @param  array<string, mixed>  $metadata
     * @return array{imdb: ?string, tmdb: ?int, tvdb: ?int}
     */
    public static function extract(array $metadata): array
    {
        $normalizers = [
            'imdb' => SourceId::imdb(...),
            'tmdb' => SourceId::tmdb(...),
            'tvdb' => SourceId::positiveInt(...),
        ];

        $ids = ['imdb' => null, 'tmdb' => null, 'tvdb' => null];

        // The entity's own ids (Guid[]) rank ahead of the parent/grandparent
        // fields, so first-non-null-wins keeps an episode's own guid from being
        // overwritten by its show's parentGuid.
        $candidates = [];

        foreach ($metadata['Guid'] ?? [] as $guid) {
            $candidates[] = $guid['id'] ?? null;
        }

        foreach (['guid', 'parentGuid', 'grandparentGuid'] as $field) {
            $candidates[] = $metadata[$field] ?? null;
        }

        foreach ($candidates as $id) {
            if (! is_string($id)) {
                continue;
            }

            foreach ($normalizers as $scheme => $normalize) {
                $prefix = $scheme.'://';

                if ($ids[$scheme] === null && Str::startsWith($id, $prefix)) {
                    $ids[$scheme] = $normalize(Str::after($id, $prefix));

                    break;
                }
            }
        }

        return $ids;
    }
}
