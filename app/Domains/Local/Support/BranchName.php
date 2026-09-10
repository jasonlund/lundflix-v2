<?php

declare(strict_types=1);

namespace App\Domains\Local\Support;

use Illuminate\Support\Str;

final readonly class BranchName
{
    /**
     * The title slug's whole share of the name, beside the ticket ids that prefix it.
     * Nothing downstream trims further — `WorkspaceName`'s 40-character cut guards the
     * workspace slug, not this — so this budget is what keeps a Linear title from
     * running past 60 characters into the worktree directory, Herd site and database
     * name derived from the branch.
     */
    private const int MAX_TITLE_LENGTH = 20;

    /**
     * @param  list<string>  $ticketIds
     */
    public static function derive(array $ticketIds, string $title): string
    {
        // Slugging each id (rather than just lowercasing it) absorbs the space
        // `explode(',', 'FLIX-303, FLIX-302')` leaves on every id past the first.
        $ids = collect($ticketIds)
            ->map(fn (string $id): string => Str::slug($id))
            ->reject(fn (string $id): bool => $id === '')
            ->implode('-');

        // A part that slugs away to nothing is dropped rather than joined, so neither an
        // empty ticket id nor an unsluggable title can leave a leading, doubled or
        // trailing hyphen. Both empty derives '', which fails visibly where the caller
        // captures it — a lone `-` would pass for a branch name.
        return collect([$ids, self::titleSlug($title)])
            ->reject(fn (string $part): bool => $part === '')
            ->implode('-');
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
