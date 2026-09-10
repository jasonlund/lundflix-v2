<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\UpdateTmdbPopularity;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Services\TmdbExportService;
use App\Domains\Catalog\Support\Batches;
use App\Domains\Common\Console\Concerns\EmitsHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Description('Refresh _tmdb_popularity on every held movie and show listed in the TMDB daily id exports')]
#[Signature('catalog:refresh-popularity')]
final class RefreshTmdbPopularity extends Command
{
    use EmitsHeartbeat;

    private const string MOVIE_EXPORT = 'movie_ids';

    private const string SHOW_EXPORT = 'tv_series_ids';

    /** Heartbeat tag, source-prefixed so a line names which pipeline emitted it. */
    private const string HEARTBEAT_TAG = 'tmdb popularity';

    /** Rows refreshed before a `[tmdb popularity n]` beat. */
    private const int HEARTBEAT_INTERVAL = 1000;

    /**
     * Exported rows per bulk update — a placeholder budget, not a memory one.
     * `BulkCaseUpdate` spends 2 bindings per column per matched row plus 1 for the
     * WHERE IN id, and this leg writes a single column with no updated_at binding,
     * so a fully-matched batch costs 3 a row: 4000 × 3 = 12,000 against MySQL's
     * 65,535 cap, the same ~5x headroom {@see ImdbSyncCommand} derives for the
     * other one-column feed.
     */
    private const int BATCH_SIZE = 4000;

    /**
     * Rows refreshed so far, carried across both exports: the operator watches one
     * catalog-wide figure, not a per-leg count that restarts halfway through.
     */
    private int $refreshed = 0;

    public function __construct(
        private readonly TmdbExportService $export,
        private readonly UpdateTmdbPopularity $updatePopularity,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->output->writeln('Refreshing movie popularity…');
        $this->refresh(self::MOVIE_EXPORT, Movie::query());

        $this->output->writeln('Refreshing show popularity…');
        $this->refresh(self::SHOW_EXPORT, Show::query());

        $this->flushTotal(self::HEARTBEAT_TAG, $this->refreshed);
        $this->output->writeln('Done.');

        return self::SUCCESS;
    }

    /**
     * Download one daily id export, stream it into bulk popularity writes, and
     * delete the temp file.
     *
     * The rows stream is consumed to exhaustion inside the try, which is what lets
     * the finally unlink: a partially read export leaves the gz handle open until
     * GC.
     *
     * @param  Builder<Movie>|Builder<Show>  $query
     */
    private function refresh(string $name, Builder $query): void
    {
        $file = $this->export->download($name);

        try {
            foreach (Batches::of($this->export->rows($file), self::BATCH_SIZE) as $rows) {
                $this->refreshed += $this->updatePopularity->handle($query, $rows);

                $this->beat(self::HEARTBEAT_TAG, $this->refreshed, self::HEARTBEAT_INTERVAL);
            }
        } finally {
            @unlink($file);
        }
    }
}
