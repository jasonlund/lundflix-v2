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

## Sync ordering (`tmdb:sync-shows`)

- `tmdb:sync-shows` create-or-merge — it matches an existing show by **any**
  source id (`_imdb_id`, `_tmdb_id`, or `_tvdb_id`, including the IMDb id nested
  in `external_ids`) and merges its `_tmdb_*` columns onto it; when nothing
  matches it inserts a tmdb-only row (seeding `_imdb_id` when the payload carries
  one). It no longer depends on `tvdb:sync-shows` having run first.

## Ratings update (`UpdateImdbRatings`)

Ratings apply as a **single bulk CASE update per table** (Movie, Show), returning
the matched count. CASE bindings live in the query's join-binding slot — see the
in-code comment for the binding-order mechanics before touching it.
