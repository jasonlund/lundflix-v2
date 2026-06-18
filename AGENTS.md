<laravel-boost-guidelines>
=== .ai/project rules ===

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

## Linting & formatting (finalize gates)

Before finalizing **any** change, run every linter/formatter that applies to the
files you actually touched — not just Pint. Scope each tool to your changed work,
never a repo-wide sweep (a bare `vendor/bin/rector` rewrites generated
`bootstrap/cache/*` and unrelated files; only process what you changed).

- **PHP files touched** → run, in order:
  1. `vendor/bin/rector process <your changed files>` — typed constants, return
     types, dead code, code-quality sets.
  2. `vendor/bin/pint --dirty --format agent` — style + `declare(strict_types=1)`.
     Run Pint **after** Rector so it normalizes anything Rector reformatted.
- **Frontend files touched** (`.ts`/`.tsx`/`.js`/`.css` under `resources/`) → run:
  - `npm run lint` (ESLint `--fix`)
  - `npm run format` (Prettier `--write resources/`)
  - `npm run types` (`tsc --noEmit`)
- Then run the affected tests (`php artisan test` / `npm test`) — linters reorder
  and retype code, so re-verify green before finalizing.

## Configuration

- **Base URLs for third-party data sources are service constants, not env or
  config.** A public, fixed endpoint (e.g. the IMDb datasets host
  `https://datasets.imdbws.com`) is not a secret and does not vary by
  environment — commit it as a `private const` on the service that calls it, so
  the value sits next to the code that uses it. Reserve `config`/`env` for
  secrets, credentials, and values that genuinely differ per environment.
- **Name a credential's config key after the provider's own doc verbiage.** A
  third-party credential's `config`/`env` key uses the term that provider's API
  docs use for it — don't force one shared word across providers. TMDB's docs
  call it the "API Read Access Token" → `services.tmdb.token` / `TMDB_TOKEN`;
  TheTVDB's docs call it the "apikey" → `services.tvdb.key` / `TVDB_KEY`. They
  differ on purpose: the config reads the way each provider's docs read. A
  short-lived value *derived* from the stored credential (e.g. the JWT TheTVDB
  returns from `POST /login`) is internal — cache it, never put it in
  `config`/`env`. So `key`/`token` names what you store; the bearer you send may
  be that same value (TMDB) or one exchanged for it (TVDB).
- **Only *required* env vars belong in `.env.example`.** An env var the app
  needs to run (a secret/credential with no safe default — e.g. an API token)
  goes in `.env.example`. An *optional* tunable that reads through `env()` with a
  sensible default in `config/` (e.g. a concurrency cap or retry delay) stays out
  of `.env.example` — its default IS the documentation. Don't pad the example
  file with every knob; a fresh clone should see only the keys it must fill in.

## Documentation

- **Keep the README in sync.** When a change touches anything `README.md`
  documents (setup, commands, env vars, architecture, dependencies, structure),
  prompt the user to update it and name the stale section. Never silently edit
  the README; never let it drift.
- **A new required env var → update the README install steps.** When work adds an
  env var the app needs to run (e.g. a third-party API key/token), add it to the
  README "Required API keys" table in *Getting Started* so a fresh install has
  every key defined — var name, what it's required for, and where to obtain it.
  Don't leave a key documented only in `.env.example`.
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
- **Planning artifacts live in Linear, not in the repo.** PRDs, plans, slice
  backlogs, and decomposition notes belong in the relevant Linear issue
  (description or comment) — never committed as repo files. Do not create a
  `docs/plans` or `.ai/plans` tree; a plan written to disk drifts from the ticket
  and silently biases future agents who read it as if it were a convention. This
  bars *version-controlled* planning files only — it does not apply to
  un-versioned, gitignored scratch space an agent needs for its own tracking
  (e.g. the `.context` working directory). Writing transient notes there is fine;
  they never enter the repo, so they can't drift into a false convention.

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
