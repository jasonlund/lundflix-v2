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
        if (preg_match('/bluray|bdmv|remux/i', $name)) {
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

        if (preg_match('/web/i', $name)) {
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
