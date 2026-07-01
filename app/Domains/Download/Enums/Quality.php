<?php

declare(strict_types=1);

namespace App\Domains\Download\Enums;

enum Quality: string
{
    case P1080 = '1080p';
    case P720 = '720p';

    public static function fromName(string $name): ?self
    {
        // 720 must be checked first so it wins over a 1080 token in the same name.
        if (str_contains($name, '720')) {
            return self::P720;
        }

        if (str_contains($name, '1080')) {
            return self::P1080;
        }

        return null;
    }
}
