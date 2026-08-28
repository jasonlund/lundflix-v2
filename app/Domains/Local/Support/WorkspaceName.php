<?php

declare(strict_types=1);

namespace App\Domains\Local\Support;

use Illuminate\Support\Str;

final class WorkspaceName
{
    /**
     * MySQL caps a database name at 64 characters, so the branch slug is cut here
     * to leave the `lf_` prefix (and any future suffix) room to fit.
     */
    private const int MAX_BRANCH_LENGTH = 40;

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

        // The cut can land on a separator, which would trail into the site and
        // database names.
        return Str::rtrim(Str::substr($branch, 0, self::MAX_BRANCH_LENGTH), '-');
    }

    public static function site(string $branch): string
    {
        return 'lf-'.$branch;
    }

    public static function database(string $branch): string
    {
        return 'lf_'.Str::replace('-', '_', $branch);
    }
}
