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
  the code (see the `tdd` skill in `.claude/skills/tdd`).
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

### Running locally

```bash
composer dev
```

Starts the PHP server, queue worker, log tailer (Pail), and Vite dev server
together. Visit the app at the URL printed by `php artisan serve`.

### Running tests

```bash
php artisan test   # backend (Pest)
npm test           # frontend (Vitest)
```

## Continuous Integration

Every push to `main` and every pull request runs `.github/workflows/ci.yml`,
which gates merges on three parallel jobs:

- **Backend tests** — builds frontend assets (for the Vite manifest), then runs
  Pest (`php artisan test`).
- **PHP code quality** — Pint style check (`vendor/bin/pint --test`) and a
  Composer security audit.
- **Frontend** — ESLint, Prettier format check, TypeScript type check, Vitest,
  a production build, and an npm audit of production dependencies.

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
