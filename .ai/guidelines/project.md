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
- **A domain owns business logic, not the HTTP layer.** Never create
  `app/Domains/{Domain}/Http`. Infra/UI stays at `app/` root and *calls into*
  domains: `app/Http`, `app/Filament`, `app/Providers`.
- **Controllers live in `app/Http/Controllers/{Domain}`** — PascalCase folder per
  bounded context, namespace `App\Http\Controllers\{Domain}` (see `Identity`),
  and `routes/web.php` points at them. Keep them thin — guard, call an Action or
  Service, respond.

### Class modifiers

Two mechanical rules, no per-file judgment, enforced by `tests/Unit/ArchTest.php`
over everything the repo owns (`app/`, the PSR-4 halves of `database/`, the
helper classes under `tests/`):

- **Every non-abstract class is `final`.** Inheritance is opt-in, and opting in is
  spelled `abstract` — a shared base (`PlexLibraryCommand`, `TmdbSyncCommand`)
  declares itself abstract and is named in the arch test's `->ignoring()` list,
  which a staleness guard pins as genuinely abstract.
- **A class with no parent is additionally `readonly`** — `final readonly class`.
  This holds for stateless and static-only helpers too; the point is that the
  shape is predictable, not that each class earns it individually.

The second rule stops at the parent because **PHP forbids a `readonly class` from
extending a non-readonly one** (a fatal, not a warning). So everything extending a
framework base — Models, Commands, Factories, Exceptions, spatie `Data`,
Providers, Middleware, Filament pages — can only ever take `final`. Enums, traits
and interfaces are outside both rules; enums are implicitly final and can never be
readonly.

Anonymous migration classes are the one structural exclusion — they can't be named,
and the arch targets (`Database\Factories`, `Database\Seeders`) don't reach them.

### Action classes

Single-purpose actions live in `App\Domains\{Domain}\Actions`.

- Name `VerbNoun` in PascalCase, **no `Action` suffix**; the noun includes the
  entity so it reads clearly when imported — `CreateUser`, `UpdateUserProfile`
  (not `Create`, `UpdateProfile`).
- Standalone actions expose one `handle()`. Actions bound to a framework contract
  (e.g. Fortify `CreatesNewUsers`) keep the interface's method name. Fortify
  auth/profile actions live in `App\Domains\Identity\Actions`, wired in
  `App\Providers\FortifyServiceProvider`.

### DTOs — domain boundaries speak in types, not array shapes

A public method on a domain's `Actions`/`Services` **never takes or returns a bare
`array`** for an app-shaped struct. `array{id: int, …}` in a docblock is a type the
language won't check; make it a class. Enforced by
`tests/Feature/Architecture/DtoBoundaryTest.php`.

- **Location: `App\Domains\{Domain}\Data`** — every data-carrying shape. `Support/`
  holds **behavior helpers only** (`SourceId`, `RawSourceColumns`, `BulkCaseUpdate`).
  A class whose job is to carry values belongs in `Data/` even when it exposes
  accessors over them (`SyncWindow`).
- **Base class by boundary:** plain `final readonly class` by default; extend spatie
  `Data` **only** when the object crosses a serialization boundary — Inertia props,
  `#[TypeScript]`, `::from()` hydration. A reflection-heavy base buys nothing on an
  internal service→action struct.
- **Plain carriers.** No `toArray()`, named constructors, or behavior. Marshalling
  belongs to the seam that needs it, not the DTO (see `PlexSession`).
- **Nullability states trust.** A DTO of verified data types its fields tightly
  (`PlexServerConnection`); one carrying unvalidated request data is nullable so a
  missing field reaches the validator instead of the constructor
  (`PlexRegistrationInput`). Omitting a field entirely is a real guard —
  `PlexRegistrationInput` has no `email`, so a spoofed one has nowhere to land.

**Three exemptions, and only these** (the fence documents each entry with its reason):

1. **Raw upstream payloads** — the wire shape is the source's, not ours. Two forms,
   and which one you have decides how much of the signature is exempt:
   - **Ingest sinks — the exempt `array` is a *parameter*.** `array $payloads`/`$rows`/
     `$page`/`$sections` feeding the `_{source}_*` raw-parity columns
     (`UpsertTmdbMovies::handle`, `ReconcilePlexLibraries::handle`,
     `ImportImdbTitles::handle`). A DTO there is a transform at ingest and breaks the
     `RAW_COLUMNS` list-driven mapping. The **return** still converts — these hand
     back a count or a DTO (`TitleImportCounts`).
   - **Wire-shape reads — the exempt `array` is the *return*.** A method whose
     `array`/`?array` return *is* the decoded upstream response body
     (`TmdbApiService::movie`, `TmdbApiService::configuration`,
     `PlexLibraryService::fetchSections`, and their TVDB/Plex siblings). No
     `RAW_COLUMNS` mapping to break and no DTO planned — modelling a third party's
     response shape buys a class that changes whenever they change. The return
     stays `array` indefinitely.
2. **Framework-fixed signatures** — Fortify's `CreatesNewUsers::create(array $input)`,
   Inertia's `share(): array`. Not ours to retype.
3. **Scalar lists** — `list<int>`/`list<string>` returns. A list of ints is not a
   struct.

The fence **throws on an exemption entry that no longer resolves** to a real
`Class::method`, so the list can't rot into silently exempting nothing. Adding an
entry is a deliberate act — a new source integration must classify its ingest methods
consciously.

**Session gotcha:** `config('session.serialization')` is `json`, so a PHP object put
in the session decodes back as an array. Stash a JSON-safe payload and hydrate on
read (`PlexSession`). The Feature suite **cannot catch this** — the test client sends
no session cookie between requests, so each gets a fresh id and an in-memory object
survives. Round-trip the value through the serializer in the test, as
`PlexRegistrationTest` does.

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
└── pages/{domain}/    # Inertia entry points by domain; page-local components only
```

- Pages group by **domain**, not by URL — `pages/identity/Login.tsx`, lowercase
  folder matching `modules/{domain}`. The render key is the path, so the
  controller calls `Inertia::render('identity/Login')`. App-wide pages that
  belong to no domain (e.g. `Welcome`) sit at the `pages/` root, mirroring
  app-wide infra staying at `app/` root.
- `pages/{x}/components/` = that page only. Shared domain UI → `modules/`.
- PascalCase components, camelCase other files, kebab-case dirs, `Page`/`Layout`
  suffixes.

#### No styling until the design phase (current standing rule)

**Build every UI as bare, functional HTML — no `className`, no Tailwind utilities,
no inline styles, no component library.** Native `<input>`, `<button>`, `<label>`,
`<h1>`, `<p>`. Semantics and behavior only; the browser's default appearance is
the intended appearance.

- Applies to **all** new UI, not just auth. The design pass happens later as
  deliberate work — styling written now is throwaway that biases it.
- Attributes that carry **behavior or accessibility** stay: `htmlFor`/`id`,
  `type`, `name`, `required`, `readOnly`, `autoComplete`, `role`, `aria-*`.
- **Tailwind's stylesheet is not imported** during this phase (`resources/css/app.css`
  keeps it commented out with the restore block). Its Preflight reset strips the
  border and background off `<input>`/`<button>`, which renders bare HTML forms
  invisible — so "no classes" and "Tailwind loaded" can't coexist. The package
  stays installed and configured; don't add a UI dependency to fill the gap either.
- Tests assert semantics (roles, labels, values, form `action`/`method`) — never
  classes — so the eventual design pass won't break them.
- Lift this rule only when the front-end design work starts in earnest; then
  delete this block rather than letting it rot.

### Testing (DDD + TDD)

**Test-first by default** via the `tdd` skill: RED → GREEN → REFACTOR, one
behavior **slice** (~2–6 tests) per cycle, each phase in an isolated subagent so
tests can't be retrofitted. RED slice approved in the harness's plan UI first
(Conductor's, or plain terminal approval under LaborForest + Solo — the gate is the
approval, not the UI that renders it).

- **AAA, always.** Three blocks in order — arrange, **one** act, assert —
  separated by blank lines. One Act per test; need a second action → second test.
  Keep Arrange minimal (factories/props). Label form is a **strict, enforced
  standard** (mandatory label-only lines, ` & ` collapse only, protected
  banners) — see the testing skills; guarded by `tests/Unit/TestCommentStandardTest.php`.
- **Test behavior through public interfaces**, not implementation — tests survive
  refactoring. A slice = one behavior + its obvious variants.
- **Tests mirror the domain tree:** `tests/Feature/{Domain}/`,
  `tests/Unit/{Domain}/`, and `tests/Browser/{Domain}/` mirror
  `app/Domains/{Domain}/`.
- **`tests/Support/` holds helper *classes*** (PSR-4 `Tests\Support\…`; `composer.json`
  already maps `Tests\` → `tests/`) backing the self-policing guards, e.g.
  `TestOrganizationScanner`. `tests/Pest.php` stays the home for global helper
  *functions* (`fixtureBytes`, `staleShow`, …); a cohesive rule engine with its own
  constants belongs in a class, which also sidesteps the suite-wide uniqueness rule
  on global helper names.
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

#### Browser tests (Pest 4 + Playwright) — the seam the other two suites can't reach

`tests/Browser/{Domain}/` drives real Chromium via `visit()`. It exists for one
reason: a Feature test posts raw HTTP so React never runs, and a Vitest test
stubs `@inertiajs/react` so the submit never leaves the component. **Neither
proves the form a user actually fills reaches the controller.** Write a browser
test only for that seam — a full-stack flow whose submit/redirect round trip is
otherwise unproven. Everything a Feature or RTL test can assert stays there;
they are far faster and browser coverage that duplicates them is pure drag.

- **Same process as the test.** Pest serves the app on an in-process Amp socket
  through `HttpKernel`, and merges `test()->prepareCookiesForRequest()` into
  every browser request. So the whole Laravel test API reaches the browser:
  `RefreshDatabase` on sqlite `:memory:`, `Http::fake()`,
  `$this->withSession([...])`, `actingAs`, `assertAuthenticated`. Arrange
  session/DB state in PHP exactly as in a Feature test — no UI setup walk.
- **Never let a browser test reach a third party.** A leg that redirects
  off-site (the Plex hand-off → `app.plex.tv`) is out of scope by rule: following
  it hits a real host on every CI run. Cover that redirect with a Feature-test
  header assertion and start the browser test at the first page we serve.
- **Assert `assertNoJavaScriptErrors()`** on every page driven — it's the one
  check no other suite can make. Avoid `assertNoSmoke()`/`assertNoConsoleLogs()`
  unless the page is genuinely log-free.
- **Locate by `#id`, not text**, for form fields — the no-styling phase means
  bare HTML with stable ids, and text lookups break on the design pass.
- **Requires built assets.** `npm run build` must have run, or Inertia 500s on
  the Vite manifest. CI builds before Pest and installs Chromium with
  `npx playwright install --with-deps chromium`.
- Registered as its own `Browser` testsuite in `phpunit.xml` and bound in
  `tests/Pest.php` (`->in('Feature', 'Browser')`). Screenshots are gitignored.

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
  - an indented bracketed heartbeat — `  [tmdb movies 1000]`, `  [plex episodes 288]`
    (two-space indent, `[tag value]`).
- **Simple, not fancy.** No progress bars, spinners, tables, colors, or ASCII art.
  Line by line. A heartbeat per phase boundary and per item in a long fan-out —
  enough to prove liveness, not a dashboard.
- Put the output in the shared base when a family of commands share an engine
  (e.g. `PlexLibraryCommand`), so every subcommand inherits it.

### Heartbeat tags are source-prefixed

- **Every tag names its source first** — `imdb` / `tmdb` / `tvdb` / `plex` /
  `download`: `[tmdb movies 1000]`, `[tvdb episodes 102]`, `[tvdb feed 10000]`,
  `[imdb ratings 250000]`, `[plex episodes 288]`, `[download rss Movies 40]`.
  `catalog:sync` runs its children into one interleaved stream, so an unprefixed
  `[movies N]` / `[episodes N]` is ambiguous — each meant one thing in Catalog and
  a different thing in Plex.
- **Local tooling has no third-party source, so the tag names the work instead** —
  `[dump movies 240]`, `[import movies]`. Don't invent a prefix for it.
- **`[elapsed …]` is the one unprefixed exception**, and it predates this rule —
  `SyncImdbCatalog` emits `[elapsed {dataset} 12.4s]` and `ImdbSyncCommand`
  `[elapsed 12.4s]`. It measures the leg rather than naming what the leg read, so
  there is no source to put first. Don't prefix it, and don't copy the shape for a
  tag that does count a source's work.
- **The value need not be a count.** `[download index Movies p10]` is a walk
  position and `[elapsed titles 12.4s]` a leg duration; a reader tells them apart
  by the value. Only *running totals* go through the emitter below — a position or
  a one-shot fact stays a plain `writeln`.

### `EmitsHeartbeat` is the shared emitter

`App\Domains\Common\Console\Concerns\EmitsHeartbeat`. New commands `use` it rather
than hand-rolling a counter: it tracks the last mark **per tag**, so one command
can beat several tags independently and the closing total never repeats a line the
beat already printed.

- **`mark($tag, $total, $suffix = null)` — the caller owns the cadence.** For a
  one-shot count (`[plex libraries 2]`) or a per-batch flush where the batch size
  is already the operator's cadence knob (IMDb's probe batches). Reaching for
  `beat()` at those callsites silences nearly every line, because the totals sit
  far below any sensible interval.
- **`beat($tag, $total, $interval, $suffix = null)` — a running total on interval
  boundaries.** Reach for it when a total climbs steadily and the interval, not the
  batch, is the operator's cadence knob.
- **`flushTotal($tag, $total)` — the closing exact total.** Every run calls it, so a
  run shorter than one interval still reports what it did.
- **`failureSummary($count, $noun, $consequence)` — the closing failure line**,
  a no-op at zero.

### Every run closes: final total, failures, `Done.`

- **A final total, then `Done.`, on every command.** `flushTotal()` exists so a run
  shorter than one beat interval still reports what it did — 47 episodes or a
  single sub-batch of titles used to print no count at all — and a run that
  processed nothing still prints its `0`. The one exception is a marker-gated early
  return (`IMDb … unchanged since the last sync; skipping.`): that line is already
  terminal, and `Done.` after it would claim work that never happened.
- **A caught-and-reported failure emits a plain closing line naming the count *and*
  the consequence** — `3 shows failed; marker not advanced.` Unindented on purpose:
  the two-space indent means "running count", and a run-level consequence is not
  one. The consequence is the half that matters, and it names whatever *this* leg
  held back — `marker not advanced` in Catalog, `watermark not advanced` in Plex.
  Either way it says the window gets re-covered next run.
- **…and the command returns `FAILURE`.** This knowingly overrode three tests that
  named exit-SUCCESS-on-failure as intent: an invisible failure that silently skips
  a marker advance is worse than a cron alert on a transient miss. A 404 is still
  **not** a failure — it stays present-as-null, or every deleted upstream title
  would alert on every run.
- **An orchestrator names what it lost rather than counting it** — `catalog:sync`
  closes with `Failed commands: catalog:sync-movies`, because a re-runnable command
  name is more use to an operator than a number. That is why it does **not** go
  through `failureSummary()`: a count would say "1 command failed" of a run whose
  whole point is that it kept going, leaving the operator to find which one in the
  interleaved wall of child output. `Done.` still follows it — losing a leg does not
  exempt a run from closing.

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

### Column position: timestamps always last

**`created_at`/`updated_at` are the final two columns of every table, in that
order** — a table reads `id` → keys → source blocks `imdb → tmdb → tvdb` (each
closed by its own `*_synced_at`) → app bookkeeping → timestamps. A new table
declares `$table->timestamps()` last and gets this for free.

- **A migration that adds a column to an existing table places it with
  `->after('<preceding column>')`** — the column lands in its source block instead
  of being appended past `updated_at`, which is how six tables ended up scrambled
  (FLIX-247). Add a whole block with `$table->after('<col>', function (Blueprint
  $table): void { … })` so the group stays contiguous. `after` is a MySQL
  modifier; other grammars ignore it, so it costs nothing on sqlite.
- **Nothing should need rearranging again.** The one-off repair lives in
  `2026_08_13_000000_reorder_table_columns_to_keep_timestamps_last.php` and is
  history, not a pattern to copy — don't write another reposition migration to
  clean up after a missing `after()`.
- `App\Domains\Local\Database\ColumnOrder::alterStatement()` exists for that
  repair: given a table's `SHOW FULL COLUMNS` rows plus a target name order it
  returns one `ALTER TABLE … MODIFY COLUMN … AFTER …` statement, rebuilding each
  definition verbatim and throwing `ColumnOrderMismatch` unless the target order
  is an exact permutation. Reach for it only if a table is already scrambled, and
  guard the call on the MySQL driver.
- Column order is a MySQL concern — the sqlite test DB has none, so ordering is
  never assertable in CI. Verify by hand with `SHOW COLUMNS` after migrating.

### Crosswalk / queryable-id columns — the one ingest-normalize exception

"No transform at ingest" holds for descriptive fields (normalize at read). It does
**not** hold for a **crosswalk id that SQL must key on** — an `upsert` conflict key,
a `whereIn` target, a join key (`_imdb_id`, `_tmdb_id`). A read-time accessor can't
be any of those, so the clean value **must be materialized into the column at write
time**. Ingest therefore does two writes from one source: the **raw** value verbatim
into its own column (e.g. TVDB's whole `_tvdb_remoteIds` crosswalk list — dumb parse,
full parity), **and** the **normalized** id into the queryable column.

- **One shared normalizer, never an inline guard.** Every crosswalk parse site routes
  through the single `App\Domains\Common\Support\SourceId` (`imdb`/`tmdb`/`positiveInt`)
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

## Persistence: version-controlled database seed

The catalog is seeded from **real data committed to the repo** so a fresh
checkout/workspace has a usable dataset with no third-party API calls (FLIX-194).

- **`db:dump` / `db:import`** live in the **`Local`** domain (local-development
  tooling) — `App\Domains\Local\Console\Commands` (registered in `bootstrap/app.php`
  `withCommands`), with `mysqldump`/`mysql` shelled through the `Process` facade
  (fakeable) and the pure helpers in `App\Domains\Local\Database` (`DumpFit`
  fitting, `DumpSelection` coherence, `MysqlConnection` args). "Local-development
  tooling" names the *commands* only — `App\Domains\Local\Database` also holds pure
  schema helpers called from **migrations** (`ColumnOrder`), which run in every
  environment, so the domain must ship to production.
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
  workspace setup smoke (Conductor's `setup.sh`, or LaborForest's `refresh`
  workflow), not a Pest test.

## Cache: store scalars, never objects

`config/cache.php` sets `'serializable_classes' => false` (Laravel's gadget-chain
hardening default), so every store reads through
`unserialize($value, ['allowed_classes' => false])` and **no object survives the
round trip** — it returns as `__PHP_Incomplete_Class`. A `Cache::put`/`forever` of
an object writes fine and can never be read back: the value is write-only.

- **Cache strings, ints, bools, and arrays of those.** A timestamp goes in as
  `->toIso8601String()` and is parsed on read (`SyncMarker`); a header goes in
  verbatim (`ImdbDatasetMarker`).
- **Type-check the read** whenever a stale key may predate the rule
  (`is_string($marker)`) and degrade to the no-value path. An entry poisoned by an
  older build then self-heals on the next write instead of throwing — no manual
  `cache:forget` in the deploy.
- **Never widen `serializable_classes` to rescue a call site** — it weakens a
  security default app-wide for one value that should have been a scalar.
- **The test `array` store is `'serialize' => true` on purpose**, against the
  framework default, so the suite serializes exactly like production. Leaving it
  false is what let a cached `CarbonImmutable` pass all 1217 tests and fail every
  production run (FLIX-287). Never flip it back.

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

## Local worktree tooling: LaborForest + Solo

Two tools, version-controlled. **LaborForest** (`lf`) owns worktree lifecycle
through `.laborforest/workflows/{up,down,refresh}.yaml`; **Solo** owns the
long-running dev processes through a committed `solo.yml`. Conductor's `.conductor/`
is still live for its in-flight workspaces — both toolchains work side by side, and
LaborForest + Solo is the current path for new work.

- **Never put computation in a workflow's bash string.** A `shell` step's `run:` is
  a string inside YAML — nothing can test it, so any logic there is unverifiable by
  construction. Route it through an artisan command and test that at `artisan()`:
  `lf:workspace-env` derives a workspace's site/database/URL. A step should be one
  line.
- **The workflows never touch Solo — but the agent driving them may.** `up` creates no
  Solo state, so `down` has none to reverse, and the boundary stays where the two tools
  already draw it: LaborForest orchestrates worktrees, Solo runs processes inside one.
  A `shell` step reaching across it means either Solo's CLI (gated behind a per-machine
  "local CLI access" setting nothing in the repo can enforce — it *silently no-ops*
  when off, which is worse than failing) or a JSON-RPC socket client. Neither belongs
  in a workflow. **The constraint is on the workflow, not on Solo.** `mcp__solo__create_project`
  registers a worktree with no UI and none of the CLI's fragility, so an agent
  provisioning a workspace should register it there too — the committed `solo.yml`
  processes sync in on their own.
- **Trusting those processes stays human-only, by design.** Every Solo start/restart
  tool is scoped to *trusted* commands and the API exposes no trust/approve tool, so a
  freshly registered project starts with every process stopped; `npm:dev` is the one
  the gate actually changes, its `auto_start: true` notwithstanding. That gate is what
  stops a committed `solo.yml` from auto-running arbitrary commands in any checkout
  that clones it. Never document or script around it; leave the one click to the
  operator.
- **`solo.yml` is repo-controlled, with limits worth knowing.** Solo syncs it into
  local state, but **only `command` processes are YAML-backed** — terminals and
  agents are not stored there at all, so they stay per-machine. New or changed YAML
  commands start **untrusted** and will not run or auto-start until trusted in
  Solo's UI.
- **Only servers that resolve on every checkout belong in the committed `.mcp.json`.**
  `php artisan boost:mcp` is repo-relative and qualifies. Solo's MCP server lives in
  a macOS app bundle — machine-local, so it goes in a user-scoped Claude config, not
  a project-scoped file every checkout inherits.
- **Every destructive step carries the primary guard** —
  `if: test "{{ WORKSPACE_DIR }}" != "{{ PROJECT_PRIMARY_DIR }}"` — and so does
  `up`'s nested `refresh` call. The primary's `lundflix` database holds far more
  than the committed dumps can restore. `tests/Unit/Local/LaborForestWorkflowTest.php`
  is what enforces this; add a destructive step and you must add its matcher there.
- **Teardown is best-effort and always exits 0.** `down` is the only path
  `ready → suspended`, and LaborForest hides its Remove action unless a workspace is
  suspended — so a step that fails makes the worktree permanently undeletable. An
  orphaned resource is the cheaper failure; say so in a heartbeat rather than
  returning non-zero.
- **Per-workspace names are computed, never templated.** `{{ WORKSPACE_SLUG_SNAKE }}`
  verbatim overflows MySQL's 64-char database-name limit on real branch names.
  `lf:workspace-env` strips the project prefix, trims the branch to 40 chars, and
  prefixes `lf-` (Herd site) / `lf_` (database) — which also keeps LaborForest's
  databases visibly distinct from Conductor's `lundflix_*`.
- **Drive LaborForest through its MCP, not the `lf` CLI.** When
  `mcp__laborforest__*` tools are present, use `add-workspace` to cut the worktree,
  `run-workflow` to run `up`/`down`/`refresh`, and `override-workspace-status` to
  clear the `error` a failed run leaves behind — README's *"Driving it from an agent
  (LaborForest MCP)"* carries each tool's arguments. `lf` exposes only `add-project`,
  `run`, `validate` — it cannot create a workspace or clear a status at all. There
  is **no `remove-workspace` tool**; final removal is a GUI action (`remove-project`
  deletes a whole project, not one workspace).
- **`run-workflow` only dispatches.** It returns a run id and the workflow executes
  asynchronously inside the app, so its return says nothing about success. Read
  `.laborforest/ignored/logs/` — the newest file records every step's exit code,
  output and `skip_reason`. Judging a run by the dispatch call is how a failure gets
  reported as a success.
- **Validate through the MCP; the CLI's `lf validate` is inert.** `lf validate` exits
  0 for a missing file *and* for a schema-invalid one. The MCP's `validate-workflow`
  is a real check — it returns `isError` with the reason ("The selected require
  status is invalid. The sort order field is required.") on the same file the CLI
  passes. So a schema check exists, but only through the MCP; the Pest guards remain
  the only check that runs in CI, and a real `up` → `down` round trip is still the
  only end-to-end proof.
- **Fall back to the GUI when the MCP is absent.** No `laborforest` tools usually
  means the session started before the server was registered — a new session fixes
  it. "Nothing is listening" usually means the Settings toggles were never saved;
  `~/.laborforest/settings.yaml` is the source of truth, and the server starts from
  the saved file.
- Machine-local settings that cannot be version-controlled — `~/.laborforest/settings.yaml`
  (`command_launch_terminal`) and trusting a worktree's `solo.yml` commands in Solo's
  UI — are README operator steps. Keep that list short: **an operator step nothing
  enforces is a liability**, so prefer a design that needs none over one that
  documents another. Never install a tool by symlinking out of an app bundle; use
  the app's own supported path, or don't depend on it.

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
- **New required env var → also set the seed `.env` of whichever worktree tool you
  use.** A fresh workspace copies `.env` from a seed checkout, never from
  `.env.example`, so a var added only to `.env.example` leaves every new workspace
  without it.
  - **LaborForest + Solo (current)** — the seed is the primary checkout's own
    `.env`, `~/Sites/<repo>/.env`, which `up.yaml` copies from
    `{{ PROJECT_PRIMARY_DIR }}`.
  - **Conductor (in-flight workspaces)** — the seed is
    `~/conductor/repos/<repo>/.env`.

  Both toolchains are live; set the var in the seed `.env` of each one you still
  cut workspaces from.

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
- **Durable decision records are a different artifact class, and DO live in the
  repo.** The bar above is on **per-ticket** planning — a plan for one piece of
  work, which drifts from its ticket the moment either changes. A **glossary**
  (`CONTEXT.md`) and an **ADR** (`docs/adr/NNNN-slug.md`) are neither: they are
  cross-ticket, decision-level, and outlive the work that produced them. They also
  have to be checked in to do their job — skills read them from the working tree
  while exploring, which a Linear body can't support. Both are created **lazily**,
  only when a term is actually resolved or a decision actually made; see
  `docs/agents/domain.md`.
  - An **ADR is 1–3 sentences** and earns its place only when all three hold:
    hard to reverse, surprising without context, and the result of a real
    trade-off. Miss one and skip it — an easily-reversed decision just gets
    reversed, and an unsurprising one leaves nobody wondering why.
  - **Don't duplicate what this file already says.** A convention documented here
    at length (the DDD layout, raw-source column prefixes) does not also get an
    ADR; two sources drift. ADRs are for decisions with no home here — especially
    deliberate deviations from an outside authority.

### Automatic ticket status transitions

The flow skills advance a ticket's status **automatically** as work crosses each
lifecycle boundary — no manual status changes. The map (each fires once, at the
boundary named):

| Boundary | Fired by | Target status |
| --- | --- | --- |
| Planning done (TDD backlog appended) | `plan-slices` | **Todo** |
| Execution begins (first slice for the ticket) | `tdd` | **In Progress** |
| PR opened | `review:create-pr`, then **verified** (see below) | **In Review** |
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

### PR-open is contended — write, then verify

At PR-open **both** `review:create-pr` and Linear's GitHub integration write the
status, and the integration's default mapping for *opened* is In Progress — so our
In Review write can be reverted milliseconds later, nondeterministically and
silently. That one transition is therefore **write → read back → correct once**:
`save_issue(state: "In Review")`, re-read with `get_issue` **after** the PR-created
call returns, and re-apply once if it was reverted (say so in the report). A
**second** revert means the integration is fighting the contract — stop, leave it,
tell the user to fix the mapping, never loop. **The durable cure is one writer, not
a better retry:** set the integration's PR-opened mapping to In Review in Linear's
GitHub settings — a vendor-dashboard click, so offer `mattpocock-skills:wizard`.

The incident behind the rule and the timing forensics:
`docs/agents/linear-pr-open-contention.md`.

## Agent skills

Configuration the installed engineering skills read before they act —
`mattpocock-skills:triage`, `:to-spec`, `:to-tickets`, `:wayfinder`,
`:code-review`. They are **user-scoped**, not a plugin and not in this repo —
they live under `~/.claude/skills/mattpocock-skills/`, so **every one is invoked
with that prefix** and none of them are available in a checkout on a machine that
has not installed them; the skill files' own cross-references to bare
`/to-spec`-style names are upstream text and are stale here. Written by
`mattpocock-skills:setup-matt-pocock-skills`; edit `docs/agents/*.md` directly to
change the config.

**`/map` is the router** — one user-invoked skill naming every skill, command,
subagent, and flow here, and pointing at the phase-boundary tree beside it. Open it
when you've forgotten what exists.

### Borrowed practice carries a Source line

Several native skills adapt practice from the AI Hero plugin rather than calling it,
each borrowed section closing with a `**Source:**` line naming the upstream skill.
Two reasons. **20 of the 35 upstream skills set `disable-model-invocation: true`**,
so nothing here *can* call them — including the one inlined most directly,
`ask-matt` (Source of `/map`). The rest are callable — `code-review`'s smell
baseline, `writing-for-agents` — and are inlined anyway, because the practice has to
be in context *before* the work starts: one Skill call per reviewer costs more than
the text and lands too late to shape the finding. (A different set from the five
config readers named above; these are skills whose *text* is adapted here.)

**When you apply a section that carries one, offer to explain its origin** — the
upstream skill, what it argues, and the file to read. One line, then continue:
*"This is the seam contract, adapted from `mattpocock-skills:tdd` — want the
original reasoning?"* Offer once, and paste upstream text only when asked.

### Human-only steps → offer the wizard

When a task needs steps only a human can take — provisioning a third-party
credential, clicking through a vendor dashboard, setting a CI secret, a one-off
cutover — offer `mattpocock-skills:wizard`. It generates an interactive bash script
that opens each URL, captures each value, and writes it where it belongs, so the
procedure stops being re-explained every time. Adding an API credential here is the
standard case: the value must reach `.env.example`, the README key table, **and**
the seed `.env` of every worktree tool in use — the primary checkout's `.env` under
LaborForest + Solo, the Conductor root `.env` for in-flight Conductor workspaces.
Do the work directly whenever you can; the wizard is for where a human is genuinely
in the loop.

### Issue tracker

Linear, team `lundflix` (`FLIX-123`), via `mcp__linear-server__*` only — GitHub
Issues are unused. See `docs/agents/issue-tracker.md`.

### Triage labels

The five canonical roles, each label string equal to its name. See
`docs/agents/triage-labels.md`.

### Domain docs

Single-context: `CONTEXT.md` + `docs/adr/` at the repo root. See
`docs/agents/domain.md`.
