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
- `count()` applies the **same `includes()` filter** as `rows()`, so a progress
  total matches the rows actually yielded.
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

## Enums filter raw rows

Enum filters (`ImdbDataset`, `Genre::knownValues`) run on the **raw string row
before casting** — drop unrecognized values there, not after hydration.

## Ratings update (`UpdateImdbRatings`)

Ratings apply as a **single bulk CASE update per table** (Movie, Show), returning
the matched count. CASE bindings live in the query's join-binding slot — see the
in-code comment for the binding-order mechanics before touching it.
