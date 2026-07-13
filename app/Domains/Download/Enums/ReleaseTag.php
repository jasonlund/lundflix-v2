<?php

declare(strict_types=1);

namespace App\Domains\Download\Enums;

enum ReleaseTag: string
{
    case Proper = 'proper';
    case Repack = 'repack';
    case None = 'none';

    public static function fromName(string $name): self
    {
        // Proper is checked first: a PROPER-of-a-REPACK name carries both tokens,
        // and the higher-signal PROPER must win.
        if (preg_match('/proper/i', $name)) {
            return self::Proper;
        }

        if (preg_match('/repack/i', $name)) {
            return self::Repack;
        }

        return self::None;
    }

    public function priority(): int
    {
        return match ($this) {
            self::Proper => 2,
            self::Repack => 1,
            self::None => 0,
        };
    }
}
