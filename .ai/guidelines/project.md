# Project Guidelines

Project-specific conventions, on top of the Boost guidelines above.

## Editing these guidelines

This whole `<laravel-boost-guidelines>` block is **generated** — do not hand-edit
`CLAUDE.md` or `AGENTS.md` (they'd drift apart and your change is overwritten on
the next regen). Single source of truth: `.ai/guidelines/*.md`. To change project
conventions, edit `.ai/guidelines/project.md`, then run `php artisan boost:install
--guidelines` to rewrite the block into every agent file identically.

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

- Name `VerbNoun` in PascalCase, **no `Action` suffix**; noun includes the entity
  so it reads clearly when imported — `CreateUser`, `UpdateUserProfile`,
  `ResetUserPassword` (not `Create`, `UpdateProfile`).
- Standalone actions expose one `handle()` method. Actions bound to a framework
  contract (e.g. Fortify `CreatesNewUsers`) keep the interface's method name
  (`create`/`update`/`reset`).
- Example: Fortify auth/profile actions live in `App\Domains\Identity\Actions`,
  wired in `App\Providers\FortifyServiceProvider` (app root, infra).

### Exceptions

Always use **explicitly named exception classes** — one class per distinct
failure, named for the failure it represents. Never funnel multiple unrelated
failures through a single catch-all exception (with static factory methods or a
type/code discriminator) — split it into named classes so callers can `catch`
each case by type.

- Name for the condition, PascalCase, in `App\Domains\{Domain}\Exceptions` —
  e.g. `CorruptImdbDatasetArchive`, `CannotOpenImdbDatasetArchive`.
- A static named constructor (`::at($path)`) for message construction is fine;
  one-failure-per-class is the rule, not the factory style.

### Enums

Logic that operates over an enum's **own cases** — validating, parsing, or
normalizing raw values against the case set — lives as **static methods on the
enum**, not in a trait, helper, or action. Keep it next to the cases it checks.

- e.g. `Genre::knownValues(array $raw): array` filters raw IMDb strings to
  recognized backing values, dropping unknown ones.
- Don't reach for a shared `Concerns/` trait just because two actions need it —
  a static enum method shares just as well and keeps the knowledge on the type.

### Cross-domain rules

- A domain never imports another domain's `Models` or internals — only its
  `Contracts/` (interfaces) or published `Services`.
- `Common` is the shared kernel: only *incredibly stable* shared concepts (value
  objects, enums, contracts, DTOs). Keep it small — bloat couples every domain.
- `Common` depends on nothing domain-specific.

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

**Test-first by default.** Build features via the `tdd` skill
(`.claude/skills/tdd`): RED → GREEN → REFACTOR, one behavior **slice** (a cohesive
set of ~2–6 tests) per cycle, each phase run by an **isolated subagent** so tests
can't be retrofitted to the code. The RED slice is presented for approval via
Conductor's plan UI before any code is written.

- **Every test follows Arrange–Act–Assert (AAA).** No exceptions, backend or
  frontend: three blocks in order — set up state, perform **one** action, assert
  the outcome — separated by a blank line. One Act per test; need a second action,
  write a second test. Keep Arrange minimal (factories/props only).
- **Test behavior through public interfaces, not implementation** — tests must
  survive refactoring. A slice = one coherent behavior plus its obvious variants.
- **Tests mirror the domain tree.** Backend tests live under
  `tests/Feature/{Domain}/` (and `tests/Unit/{Domain}/`), mirroring
  `app/Domains/{Domain}/` — the same way the frontend mirrors domains via
  `resources/js/modules/{domain}/`.
- **API / external-HTTP tests use real-data fixtures in the API's native wire
  format.** Capture a small real response slice, commit it under
  `tests/Fixtures/{Domain}/{source}/` (domained, sub-keyed by external source) in
  the exact format + extension the API returns (`.tsv.gz`, `.json`, …) — the
  fixture is a **byte-exact copy** of the source response, no transform. Load it
  into `Http::fake()` via `fixtureBytes('Catalog/imdb/title.basics.tsv.gz')`
  (reads the bytes; Pest's built-in `fixture()` resolves the path). Never
  fabricate response bodies by hand. `Http::preventStrayRequests()` is on
  globally for Feature tests (`tests/Pest.php`), so every external call must be
  faked or the test fails. (DB *state* still uses factories, never fixtures —
  response-body fixtures are a different thing. Synthetic bodies are allowed only
  for inputs that can't exist in real data: malformed/corrupt payloads, blank
  lines, HTTP error responses.)
- **Backend:** Pest 4 — `php artisan test --compact` (filter `--filter=name`).
  Feature tests are the default (`tests/Feature`); unit tests only for isolated
  logic (`tests/Unit`). Use factories + `RefreshDatabase`; assert Inertia props
  with `AssertableInertia`. Create tests via `php artisan make:test --pest`.
- **Frontend:** Vitest + React Testing Library — `npm test`. Colocate a
  `*.test.tsx` sibling; query by role/text; mock `@inertiajs/react`; jsdom env,
  setup at `resources/js/test/setup.ts`.
- **Full-stack Inertia feature** → two cycles, **backend first** (Feature test
  asserts the Inertia component + props), then frontend (RTL renders the page with
  those props).
- Detailed conventions live in the `.claude/skills/laravel-testing` and
  `.claude/skills/react-testing` skills (referenced, not duplicated here).

Domain boundaries are enforced by **Pest architecture tests**, not code review (a
domain's `Models` used only within that domain; `Common` depends on no concrete
domain). The arch-test suite itself lands in a separate PR.

### File creation

Always create files with `php artisan make:*` commands when one exists (models,
migrations, policies, tests, classes via `make:class`, etc.) — don't hand-write
boilerplate. Make it land in the DDD structure: pass the domain path in the
name, e.g. `php artisan make:model Domains/Catalog/Models/Product` →
`app/Domains/Catalog/Models/Product.php`, namespace
`App\Domains\Catalog\Models`. If a generator can't target the domain path,
generate then move the file and fix its namespace. Never break the DDD layout
to satisfy a generator's default location.

## Documentation

- **Keep the README in sync.** When a change touches anything `README.md`
  documents (setup, commands, env vars, architecture, dependencies, structure),
  prompt the user to update it and name the stale section. Never silently edit
  the README; never let it drift.
- **Grow the Overview and Screenshots as features ship.** The README `Overview`
  and `Screenshots` sections start as TODO placeholders. When a user-facing
  feature lands, prompt the user to extend the Overview to describe it and to add
  a screenshot/demo of it. Remove the TODO marker once the section has real
  content.

## Linear (Issue Tracking)

All work tracked in **Linear** via the `mcp__linear-server__*` MCP (Conductor
provides detailed usage separately).

- **Always use the Linear MCP tools** for every lookup/create/update — never
  assume or hand-edit ticket state.
- **Every branch maps to ≥1 ticket**, and the branch name includes every ticket
  id it addresses. Use Linear's branch name but drop the user prefix
  (`jasonlund/`) and shorten to ≤40 chars — e.g. `flix-123-scaffold-new-app`.
- **No ticket yet → prompt to create one** (via Linear MCP) from the work at
  hand. Don't proceed ticketless.
- **Work deviates → update the ticket, confirm first.** If implementation
  diverges from the ticket, prompt the user to confirm, then update the ticket
  and mark it clearly as a deviation.
