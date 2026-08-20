<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('catalog:sync')->twiceDaily(0, 12)->timezone('America/Los_Angeles')->withoutOverlapping();

// 06:00 sits halfway between catalog:sync's 00:00 and 12:00 starts, so the ~600MB IMDb dataset download never overlaps the TMDB/TVDB sync.
Schedule::command('catalog:sync-imdb')->dailyAt('06:00')->timezone('America/Los_Angeles')->withoutOverlapping();

Schedule::command('plex:sync')->everyMinute()->withoutOverlapping(30);
