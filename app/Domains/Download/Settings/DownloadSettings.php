<?php

declare(strict_types=1);

namespace App\Domains\Download\Settings;

use Spatie\LaravelSettings\Settings;

final class DownloadSettings extends Settings
{
    public string $uid;

    public string $pass;

    public static function group(): string
    {
        return 'download';
    }

    /** @return list<string> */
    #[\Override]
    public static function encrypted(): array
    {
        return ['pass'];
    }
}
