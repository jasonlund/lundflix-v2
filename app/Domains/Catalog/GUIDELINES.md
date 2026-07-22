# Catalog — domain notes

Non-obvious contracts for the Catalog domain. Read when working in
`app/Domains/Catalog`. Universal conventions live in `.ai/guidelines/project.md`.

## Raw-source columns

External-source attributes are stored **raw**, one column each, prefixed
`_{source}_{rawAttr}` (e.g. `_imdb_averageRating`, `_tmdb_overview`). Column
order follows source priority: imdb → tmdb → tvdb. Persist the source value
verbatim; derive/normalize downstream, not on ingest.

**Exception — crosswalk ids SQL keys on** (`_imdb_id`, `_tmdb_id`): the raw stays
(e.g. `_tvdb_remoteIds`), but the queryable id is **normalized at write time**
through `Support\SourceId` (an upsert/`whereIn`/join key can't be a read accessor).
Malformed upstream → null. `Support\TvdbCrosswalk::normalize()` is the shared
remoteIds → `{_imdb_id, _tmdb_id}` derivation used by `UpsertTvdbShows`. See the
raw-source-prefix note in `.ai/guidelines/project.md` for the full rule.

## IMDb dataset streaming (`ImdbDatasetService`)

- `rows()` returns a `LazyCollection` over a gzip stream. The gz handle is closed
  in a `finally` that runs **only** when the generator completes or is GC'd —
  callers **MUST fully consume** the collection (`->all()`, or foreach to the
  end). Abandoning it part-way leaks the handle until GC.
- `count()` skips the header and blank lines, counting every data row, so a
  progress total matches the rows `rows()` actually yields.
- An empty gz body (valid magic, no content) surfaces a domain exception, not a
  raw `ValueError`.

## TMDB API (`TmdbApiService`)

- Batch fetch = one request per id via a single `Http::pool` per chunk, at most
  `concurrency` in flight; responses decode in input order.
- **Per-id 404 → `null`** (a miss, not a failure); does not sink siblings.
- **401 → throw immediately** — auth is fatal for the whole batch.
- Connection-level failures and responses still failing after retries are
  collected per-id; the rest still decode; failed ids surface together as one
  `TmdbRequestFailed::forIds`.
- A single GET normalizes a post-retry `ConnectionException` into
  `TmdbRequestFailed`, so single-request and batch callers see the same typed
  failure.

## Sync ordering (`catalog:sync-shows-tmdb`)

- **TVDB is the sole creator of `shows` rows** — `catalog:sync-shows-tmdb` never
  inserts a show; it only **hydrates by id** onto rows TVDB already created. So
  TVDB-first ordering is load-bearing: `catalog:seed-shows-tvdb` /
  `catalog:sync-shows-tvdb` must have run first, or there is nothing to hydrate.
- Hydrate phase — walks **our own** shows missing `tmdb_synced_at`, matched by
  `_tmdb_id`, and merges `_tmdb_*` metadata + artwork onto them. `--fresh`
  reprocesses every candidate; `--limit` caps the set.
- imdb-only rows (have `_imdb_id`, no `_tmdb_id`) are reconciled best-effort via
  TMDB `/find`, stamping the resolved `_tmdb_id` onto the row before hydrating. A
  resolved id already claimed by another row can't be re-pointed (UNIQUE
  `_tmdb_id`) — the row stays TVDB-only and the collision is reported, same as an
  empty `/find` result.
- Update-changed phase (default full run only, skipped under `--fresh`/`--limit`)
  — re-hydrates the intersection of the marker-derived changes window (see
  **Incremental sync markers** below) and rows we've already synced.

## TVDB sync split (`catalog:seed-shows-tvdb` / `catalog:sync-shows-tvdb`)

- `catalog:seed-shows-tvdb` — one-time manual bootstrap that crawls **every** TheTVDB
  series and upserts each. TheTVDB offers no re-download list, so failures heal
  **within the run**: one retry pass over the crawl's failures, then report the
  remainder. No persisted skip state.
  - `--ids-file=<path>` re-hydrates only the series ids in that single-line CSV
    (skipping the crawl) — the recovery path for the still-failing ids the run
    logged; a missing path refuses (exit 1) rather than falling back to the full
    crawl. No TMDB flag is needed: the rows it creates carry no `tmdb_synced_at`,
    so the next default `catalog:sync-shows-tmdb` hydrates them.
- `catalog:sync-shows-tvdb` — the incremental `/updates`-feed sync, wired into
  `catalog:sync`. Reads the `tvdb_shows` marker (see **Incremental sync markers**)
  for `since`, and advances it only on a clean, unbounded run; idempotent upserts
  make the overlap re-processing harmless.
- `seriesMany(array $ids)` returns a `PooledResult` — the input-ordered id →
  raw body map (`null` on 404) plus `failedIds` (non-404 http/connection
  failures). Callers upsert the bodies and feed `failedIds` back for retry.

## TVDB episodes sync (`catalog:sync-episodes-tvdb`)

- The incremental `/updates?type=episodes` sync. Reads the `tvdb_episodes` marker
  (see **Incremental sync markers**) for `since` — a 6h overlap, 24h first-run
  fallback, capped at 14 days — and advances it only on a clean, unbounded run;
  idempotent upserts make the overlap re-processing harmless. The `catch` is
  narrowed to `TvdbRequestFailed`/`TvdbAuthenticationFailed` so a real bug (e.g. a
  `QueryException`) surfaces instead of being swallowed as a fetch failure.
- Refreshes **only already-seeded shows** — the working set is the feed's
  `seriesId`s intersected with `whereNotNull('episodes_synced_at')`. A show is
  "episode-seeded" once `SeedTvdbEpisodes` stamps `episodes_synced_at`; the
  **on-demand seed trigger is a separate consumer** (out of scope of FLIX-197), so
  the command is intentionally dormant until that consumer exists and stamps the
  first shows.
- **Season resolution** — `SeedTvdbEpisodes` resolves each episode's `season_id`
  by matching its `_tvdb_seasonNumber` against the show's seasons filtered to
  `_tvdb_type->id === $show._tvdb_defaultSeasonType` (the default ordering the
  episodes were fetched under). Custom orderings (DVD/absolute/alternate) are
  deferred to FLIX-225.

## Incremental sync markers (`SyncMarker` / `SyncFeed`)

All four catalog syncs (`catalog:sync-movies`, `catalog:sync-shows-tmdb`,
`catalog:sync-shows-tvdb`, `catalog:sync-episodes-tvdb`) fetch only what changed
since their last successful run via a per-feed cache marker — no fixed rolling
window.

- `SyncMarker` (`Support/`) owns read + advance. `window(SyncFeed)` derives the
  fetch interval as a `SyncWindow` VO: `since` = marker − 6h overlap (24h fallback
  when unset), floored at `now − 14d` (TMDB's max `/changes` span; TVDB matched for
  parity). `advance(SyncFeed, $startedAt)` persists **run-start** via
  `Cache::forever` — one key per `SyncFeed` case (`TvdbShows`/`TvdbEpisodes`/
  `TmdbShows`/`TmdbMovies`), so the four feeds advance independently.
- **Zero-failure gate:** a run advances its marker only if it finished with **no**
  failed ids/chunks **and** no `--limit`; `--fresh` still advances (clean
  baseline). A per-id hydrate failure counts — the pooled `movies()`/`tvShows()`/
  `seriesMany()` results **drop a failed id's key** (report-not-throw), so a short
  result count (`count($results) < count(array_unique($ids))`) flags the failure
  and holds the marker. Any failure → marker unchanged → the next run re-covers the
  whole gap (idempotent upserts make that safe). A cache flush just drops to the
  24h fallback, not data loss.

## Ratings update (`UpdateImdbRatings`)

Ratings apply as a **single bulk CASE update per table** (Movie, Show), returning
the matched count. CASE bindings live in the query's join-binding slot — see the
in-code comment for the binding-order mechanics before touching it.
