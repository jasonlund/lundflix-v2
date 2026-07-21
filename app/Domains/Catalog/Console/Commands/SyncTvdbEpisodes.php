<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\SeedTvdbEpisodes;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Services\TvdbApiService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Description('Sync TheTVDB episodes for already-seeded shows from the rolling 14-day updates feed')]
#[Signature('catalog:sync-episodes-tvdb {--limit=}')]
class SyncTvdbEpisodes extends Command
{
    /**
     * Rolling overlap window fed to the /updates feed. Far wider than the 12h
     * sync cadence: a dropped update is re-covered by ~28 later runs, and
     * idempotent upserts make the re-processing harmless — so the window itself
     * is the self-heal, needing zero persisted state (no marker, no skip set).
     */
    private const int WINDOW_DAYS = 14;

    public function handle(TvdbApiService $api, SeedTvdbEpisodes $seed): int
    {
        $since = now()->subDays(self::WINDOW_DAYS)->timestamp;

        $seriesIds = collect($api->updates($since, 'episodes'))
            ->pluck('seriesId')
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $query = Show::query()
            ->whereIn('_tvdb_id', $seriesIds->all())
            ->whereNotNull('episodes_synced_at');

        $limit = $this->option('limit');
        if ($limit !== null) {
            $query->limit((int) $limit);
        }

        // Read-only walk over a bounded, --limit-capped set that dispatches a
        // per-show seed; not an iterate-and-write over these rows, so chunkById
        // does not apply. Report-and-continue so one bad show can't abort the run.
        foreach ($query->get() as $show) {
            try {
                $seed->handle($show);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
