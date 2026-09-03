# lundflix

> Plex library management for friends & family

![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)
![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![React 19](https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=black)
![Inertia v3](https://img.shields.io/badge/Inertia-v3-9553E9?logo=inertia&logoColor=white)
![TypeScript](https://img.shields.io/badge/TypeScript-3178C6?logo=typescript&logoColor=white)
![Tailwind v4](https://img.shields.io/badge/Tailwind-v4-06B6D4?logo=tailwindcss&logoColor=white)
![License: MIT](https://img.shields.io/badge/License-MIT-yellow)
[![CI](https://github.com/jasonlund/lundflix-v2/actions/workflows/ci.yml/badge.svg)](https://github.com/jasonlund/lundflix-v2/actions/workflows/ci.yml)

## Overview

<!-- TODO: write the overview — the problem and what lundflix does. Expand as features ship. -->

## Screenshots

<!-- TODO: add screenshots / demo link as features ship. -->

## Tech Stack

| Layer | Tech |
| --- | --- |
| Backend | PHP 8.4 · Laravel 13 |
| Frontend | Inertia v3 · React 19 · TypeScript · Tailwind v4 |
| Admin | Filament v5 |
| Infra | Horizon · Scout · Pennant · Nightwatch |
| Tooling | Pest v4 · Pint · Rector · ESLint · Prettier |

## Architecture & Key Decisions

This app is built **domain-driven** — code is organized by business domain, not
technical type — with each decision made to keep tooling and the team moving fast.

- **Single domain namespace (`App\Domains\*`).** All domain code lives under one
  root inside `app/`. Chosen over a hexagonal `app/{Domain,Application,Infrastructure}`
  split specifically so Rector, Shift, and IDE auto-discovery keep working — DDD
  without fighting the framework.
- **`Common` shared kernel.** Stable, cross-domain concepts (value objects, enums,
  contracts, DTOs) live in `App\Domains\Common`. Kept deliberately small — a
  bloated shared kernel couples every domain together.
- **Domains talk through contracts, not internals.** A domain never reaches into
  another's models; it depends on the other domain's `Contracts/`. Boundaries stay
  explicit and refactors stay local.
- **Frontend mirrors the backend.** `resources/js` splits into `common/` (generic
  UI), `modules/{domain}/` (domain UI), and `pages/` (Inertia entry points) — the
  same mental model on both sides of the Inertia boundary.

## Project Structure

```
app/Domains/
├── Common/              # shared kernel — value objects, enums, contracts, DTOs
└── {Domain}/            # one folder per bounded context (Models, Actions, Contracts, …)
app/                     # Http, Filament, Providers — infra/UI that calls into domains
resources/js/
├── common/              # generic, reusable UI
├── modules/{domain}/    # domain-specific UI/logic
└── pages/               # Inertia pages, organized by URL
```

## Testing

Built **test-first (TDD)**, and the architecture defends itself:

- **A RED → GREEN → REFACTOR workflow** drives feature work — one behavior slice
  at a time, each phase handled by an isolated agent so tests are written before
  the code (see the `tdd` skill in `.claude/skills/tdd`). Ahead of it, the
  `plan-draft` skill turns a rough Linear ticket into one concrete, decision-locked
  plan through an interactive interview; `plan-breakdown` decomposes a large
  plan/PRD into parallelizable Linear tickets; and `plan-slices` turns each
  ticket/plan into an ordered, test-first slice backlog to feed it
  (`.claude/skills/{plan-draft,plan-breakdown,plan-slices}`).
- **Ticket status tracks the flow automatically.** The flow skills advance the
  Linear ticket as work crosses each boundary — see "Automatic ticket status
  transitions" in `.ai/guidelines/project.md` for the full boundary → status map.
  The one exception is the final **Done** transition, which is not skill-driven: it
  comes from **Linear's native GitHub integration**, a one-time operator setup. In
  Linear → Settings → Integrations → GitHub, map a **merged** linked PR to **Done**,
  and make sure its "PR opened" automation is off (or also set to In Review) so it
  doesn't conflict with the skill-driven In Review. Without this integration
  configured, merged tickets never move to Done.
- **Backend:** feature and unit tests via **Pest v4**, run with `php artisan test`.
- **Frontend:** component/page tests via **Vitest + React Testing Library**, run
  with `npm test`.
- **Pest architecture tests enforce domain boundaries** — a domain's models may
  only be used within that domain, and `Common` may not depend on any concrete
  domain. The rules aren't documentation, they're a failing test if violated.

## Getting Started

### Prerequisites

- PHP 8.4
- Composer
- Node.js & npm

### Installation

```bash
git clone <repo-url>
cd <project>

composer setup
```

`composer setup` runs the full bootstrap: installs PHP & npm dependencies,
copies `.env`, generates the app key, runs migrations, and builds frontend
assets.

### Using LaborForest + Solo (parallel agent workspaces) — current

Two tools split the job. **LaborForest** (`lf`) owns worktree lifecycle;
**Solo** owns the long-running dev processes inside each worktree. Both are
configured in version control, so a fresh clone knows how to build a workspace and
what to run in it.

Worktrees land beside the primary checkout as `~/Sites/lundflix-v2-<branch-slug>`.

- **Create** → `.laborforest/workflows/up.yaml`: fetch and fast-forward onto
  `origin/main`, copy `.env` from the primary, derive this workspace's names, create
  its **own** MySQL database (`lf_<branch>`), install deps, build assets, link a
  secured Herd site (`https://lf-<branch>.test`), migrate, then reseed via
  `refresh`. Each workspace is isolated, so one branch's migrations never touch
  another's schema.
- **Run** → open the worktree in Solo; `solo.yml` declares `npm:dev` (auto-starts),
  `Horizon`, `Queue`, and `Pint`.
- **Reset** → `lf run refresh`: `migrate:fresh` → `db:seed` → `db:import`, which
  restores the committed catalog dumps when `database/dumps/` holds any. Re-runnable
  against a working workspace. **That directory is currently empty**, and `db:import`
  skips missing files and still reports success — so a fresh workspace comes up with a
  working app and an empty catalog.
- **Remove** → `down` reverses everything `up` created outside the worktree: the
  database and the Herd site. LaborForest hides its Remove action unless a workspace
  is suspended, and `down` is the only way to get there — so teardown cannot be
  skipped.

**The primary checkout is `~/Sites/lundflix-v2`, and `up`, `refresh` and `down` all
guard it.** Each destructive step — and `up`'s nested `refresh` call — compares the
workspace directory against the project's primary directory and skips when they are
the same, so running any of them from the primary drops or reseeds nothing.

**Env vars:** new workspaces copy `.env` from the **primary checkout**
(`~/Sites/lundflix-v2/.env`), *not* from `.env.example` — so a new required var must
be added there too. The primary also needs one key no workflow writes for it:
`LF_SITE=lundflix-v2`.

#### Driving it from an agent (LaborForest MCP)

LaborForest ships a local MCP server, so an agent can cut a worktree and provision
it without you touching the GUI. Enable it once in **LaborForest → Settings → MCP**
(Enable MCP on, Read only **off** — `add-workspace` is only exposed on a writable
server), press **Save changes**, then run the `claude mcp add …` line it shows you.

That command is `--scope user` on purpose: the server is a machine-local app on a
localhost port with a bearer token, so it belongs in your user config. It is **not**
in this repo's `.mcp.json`, which only carries servers whose command resolves on
every checkout.

The tools arrive prefixed `mcp__laborforest__`, and the four that matter are:

| Tool | Arguments | Does |
| --- | --- | --- |
| `add-workspace` | `path` (project) or `uuid`, `branch`, `base_branch` | cuts the worktree |
| `run-workflow` | `path` (workspace), `workflow` | runs `up` / `down` / `refresh`; returns a run id |
| `override-workspace-status` | `path`, `status` (`ready`\|`suspended`) | clears the `error` a failed run leaves |
| `validate-workflow` | `path`, `workflow` | parses a workflow — see the caveat below |

So the whole provision is one instruction to an agent: *"cut a worktree for branch X
off main and bring it up."* It calls `add-workspace`, then `run-workflow up`, then
reads `.laborforest/ignored/logs/` for the per-step result — `run-workflow` only
dispatches, so the log is where success or failure actually shows up. It can finish
the job with `mcp__solo__create_project` (see [Adding a worktree to
Solo](#adding-a-worktree-to-solo)), which leaves you one trust click from a running
workspace.

**A branch cut before the LaborForest work landed cannot be brought up as-is.** `up`
calls `php artisan lf:workspace-env`, which arrived with that same change, and step 2
deliberately does *not* fast-forward a branch carrying its own commits — so the run
aborts with `There are no commands defined in the "lf" namespace` and every later step
reports `skip_reason: aborted`. Merge `main` into the branch first, then clear the
`error` status and re-run.

**Two limits.** There is **no `remove-workspace` tool** — `remove-project` removes a
whole project, not one workspace — so final removal stays a GUI action after `down`.
And enabling this grants more than worktrees: with Read only off and shell execution
allowed, the bearer token authorises arbitrary shell commands and `update-settings`
can re-widen the settings themselves. Regenerate the token if it is ever exposed.

#### Fallback: when the MCP doesn't answer

Everything below works with the MCP off, and is what to reach for when an agent
reports no `laborforest` tools or a connection error.

First, tell the two apart:

- **No `laborforest` tools at all** — Claude Code binds MCP servers at session start.
  If you registered the server after the session began, start a new session; nothing
  needs redoing.
- **"Nothing is listening" / connection refused** — check `~/.laborforest/settings.yaml`
  actually says `mcp_enabled: true`. The Settings toggles do nothing until **Save
  changes** is pressed, and the server starts from the saved file, so unsaved toggles
  look correct while nothing is bound to the port.
- **401** — the token was regenerated; re-run `claude mcp add` with the new one.

Then do it by hand:

| Instead of | Do |
| --- | --- |
| `add-workspace` | **Add workspace** in the LaborForest GUI |
| `run-workflow` | the row's **Workflows** button, or `lf run <name>` from inside the worktree |
| `override-workspace-status` | the row's **⋮** menu → status action → `suspended` |
| reading the run id | `.laborforest/ignored/logs/` directly, newest file |

`lf` itself only exposes `add-project`, `run` and `validate` — there is no CLI path
to creating a workspace or clearing a status, which is why those two rows say GUI.

#### Adding a worktree to Solo

**Solo's project list is managed in Solo, not by the workflows** — nothing in
`up`/`down` touches Solo's registry, so there is no Solo state for teardown to
reverse. That constraint is on the *workflows*, though, not on you: registering the
worktree is one MCP call, and only trusting its processes needs a human.

- **Registering — an agent can do this.** `mcp__solo__create_project` with the
  worktree path registers it without opening Solo's onboarding UI, so "cut a worktree
  for branch X and bring it up" can end with the project already in Solo. By hand it's
  **Add project** in Solo's UI. Remove it there when you're done either way.
- **Trusting — only you can do this.** New or changed YAML commands start
  **untrusted**, and every Solo start/restart tool is scoped to trusted commands, so a
  freshly registered project sits with all four processes stopped — `npm:dev` included,
  despite its `auto_start: true`. Trust them in the Solo UI or they will not run. This
  is deliberate: it's what stops a committed `solo.yml` from auto-running arbitrary
  commands in any checkout that clones it.
- Solo reads the worktree's committed `solo.yml` and syncs those processes in. Only
  **command** processes are YAML-backed — terminals and agents are not stored in
  `solo.yml`, so those stay per-machine.

Optionally point LaborForest's terminal launcher at Solo in
`~/.laborforest/settings.yaml` (`command_launch_terminal`) — a machine-local setting,
not version-controlled.

#### When a workflow fails

LaborForest stops at the first failing step, skips the rest, and leaves the
workspace unprovisioned rather than half-built — but it also moves it to **`error`**,
and only `ready` and `suspended` can launch a workflow. So **fixing the problem is
not enough to retry**: `lf run up` will print `Running workflow: up`, exit 0, and do
nothing. Clear it with `override-workspace-status` (`status: suspended`), or the
status action in the workspace row's ⋮ menu. There is no `lf` equivalent — this is
one of the two things only the MCP or the GUI can do.

Read the run logs at `.laborforest/ignored/logs/` — each records every step's exit
code, output, and `skip_reason`, which is the fastest way to see where a run stopped.

Note `lf validate` is not a check — it exits 0 for a missing or schema-invalid
workflow. The Pest guards in `tests/Unit/Local/` are what verify these files.

### Using Conductor (parallel agent workspaces) — legacy

Still live for the workspaces already cut under it; not used for new work. Each
Conductor workspace is its own git worktree; setup/teardown is automated by
`.conductor/`:

- **Create** → `.conductor/setup.sh` installs deps, builds assets, links a
  per-workspace Herd site (`https://<workspace>.test`), and provisions the
  workspace its **own** MySQL database (`lundflix_<workspace>`): create → migrate →
  seed → `db:import` of the committed catalog dumps. Each workspace is isolated, so
  a branch's migrations never touch another's schema.
- **Run** → `npm run dev` (Vite); Herd serves the PHP app. One workspace at a time
  (`run_mode = "nonconcurrent"`).
- **Merge** → the workspace auto-archives on PR merge, `.conductor/archive.sh`
  unlinks the Herd site, **drops** the workspace's database, and the branch is
  deleted.

**Env vars under Conductor:** new workspaces copy `.env` from the repository's
**root checkout** (`~/conductor/repos/lundflix-v2/.env`), *not* from
`.env.example` — so a new required var must be added to that root `.env` too.

### Required API keys

Some features call third-party APIs and need credentials in your `.env` before
they work. After `composer setup`, fill in every key below:

| Env var | Required for | How to obtain |
| --- | --- | --- |
| `TMDB_TOKEN` | Catalog — TMDB movie/TV metadata | A TMDB API Read Access Token from your [themoviedb.org](https://www.themoviedb.org/settings/api) account settings |
| `TVDB_KEY` | Catalog — TheTVDB movie/TV metadata | A TheTVDB v4 apikey from the [thetvdb.com](https://thetvdb.com/api-information) API information page (free tier requires TheTVDB attribution) |
| `PLEX_TOKEN` | PlexLibrary — server discovery + library sync | Your Plex account's `X-Plex-Token`; obtain it from any authenticated Plex request per Plex's [Finding an authentication token](https://support.plex.tv/articles/204059436-finding-an-authentication-token-x-plex-token/) article |
| `PLEX_SERVER_IDENTIFIER` | Identity — registration access check | Your Plex Media Server's machine identifier — the `clientIdentifier` of your server in a `GET https://clients.plex.tv/api/v2/resources` response. Registration verifies the visitor can reach this server, so the flow fails without it |
| `DOWNLOADS_UID` | Download — provider authentication | The `uid` browser cookie value from a logged-in provider session. Seeds the initial value; rotate later in the admin panel without a redeploy |
| `DOWNLOADS_PASS` | Download — provider authentication | The `pass` browser cookie value from a logged-in provider session. Seeds the initial value; rotate later in the admin panel without a redeploy |
| `DOWNLOADS_RSS_KEY` | Download — RSS feed authentication | The account-level `tp` token from the provider's Generate RSS page. Seeds the initial value; rotate later in the admin panel without a redeploy |
| `SLACK_BOT_USER_OAUTH_TOKEN` | Notifications — Slack delivery | The Bot User OAuth Token from your Slack app's [OAuth & Permissions](https://api.slack.com/apps) page; authenticates `chat.postMessage` |
| `SLACK_BOT_USER_DEFAULT_CHANNEL` | Notifications — Slack delivery | The Slack channel name or ID notifications post to by default (e.g. `#alerts`); choose a channel your bot is a member of |

### MCP servers (Claude Code)

The repo commits a project-scoped `.mcp.json` registering the **Laravel Boost**
MCP server (`php artisan boost:mcp`), which gives Claude Code the Boost tools
(`search-docs`, `tinker`, `database-schema`, …) on top of the generated
guidelines block. Because it is project-scoped, **the first Claude Code session in
any new checkout or workspace prompts you to approve it** — approve it once and
restart the session for the Boost tools to connect.

Only servers whose command resolves on *every* checkout belong in this file;
`php artisan boost:mcp` is repo-relative and does. **Solo's MCP server is not
registered here** — it lives inside the Solo app bundle, so committing its path
would bake one machine's layout into shared config. Register it per-user instead if
you want its tools (process output, bound-port waits, locks, todos, scratchpads).

### Running locally

```bash
composer dev
```

Starts the PHP server, queue worker, log tailer (Pail), and Vite dev server
together. Visit the app at the URL printed by `php artisan serve`.

In a worktree, Herd serves the PHP app and only Vite needs starting:
`https://lf-<branch>.test` under LaborForest (Solo's `npm:dev` process auto-starts
it), or `https://<workspace>.test` in a Conductor workspace (the Run button).

### Running tests

```bash
php artisan test   # backend (Pest)
npm test           # frontend (Vitest)
```

### Database seed (dumps)

The catalog is seeded from real data committed to the repo, so a fresh checkout
or workspace has a usable dataset without calling any third-party API.

```bash
php artisan db:import   # truncate the catalog tables and load database/dumps/*.sql.gz
php artisan db:dump     # regenerate the dumps from the current database
```

- **`db:import`** loads the version-controlled seed (`database/dumps/`) into the
  current database, truncating each table first. Pass `--from=<dir>` (or
  `--path=<dir>`) to load a full local dump from elsewhere instead.
- **`db:dump`** writes two artifacts: the **version-controlled set** — one gzipped
  file per table under `database/dumps/`, each capped under 50 MB (a best-first
  slice by popularity) so it stays git-friendly — and a **full local dump** to
  `DB_DUMP_PATH` (optional; defaults to `storage/app/backups`, may point anywhere
  including outside the project). `--vc` / `--full` write only one side;
  `--unlimited` removes the size cap. Regenerating rewrites the committed blobs, so
  do it deliberately (each regen grows git history by roughly the seed size).

## Configuration

`.env.example` holds **local development** values only — it is the one env file
committed to the repo, and it is an example of a *local* setup, **not**
production. Copy it to `.env` and you have a working local environment.

Real environments are gitignored: `.env` (local) and `.env.production` (prod).
Production values are set on the host platform (Laravel Cloud), not committed to
the repo, so they do not live in any file you can read here.

**Production diverges from `.env.example`.** When investigating a production
issue, you cannot assume production uses the same values as `.env.example` —
verify against the production environment, and consult the table below for the
keys that intentionally differ.

| Key | Local (`.env.example`) | Production |
| --- | --- | --- |
| `SCOUT_DRIVER` | `database` (search indexed in the local DB via SQL — no extra service) | `typesense` (dedicated search engine) |
| `NIGHTWATCH_TOKEN` | empty (agent does not run locally) | set (monitoring agent runs in prod) |
| `QUEUE_CONNECTION` | `sync` (jobs run inline; no worker/redis needed) | `redis` (jobs processed by Horizon) |
| `SCOUT_QUEUE` | unset → `false` (catalog commands index synchronously) | `true` — the catalog is large enough that inline indexing would serialize millions of writes into the import, so writes are queued (requires Horizon running) |

## Continuous Integration

Every push to `main` and every pull request runs `.github/workflows/ci.yml`,
which gates merges on four parallel jobs:

- **Backend tests** — builds frontend assets (for the Vite manifest), then runs
  Pest (`php artisan test`).
- **PHP code quality** — Pint style check (`vendor/bin/pint --test`) and a
  Composer security audit.
- **Frontend code quality** — ESLint, Prettier format check, TypeScript type
  check, and an npm audit of production dependencies.
- **Frontend tests** — Vitest and a production build.

Run the same checks locally:

```bash
composer test:lint   # Pint (style, check-only)
composer test:refactor  # Rector (dry run; not yet a CI gate)
npm run lint:check   # ESLint (check-only)
npm run format:check # Prettier
npm run types        # tsc --noEmit
```

Dependency updates are proposed weekly by Dependabot (`.github/dependabot.yml`)
for Composer, npm, and GitHub Actions.

## License

Released under the [MIT License](LICENSE).
