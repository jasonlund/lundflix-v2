<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

enum ImdbDataset
{
    case TitleBasics;
    case TitleAkas;
    case TitleRatings;

    public function filename(): string
    {
        return match ($this) {
            self::TitleBasics => 'title.basics.tsv.gz',
            self::TitleAkas => 'title.akas.tsv.gz',
            self::TitleRatings => 'title.ratings.tsv.gz',
        };
    }

    /**
     * Columns needing a cast; everything else stays string|null.
     *
     * @return array<string, string>
     */
    public function casts(): array
    {
        return match ($this) {
            self::TitleBasics => [
                'isAdult' => 'bool',
                'startYear' => 'int',
                'endYear' => 'int',
                'runtimeMinutes' => 'int',
                'genres' => 'array',
            ],
            self::TitleAkas => [
                'types' => 'multi',
                'attributes' => 'multi',
            ],
            self::TitleRatings => [
                'averageRating' => 'float',
                'numVotes' => 'int',
            ],
        };
    }
}
