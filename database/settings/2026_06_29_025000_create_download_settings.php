<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Register the keys with empty defaults only — the credential is
        // coalesced at read time in DownloadSearchService (stored value wins,
        // else env/config), so seeding from env here would freeze the env value
        // into the DB and stop later env changes propagating.
        $this->migrator->add('download.uid', '');
        $this->migrator->addEncrypted('download.pass', '');
    }
};
