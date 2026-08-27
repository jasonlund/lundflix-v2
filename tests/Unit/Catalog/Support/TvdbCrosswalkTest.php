<?php

declare(strict_types=1);

use App\Domains\Catalog\Support\TvdbCrosswalk;

describe('normalize() crosswalk mapping', function (): void {
    it('maps both IMDb and TMDB remoteIds entries', function (): void {
        // Arrange
        $remoteIds = [
            ['sourceName' => 'IMDB', 'id' => 'tt0903747'],
            ['sourceName' => 'TheMovieDB.com', 'id' => '1396'],
        ];

        // Act
        $actual = TvdbCrosswalk::normalize($remoteIds);

        // Assert
        expect($actual)->toBe(['_imdb_id' => 'tt0903747', '_tmdb_id' => 1396]);
    });

    it('maps only the IMDb entry when TMDB is absent', function (): void {
        // Arrange
        $remoteIds = [
            ['sourceName' => 'IMDB', 'id' => 'tt0903747'],
        ];

        // Act
        $actual = TvdbCrosswalk::normalize($remoteIds);

        // Assert
        expect($actual)->toBe(['_imdb_id' => 'tt0903747', '_tmdb_id' => null]);
    });

    it('maps only the TMDB entry when IMDb is absent', function (): void {
        // Arrange
        $remoteIds = [
            ['sourceName' => 'TheMovieDB.com', 'id' => '1396'],
        ];

        // Act
        $actual = TvdbCrosswalk::normalize($remoteIds);

        // Assert
        expect($actual)->toBe(['_imdb_id' => null, '_tmdb_id' => 1396]);
    });

    it('nulls both when no sourceName matches', function (): void {
        // Arrange
        $remoteIds = [
            ['sourceName' => 'Wikidata', 'id' => 'Q1079'],
        ];

        // Act
        $actual = TvdbCrosswalk::normalize($remoteIds);

        // Assert
        expect($actual)->toBe(['_imdb_id' => null, '_tmdb_id' => null]);
    });
});

describe('normalize() malformed input', function (): void {
    it('nulls a malformed id while the other source still maps', function (): void {
        // Arrange
        $malformedImdb = [
            ['sourceName' => 'IMDB', 'id' => 'nm0000123'],
            ['sourceName' => 'TheMovieDB.com', 'id' => '1396'],
        ];
        $malformedTmdb = [
            ['sourceName' => 'IMDB', 'id' => 'tt0903747'],
            ['sourceName' => 'TheMovieDB.com', 'id' => '1335814-silvio-santos'],
        ];

        // Act
        $actual = [
            'malformedImdb' => TvdbCrosswalk::normalize($malformedImdb),
            'malformedTmdb' => TvdbCrosswalk::normalize($malformedTmdb),
        ];

        // Assert
        expect($actual)->toBe([
            'malformedImdb' => ['_imdb_id' => null, '_tmdb_id' => 1396],
            'malformedTmdb' => ['_imdb_id' => 'tt0903747', '_tmdb_id' => null],
        ]);
    });

    it('nulls both for an empty remoteIds array', function (): void {
        // Arrange

        // Act
        $actual = TvdbCrosswalk::normalize([]);

        // Assert
        expect($actual)->toBe(['_imdb_id' => null, '_tmdb_id' => null]);
    });

    it('nulls both for a non-array or null remoteIds without throwing', function (): void {
        // Arrange

        // Act
        $actual = [
            'scalar' => TvdbCrosswalk::normalize('garbage'),
            'null' => TvdbCrosswalk::normalize(null),
        ];

        // Assert
        expect($actual)->toBe([
            'scalar' => ['_imdb_id' => null, '_tmdb_id' => null],
            'null' => ['_imdb_id' => null, '_tmdb_id' => null],
        ]);
    });
});
