# lundflix

> One-line tagline — what this app does.

<!-- badges: CI · PHP 8.4 · Laravel 13 · License -->

## Overview

<!-- 2–3 sentences: the problem, and what you built. -->

## Screenshots

<!-- demo link / screenshots -->

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

## License

<!-- license -->
