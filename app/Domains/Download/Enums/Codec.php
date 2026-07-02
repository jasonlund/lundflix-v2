<?php

declare(strict_types=1);

namespace App\Domains\Download\Enums;

enum Codec: string
{
    case Hevc = 'hevc';
    case X264 = 'x264';
    case Other = 'other';

    public static function fromName(string $name): self
    {
        // HEVC/x265/h265 must be checked first so HEVC wins over any x264/h264 token in the same name.
        if (preg_match('/hevc|[xh][\s.]*265/i', $name)) {
            return self::Hevc;
        }

        if (preg_match('/[xh][\s.]*264/i', $name)) {
            return self::X264;
        }

        return self::Other;
    }

    public function priority(): int
    {
        return match ($this) {
            self::Hevc => 0,
            self::X264 => 1,
            self::Other => 2,
        };
    }
}
