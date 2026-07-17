<?php

declare(strict_types=1);

namespace App\Domains\Download\Enums;

enum Quality: string
{
    case P720 = '720p';
    case I720 = '720i';
    case P1080 = '1080p';
    case I1080 = '1080i';
    case P576 = '576p';
    case I576 = '576i';
    case P480 = '480p';
    case I480 = '480i';

    /**
     * Resolve the first token present, scanning cases in declared (priority)
     * order — so when several tokens co-occur the highest-priority one wins
     * (e.g. "1080p 720p" → P720). This encodes the deliberate 720-over-1080
     * preference (smaller 720p file preferred); it is NOT a bug to "fix".
     */
    public static function fromName(string $name): ?self
    {
        foreach (self::cases() as $case) {
            if (stripos($name, $case->value) !== false) {
                return $case;
            }
        }

        return null;
    }

    /**
     * TODO: retained for the upcoming download-picking logic (currently uncalled) —
     * ranks cases so the best available release can be selected.
     */
    public function priority(): int
    {
        return match ($this) {
            self::P720 => 7,
            self::I720 => 6,
            self::P1080 => 5,
            self::I1080 => 4,
            self::P576 => 3,
            self::I576 => 2,
            self::P480 => 1,
            self::I480 => 0,
        };
    }
}
