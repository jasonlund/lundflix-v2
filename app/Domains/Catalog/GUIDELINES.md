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
through `Common\Support\SourceId` (an upsert/`whereIn`/join key can't be a read
accessor).
Malformed upstream → null. `Support\TvdbCrosswalk::normalize()` is the shared
remoteIds → `{_imdb_id, _tmdb_id}` derivation used by `UpsertTvdbShows`. See the
raw-source-prefix note in `.ai/guidelines/project.md` for the full rule.

## Refused titles (`Models\Concerns\Refusable`)

A **refused title** (`CONTEXT.md`) — adult, softcore, or promo — is stored and
filtered at read, never dropped at ingest (ADR-0004). The trade has two halves and
the second is the one that rots:

- **No ingest leg filters.** Every source's refusal flags are ordinary raw-source
  columns (`_imdb_isAdult`, `_tmdb_adult`, `_tmdb_softcore`, and movies-only
  `_tmdb_video` — TMDB `/tv` carries no `video` key). They upsert like any other
  column, so a refused record gets its `*_synced_at` stamp and the membership probe
  stops re-fetching it. Do not re-add a drop anywhere; the five that used to exist
  are what made ~94% of the movies export sweep unpersistable.
- **Every read path carries the filter, and that is not optional.** `Refusable`
  gives `Movie` and `Show` an `isRefused()` and a `notRefused()` query scope, and
  overrides Scout's `shouldBeSearchable()` (resolved `insteadof Searchable` in each
  model). A new listing, API surface, or export **must** apply `notRefused()` —
  nothing enforces it but this line, because the rows are really there.
- **A row that becomes refused must leave the index, not merely stop entering it.**
  `ReindexTouchedRows` partitions each chunk and calls `unsearchable()` on the
  refused share; Scout's *Collection* `searchable()` macro does no
  `shouldBeSearchable()` filtering of its own (only its *builder* macro does), so
  the partition is load-bearing rather than belt-and-braces.

## IMDb dataset streaming (`ImdbDatasetService`)

- **`ImdbDataset` (enum) owns each dataset's filename and cast map**; the service
  is dataset-agnostic — `download()`/`rows()` take the case. Cast types: `int`,
  `float`, `bool`, `array` (comma-split, e.g. basics `genres`), and `multi`
  (split on `\x02`, which is how akas `types`/`attributes` pack multiple values
  upstream).
- `rows()` returns a `LazyCollection` over a gzip stream. The gz handle is closed
  in a `finally` that runs **only** when the generator completes or is GC'd —
  callers **MUST fully consume** the collection (`->all()`, or foreach to the
  end). Abandoning it part-way leaks the handle until GC.
- `count()` skips the header and blank lines, counting every data row, so a
  progress total matches the rows `rows()` actually yields.
- An empty gz body (valid magic, no content) surfaces a domain exception, not a
  raw `ValueError`.

## IMDb dataset ingest (`catalog:sync-ratings` / `-titles` / `-akas`)

Three commands, one per title-level dataset, all keyed `tconst` → `_imdb_id`. They
run under the daily `catalog:sync-imdb` wrapper (`SyncImdbCatalog`), not inside
`catalog:sync` — the datasets are far too large to pull on that twice-daily sync.
Each is gated on its own `Last-Modified` header, so an unchanged dataset skips in
seconds. TMDB/TVDB still create the rows; IMDb enriches them.

- **Titles and akas write only rows already in the catalog, and decide that per
  batch** — every streamed row/group is buffered, and `flush()` resolves membership
  against just that batch's ids via `Support\CatalogImdbIds::existing()`: two
  bounded `in (…)` reads (movies + shows) per flush, so nothing about the catalog's
  size is ever held in memory. Unmatched entries are dropped before the importer
  builds any values, and a batch left with nothing to write returns silently — no
  importer call, no heartbeat (a real run flushes thousands of zero-match batches).
- **Why the explicit probe, when ratings gets by without one?** Ratings buffers every
  streamed row and lets `BulkCaseUpdate`'s own `WHERE IN` be the membership check.
  Mirroring that here would make `ImportImdbAkas::akasColumn()` json-encode ~10M title
  groups instead of ~265k, because title.akas covers far more titles than the catalog
  holds. The cheap id probe keeps the CASE batches dense and the encode work
  proportional to matches, at the cost of two extra bounded queries per flush.
- **`isAdult` is persisted like any other basics column**, and refusal is filtered
  at read via `Models\Concerns\Refusable` (`isRefused()` / the `notRefused()` scope)
  — ADR-0004. The leg withholds nothing, so it has no skip tally to report; a
  dropped row would never get its `*_synced_at` stamp and would be re-fetched every
  run. The TMDB legs no longer filter adult/softcore either, so no source-side
  filter is left for this one to compensate for.
- **akas group per title** — the file is sorted contiguously by `titleId`, so
  rows accumulate until it changes. The last group never sees a change: closing
  it after the loop only *buffers* it, so the trailing `flush()` is what writes
  it. Neither is redundant.
- **Batch sizes differ per dataset and bound the buffer, not the write.** Both
  buffers hold **raw dataset rows/groups** — matched or not — and `BulkCaseUpdate`
  re-narrows to the probed ids before building any CASE, so the write side only ever
  sees the catalog's share of a batch. Titles' 2000 still respects the placeholder
  ceiling it was picked for (2 bindings per column per row plus the `WHERE IN` id =
  17/row over 8 columns, against MySQL's 65,535 cap less the per-statement
  `updated_at` binding = 3854 rows) — and because that is now below the shared
  `MAX_BATCH_SIZE` of 4000, **titles carries its own lower `--batch` ceiling** of
  3500 via `maxBatchSize()`; the shared constant still fits ratings (2 columns) and
  akas (1) with room to spare. Akas' 1000 is now a
  **memory** bound: one entry is a whole title's aka group and a popular title carries
  100+ rows, so raising it is the risk, not lowering it. Both commands take `--batch=`
  to override.

## Bulk CASE updates (`Support\BulkCaseUpdate`)

All three IMDb ingest actions write via one bulk `CASE _imdb_id WHEN … END`
update per table, returning the matched ids. The caller does **not** index them —
search indexing is deferred to one watermark pass at the end of the whole
`catalog:sync-imdb` run, which is why the update also stamps `updated_at` (a bare
`toBase()->update()` would not, and the watermark would miss every row it wrote).
A leg run standalone (`catalog:sync-ratings` and friends) therefore does not
reindex; only the wrapper does. CASE bindings live in the query's
**join**-binding slot and are appended,
never replaced — see the in-code comment for the ordering mechanics before
touching it. A bulk update bypasses Eloquent's casts, so `array`-cast columns
(`_imdb_genres`, `_imdb_akas`) must be json-encoded on the way in.

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

## TMDB movies sync/seed split (`catalog:sync-movies` / `catalog:seed-movies`)

Mirrors the TVDB split below, for the same reason: an incremental leg on the
schedule, a full-dataset leg only an operator runs.

- `catalog:sync-movies` — the scheduled leg, wired into `catalog:sync`. **One pass
  over the `/movie/changes` window and nothing else** — it never downloads the ids
  export, and takes no `--fresh` (the window comes from the marker, so there is
  nothing to be fresh about).
  - **Insert and refresh are one pass.** Each feed slice is probed once via
    `syncedIdsAmong()`; held ids refresh, unheld ids insert. The two were only ever
    separate phases because they had separate *sources* — they ask the same question
    from opposite sides of that probe.
  - **Two heartbeat tags inside the one pass**, because an operator reads insert
    volume and refresh volume as different facts: `[tmdb movies n]` for a refresh,
    `[new tmdb movies n]` for an insert.
- `catalog:seed-movies` — the full ids-export scan (~1.23M rows), **operator-invoked
  and on no schedule** (`CatalogScheduleTest` guards the omission). It is the remedy
  for a marker stale past the cap, and what `catalog:sync --fresh` dispatches in the
  incremental leg's place. `--fresh` here skips the already-synced probe and
  re-hydrates every exported row.
- **Why the export left the schedule (FLIX-289).** That phase alone exceeded an hour
  per production run and did not shrink as the catalog converged. FLIX-286 measured
  the feed against the live API: `/movie/changes` reported 465/465 of the ids added
  between two daily export snapshots and 112/112 of those removed, so the export
  contributed **no unique discovery**. Of the ~66k ids it re-hydrated every run, ~94%
  were `video:true` promo records that `payloads()` then dropped pre-upsert — never
  stamped `tmdb_synced_at`, so the probe reported them missing forever. FLIX-290
  removed that drop: a promo record now persists and stamps like any other, so the
  export sweep converges instead of re-paying those hydrations every run.
- **A blind reconciliation sweep is deliberately NOT scheduled.** It would mask the
  marker-stall bug that caused FLIX-289, and the capped-window guard below is the
  cheaper alarm.

### Feed-driven insert is opt-in (`insertHeartbeatTag()`)

`TmdbSyncCommand::insertHeartbeatTag()` returns `null` by default, meaning **refresh
only**: an unheld changed id is left to whatever other phase owns discovery. A
non-null tag opts the leg into inserting from the feed *and* names the second tag
`closeRun()` flushes — one seam, both uses, so there is no companion boolean.

Only `catalog:sync-movies` opts in. **`catalog:sync-shows-tmdb` must not**: TVDB is
the sole creator of `shows` rows (see below), so an unheld `/tv/changes` id would
create a show with no TVDB identity. That leg's own residue is FLIX-291.

## Sync ordering (`catalog:sync-shows-tmdb`)

- **TVDB is the sole creator of `shows` rows** — `catalog:sync-shows-tmdb` never
  inserts a show; it only **hydrates by id** onto rows TVDB already created. So
  TVDB-first ordering is load-bearing: `catalog:seed-shows-tvdb` /
  `catalog:sync-shows-tvdb` must have run first, or there is nothing to hydrate.
- Hydrate phase — walks **our own** shows missing `tmdb_synced_at`, matched by
  `_tmdb_id`, and merges `_tmdb_*` metadata + artwork onto them. `--fresh`
  reprocesses every candidate.
- imdb-only rows (have `_imdb_id`, no `_tmdb_id`) are reconciled best-effort via
  TMDB `/find`, stamping the resolved `_tmdb_id` onto the row before hydrating. A
  resolved id already claimed by another row can't be re-pointed (UNIQUE
  `_tmdb_id`) — the row stays TVDB-only and the collision is reported, same as an
  empty `/find` result.
- Update-changed phase (default full run only, skipped under `--fresh`)
  — re-hydrates the intersection of the marker-derived changes window (see
  **Incremental sync markers** below) and rows we've already synced. It stays an
  *intersection*: this leg leaves `insertHeartbeatTag()` at its `null` default, so a
  changed tv id we don't hold is ignored rather than inserted. That is what keeps
  "TVDB is the sole creator of `shows` rows" true now that the movies leg inserts
  from its feed.

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
  for `since`, and advances it only on a clean run; idempotent upserts
  make the overlap re-processing harmless.
- `seriesMany(array $ids)` returns a `PooledResult` — the input-ordered id →
  raw body map (`null` on 404) plus `failedIds` (non-404 http/connection
  failures). Callers upsert the bodies and feed `failedIds` back for retry.

## TVDB episodes sync (`catalog:sync-episodes-tvdb`)

- The incremental `/updates?type=episodes` sync. Reads the `tvdb_episodes` marker
  (see **Incremental sync markers**) for `since` — a 6h overlap, 24h first-run
  fallback, capped at 14 days — and advances it only on a clean run;
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

## Shared sync-command mechanics

Conventions every `catalog:sync-*` command follows, so the per-command classes
don't restate them:

- **Probe wide, hydrate narrow.** `PROBE_SIZE` (1000) bounds an id buffer of bare
  ints; `HYDRATE_SIZE` (250) bounds a batch of decoded payloads (~100–400 KB each,
  ~150 KB for TVDB `/series/{id}/extended`). They are separate numbers on purpose —
  one hydrate batch as wide as a probe buffer is what puts hundreds of MB live at
  once, and narrowing the probe to match would multiply cheap queries for nothing.
  Neither bounds query size.
- **Never read a whole id column.** Membership is resolved per buffer via a bounded
  `in (…)` probe, so resident memory is independent of catalog size.
- **One bad chunk never aborts a run.** Chunk work is wrapped, the failure is
  `report()`ed, the loop moves on, and the chunk counts as failed — a throw would
  silently truncate the catalog. TVDB narrows its `catch` to
  `TvdbRequestFailed`/`TvdbAuthenticationFailed` so a real bug (`QueryException`)
  still surfaces. Any chunk failure gates the marker advance (see below).
- **Per-id failures are reported, not thrown — but the two APIs signal them
  differently.** TMDB's `movies()`/`tvShows()` hand back only the results map and
  drop a failed id's key, so a short count
  (`count($results) < count(array_unique($ids))`) is the only way to detect one —
  what `TmdbSyncCommand::syncChunk()` relies on. TVDB's `seriesMany()` returns a
  `PooledResult` and names the failures in `PooledResult::failedIds`, which
  `TvdbShowsCommand::chunkResult()` reads; never infer them from a short result
  count. A 404 stays present-as-null either way and is not a failure.
- **Heartbeats are plain `writeln`, never `spin()`/`progress()`** — those fork a
  renderer that overwrites the terminal and renders nothing at all under
  `catalog:sync`'s nested `Artisan::call`, which swallows the per-batch line. (The
  line-by-line output rule itself is in `.ai/guidelines/project.md`.)

## Scale-with-change (every sync/ingest leg)

A leg is an **offender** if its per-run cost grows with a collection whose growth
we do not control. Cost that grows with the *change* is correct; cost that grows
with the *catalog* is a bug, not a tuning opportunity — optimizing its constant
factor optimizes a loop that should not run (FLIX-286, which struck FLIX-270's
"the export scan stays in every run" on exactly that ground).

Applying it needs two questions, not one:

- **Does the leg re-read a whole upstream dataset to find a small delta an
  incremental endpoint already reports?** Prefer the incremental endpoint. But
  verify the endpoint is complete before trusting it, and check what the full
  dataset was *filtering* — TMDB's `movie_ids` export carries no adult rows at
  all, so the export reader's adult screen had never fired in production; the
  changes feed is unfiltered, and ~10% of its ids are absent from the export,
  mostly adult. Discovering from the feed therefore widens what reaches the
  catalog, which is why FLIX-290 had to give refusal a column before FLIX-289's
  feed-driven discovery could be trusted.
- **Does a record the leg refuses to persist come back every run?** A refused
  title (see `CONTEXT.md`) that is dropped pre-upsert never gets its
  `*_synced_at` stamp, so the membership probe reports it missing forever. Store
  the row and filter at read — `ADR-0004`, implemented in FLIX-290: no ingest leg
  drops a refused record any more, and the read side carries the filter
  (`Models\Concerns\Refusable`). This residue is invisible in the heartbeats: the
  scan beat counts rows read, so a leg re-fetching tens of thousands of
  unpersistable ids looks identical to one making progress.

Audited 2026-08-27. Clean: `catalog:sync-shows-tvdb` (`/updates` since marker —
**the reference pattern**), `catalog:seed-shows-tvdb` (full crawl, but manual
bootstrap, never scheduled), the three IMDb legs (a `Http::head()`
`Last-Modified` probe short-circuits before `download()`; a full parse on a real
change is inherent, since IMDb publishes only full dumps), `download:sync-index`
(stops at the first fully-seen page) and `download:sync-rss` (constant).

**Fixed (FLIX-289 + FLIX-290):** `catalog:sync-movies` was the first offender — its
export scan re-hydrated ~66k ids per run, 94% of them unpersistable. FLIX-289 made it
read only the changes feed and moved the export to the unscheduled
`catalog:seed-movies` (see **TMDB movies sync/seed split**). FLIX-290 then removed the
residue itself: a refused title persists and stamps, so no leg re-fetches one it
already holds, and the export sweep converges.

Offenders still open, each with its own ticket: `catalog:sync-shows-tmdb`, where a
`/find` miss or `_tmdb_id` collision is re-walked every run — 95,340 rows on
production, ~55% of the show universe (FLIX-291); and `catalog:sync-episodes-tvdb`,
which reads only `seriesId` off an updates record that also carries the episode's own
`recordId`, then re-crawls the show's entire episode list (FLIX-292).

## Incremental sync markers (`SyncMarker` / `SyncFeed`)

All four catalog syncs (`catalog:sync-movies`, `catalog:sync-shows-tmdb`,
`catalog:sync-shows-tvdb`, `catalog:sync-episodes-tvdb`) fetch only what changed
since their last successful run via a per-feed cache marker — no fixed rolling
window.

- `SyncMarker` (`Support/`) owns read + advance. `window(SyncFeed)` derives the
  fetch interval as a `SyncWindow` VO: `since` = marker − 6h overlap (24h fallback
  when unset or unreadable), floored at `now − 14d` (TMDB's max `/changes` span;
  TVDB matched for parity). `advance(SyncFeed, $startedAt)` persists **run-start**
  as an ISO-8601 string via `Cache::forever` (never the Carbon itself — see the
  scalars-only cache rule in `.ai/guidelines/project.md`) — one key per `SyncFeed`
  case (`TvdbShows`/`TvdbEpisodes`/`TmdbShows`/`TmdbMovies`), so the four feeds
  advance independently.
- **Zero-failure gate:** a run advances its marker only if it finished with **no**
  failed ids/chunks; `--fresh` still advances (clean baseline). A per-id hydrate
  failure counts, detected per the failure-signal rule above — a short
  `movies()`/`tvShows()` result count, or a non-empty `seriesMany()`
  `PooledResult::failedIds` — and holds the marker. Any failure → marker unchanged
  → the next run re-covers the whole gap (idempotent upserts make that safe). A
  cache flush just drops to the 24h fallback, not data loss.
- **The 14-day cap is loud, not silent (FLIX-289).** When the floor moves `since`,
  the span between the marker and the floor is never fetched and never retried —
  permanent loss, not a deferral. `SyncWindow` therefore carries the discarded start
  (`uncoveredSince` → `isCapped()` / `uncoveredStartDate()`), and
  `TmdbSyncCommand::recordCappedWindow()` counts it on `$failedChangesWindows` — the
  counter for a window-level fault with no entity to count — so `closeRun()` prints
  `1 changes-feed window failed; {start} to {end} uncovered; marker not advanced.`
  and returns `FAILURE`.
  - The capped run **still covers the 14 days it can reach**; it reports the gap
    rather than skipping the window.
  - The marker deliberately stays put, so the alarm repeats every run until an
    operator runs `catalog:seed-movies`. That is the intended escalation: ideal
    operation is the assumption, and the guard exists so a departure is noticed
    immediately instead of months later. Production carried **no**
    `catalog:sync:marker:tmdb_movies` entry at all while every row was stamped —
    `advance()` fires only on a zero-failure run, and one transient failure among
    ~66k hydrations was enough to block it forever.
  - **TMDB legs only so far.** The guard lives in `TmdbSyncCommand`; the TVDB legs
    share `SyncMarker` but not that base, so a capped TVDB window is still silent.

