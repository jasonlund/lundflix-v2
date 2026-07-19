<?php

declare(strict_types=1);

use App\Domains\Catalog\Support\SourceId;

it('validates and coerces IMDb crosswalk ids, malformed to null', function (): void {
    // Arrange
    $backtick = 'tt5078754`';

    // Act
    $actual = [
        'valid' => SourceId::imdb('tt0903747'),
        'trimmed' => SourceId::imdb(' tt5078754 '),
        'trailingJunk' => SourceId::imdb($backtick),
        'missingPrefix' => SourceId::imdb('0167577'),
        'person' => SourceId::imdb('nm0000123'),
        'event' => SourceId::imdb('ev0000123'),
        'urlGarbage' => SourceId::imdb('www.imdb.comtitlett1489340'),
        'null' => SourceId::imdb(null),
        'empty' => SourceId::imdb(''),
    ];

    // Assert
    expect($actual)->toBe([
        'valid' => 'tt0903747',
        'trimmed' => 'tt5078754',
        'trailingJunk' => null,
        'missingPrefix' => null,
        'person' => null,
        'event' => null,
        'urlGarbage' => null,
        'null' => null,
        'empty' => null,
    ]);
});

it('validates and coerces TMDB crosswalk ids by range, malformed to null', function (): void {
    // Arrange
    // pure normalizer, inputs supplied inline

    // Act
    $actual = [
        'valid' => SourceId::tmdb('1396'),
        'overflow' => SourceId::tmdb('129536129536'),
        'spaceOverflow' => SourceId::tmdb(' 51996251996'),
        'slugAppended' => SourceId::tmdb('1335814-silvio-santos'),
        'midRangeUnknown' => SourceId::tmdb('5643188'),
        'zero' => SourceId::tmdb('0'),
        'nonDigit' => SourceId::tmdb('abc'),
        'null' => SourceId::tmdb(null),
    ];

    // Assert
    expect($actual)->toBe([
        'valid' => 1396,
        'overflow' => null,
        'spaceOverflow' => null,
        'slugAppended' => null,
        'midRangeUnknown' => 5643188,
        'zero' => null,
        'nonDigit' => null,
        'null' => null,
    ]);
});

it('coerces positive ints for upsert keys, non-positive to null', function (): void {
    // Arrange
    // pure normalizer, inputs supplied inline

    // Act
    $actual = [
        'positiveInt' => SourceId::positiveInt(5),
        'numericString' => SourceId::positiveInt('5'),
        'zero' => SourceId::positiveInt(0),
        'negative' => SourceId::positiveInt(-3),
        'nonNumeric' => SourceId::positiveInt('x'),
        'null' => SourceId::positiveInt(null),
    ];

    // Assert
    expect($actual)->toBe([
        'positiveInt' => 5,
        'numericString' => 5,
        'zero' => null,
        'negative' => null,
        'nonNumeric' => null,
        'null' => null,
    ]);
});
