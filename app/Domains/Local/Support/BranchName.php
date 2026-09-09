<?php

declare(strict_types=1);

namespace App\Domains\Local\Support;

use Illuminate\Support\Str;

final readonly class BranchName
{
    /**
     * The title's share of the ≤ 40-character branch convention, leaving room for the
     * ticket ids that prefix it.
     */
    private const int MAX_TITLE_LENGTH = 20;

    /**
     * @param  list<string>  $ticketIds
     */
    public static function derive(array $ticketIds, string $title): string
    {
        $ids = collect($ticketIds)
            ->map(fn (string $id): string => Str::lower($id))
            ->implode('-');

        return $ids.'-'.self::titleSlug($title);
    }

    private static function titleSlug(string $title): string
    {
        $slug = Str::slug($title);

        if (Str::length($slug) <= self::MAX_TITLE_LENGTH) {
            return $slug;
        }

        // One character past the budget: when that character is the separator, the words
        // before it already fill the budget exactly, and a window cut at the budget would
        // find no separator there and backtrack to the word before.
        $window = Str::substr($slug, 0, self::MAX_TITLE_LENGTH + 1);

        // beforeLast() hands back the whole subject when the separator is absent, so a
        // first word longer than the budget would keep the entire window — one character
        // over. The hard cut is what holds the budget for a title with no word boundary.
        return Str::contains($window, '-')
            ? Str::beforeLast($window, '-')
            : Str::substr($slug, 0, self::MAX_TITLE_LENGTH);
    }
}
