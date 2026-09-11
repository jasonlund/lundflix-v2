<?php

declare(strict_types=1);

namespace App\Domains\Download\Support;

use App\Domains\Download\Data\EpisodeIdentity;

final readonly class EpisodeIdentityParser
{
    /**
     * Season and episode are capped at 4 digits (always fits an unsigned small
     * integer) with a digit boundary after each, so an overlong run fails to
     * match rather than truncating into a valid-looking identity. A bare season
     * also refuses a following `E`, so `S01E123456` can't fall back to a season
     * pack.
     */
    private const string PATTERN = '/\bS(?<season>\d{1,4})(?:E(?<episode>\d{1,4})(?!\d)|(?![\dE]))/';

    public static function fromName(string $name): ?EpisodeIdentity
    {
        // Two identities are a conflict, not a choice: picking one would acquire
        // the wrong episode, while null is only a miss.
        if (preg_match_all(self::PATTERN, $name, $matches, PREG_SET_ORDER) !== 1) {
            return null;
        }

        [$match] = $matches;
        $episode = isset($match['episode']) ? (int) $match['episode'] : null;

        return new EpisodeIdentity(season: (int) $match['season'], episode: $episode, isSeasonPack: $episode === null);
    }
}
