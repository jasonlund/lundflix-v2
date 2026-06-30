<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

enum ImdbDataset: string
{
    case TitleBasics = 'title.basics';
    case TitleRatings = 'title.ratings';

    public function filename(): string
    {
        return "{$this->value}.tsv.gz";
    }

    /**
     * Columns needing a cast; everything else stays string|null.
     *
     * @return array<string, string>
     */
    public function casts(): array
    {
        return match ($this) {
            self::TitleBasics => ['startYear' => 'int', 'endYear' => 'int', 'runtimeMinutes' => 'int', 'genres' => 'array'],
            self::TitleRatings => ['averageRating' => 'float', 'numVotes' => 'int'],
        };
    }

    /**
     * Domain filter, evaluated on the RAW (string) row before casting.
     *
     * @param  array<string, string|null>  $row
     */
    public function includes(array $row): bool
    {
        return match ($this) {
            self::TitleBasics => in_array($row['titleType'] ?? null, TitleType::values(), true) && ($row['isAdult'] ?? null) !== '1',
            self::TitleRatings => true,
        };
    }
}
