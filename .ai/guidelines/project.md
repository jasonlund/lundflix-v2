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

Domain boundaries enforced by **Pest architecture tests**, not code review (a
domain's `Models` used only within that domain; `Common` depends on no concrete
domain). Full TDD conventions + arch-test suite land in a separate PR.

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
