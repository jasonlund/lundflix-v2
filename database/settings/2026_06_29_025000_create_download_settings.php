<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('download.uid', (string) config('services.downloads.uid'));
        $this->migrator->addEncrypted('download.pass', (string) config('services.downloads.pass'));
    }
};
