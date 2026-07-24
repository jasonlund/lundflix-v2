# Project Guidelines

Project-specific conventions, on top of the Boost guidelines above.

## Editing these guidelines

This `<laravel-boost-guidelines>` block is **generated** — never hand-edit
`CLAUDE.md`/`AGENTS.md`. Edit `.ai/guidelines/project.md`, then run
`php artisan boost:install --guidelines` to rewrite the block into every agent
file identically.

## Context routing

Put a convention where it's cheapest to load. Universal rules (apply across
domains) → this file. Rules specific to one bounded context →
`app/Domains/{Domain}/GUIDELINES.md`, read on demand (never `@import` — imports
load at launch and save nothing). A multi-step workflow → a skill in
`.claude/skills/`. Keep this file to the shared kernel; a rule that only matters
inside one domain belongs in that domain's file.

## Architecture: Domain-Driven Design

Business logic organized by **domain**, not technical type. All domain code
shares one namespace root `App\Domains\*` under `app/` — so Rector, Shift, IDE
tooling, and Laravel auto-discovery work with no `composer.json` autoload
changes.

### Backend layout

```
app/Domains/
├── Common/              # shared kernel — used by many domains
│   ├── Contracts/       # interfaces other domains depend on
│   ├── ValueObjects/
│   ├── Enums/
│   └── Data/            # DTOs
└── {Domain}/            # e.g. Catalog, Billing — one bounded context
    ├── Models/
    ├── Actions/         # see Action classes below
    ├── Contracts/       # the ONLY cross-domain entry point
    └── ...              # add default Laravel folders as needed
```

- Plural `Domains`, one folder per bounded context.
- Folders use default Laravel names (`Models`, `Actions`, `Services`, `Events`,
  `Jobs`, `Policies`, `Enums`, `Exceptions`, `Data`, …). Create a subfolder only
  when you have something to put in it — no empty scaffolding.
- Non-domain infra/UI (`app/Http`, `app/Filament`, `app/Providers`) stays at
  `app/` root and *calls into* domains.

### Action classes

Single-purpose actions live in `App\Domains\{Domain}\Actions`.

- Name `VerbNoun` in PascalCase, **no `Action` suffix**; the noun includes the
  entity so it reads clearly when imported — `CreateUser`, `UpdateUserProfile`
  (not `Create`, `UpdateProfile`).
- Standalone actions expose one `handle()`. Actions bound to a framework contract
  (e.g. Fortify `CreatesNewUsers`) keep the interface's method name. Fortify
  auth/profile actions live in `App\Domains\Identity\Actions`, wired in
  `App\Providers\FortifyServiceProvider`.

### Exceptions

**One explicitly named class per distinct failure**, named for the condition,
PascalCase, in `App\Domains\{Domain}\Exceptions` — one domain often has several
(e.g. `CorruptImdbDatasetArchive` and `CannotOpenImdbDatasetArchive`).
Never funnel unrelated failures through a catch-all (factory methods or a
type/code discriminator) — split them so callers `catch` each by type. A static
named constructor (`::at($path)`) for the message is fine.

### Enums

Logic over an enum's **own cases** (validating, parsing, normalizing raw values
against the case set) lives as **static methods on the enum**, not a trait,
helper, or action — e.g. `Genre::fromRawValues(array $raw): list<Genre>`. Don't reach for
a shared `Concerns/` trait when a static enum method shares just as well and
keeps the knowledge on the type.

### Cross-domain rules

- A domain never imports another domain's `Models` or internals — only its
  `Contracts/` (interfaces) or published `Services`.
- `Common` is the shared kernel: only *incredibly stable* shared concepts (value
  objects, enums, contracts, DTOs). Keep it small — bloat couples every domain.
  `Common` depends on nothing domain-specific.

### Frontend layout (Inertia + React)

Mirrors backend domains (Inertia owns `pages/`, so it can't live under a PHP
namespace). Rule: *"Does it relate to a business domain/feature?"*

```
resources/js/
├── common/            # generic, reusable, no domain knowledge (mirrors Domains\Common)
├── modules/{domain}/  # reusable domain UI/logic across pages (mirrors Domains\{Domain})
└── pages/             # Inertia entry points by URL; page-local components only
```

- `pages/{x}/components/` = that page only. Shared domain UI → `modules/`.
- PascalCase components, camelCase other files, kebab-case dirs, `Page`/`Layout`
  suffixes.

### Testing (DDD + TDD)

**Test-first by default** via the `tdd` skill: RED → GREEN → REFACTOR, one
behavior **slice** (~2–6 tests) per cycle, each phase in an isolated subagent so
tests can't be retrofitted. RED slice approved in Conductor's plan UI first.

- **AAA, always.** Three blocks in order — arrange, **one** act, assert —
  separated by blank lines. One Act per test; need a second action → second test.
  Keep Arrange minimal (factories/props). Label form is a **strict, enforced
  standard** (mandatory label-only lines, ` & ` collapse only, protected
  banners) — see the testing skills; guarded by `tests/Unit/TestCommentStandardTest.php`.
- **Test behavior through public interfaces**, not implementation — tests survive
  refactoring. A slice = one behavior + its obvious variants.
- **Tests mirror the domain tree:** `tests/Feature/{Domain}/` and
  `tests/Unit/{Domain}/` mirror `app/Domains/{Domain}/`.
- **External-HTTP tests use real-data fixtures: byte-exact, in the API's native
  wire format**, committed under `tests/Fixtures/{Domain}/{source}/` in the exact
  extension the API returns (`.tsv.gz`, `.json`). Load via
  `fixtureBytes('Catalog/imdb/title.basics.tsv.gz')`. Never fabricate bodies by
  hand. `Http::preventStrayRequests()` is global in Feature tests — fake every
  call. DB *state* uses factories, never fixtures. Synthetic bodies only for
  inputs real data can't produce (corrupt payloads, blank lines, HTTP errors).
- **Backend:** Pest 4, `php artisan test --compact` (`--filter=name`). Feature is
  default; Unit only for isolated logic. Factories + `RefreshDatabase`; assert
  Inertia with `AssertableInertia`. Create via `php artisan make:test --pest`.
- **Frontend:** Vitest + RTL, `npm test`. Colocate `*.test.tsx`; query by
  role/text; mock `@inertiajs/react`; jsdom, setup `resources/js/test/setup.ts`.
- **Full-stack Inertia** → two cycles, backend first (assert component + props),
  then frontend (RTL renders with those props).
- Detailed conventions: `.claude/skills/tdd-laravel-testing` + `tdd-react-testing`.

Domain boundaries are enforced by **Pest architecture tests** (a domain's
`Models` used only within it; `Common` depends on no concrete domain). The arch
suite lands in a separate PR.

### File creation

Create files with `php artisan make:*` whenever a generator exists (models,
migrations, policies, tests, `make:class`) — don't hand-write boilerplate. Land
them in the DDD structure by passing the domain path, e.g.
`php artisan make:model Domains/Catalog/Models/Product`. If a generator can't
target the domain path, generate then move the file and fix its namespace. Never
break the DDD layout to satisfy a generator's default location.

### Filament pages

**Never hand-write a page's Blade view for a standard form/table page.** Every
Filament page renders through its `content(Schema $schema)` method, not a bespoke
template — override `content()` and drop the `$view` property entirely. The base
`Page` already renders `{{ $this->content }}` via `filament-panels::pages.page`,
so a custom `.blade.php` under `resources/views/filament/` is templated
boilerplate that duplicates what the schema gives for free.

- Embed the page's form in `content()` the way Filament's own auth pages do:
  `Form::make([EmbeddedSchema::make('form')])->livewireSubmitHandler('save')`
  with the submit button as an `Actions`/`Action` in the form `->footer([...])`.
  (`EditProfile`/`Login` in `filament/filament` are the reference.)
- A custom Blade view is justified **only** for genuinely non-schema markup a
  `content()` schema can't express — and even then prefer `getHeader()`/
  `getFooter()` view slots over replacing the whole page view.

### Comments

- **Comment the *why*, let tests pin the *what*.** A comment earns its place by
  capturing a non-obvious reason, contract, or gotcha a reader can't derive from
  the code. If a passing test or the code itself already says it, cut it.
- **Docblocks: keep type info PHP can't express** (`@param array<int, array{...}>`,
  `@return list<string>`, generics, `@throws`) and genuine "why" prose. Cut
  summary lines that restate the method name, `@param`/`@var` that add nothing
  past the native type hint, and framework stubs (a `@var string` that only
  restates a typed property).

## Persistence: third-party API columns (raw-source prefix)

A DB column populated directly by a third-party API's attribute is **prefixed
with its source** — `_{source}_{rawAttribute}` — and stores the value **raw, as
the API returned it**:

- `_imdb_runtime`, `_tmdb_original_title`, `_tvdb_overview`.
- **No transform at ingest.** Persist the raw value unmodified. Any
  crosswalk/enum mapping/normalization (e.g. TMDB `"Science Fiction"` →
  `Genre::SciFi`, or unioning sources into a display value) happens at **read
  time**, never at write time.
- **Group columns by source, order sources `imdb → tmdb → tvdb`** in migrations
  and model definitions, so each source's fields sit together in a predictable
  order.
- **Source identity & discriminators ARE prefixed** — they are source-owned, not
  app bookkeeping. The unique source identifier is the one **naming exception**:
  always `_{source}_id` (e.g. `_imdb_id`, even though IMDb's raw attribute is
  `tconst`), and **listed first** in that source's block. A source-provided
  discriminator is prefixed too — e.g. `Media._tvdb_type` (TVDB's raw
  artwork-type code, cast `'integer'`), kept separate from the app's own derived,
  source-agnostic dimension in the unprefixed `type` column (cast to
  `ArtworkType`), so no single source owns the app's own dimension.
- **App-owned bookkeeping columns are NOT prefixed** — the surrogate PK `id`,
  foreign/morph keys, `*_synced_at`, `is_active`, `created_at`/`updated_at`, and
  any column the app computes or owns. (The *source* identity key is **not** one of
  these — it is `_{source}_id`, above.)

This is deliberate: each source owns its own namespaced columns, so there are no
cross-source value "conflicts" to resolve at ingest (e.g. `_imdb_runtime` and
`_tmdb_runtime` coexist rather than fighting over one `runtime` column). The
source of truth is chosen per read, not baked into the schema.

### Crosswalk / queryable-id columns — the one ingest-normalize exception

"No transform at ingest" holds for descriptive fields (normalize at read). It does
**not** hold for a **crosswalk id that SQL must key on** — an `upsert` conflict key,
a `whereIn` target, a join key (`_imdb_id`, `_tmdb_id`). A read-time accessor can't
be any of those, so the clean value **must be materialized into the column at write
time**. Ingest therefore does two writes from one source: the **raw** value verbatim
into its own column (e.g. TVDB's whole `_tvdb_remoteIds` crosswalk list — dumb parse,
full parity), **and** the **normalized** id into the queryable column.

- **One shared normalizer, never an inline guard.** Every crosswalk parse site routes
  through the single `App\Domains\Catalog\Support\SourceId` (`imdb`/`tmdb`/`positiveInt`)
  — regex/range validate, `ctype_digit` before any `(int)` cast (so `1335814-slug` →
  null, not a truncated int). No per-callsite range checks or magnitude caps.
- **Malformed upstream → `null`, never trusted.** Third-party crosswalks ship free-text
  garbage (overflow, slug-appended, URLs, wrong-entity ids); a bad value nulls out while
  the row still imports. It is not an "API failure" — do not let a `QueryException` from
  a bad crosswalk masquerade as a retryable fetch failure.
- **Because the raw is retained, corrections need no reseed** — the queryable column can
  be re-derived from the stored raw via the same `SourceId` (e.g.
  `TvdbCrosswalk::normalize()` over a show's stored `_tvdb_remoteIds`).

## Persistence: Eloquent is globally unguarded

Eloquent runs **unguarded application-wide** by deliberate decision (FLIX-153):
`Model::unguard()` in `AppServiceProvider::boot()`, and models intentionally
carry **no** `#[Fillable]` / `$fillable` / `$guarded`. Every column is
mass-assignable; write paths whitelist attributes **explicitly at the callsite**
(e.g. Fortify actions pass keyed arrays; ingest actions pass fixed column lists).

- **Do not** re-add per-model `#[Fillable]`/`$guarded`, and do not "scope" the
  unguard to one flow — global is the chosen design.
- This is **not** a mass-assignment vulnerability to flag: no `$request->all()`
  / `->validated()` is ever spread into a model. A reviewer raising "unguard
  removes mass-assignment protection" or "model is missing `$fillable`" is a
  known false positive — the protection lives at the callsite by convention.

## Laravel helpers over PHP functions

Prefer `Illuminate\Support\Str` / `Arr` helpers over the PHP-native equivalents
(FLIX-206). This is **mechanically enforced** — a custom `FuncCallToStaticCall`
map in `rector.php` rewrites the native call and the `rector --dry-run` CI gate
fails on any that slip in, so you rarely hand-write the native form.

- **Rector-rewritten (don't write the native form):** `str_starts_with` /
  `str_ends_with` / `str_contains` → `Str::startsWith`/`endsWith`/`contains`;
  `str_replace` → `Str::replace`; `strtolower`/`strtoupper`/`ucfirst` →
  `Str::lower`/`upper`/`ucfirst`; `trim`/`ltrim`/`rtrim` → `Str::trim`/…;
  `substr` → `Str::substr`; `strlen` → `Str::length`; `str_repeat` →
  `Str::repeat`; `ucwords` → `Str::ucwords`.
- **Stay native — do NOT "fix" these** (no clean 1:1, don't add them to the map):
  `array_key_exists` (`Arr::exists($array, $key)` swaps the argument order — the
  positional Rector map can't express it); signature-mismatch `str_pad` /
  `preg_replace` / `explode`; no-equivalent `preg_match` /
  `preg_split` / `implode` / `sprintf` / `count` / `in_array` and
  the `array_map`/`filter`/`merge`/`keys`/`values`/`column`/`unique`/`flip`/
  `combine` family; `json_encode` / `json_decode` (`Js::` is HTML-embedding only);
  `last()` / `head()` (already helpers).
- **Multibyte caveat:** `Str::lower`/`upper`/`length`/`substr` are multibyte and
  `Str::trim` strips unicode whitespace (nbsp/BOM) — correct for ASCII inputs. If
  a call measures **bytes** (payload size), keep native `strlen`/`substr`.
- **Null-haystack caveat:** `Str::startsWith`/`endsWith`/`contains` coerce a
  `null` haystack to `false`, where the native `str_starts_with`/`str_ends_with`/
  `str_contains` (under `strict_types`) would throw a `TypeError` — guard the
  haystack rather than relying on the silent `false`.
- **Forward-looking** (no current usages, follow going forward): `number_format`
  → `Number::format` (`currency`/`fileSize`/`percentage`); `date()`/`time()`/
  `strtotime()` → `now()`/`today()`/Carbon.
- **Collections over arrays** — in **new** code prefer a Collection pipeline
  (`collect($x)->map(...)->filter(...)->pluck(...)`) over chained `array_*` calls.
  Convention only (not Rector-enforced); existing arrays are left as-is.

## Persistence: iterating rows you write to → `chunkById`

When a loop **writes to the DB rows it is iterating** (stamping a column,
flipping a flag, reconciling a crosswalk), walk the query with **`chunkById()`**,
not `chunk()`, `get()`, or `lazy()`. `chunkById` paginates by primary key, so
mutating a **non-key** column mid-iteration cannot skip or double-process a
row — the failure mode `chunk()` (offset-paginated) and a materialized `get()`
both invite. This is the default for any iterate-and-write path; deviate only for
a **distinct, stated reason** (e.g. a read-only walk, or a set small and bounded
enough that materializing is provably fine — say why in a comment).

- Read-only iteration with no writes → `lazy()`/`cursor()` is fine (streams
  without the PK-pagination overhead).
- `--limit`-style caps don't compose with `chunkById` directly — track a
  processed count and `return false` from the closure to halt early.

## Persistence: version-controlled database seed

The catalog is seeded from **real data committed to the repo** so a fresh
checkout/workspace has a usable dataset with no third-party API calls (FLIX-194).

- **`db:dump` / `db:import`** live in the **`Local`** domain (local-development
  tooling) — `App\Domains\Local\Console\Commands` (registered in `bootstrap/app.php`
  `withCommands`), with `mysqldump`/`mysql` shelled through the `Process` facade
  (fakeable) and the pure helpers in `App\Domains\Local\Database` (`DumpFit`
  fitting, `DumpSelection` coherence, `MysqlConnection` args).
- **`database/dumps/*.sql.gz`** are generated blobs: **one file per table**
  (`movies`, `shows`, `seasons`, `media`, `downloads` — never `settings`, which is
  secret + `APP_KEY`-encrypted), each capped under 50 MB. `movies`/`shows` are the
  best-first prefix by `_tmdb_popularity`; `seasons` and `media` are kept
  **coherent** to those included shows/titles (never orphaned); `downloads` are
  seeded **independently**, best-first by `_provider_availability` — their title
  links (`_imdb_id`/`_tmdb_id`) are optional and frequently unset, so coherence
  would empty the file. Best-first prefixes carry a unique `, id DESC` tiebreak so
  a parent dump and its child coherence subquery pick identical boundary rows on
  `_tmdb_popularity` ties (otherwise a few children orphan). They are
  `binary linguist-generated` in `.gitattributes` — never hand-edit; regenerate
  with `php artisan db:dump`.
- **The dumps are byte-exact real captures**, like `tests/Fixtures/`. The
  download-vocabulary pre-finalize grep **excludes `database/dumps/`** (and
  `tests/Fixtures/`) — real `_provider_*` values are logic-tied data, not prose.
- **The test DB is sqlite `:memory:`; prod/dev is MySQL.** A MySQL-dialect dump
  can't load into sqlite, so `db:import` tests assert the real truncate + the
  faked load invocation, not reloaded rows — the byte-apply is covered by the
  Conductor setup smoke, not a Pest test.

## Linting & formatting (finalize gates)

Before finalizing **any** change, run every linter/formatter for the files you
touched — scoped to your changed work, never a repo-wide sweep (a bare
`vendor/bin/rector` rewrites generated `bootstrap/cache/*` and unrelated files).

- **PHP touched**, in order: `vendor/bin/rector process <changed files>` then
  `vendor/bin/pint --dirty --format agent` (Pint after Rector, to normalize what
  Rector reformatted).
- **Frontend touched** (`.ts`/`.tsx`/`.js`/`.css` under `resources/`):
  `npm run lint`, `npm run format`, `npm run types`.
- Then re-run the affected tests — linters reorder and retype code, so re-verify
  green before finalizing.

## Configuration

- **Third-party base URLs are service constants, not env/config.** A public,
  fixed endpoint (e.g. IMDb `https://datasets.imdbws.com`) isn't a secret and
  doesn't vary by environment — commit it as a `private const` on the calling
  service. Reserve `config`/`env` for secrets and genuinely per-environment
  values.
- **Name a credential's config key after the provider's own doc verbiage.** A
  credential's `config`/`env` key uses the term that provider's API docs use —
  don't force one shared word across providers. TMDB's "API Read Access Token" →
  `services.tmdb.token` / `TMDB_TOKEN`; TheTVDB's "apikey" → `services.tvdb.key`
  / `TVDB_KEY`. A short-lived value *derived* from the stored credential (e.g.
  the JWT TheTVDB returns from `POST /login`) is internal — cache it, never put
  it in `config`/`env`. So `key`/`token` names what you store; the bearer you
  send may be that same value (TMDB) or one exchanged for it (TVDB).
- **Only *required* env vars belong in `.env.example`** — a secret/credential the
  app needs to run. Optional tunables that read `env()` with a `config/` default
  stay out; the default is the documentation.
- **New required env var → also set the Conductor root `.env`.** Fresh workspaces
  copy `.env` from `~/conductor/repos/<repo>/.env`, not from `.env.example` — set
  it there too or new workspaces start without it.

## Documentation

**Default to `.ai/guidelines/project.md` (agent context, every session); write to
README only when a human operator needs it; write nothing when code or git
already says it.**

- **`project.md` — default.** Any convention, architecture/domain boundary,
  naming/structure rule, always/never, or non-obvious rationale a future agent
  would miss. Edit `project.md` only (never the generated `CLAUDE.md`/`AGENTS.md`),
  then run `php artisan boost:install --guidelines`.
- **`README.md` — human-operator surface only.** Install, run, test, required
  credentials, what the app is. Never edit silently — prompt and name the stale
  section. New required env var → README "Required API keys" table (var, purpose,
  where to get it). Grow `Overview`/`Screenshots` as user-facing features ship;
  drop the TODO marker once real.
- **Nothing** when derivable from code/tests/git, or true only of this one change.

Both a rule and an operator step? Rule → `project.md`, step → README,
cross-reference — don't duplicate.

## Linear (issue tracking)

- **Always use the `mcp__linear-server__*` tools** for every lookup/create/update
  — never assume or hand-edit ticket state.
- **Write to the ticket body, never comment.** When recording progress, plans,
  results, or deviations, replace or append the ticket's **description**
  (`save_issue` with `description`) — keep it the single source of truth, not
  `save_comment`.
- **Every branch maps to ≥1 ticket**; the branch name includes every ticket id,
  drops the `jasonlund/` prefix, ≤40 chars (e.g. `flix-123-scaffold-new-app`).
- **No ticket yet → prompt to create one** before proceeding.
- **Work deviates → confirm first, then update the ticket** and mark it a
  deviation.
- **Planning artifacts live in Linear, not in the repo.** PRDs, plans, slice
  backlogs, and decomposition notes belong in the relevant Linear issue —
  never committed as repo files. Don't create a `docs/plans` or `.ai/plans`
  tree; a plan on disk drifts from the ticket and biases future agents who read
  it as a convention. Bars *version-controlled* planning files only — gitignored
  scratch space (e.g. `.context`) is fine; it never enters the repo.
