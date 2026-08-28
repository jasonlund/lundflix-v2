<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\ImdbDataset;

describe('filename() remote names', function (): void {
    it('returns the remote dataset filename for each case', function (): void {
        // Arrange
        $expected = [
            'TitleBasics' => 'title.basics.tsv.gz',
            'TitleAkas' => 'title.akas.tsv.gz',
            'TitleRatings' => 'title.ratings.tsv.gz',
        ];

        // Act
        $actual = collect(ImdbDataset::cases())
            ->mapWithKeys(fn (ImdbDataset $case): array => [$case->name => $case->filename()])
            ->all();

        // Assert
        expect($actual)->toBe($expected);
    });
});

describe('casts() column types', function (): void {
    it('casts the title ratings score as float and the vote count as int', function (): void {
        // Arrange
        // enum under test, no state to set up

        // Act
        $actual = ImdbDataset::TitleRatings->casts();

        // Assert
        expect($actual)->toBe([
            'averageRating' => 'float',
            'numVotes' => 'int',
        ]);
    });

    it('casts the title basics adult flag as bool, its year and runtime columns as int, and genres as array', function (): void {
        // Arrange
        // enum under test, no state to set up

        // Act
        $actual = ImdbDataset::TitleBasics->casts();

        // Assert
        expect($actual)->toBe([
            'isAdult' => 'bool',
            'startYear' => 'int',
            'endYear' => 'int',
            'runtimeMinutes' => 'int',
            'genres' => 'array',
        ]);
    });

    it('casts the title akas types and attributes columns as multi', function (): void {
        // Arrange
        // enum under test, no state to set up

        // Act
        $actual = ImdbDataset::TitleAkas->casts();

        // Assert
        expect($actual)->toBe([
            'types' => 'multi',
            'attributes' => 'multi',
        ]);
    });
});
