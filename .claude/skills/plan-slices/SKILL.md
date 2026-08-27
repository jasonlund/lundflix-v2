---
name: plan-slices
description: >-
  Turn an already-written implementation plan into an ordered, test-first TDD
  slice backlog for this Laravel + Inertia + React app. Use when you have one
  ticket/plan (architecture, files, decisions) — a Linear ticket id or a plan file
  — and need it restructured into behavior slices before running the `tdd` skill.
  Planner/critic only — it is opinionated about testability and STOPS at the
  backlog; it never writes tests or code. The back half of `plan-breakdown`.
---

# TDD Planner

An implementation plan is written for architecture, files, and decisions — with
**zero TDD concern**: no behavior slices, no test ordering, no testability seams.
The `tdd` skill *executes* RED → GREEN → REFACTOR but assumes that translation is
already done. This skill *does the translation*: it reads a finished plan and
appends an ordered **TDD Slice Backlog** that drops straight into the `tdd`
skill's Step 1. It is the **back half of `plan-breakdown`** — and also runs
standalone against a single ticket or plan.

You are a **planner and critic, not an executor**. You analyze, you push back on
untestable design, and you write the backlog. You do **not** enter RED, write
tests, write implementation, refactor, or spawn the `tdd` subagents.

## Input & output target

Resolve the target before analyzing — prompt if it's unclear, never invent one:

- **A Linear ticket id** (the default when invoked by `plan-breakdown`) → read the
  ticket's plan from its body; **append the backlog into that same body** via the
  `linear-server` MCP (`save_issue` with `description`, per the repo rule: write to
  the ticket body, never a comment), so one ticket = one self-contained body.
- **A plan file path** → append the backlog to that file.

## Core rules

- **Stop at the backlog.** Your final act is appending the backlog to the target
  and telling the user to run the `tdd` skill. Never go further.
- **Testability beats design.** The input plan has no TDD concern; when a design
  choice can't be driven test-first, flag it and recommend the **smallest seam**.
  When design and testability conflict, testability wins.
- **Output target = the ticket body or the plan file** (see above) — single source
  of truth, not a separate doc, not chat-only.
- **Reference, don't restate.** Slice sizing, AAA, and exact commands live in the
  `tdd`, `tdd-laravel-testing`, and `tdd-react-testing` skills. Point at them.

## Step 1 — Classify the surface

Mirror Step 0 of the `tdd` skill (`.claude/skills/tdd/SKILL.md`):

- **Backend-only** (model, action, API, policy, job) → Laravel cycles only.
- **Frontend-only** (component/page behavior, no new server data) → React only.
- **Full-stack Inertia feature** (new page/data flow) → both, **backend first**
  (Feature test asserts the Inertia component + props), then frontend.

Order the backlog so backend slices precede the frontend slices that consume them.

## Step 2 — Extract observable behaviors

List only what's visible **through a public interface**: a return value, an
HTTP/Inertia response, rendered DOM, a thrown exception, or a persisted
side-effect. Internal method calls and private state are not behaviors.

- Each locked decision that changes an **assertion** — return shape, filter, cast,
  default, error type, validation rule — becomes **at least one** behavior.
- Drop "behaviors" that nothing can observe (see the testability gate).

## Step 3 — The seam contract (the opinionated part)

A **seam** is the public boundary a test observes behavior at without reaching
inside — the interface is the test surface. Vocabulary:
`.claude/skills/codebase-design/SKILL.md`.

### 3a — Name the seams, and get them confirmed

Before any slice is written, list the seams this ticket's tests will run against.
**No test is planned at an unconfirmed seam.** You can't test everything, so
agreeing the seams up front is how the effort lands on critical paths instead of
spreading evenly over every edge case.

- **Prefer an existing seam** to a new one. An `artisan()` call, an HTTP route, an
  existing Action's `handle()` — each costs nothing and already has prior art.
- **Take the highest seam that can still observe the behavior.** Testing at the
  command or route level exercises the wiring too; testing at a private helper
  proves only that the helper works.
- **Fewest seams wins — one is the ideal.** State the count outright ("one new
  seam: `SyncShows::handle()`; everything else runs through existing `artisan()` +
  `Http::fake()`"). A ticket that needs four new seams is usually a design problem
  surfacing early, not a testing problem.
- **New seam ⇒ justify it.** One adapter is a hypothetical seam; two (production +
  test) make it real. A seam nothing varies across is just indirection.

Put the seam list at the **top of the backlog** and confirm it with the user
alongside the testability findings.

**Source:** the seam contract is adapted from `mattpocock-skills:tdd`'s *Seams:
where tests go* (test only at pre-agreed seams, prefer the existing public
boundary) and `mattpocock-skills:codebase-design`'s `DEEPENING.md` (one adapter is
a hypothetical seam; two make it real). Offer to explain the upstream reasoning
when a new-seam count is challenged.

### 3b — Testability gate

For each behavior, check it can be driven test-first at one of those seams. Flag
the problem and recommend the **smallest seam**:

- **I/O isn't fakeable** — hard-coded URL, `now()`/clock, filesystem, a `new`'d
  HTTP client → **inject it or move it to config** so a test can fake it.
- **Behavior only reachable via a private method** → **expose it** on the public
  interface or **extract a testable module** that owns it.
- **A unit needs the app container / a facade** → it can't be a `tests/Unit`
  test (Unit boots no app) → **make it a Feature test**, or refactor to a pure
  unit with the framework dependency injected.
- **A decision has no observable effect** → **cut it**, or add the missing output
  (return value / prop / response field) that makes it assertable.

Prefer the cheapest seam: constructor injection, a config value, returning a
value instead of hiding it, or pushing rules to a **schema authority** (enum /
value object) so the unit-under-test stays thin. These findings **lead** the
backlog — a serious one may send the plan back for a design edit before any
slice is worth executing.

## Step 4 — Group into slices

A slice is **2–6 tests, one coherent behavior + its obvious variants** (see
"Sizing a slice" in the `tdd` skill). **Split** on: backend vs frontend,
unrelated behaviors, or a set past ~6 tests. **Tighten** (smaller slice) for
risky or subtle logic. **Order** bottom-up / dependency-first — a slice never
depends on code a later slice introduces.

## Step 5 — Honest RED per slice

Predict the slice's **first** test run, and make each test fail for its own
reason — the right-reason-RED gate the `tdd` skill and `tdd-test-writer` enforce.

- If every test would die on the **same** "class/method/route missing" crash,
  that's a **weak RED** — it proves only that nothing exists yet, not that each
  assertion is real.
- Specify the **minimal stub** that turns the shared crash into per-assertion
  failures: an empty class with method signatures returning `null` / throwing
  "not implemented", a registered route returning an empty response, or a
  component that renders nothing. Note it per slice as a **RED stub note**.
- Exception: a 404/500 status is acceptable RED **only when that status IS the
  behavior under test**.

## Step 6 — Write the backlog

Append a `## TDD Slice Backlog` section to the target (the Linear ticket body or
the plan file — see **Input & output target**), in this order:

1. **The seam contract** first (Step 3a) — the named seams, which already exist vs
   which are new, and the total new-seam count.
2. **Testability findings** (Step 3b) — each with its recommended seam, marked if
   it requires a design edit before execution.
3. A **Decision ↔ test traceability** table: each locked decision → the
   slice/test that proves it. Flag **untested decisions** and any **behavior with
   no backing decision**.
4. One block **per slice**, in execution order:
   - **Title** + a one-sentence behavior statement.
   - **Seam** — which confirmed seam this slice tests at.
   - **Stack target** (Laravel / React) + **test file path**.
   - The **2–6 test list** (each AAA, exactly one Act).
   - **Files involved.**
   - **RED stub note** (from Step 5).
   - **Verify command**, confirmed from `composer.json` / `package.json` — backend
     `php artisan test --compact --filter='…'`, frontend
     `npx vitest run <path>` (whole suite `npm test`).

Example slice block:

```markdown
### Slice 1 — Store movie (backend)
Posting a valid movie persists it and redirects; invalid input is rejected.

- **Seam:** existing — the `movies.store` route (HTTP)
- **Stack/file:** Laravel · `tests/Feature/Catalog/StoreMovieTest.php`
- **Tests:**
  1. stores a movie and redirects (valid payload)
  2. requires a title (validation error, nothing persisted)
  3. rejects a duplicate title
- **Files:** `routes/web.php`, `app/Domains/Catalog/Actions/CreateMovie.php`
- **RED stub:** add `movies.store` route → empty controller so each test fails on
  its own assertion, not a missing-route 404.
- **Verify:** `php artisan test --compact --filter='store'`
```

## Step 7 — Stop and hand off

Confirm the backlog is appended to the target, then tell the user: **confirm the
seam contract**, review the testability findings, then **invoke the `tdd` skill**
to execute the first slice. Do nothing else.

**Advance the ticket to Todo.** When the target is a **Linear ticket** and the
backlog has been appended, the ticket is planned and ready — move it to **Todo**
per the *Automatic ticket status transitions* contract in `project.md`
(forward-only, active ticket only), and note the move in the hand-off line. When
called per-ticket by `plan-breakdown`, do this for each ticket you slice. Skip
when the target is a plain plan file (no ticket to move).

## Reference

- `.claude/skills/plan-breakdown/SKILL.md` — the front half; decomposes a PRD into
  tickets and calls this skill per ticket.
- `.claude/skills/tdd/SKILL.md` — the executor; slice definition, Step 0/Step 1,
  right-reason-RED gate. Your output slots into its Step 1.
- `.claude/skills/tdd-laravel-testing/SKILL.md` — Pest/Feature/Unit conventions +
  backend commands.
- `.claude/skills/tdd-react-testing/SKILL.md` — Vitest/RTL conventions + frontend
  commands.
- `.claude/skills/codebase-design/SKILL.md` — seam / interface / depth vocabulary
  the Step 3 contract is written in.
