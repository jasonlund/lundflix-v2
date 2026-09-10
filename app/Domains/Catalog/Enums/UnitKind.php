<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

/**
 * The kinds of acquirable unit the catalog can name — the atoms a download
 * targets, as opposed to the containers (a show, a season) that hold them.
 */
enum UnitKind: string
{
    case Movie = 'movie';
    case Episode = 'episode';
}
