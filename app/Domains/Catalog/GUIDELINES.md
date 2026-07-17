# Catalog — domain notes

Non-obvious contracts for the Catalog domain. Read when working in
`app/Domains/Catalog`. Universal conventions live in `.ai/guidelines/project.md`.

## Raw-source columns

External-source attributes are stored **raw**, one column each, prefixed
`_{source}_{rawAttr}` (e.g. `_imdb_averageRating`, `_tmdb_overview`). Column
order follows source priority: imdb → tmdb → tvdb. Persist the source value
verbatim; derive/normalize downstream, not on ingest.

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
  — re-hydrates the intersection of the rolling 14-day changes feed and rows we've
  already synced.

## TVDB sync split (`catalog:seed-shows-tvdb` / `catalog:sync-shows-tvdb`)

- `catalog:seed-shows-tvdb` — one-time manual bootstrap that crawls **every** TheTVDB
  series and upserts each. TheTVDB offers no re-download list, so failures heal
  **within the run**: one retry pass over the crawl's failures, then report the
  remainder. No persisted skip state.
- `catalog:sync-shows-tvdb` — the rolling 14-day `/updates`-feed sync, wired into
  `catalog:sync`. The 14-day overlap window **is** the self-heal: idempotent
  upserts re-cover any dropped update on a later run, so it needs no persisted
  marker.
- `seriesMany(array $ids)` returns a `PooledResult` — the input-ordered id →
  raw body map (`null` on 404) plus `failedIds` (non-404 http/connection
  failures). Callers upsert the bodies and feed `failedIds` back for retry.

## Ratings update (`UpdateImdbRatings`)

Ratings apply as a **single bulk CASE update per table** (Movie, Show), returning
the matched count. CASE bindings live in the query's join-binding slot — see the
in-code comment for the binding-order mechanics before touching it.
