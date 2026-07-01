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
- Detailed conventions: `.claude/skills/laravel-testing` + `react-testing`.

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
  discriminator is prefixed too — e.g. `_imdb_title_type` (still cast to
  `TitleType`, still the movie/show discriminator; the import routing reads the
  raw row `$row['titleType']`, not the column, so the rename doesn't touch it).
- **App-owned bookkeeping columns are NOT prefixed** — the surrogate PK `id`,
  foreign/morph keys, `*_synced_at`, `is_active`, `created_at`/`updated_at`, and
  any column the app computes or owns. (The *source* identity key is **not** one of
  these — it is `_{source}_id`, above.)

This is deliberate: each source owns its own namespaced columns, so there are no
cross-source value "conflicts" to resolve at ingest (e.g. `_imdb_runtime` and
`_tmdb_runtime` coexist rather than fighting over one `runtime` column). The
source of truth is chosen per read, not baked into the schema.

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
