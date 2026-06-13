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
  path + key validation cases. Not one assertion; not the whole feature.
- **Test behavior, not implementation.** Assert what the user/caller observes
  through public interfaces. Tests must survive refactoring.
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
- Can this be a deep, testable module?

## Step 1 — 🔴 RED (presented for approval via Conductor's plan UI)

The RED slice is the contract you commit to, so present it for approval first:

1. Call **`EnterPlanMode`**.
2. Write the slice plan to the plan file: the behavior slice, the **list of tests**
   you intend to write, the target stack (Laravel or React), the files involved,
   the subagent (`tdd-test-writer`), and the verify command.
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

- The subagents **Read** `.claude/skills/laravel-testing/SKILL.md` (PHP) or
  `.claude/skills/react-testing/SKILL.md` (TSX/JSX) for stack conventions and exact
  commands. Verify actual test commands from `composer.json` / `package.json` if
  they differ from the documented defaults.
- GREEN and BLUE run automatically after RED approval. To make them stop-and-show
  too, add an `AskUserQuestion` gate before each.
- A skill-activation hook is intentionally NOT installed — see
  `.claude/tdd-hook-OPTIONAL.md` if this skill ever stops activating reliably.
