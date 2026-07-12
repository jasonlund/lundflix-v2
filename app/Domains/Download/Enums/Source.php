<?php

declare(strict_types=1);

namespace App\Domains\Download\Enums;

enum Source: string
{
    case BluRay = 'bluray';
    case WebDl = 'web-dl';
    case Web = 'web';
    case WebRip = 'web-rip';
    case Hdtv = 'hdtv';
    case BdRip = 'bd-rip';
    case DvdRip = 'dvd-rip';
    case Other = 'other';

    public static function fromName(string $name): self
    {
        // Order is load-bearing: BluRay wins over a co-occurring BDRip token, and
        // WEB-DL/WEBRip must be matched before the bare WEB fallback so a
        // separator'd token never collapses into Source::Web.
        if (preg_match('/bluray|bdmv|remux|bd(?:25|50|66|100)/i', $name)) {
            return self::BluRay;
        }

        if (preg_match('/bd[\s._-]*rip/i', $name)) {
            return self::BdRip;
        }

        if (preg_match('/web[\s._-]*dl/i', $name)) {
            return self::WebDl;
        }

        if (preg_match('/web[\s._-]*rip/i', $name)) {
            return self::WebRip;
        }

        // Bare-WEB fallback is deliberately case-sensitive (no /i) and boundary-anchored:
        // a scene source tag is all-caps "WEB", a title word is title-case "Web"
        // ("Charlotte's Web", "Cobweb"). Matching only an uppercase, letter-delimited
        // WEB token keeps a plain English title word from inflating to Source::Web.
        // A naive \bWEB\b is insufficient — it still hits the standalone title word.
        if (preg_match('/(?<![A-Za-z])WEB(?![A-Za-z])/', $name)) {
            return self::Web;
        }

        if (preg_match('/hdtv/i', $name)) {
            return self::Hdtv;
        }

        if (preg_match('/dvd[\s._-]*rip/i', $name)) {
            return self::DvdRip;
        }

        return self::Other;
    }

    public function priority(): int
    {
        return match ($this) {
            self::BluRay => 7,
            self::WebDl => 6,
            self::Web => 5,
            self::WebRip => 4,
            self::Hdtv => 3,
            self::BdRip => 2,
            self::DvdRip => 1,
            self::Other => 0,
        };
    }
}
