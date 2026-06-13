---
name: react-testing
description: >-
  Conventions for writing React + Inertia frontend tests (Vitest + React Testing
  Library) for this app: rendering pages/components with props, querying by role,
  user interaction, and Inertia mocking. Loaded by the TDD subagents when the
  target is TSX/JSX. Use when writing or refactoring React tests.
---

# React + Inertia testing conventions

> Confirm the actual runner and scripts from `package.json` before assuming. The
> defaults below are standard Vite + Vitest + Inertia React.

## Runner & commands

- **Vitest** + **React Testing Library** + `@testing-library/jest-dom` +
  `@testing-library/user-event`.
- Run one test file: `npx vitest run resources/js/Pages/Movies/Index.test.tsx`
- Watch a single file while iterating: `npx vitest resources/js/.../X.test.tsx`
- Run the single test under work during a TDD cycle; run the broader suite before
  finishing GREEN.

## Where tests live

- Colocate next to source: `resources/js/Pages/...`, `resources/js/Components/...`
  with a `*.test.tsx` sibling. Pages and components are the unit under test.

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
