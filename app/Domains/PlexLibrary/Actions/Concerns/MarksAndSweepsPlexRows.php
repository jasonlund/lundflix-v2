<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Actions\Concerns;

use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexServer;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The mark-and-sweep skeleton shared by the Plex reconcilers: a page upsert that
 * stamps `synced_at = $now`, then a sweep of whatever that pass left behind. The
 * per-reconciler differences are injected via the abstract hooks below — the
 * table through `model()`, the row shape through {@see rowFor()}, and any
 * extra work around the write through {@see writePage()}.
 */
trait MarksAndSweepsPlexRows
{
    /**
     * The upsert conflict target (the table's unique index) — and therefore the
     * columns an update must leave alone.
     *
     * @var list<string>
     */
    private const array UNIQUE_BY = ['plex_server_id', '_plex_ratingKey'];

    /**
     * Stamps every row `synced_at = $now`. Never deletes — the sweep is {@see prune()}.
     *
     * @param  array<int, array<string, mixed>>  $page  decoded Plex "section all" Metadata items
     */
    public function upsertPage(PlexServer $server, PlexLibrary $library, array $page, CarbonInterface $now): int
    {
        if ($page === []) {
            return 0;
        }

        // Re-indexed, so the first row is addressable as the column-shape sample below.
        $rows = array_map(
            fn (array $item): array => $this->rowFor($server, $library, $item, $now),
            array_values($page),
        );

        $this->writePage($server, $rows);

        return count($rows);
    }

    /**
     * Sweep this library's rows left behind by the pass that stamped `$now` —
     * mark-and-sweep, so no per-row bindings.
     *
     * Strictly `<`: `synced_at` is second-precision, so a row written during the
     * same second as `$now` must survive — the failure direction has to be
     * under-delete, never over-delete.
     */
    public function prune(PlexServer $server, PlexLibrary $library, CarbonInterface $now): void
    {
        $model = static::model();

        $model::query()
            ->where('plex_server_id', $server->id)
            ->where('plex_library_id', $library->id)
            ->where('synced_at', '<', $now)
            ->delete();
    }

    /**
     * The model whose table this reconciler marks and sweeps.
     *
     * @return class-string<Model>
     */
    abstract protected static function model(): string;

    /**
     * The page write, as its own seam: a reconciler that has to observe the
     * stored rows before the upsert overwrites them overrides this and calls
     * {@see upsertRows()} itself.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function writePage(PlexServer $server, array $rows): void
    {
        $this->upsertRows($rows);
    }

    /**
     * Update every column the row carries except the conflict target, whose
     * shape is sampled off the first (re-indexed) row.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function upsertRows(array $rows): void
    {
        $updateColumns = array_values(array_diff(array_keys($rows[0]), self::UNIQUE_BY));

        $model = static::model();

        $model::upsert($rows, self::UNIQUE_BY, $updateColumns);
    }

    /**
     * Build a cast-bypassing row for `Model::upsert()`: values are pre-rendered
     * — json-encoded arrays, datetime-string timestamps — since `upsert()`
     * writes raw values without invoking the model's casts.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    abstract protected function rowFor(PlexServer $server, PlexLibrary $library, array $item, CarbonInterface $now): array;
}
