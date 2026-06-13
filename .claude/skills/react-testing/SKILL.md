---
name: react-testing
description: >-
  Conventions for writing React + Inertia frontend tests (Vitest + React Testing
  Library) for this app: rendering pages/components with props, querying by role,
  user interaction, and Inertia mocking. Loaded by the TDD subagents when the
  target is TSX/JSX. Use when writing or refactoring React tests.
---

# React + Inertia testing conventions

> Stack: React 19 + `@inertiajs/react` ^3, Vite 8, npm. Confirm the actual runner
> and scripts from `package.json` before assuming.
>
> ⚠️ As of the scaffold, the frontend test toolchain is **not yet installed**
> (no `vitest`, no `@testing-library/*`, no `jsdom`, no `test` script, no vitest
> config). Until it is, frontend RED cannot run. See "Setup (one-time)" below.

## Setup (one-time, if not already present)

Install dev deps and add a `test` script:

```bash
npm i -D vitest jsdom @testing-library/react @testing-library/jest-dom \
  @testing-library/user-event @testing-library/dom
```

`package.json` script: `"test": "vitest run"` (and `"test:watch": "vitest"`).
Add a `vitest` block to `vite.config.ts` with `environment: 'jsdom'`,
`globals: true`, and `setupFiles` importing `@testing-library/jest-dom`. A setup
file at `resources/js/test/setup.ts` is the convention.

## Runner & commands

- **Vitest** + **React Testing Library** + `@testing-library/jest-dom` +
  `@testing-library/user-event`.
- Run one test file: `npx vitest run resources/js/pages/movies/Index.test.tsx`
- Watch a single file while iterating: `npx vitest resources/js/.../X.test.tsx`
- Whole suite (once `test` script exists): `npm run test`.
- Run the slice under work during a TDD cycle; run the broader suite before
  finishing GREEN.

## Where tests live

- Pages live in `resources/js/pages/` (**lowercase**; Inertia resolves
  `./pages/${name}.tsx`), components in `resources/js/components/`.
- Colocate a `*.test.tsx` sibling next to the page/component under test.

## Patterns

- Render the page/component **with props** (the same shape the Laravel Inertia
  response provides) and assert what the user sees:

```tsx
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import Index from './Index'

test('shows the movies returned by the server', () => {
  render(<Index movies={[{ id: 1, title: 'Heat' }]} />)
  expect(screen.getByRole('heading', { name: /heat/i })).toBeInTheDocument()
})
```

- **Query by role/text/label**, not test IDs or class names. Use `findBy*` for
  async UI.
- Drive interaction with `userEvent` (`await userEvent.click(...)`), not `fireEvent`.
- Mock Inertia where components call it: stub `@inertiajs/react`'s `router`,
  `Link`, `useForm`, or `usePage` so you test the component's behavior, not Inertia
  internals. Pass page data through props rather than a real Inertia visit.

## RED checklist (for tdd-test-writer)

- A small cohesive set (2–6) of failing tests for one behavior slice; each describes
  one user-observable behavior (something rendered, or a reaction to interaction).
- Render with realistic props; assert via role/text.
- Run it; it must fail on the **assertion** (element/behavior absent), not on a
  render crash from an unrelated missing mock.

## REFACTOR targets (for tdd-refactorer)

- Extract repeated logic into **hooks** (`useX`) and repeated markup into
  **components**.
- Simplify conditionals; clarify prop and variable names.
- Keep accessibility roles intact so behavior-level tests stay valid. Keep tests
  green; show the run.
