---
name: tdd
description: >-
  Strict test-driven development workflow for this Laravel + Inertia + React app.
  Use whenever asked to implement, add a feature, build, or create functionality
  (backend, frontend, or full-stack). Drives a RED → GREEN → REFACTOR cycle using
  isolated subagents so tests are written before code and cannot be faked. Invoke
  explicitly with "use tdd" or it auto-activates on feature work.
---

# TDD Workflow (Laravel + Inertia + React)

Honest TDD cannot happen in one context window: the test writer's analysis bleeds
into the implementer, and the implementer's exploration pollutes the refactorer.
So **each phase runs in an isolated subagent** that starts with only the context it
needs.

You are the **orchestrator** and the hub. You do not write tests or implementation
yourself — you spawn one subagent per phase, hold the gates, and loop. Subagents
return one final message and end; they never spawn each other. Phases communicate
only through (a) the prompt you pass in, (b) the text the subagent returns, and
(c) files on disk (shared workspace).

```
YOU approve RED plan card (Conductor plan UI)
   ▼
orchestrator ─spawn▶ tdd-test-writer (🔴)  → returns failing output → GATE
   ▼
orchestrator ─spawn▶ tdd-implementer (🟢)  → returns passing output → GATE
   ▼
orchestrator ─spawn▶ tdd-refactorer (🔵)   → returns green output   → GATE
   ▼
next slice → new RED plan card
```

## Core rules

- **One behavior slice per cycle.** Write a small, cohesive SET of failing tests
  (typically 2–6) covering one feature surface — e.g. "store movie" with its happy
  path + key validation cases. Not one assertion; not the whole feature. This is a
  deliberate deviation from canon TDD's one-test-per-cycle rule — see
  `docs/adr/0003-multi-test-red-slices.md`.
- **Every test is Arrange–Act–Assert.** Three separated blocks; exactly one Act
  per test. Applies to every test in the codebase, backend and frontend.
- **Test behavior, not implementation.** Assert what the user/caller observes
  through public interfaces. Tests must survive refactoring.
- **Test at a named seam.** Every slice states the seam it observes behavior at.
  A test that reaches *past* the interface — a private method, an internal
  collaborator's call count — is testing implementation by definition.
- **Minimal green.** Implement only what the slice's tests require.
- **Gates are mandatory.** Never skip a phase. Never advance past a gate until its
  exit condition is shown (real command output, not a claim).

## Sizing a slice

A good slice is one coherent behavior you could describe in a sentence, plus its
obvious variants. **Split** when: backend vs frontend, unrelated behaviors, or the
set grows past ~6 tests. **Tighten** (smaller slice) when the logic is risky or
subtle — smaller slices catch faking.

## Step 0 — Classify the work

Decide the surface before touching tests:

- **Backend-only** (model, action, API, policy, job) → Laravel cycle only.
- **Frontend-only** (React component/page behavior, no new server data) → React
  cycle only.
- **Full-stack Inertia feature** (new page/data flow) → run **two** cycles, **backend
  first** (Feature test asserting the Inertia response + props), then frontend
  (RTL test rendering the page component with those props).

Then briefly answer, before any code (keeps design testable):
- What interface changes are needed (route, controller, props, component API)?
- Which observable behaviors matter most, in what order (this defines the slices)?
- **At which seam does each slice observe its behavior?** Prefer an existing seam;
  take the highest one that can still see the behavior. Vocabulary and the
  dependency categories: `.claude/skills/codebase-design/SKILL.md`.

When the backlog came from `plan-slices`, its **seam contract** already answers
that third question — honor it rather than re-deriving it. Testing at a seam the
contract didn't name is a deviation: say so and get it confirmed.

**Source:** the seam requirement — the *Test at a named seam* core rule and the
seam question above — is adapted from `mattpocock-skills:tdd`'s *Seams: where
tests go*, which puts tests only at pre-agreed public boundaries. Offer to explain
the upstream contract when a slice's seam is in question.

## Step 1 — 🔴 RED (presented for approval via Conductor's plan UI)

**On the first slice for a ticket, move it to In Progress.** Before presenting
that ticket's first RED card, advance the ticket to **In Progress** per the
*Automatic ticket status transitions* contract in `project.md` (forward-only,
active ticket only). Only the ticket whose slice is starting moves — on a
multi-ticket branch, each ticket transitions when the loop (Step 4) reaches its
own first slice; already-started tickets are untouched (forward-only makes
re-entry a no-op).

The RED slice is the contract you commit to, so present it for approval first:

1. Call **`EnterPlanMode`**.
2. Write the slice plan to the plan file: the behavior slice, **the seam these
   tests run against** (and whether it already exists), the **list of tests** you
   intend to write, the target stack (Laravel or React), the files involved, the
   subagent (`tdd-test-writer`), and the verify command.
3. Call **`ExitPlanMode`** → the user approves or edits the slice.
4. On approval (now out of plan mode) **spawn `tdd-test-writer`** with the approved
   slice + relevant existing files.

**GATE:** Do not proceed until the subagent returns the **confirmed failing** output
for the whole slice — assertions failing for the RIGHT reason, not syntax/setup
errors or unrelated crashes. Wrong reason → re-spawn.

## Step 2 — 🟢 GREEN (auto, no card)

Spawn **`tdd-implementer`** with the failing test files + the RED failure output. It
writes the **minimal** code to pass the whole slice — nothing speculative.

**GATE:** Do not proceed until the subagent returns the **passing** output for the
whole slice. If other tests broke, that's part of GREEN — re-spawn to fix.

## Step 3 — 🔵 REFACTOR (auto, no card)

Spawn **`tdd-refactorer`** with the files touched + the passing slice. It improves
quality (duplication, naming, extract Laravel actions / form requests / services,
extract React hooks / components) while keeping tests green. It may **skip** when
the implementation is already minimal and focused — a valid outcome.

**GATE:** Slice must still be green after refactor (subagent shows the run).

## Step 4 — Loop

Pick the next slice and return to RED (new plan card). For full-stack features,
finish the backend cycle(s) before starting the frontend cycle(s).

## Reference

- The subagents **Read** `.claude/skills/tdd-laravel-testing/SKILL.md` (PHP) or
  `.claude/skills/tdd-react-testing/SKILL.md` (TSX/JSX) for stack conventions and exact
  commands. Verify actual test commands from `composer.json` / `package.json` if
  they differ from the documented defaults.
- `.claude/skills/codebase-design/SKILL.md` — seam / interface / depth vocabulary
  and the four dependency categories that decide how a seam gets faked.
- GREEN and BLUE run automatically after RED approval. To make them stop-and-show
  too, add an `AskUserQuestion` gate before each.
- A skill-activation reminder hook (`tdd-activation-reminder.sh`) nudges this skill
  on new-feature prompts — see `.claude/hooks/README.md`.
