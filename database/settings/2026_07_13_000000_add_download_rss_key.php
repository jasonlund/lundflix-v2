<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Empty default only, same read-time coalesce as the other download
        // credentials (see create_download_settings) — never seed from env.
        $this->migrator->addEncrypted('download.rss_key', '');
    }
};
