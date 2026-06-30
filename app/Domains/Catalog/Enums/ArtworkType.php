<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

enum ArtworkType: string
{
    case Poster = 'poster';
    case Backdrop = 'backdrop';
    case Logo = 'logo';

    public static function fromTvdb(int $code): ?self
    {
        return match ($code) {
            2 => self::Poster,
            3 => self::Backdrop,
            23 => self::Logo,
            default => null,
        };
    }

    public function defaultSize(): string
    {
        return match ($this) {
            self::Poster => 'w500',
            self::Backdrop => 'w1280',
            self::Logo => 'w300',
        };
    }
}
