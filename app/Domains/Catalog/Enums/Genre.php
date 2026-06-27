<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use Illuminate\Support\Collection;

enum Genre: string
{
    case Action = 'Action';
    case Adult = 'Adult';
    case Adventure = 'Adventure';
    case Animation = 'Animation';
    case Biography = 'Biography';
    case Comedy = 'Comedy';
    case Crime = 'Crime';
    case Documentary = 'Documentary';
    case Drama = 'Drama';
    case Family = 'Family';
    case Fantasy = 'Fantasy';
    case FilmNoir = 'Film-Noir';
    case GameShow = 'Game-Show';
    case History = 'History';
    case Horror = 'Horror';
    case Music = 'Music';
    case Musical = 'Musical';
    case Mystery = 'Mystery';
    case News = 'News';
    case RealityTv = 'Reality-TV';
    case Romance = 'Romance';
    case SciFi = 'Sci-Fi';
    case Short = 'Short';
    case Sport = 'Sport';
    case TalkShow = 'Talk-Show';
    case Thriller = 'Thriller';
    case War = 'War';
    case Western = 'Western';

    /**
     * Map raw IMDb genre strings to known Genre cases, dropping unrecognized ones (order preserved).
     *
     * @param  array<int, string>  $genres
     * @return list<self>
     */
    public static function fromRawValues(array $genres): array
    {
        return Collection::make($genres)
            ->map(fn (string $genre): ?self => self::tryFrom($genre))
            ->filter()
            ->values()
            ->all();
    }
}
