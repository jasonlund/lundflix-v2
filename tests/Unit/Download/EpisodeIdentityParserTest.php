<?php

declare(strict_types=1);

use App\Domains\Download\Data\EpisodeIdentity;
use App\Domains\Download\Support\EpisodeIdentityParser;

// Release names in the episode, season-pack and movie cases are copied verbatim
// from the committed captures tests/Fixtures/Download/downloads/rss_tv.xml and
// rss_movies.xml. The overlong and conflicting names are synthetic: real data
// carries neither shape.

describe('fromName() episode identity', function (): void {
    it('parses a single-episode release into its season and episode', function (): void {
        // Arrange
        $name = 'Mocro Mafia S02E04 Episode 4 1080p AMZN WEB-DL DDP2 0 H 264-TEPES';

        // Act
        $identity = EpisodeIdentityParser::fromName($name);

        // Assert
        expect($identity)->toEqual(new EpisodeIdentity(season: 2, episode: 4, isSeasonPack: false));
    });

    it('keeps digits elsewhere in the name out of the identity', function (string $name, int $season, int $episode): void {
        // Arrange
        // (name and expected identity arrive from the dataset)

        // Act
        $identity = EpisodeIdentityParser::fromName($name);

        // Assert
        expect($identity)->toEqual(new EpisodeIdentity(season: $season, episode: $episode, isSeasonPack: false));
    })->with([
        'leading digits in the show title' => ['30 Rock S04E14 MULTi XviD-AFG', 4, 14],
        'digits and a year in the show title' => ['M 101 2026 S01E01 Sociology of a Serial Killer 720p AMZN WEB-DL DDP5 1 H 264-RAWR', 1, 1],
    ]);

    it('parses a whole-season pack into its season with no episode', function (): void {
        // Arrange
        $name = 'The Five Star Weekend S01 1080 WEBRip 10Bit HEVC DDP5 1-d3g';

        // Act
        $identity = EpisodeIdentityParser::fromName($name);

        // Assert
        expect($identity)->toEqual(new EpisodeIdentity(season: 1, episode: null, isSeasonPack: true));
    });

    it('returns null for a movie release', function (string $name): void {
        // Arrange
        // (name arrives from the dataset)

        // Act
        $identity = EpisodeIdentityParser::fromName($name);

        // Assert
        expect($identity)->toBeNull();
    })->with([
        'a year and resolution only' => ['Captain America The First Avenger 2011 2160p MA WEB-DL DDP5 1 HDR H 265-HHWEB'],
        'a COMPLETE disc release' => ['The Crying Game 1992 COMPLETE UHD BLURAY-B0MBARDiERS'],
    ]);

    it('returns null for an overlong episode number rather than a truncated one', function (): void {
        // Arrange
        $name = 'Some Show S01E123456 1080p WEB H264-GRP';

        // Act
        $identity = EpisodeIdentityParser::fromName($name);

        // Assert
        expect($identity)->toBeNull();
    });

    it('returns null when the name carries two conflicting identities', function (): void {
        // Arrange
        $name = 'Some Show S01E02 S03E04 1080p WEB H264-GRP';

        // Act
        $identity = EpisodeIdentityParser::fromName($name);

        // Assert
        expect($identity)->toBeNull();
    });
});
