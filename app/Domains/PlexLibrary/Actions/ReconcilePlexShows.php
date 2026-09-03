<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Actions;

use App\Domains\PlexLibrary\Actions\Concerns\MarksAndSweepsPlexRows;
use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexServer;
use App\Domains\PlexLibrary\Models\PlexShow;
use App\Domains\PlexLibrary\Support\PlexGuids;
use App\Domains\PlexLibrary\Support\PlexTimestamp;
use Carbon\CarbonInterface;
use stdClass;

final readonly class ReconcilePlexShows
{
    use MarksAndSweepsPlexRows;

    /**
     * @return class-string<PlexShow>
     */
    protected static function model(): string
    {
        return PlexShow::class;
    }

    /**
     * Also clears `episodes_synced_at` on the shows that moved.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function writePage(PlexServer $server, array $rows): void
    {
        // Diff BEFORE the write: the upsert overwrites the very
        // `_plex_updatedAt`/`_plex_leafCount` we compare on, so a post-write read
        // could never tell a moved show apart from an unchanged one.
        $changedRatingKeys = $this->changedRatingKeys($server, $rows);

        $this->upsertRows($rows);

        $this->markChanged($server, $changedRatingKeys);
    }

    /**
     * Snapshots the stored values, so it must run before the upsert overwrites them.
     * `->toBase()` with a three-column select: hydrating `PlexShow` models would cost
     * a full row and a model per show for a comparison.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<string>
     */
    private function changedRatingKeys(PlexServer $server, array $rows): array
    {
        $stored = PlexShow::query()
            ->where('plex_server_id', $server->id)
            ->whereIn('_plex_ratingKey', array_column($rows, '_plex_ratingKey'))
            ->toBase()
            ->get(['_plex_ratingKey', '_plex_updatedAt', '_plex_leafCount'])
            ->keyBy('_plex_ratingKey');

        return collect($rows)
            ->filter(fn (array $row): bool => $this->isChanged($row, $stored->get($row['_plex_ratingKey'])))
            ->pluck('_plex_ratingKey')
            ->values()
            ->all();
    }

    /**
     * Clear the episode watermark of the shows that moved, so the crawl set can be
     * read back off the table instead of accumulated across a run's pages.
     *
     * @param  list<string>  $changedRatingKeys
     */
    private function markChanged(PlexServer $server, array $changedRatingKeys): void
    {
        if ($changedRatingKeys === []) {
            return;
        }

        PlexShow::query()
            ->where('plex_server_id', $server->id)
            ->whereIn('_plex_ratingKey', $changedRatingKeys)
            ->update(['episodes_synced_at' => null]);
    }

    /**
     * The snapshot is a base row, so `_plex_updatedAt` arrives as the driver's raw
     * text with no Carbon cast — compared against the same `toDateTimeString()`
     * rendering the write path stores, so the two sides can't differ by format alone.
     *
     * @param  array<string, mixed>  $row
     */
    private function isChanged(array $row, ?object $stored): bool
    {
        if (! $stored instanceof stdClass) {
            return true;
        }

        $updatedAtMoved = $row['_plex_updatedAt'] !== $stored->_plex_updatedAt;
        $leafCountMoved = (int) $row['_plex_leafCount'] !== (int) $stored->_plex_leafCount;

        return $updatedAtMoved || $leafCountMoved;
    }

    /**
     * Values are pre-rendered — json-encoded array, datetime-string timestamps —
     * because `Model::upsert()` never runs the model's casts.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function rowFor(PlexServer $server, PlexLibrary $library, array $item, CarbonInterface $now): array
    {
        $ids = PlexGuids::extract($item);

        return [
            'plex_server_id' => $server->id,
            'plex_library_id' => $library->id,
            '_plex_ratingKey' => (string) $item['ratingKey'],
            '_plex_guid' => $item['guid'],
            '_plex_guids' => isset($item['Guid']) ? json_encode($item['Guid']) : null,
            '_plex_title' => $item['title'],
            '_plex_year' => $item['year'] ?? null,
            '_plex_leafCount' => $item['leafCount'] ?? null,
            '_plex_childCount' => $item['childCount'] ?? null,
            '_plex_addedAt' => PlexTimestamp::fromEpoch($item['addedAt'] ?? null)?->toDateTimeString(),
            '_plex_updatedAt' => PlexTimestamp::fromEpoch($item['updatedAt'] ?? null)?->toDateTimeString(),
            '_imdb_id' => $ids['imdb'],
            '_tmdb_id' => $ids['tmdb'],
            '_tvdb_id' => $ids['tvdb'],
            'synced_at' => $now->toDateTimeString(),
        ];
    }
}
