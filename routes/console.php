<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The mutex expiry must outlive a real run but die well before the next tick: a
// run killed mid-flight (SIGKILL, OOM on the 2GB box, deploy restart) never
// releases its lock, and Laravel's default 1440-minute expiry would still hold it
// when the next start comes round — skipping that run entirely.
// A scheduled incremental sync takes minutes, so 6h is generous cover inside the 12h gap.
Schedule::command('catalog:sync')->twiceDaily(0, 12)->timezone('America/Los_Angeles')->withoutOverlapping(360);

// 06:00 sits halfway between catalog:sync's 00:00 and 12:00 starts, so the ~600MB IMDb dataset download never overlaps the TMDB/TVDB sync.
// 10h covers the worst IMDb run (~600MB download plus the full import) and still
// clears a stale lock 14h before the next daily start.
Schedule::command('catalog:sync-imdb')->dailyAt('06:00')->timezone('America/Los_Angeles')->withoutOverlapping(600);

// 03:00 Sunday is the widest gap from catalog:sync (00:00/12:00) and catalog:sync-imdb (06:00):
// this leg streams ~1.2M movie ids plus ~230k series ids and must contend with neither.
// Weekly is enough — popularity moves slowly and the run costs zero API calls (it reads only
// the daily id exports from the file host), but operator-only is what froze the value at seed time.
// 360 matches catalog:sync: generous cover for a run measured in minutes, yet dead long before
// the next weekly tick, so a run killed mid-flight can't hold its lock into the following week.
Schedule::command('catalog:refresh-popularity')->weeklyOn(0, '03:00')->timezone('America/Los_Angeles')->withoutOverlapping(360);

Schedule::command('plex:sync')->everyMinute()->withoutOverlapping(30);
