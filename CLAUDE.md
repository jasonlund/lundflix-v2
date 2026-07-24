<laravel-boost-guidelines>
=== .ai/project rules ===

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

## Console commands: simple line-by-line heartbeat output

**Every artisan command emits simple, line-by-line progress output — never silent,
never fancy.** A command that runs a pipeline must let the operator see it working:
one plain `writeln` line per phase, plus an indented bracketed heartbeat as work
flows. The bar is a command someone runs at a prompt and gets a bare prompt back,
unsure anything happened (the `plex:seed` regression) — that is a defect.

- **Write through `$this->output->writeln(...)`** — the established convention
  across `Catalog`/`Download` commands (`SyncTmdbMovies`, `SyncDownloadIndex`,
  `PlexLibraryCommand`). Not `$this->info()`, not `$this->components`, not a logger.
- **Two line shapes, that's it:**
  - a plain phase line — `writeln('Syncing movies…')`, `writeln('Done.')`;
  - an indented bracketed heartbeat — `writeln("  [movies {$count}]")`,
    `writeln("  [episodes {$ratingKey}]")` (two-space indent, `[tag value]`).
- **Simple, not fancy.** No progress bars, spinners, tables, colors, or ASCII art.
  Line by line. A heartbeat per phase boundary and per item in a long fan-out —
  enough to prove liveness, not a dashboard.
- Put the output in the shared base when a family of commands share an engine
  (e.g. `PlexLibraryCommand`), so every subcommand inherits it.

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

### Automatic ticket status transitions

The flow skills advance a ticket's status **automatically** as work crosses each
lifecycle boundary — no manual status changes. The map (each fires once, at the
boundary named):

| Boundary | Fired by | Target status |
| --- | --- | --- |
| Planning done (TDD backlog appended) | `plan-slices` | **Todo** |
| Execution begins (first slice for the ticket) | `tdd` | **In Progress** |
| PR opened | `review:create-pr` | **In Review** |
| PR merged | Linear's native GitHub integration | **Done** |

The lifecycle order is `Backlog < Todo < In Progress < In Review < Done`. Each
transition follows one shared contract — reference this section from the skills
rather than restating it:

- **Primitive.** `mcp__linear-server__save_issue(id: <FLIX-XXX>, state: "<name>")`
  — pass the status **name**, never an id. MCP only; no bash/token path.
- **Resolve the ticket from the branch** (`flix-XXX-…` → `FLIX-XXX`, the
  review-pipeline Ticket ID Auto-Extraction). **No ticket resolves → skip
  silently** (the "when applicable").
- **Forward-only.** Apply the target **only if** the ticket's current status is
  *strictly earlier* in the lifecycle. Never move backward; a re-run at or past
  the target is a silent no-op.
- **Never touch `Canceled` / `Duplicate`** tickets.
- **Active ticket only.** Each ticket transitions when *its own* work runs;
  sibling sub-tickets and a decomposed parent are left untouched. (Exception:
  at PR-open, every ticket the PR covers moves to In Review together.)
- **Report, don't ask.** State the transition in one line; the change is
  automatic — never prompt for permission.

=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- filament/filament (FILAMENT) - v5
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v13
- laravel/horizon (HORIZON) - v5
- laravel/nightwatch (NIGHTWATCH) - v1
- laravel/pennant (PENNANT) - v1
- laravel/prompts (PROMPTS) - v0
- laravel/scout (SCOUT) - v11
- livewire/livewire (LIVEWIRE) - v4
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- rector/rector (RECTOR) - v2
- @inertiajs/react (INERTIA_REACT) - v3
- react (REACT) - v19
- eslint (ESLINT) - v10
- prettier (PRETTIER) - v3
- tailwindcss (TAILWINDCSS) - v4

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>
