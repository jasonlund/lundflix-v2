---
name: tdd-react-testing
description: >-
  Conventions for writing React + Inertia frontend tests (Vitest + React Testing
  Library) for this app: rendering pages/components with props, querying by role,
  user interaction, and Inertia mocking. Loaded by the TDD subagents when the
  target is TSX/JSX. Use when writing or refactoring React tests.
---

# React + Inertia testing conventions

> Stack: React 19 + `@inertiajs/react` ^3, Vite 8, npm. Toolchain is **installed
> and wired**: Vitest 4 + React Testing Library + jest-dom + user-event + jsdom,
> `test` block in `vite.config.ts` (`environment: 'jsdom'`, `globals: true`,
> `setupFiles: ['resources/js/test/setup.ts']`), and `npm test` / `npm run
> test:watch` scripts. `globals` + jest-dom types are registered in `tsconfig.json`.

## Runner & commands

- **Vitest** + **React Testing Library** + `@testing-library/jest-dom` +
  `@testing-library/user-event`.
- Run one test file: `npx vitest run resources/js/pages/movies/Index.test.tsx`
- Watch a single file while iterating: `npx vitest resources/js/.../X.test.tsx`
- Whole suite: `npm test`.
- Run the slice under work during a TDD cycle; run the broader suite before
  finishing GREEN.

## Where tests live

- Pages live in `resources/js/pages/` (**lowercase**; Inertia resolves
  `./pages/${name}.tsx`), components in `resources/js/components/`.
- Colocate a `*.test.tsx` sibling next to the page/component under test.

## Patterns

- Render the page/component **with props** (the same shape the Laravel Inertia
  response provides) and assert what the user sees:

**Arrange–Act–Assert (mandatory):** three blank-line-separated blocks, one Act per
test. For pure-render tests the render is the Act:

```tsx
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import Index from './Index'

describe('movie index page', () => {
  it('shows the movies returned by the server', () => {
    // Arrange
    const movies = [{ id: 1, title: 'Heat' }]

    // Act
    render(<Index movies={movies} />)

    // Assert
    expect(screen.getByRole('heading', { name: /heat/i })).toBeInTheDocument()
  })
})
```

- **Query by role/text/label**, not test IDs or class names. Use `findBy*` for
  async UI.
- **Name tests for WHAT, not HOW** — `test('shows an error when the title is
  blank')`, not `test('calls setError')`.
- **Never tautological.** The expected value must not be recomputed the way the
  component computes it. Deriving the expected text from the same props with the
  same `map`/`format` call makes the assertion pass by construction:

  ```tsx
  // BAD — recomputes the component's own formatting
  expect(screen.getByText(`${movie.title} (${movie.year})`)).toBeInTheDocument()

  // GOOD — an independent literal
  expect(screen.getByText('Heat (1995)')).toBeInTheDocument()
  ```
- Drive interaction with `userEvent` (`await userEvent.click(...)`), not `fireEvent`.
- Mock Inertia where components call it: stub `@inertiajs/react`'s `router`,
  `Link`, `useForm`, or `usePage` so you test the component's behavior, not Inertia
  internals. Pass page data through props rather than a real Inertia visit.

**Source:** the never-tautological rule and WHAT-not-HOW test naming are adapted
from `mattpocock-skills:tdd`'s `tests.md`. Offer to explain the upstream reasoning
when one of them rejects a test.

## Test-comment standard (strict)

Test comments are **deliberate and mandatory** — one canonical form, strictly
enforced (the Pest guard `tests/Unit/TestCommentStandardTest.php` scans
`resources/js` too):

1. **AAA labels mandatory, one per block, label-only line.** `// Arrange`,
   `// Act`, `// Assert` — each alone on its line, no prose appended.
2. **Collapse only when one statement serves two roles**, joined by ` & `
   (space-ampersand-space): `// Arrange & Act`, `// Act & Assert`. Only `&` —
   never `/` or a no-space variant.
3. **Missing / unneeded block → label + reason on the next line**, never silently
   absent:

   ```tsx
   // Arrange
   // no props or state to set up — pure static render
   ```
4. **Why-prose on its own line(s), above the label it explains.** The AAA line
   stays label-only.

## Test-organization standard (strict)

The same standard the Pest suite follows — the guard
`tests/Unit/TestOrganizationStandardTest.php` scans `resources/js/**/*.test.ts(x)`
too, applying the grouping and description-form checks, which key off
`describe(`/`it(` and so read identically in both languages:

- **Every `it()`/`test()` lives inside a `describe()`.** Several top-level
  describes per file are fine; nesting allowed. Never a top-level test.
- **Descriptions** start lowercase, never start with "should", and are unique
  within their describe.
- **Describe labels are unique within a file** — no two `describe()` blocks in
  one file may share a label, at any nesting level.

Judgment rules, not machine-checked: label a describe by **subject + facet**
(`describe('Login page', …)`, `describe('submit handler', …)`), put the happy path
first and failures last, and prefer a per-`describe` `beforeEach` over repeating
the same arrange in every test of that group. The skeleton-order and helper-name
checks are PHP-only and don't apply here.

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
