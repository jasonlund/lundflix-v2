# lundflix

A self-hosted media catalog: it mirrors title metadata from several third-party
sources, tracks what is available to acquire, and reconciles both against what a
Plex server already holds.

This glossary is **seeded lazily** — a term is added when it actually gets
resolved, not speculatively. It is deliberately short. FLIX-278 will restructure
it into a `CONTEXT-MAP.md` plus a glossary per bounded context; until then, terms
that have earned a definition live here.

Consumption rules (when to read this, how to flag ADR conflicts):
`docs/agents/domain.md`. Architecture vocabulary — module, interface, depth, seam,
adapter — is a separate concern and lives in
`.claude/skills/codebase-design/SKILL.md`.

## Language

### Download

**Download source**:
The third-party index the app queries for acquirable copies of a title. There is
exactly one, reached through a single `BASE_URL` constant.

**Availability**:
How readily a listing can be acquired, as reported by the download source. Stored
raw as `_provider_availability` and used to rank listings best-first.

**Demand**:
How sought-after a listing is, as reported by the download source. Distinct from
availability: a listing can be in high demand and poorly available.

> The vocabulary used in this domain is constrained beyond ordinary preference.
> **The three terms above are binding**: use them, and do not introduce synonyms
> for them in code, comments, logs, tests, or prose. The constraint's full wording
> and the sweep that enforces it are operator-side, held outside version control on
> purpose — the words it rules out must not appear in a tracked file, so no file
> here can carry the rule itself. Only real captures under `tests/Fixtures/` and
> `database/dumps/`, plus a value the running code cannot work without, are exempt.

### Catalog

**Title**:
A movie or a show — the thing a user is looking for, independent of which source
described it or which file realizes it.

**Refused title**:
A title the app deliberately never surfaces — adult, softcore, or promo (a
trailer/extra rather than a film). Refusal is a property of the title, not of one
source's feed: several sources report them, and each ingest path must record a
refused title *as refused* rather than skip it, or the title is rediscovered and
re-fetched on every later run. See [ADR-0004](docs/adr/0004-refused-titles-are-stored-not-dropped.md).

**Deferred candidate**:
A row an ingest leg attempted and could not resolve — nothing came back, so there
is no payload to record the outcome in. Distinct from a refused title, which the
source did describe and the app declines to surface. The row keeps its unset
`*_synced_at` stamp and instead carries a count of failed attempts and the
instant it becomes a candidate again, on a doubling interval. Deferred, never
retired: an unresolvable row is usually only unresolvable *today*. See
[ADR-0005](docs/adr/0005-unresolvable-rows-are-deferred-not-retired.md).

**Gone title**:
A title the catalog already holds whose detail fetch a source answers with a 404 —
it was there, and now it is not. Distinct from a deferred candidate, which was
never resolved in the first place: a gone title keeps its `*_synced_at` stamp and
still reads as held, so a later run refreshes it rather than rediscovering it. It
leaves the search index while the stamp stands, and the stamp is cleared the
moment the source serves it again — a fact about the last fetch, not a
retirement. Recorded on `movies.tmdb_gone_at` only; `shows` records the same
upstream silence as a deferred candidate, and two mechanisms for one condition
drift.

**Crosswalk id**:
A third-party identifier for a title that SQL must key on (`_imdb_id`,
`_tmdb_id`, `_tvdb_id`). Unlike other source-owned columns it is normalized at
write time, because a read-time accessor cannot be an upsert conflict key, a
`whereIn` target, or a join key.
