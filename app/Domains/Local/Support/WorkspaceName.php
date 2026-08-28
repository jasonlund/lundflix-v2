<?php

declare(strict_types=1);

namespace App\Domains\Local\Support;

use Illuminate\Support\Str;

final class WorkspaceName
{
    /**
     * Branch names are ≤ 40 characters by convention, so this trims only a slug that
     * broke it — well before the ceilings it guards: MySQL caps a database name at 64
     * (`lf_` + 40 = 43), and the TLS certificate common name Herd issues for
     * `lf-<name>.test` (48) at 64 too. The digest is spent from this budget, not added
     * to it, so a trimmed name is never longer than an untrimmed one.
     */
    private const int MAX_BRANCH_LENGTH = 40;

    /**
     * 16^6 ≈ 16.8M values, so a handful of same-headed branches collide at roughly
     * one in a million — cheap next to what a collision costs below.
     */
    private const int DIGEST_LENGTH = 6;

    /**
     * The branch slug a workspace directory carries, with the repo's own slug
     * dropped from the front only — a branch may legitimately repeat the project
     * name later in the slug (`lundflix-v2-lundflix-v2-tweaks`).
     */
    public static function branch(string $workspace, string $project): string
    {
        $prefix = $project.'-';

        $branch = Str::startsWith($workspace, $prefix)
            ? Str::substr($workspace, Str::length($prefix))
            : $workspace;

        return Str::length($branch) <= self::MAX_BRANCH_LENGTH
            ? $branch
            : self::trimToBudget($branch);
    }

    public static function site(string $branch): string
    {
        return 'lf-'.$branch;
    }

    public static function database(string $branch): string
    {
        return 'lf_'.Str::replace('-', '_', $branch);
    }

    /**
     * Two branches sharing their first 40 characters would otherwise cut to the same
     * name, and `up` creates the database with IF NOT EXISTS — so the second workspace
     * silently adopts the first's database, `refresh` then runs `migrate:fresh` and
     * destroys the first's data while both runs report success, and `down` drops the
     * database out from under whichever workspace is left. The digest covers the full
     * slug so the trimmed names differ; it is a uniqueness device, not a security
     * hash, so sha1 is the right tool.
     */
    private static function trimToBudget(string $branch): string
    {
        $digest = Str::substr(sha1($branch), 0, self::DIGEST_LENGTH);

        // The cut can land on a separator, which would double up against the digest's
        // own separator and trail into the site and database names.
        $kept = Str::rtrim(
            Str::substr($branch, 0, self::MAX_BRANCH_LENGTH - self::DIGEST_LENGTH - 1),
            '-',
        );

        return $kept.'-'.$digest;
    }
}
