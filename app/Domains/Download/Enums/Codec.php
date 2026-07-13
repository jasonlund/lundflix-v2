<?php

declare(strict_types=1);

namespace App\Domains\Download\Enums;

enum Codec: string
{
    case Av1 = 'av1';
    case Hevc = 'hevc';
    case X264 = 'x264';
    case Xvid = 'xvid';
    case Other = 'other';

    public static function fromName(string $name): self
    {
        // AV1 is checked first so it wins over any co-occurring HEVC/x264 token in the same name.
        if (preg_match('/av1/i', $name)) {
            return self::Av1;
        }

        // HEVC/x265/h265 must be checked before x264 so HEVC wins over any x264/h264 token in the same name.
        if (preg_match('/hevc|[xh][\s._-]*265/i', $name)) {
            return self::Hevc;
        }

        if (preg_match('/[xh][\s._-]*264/i', $name)) {
            return self::X264;
        }

        if (preg_match('/xvid|divx/i', $name)) {
            return self::Xvid;
        }

        return self::Other;
    }

    public function priority(): int
    {
        return match ($this) {
            self::Av1 => 0,
            self::Hevc => 1,
            self::X264 => 2,
            self::Xvid => 3,
            self::Other => 4,
        };
    }
}
