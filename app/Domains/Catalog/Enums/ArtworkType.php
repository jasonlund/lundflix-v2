<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

enum ArtworkType: string
{
    case Poster = 'poster';
    case Backdrop = 'backdrop';
    case Logo = 'logo';

    public function defaultSize(): string
    {
        return match ($this) {
            self::Poster => 'w500',
            self::Backdrop => 'w1280',
            self::Logo => 'w300',
        };
    }
}
