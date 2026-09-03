<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Models;

use App\Domains\PlexLibrary\Database\Factories\PlexLibraryFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class PlexLibrary extends Model
{
    /** @use HasFactory<PlexLibraryFactory> */
    use HasFactory;

    protected static function newFactory(): Factory
    {
        return PlexLibraryFactory::new();
    }
}
