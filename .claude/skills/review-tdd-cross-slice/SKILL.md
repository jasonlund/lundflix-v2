---
name: review-tdd-cross-slice
description: >-
  Final cross-slice refactor sweep for a multi-slice TDD PR. Use after every
  slice in the PR is done and green, before finalizing — "all slices done, sweep
  the PR", "final refactor across the PR", "cross-slice cleanup". Kicks off a
  review using tdd-feedback's REFACTOR HAT over the whole PR diff. A thin trigger
  only — it owns no loop or gates of its own.
---

# TDD PR Review (multi-slice final refactor)

This skill is a **trigger + scope shim**, nothing more. It exists to close one gap:
the `tdd` loop's REFACTOR phase is **slice-scoped** — each cycle's refactorer sees
only the files it touched plus that slice (`tdd/SKILL.md:106`). Across N slices in
one PR, **nothing in the loop ever looks at the combined diff.** So per-slice refactors
structurally cannot catch:

- cross-slice duplication (slice A and slice C grew parallel helpers; neither
  refactorer saw the other)
- an abstraction that only becomes real at the 3rd repetition across slices
- naming / exception-style drift between slices done in separate sessions
- a domain-boundary smell (cross-domain import, `Common` bloat) introduced by the
  *union* of slices, not any one

The capability to fix that already exists in `tdd-feedback`'s **REFACTOR HAT** branch
— it is scope-agnostic. This skill just **points that branch at the whole PR** instead
of one comment's scope. **It builds no new machinery.** Do not duplicate the loop or
the gates; defer every spawn/gate mechanic to `tdd-feedback` → `tdd`.

## When this activates

- Every slice in a **multi-slice PR is done and green**, and the user asks for a
  final cross-PR cleanup ("sweep the PR", "final refactor", "now that everything is
  in"). This is *not* feedback language, so `tdd-feedback` won't self-trigger on it
  — that is the only reason this named hook exists.
- **Single-slice PR → don't bother.** That slice's own REFACTOR already covered it.
  Say so and stop.
- **One ticket of many slices qualifies.** Ticket count is not the variable — the
  refactorer's scope is the slice, so a single ticket sliced six ways has exactly
  the blind spot above. `/review:run` Stage 0 owns the counting; if it sent you
  here, run.

## What it does

**Kick off a review using `tdd-feedback`, REFACTOR HAT, scoped to all of the PR's
content.** Concretely:

1. **Define scope = the PR diff**, not untouched code:

   ```
   git diff origin/main...HEAD --stat   # the file set under review
   git diff origin/main...HEAD          # the change to sweep
   ```

   Keeps the sweep bounded and reviewable. Untouched files are out of scope.

2. **Invoke `tdd-feedback`** and classify this as **REFACTOR HAT** (pure structural
   cleanup against a green suite). Hand it the PR-wide diff as the scope. From there
   `tdd-feedback` runs its branch verbatim:
   - **PRECONDITION GATE** — run the **full** suite PR-wide; show it GREEN *now*
     (whole PR, not one slice).
   - on approval → **`tdd-refactorer` only**, behavior-preserving, two hats.
   - **POST GATE** — full suite still green (subagent shows the run).
   - A test breaks → it tested implementation, not behavior; fix the test, flag it.

## Hard rules (inherited, restated so they aren't lost)

- **Behavior-preserving only.** If a "cleanup" changes behavior, it is a new SLICE
  (RED first) via `tdd`, not this sweep. Split it out.
- **Two hats stay separated.** This pass cannot also fix a bug. Split it.
- **Its own gates, not the last slice's.** A whole-PR refactor sits *outside* any
  slice's gate, so it needs its own precondition + post green run — never let it ride
  on the final slice's gate, or a cross-slice change could silently break an earlier
  slice's tests uncaught.

## What this is NOT

- Not a new RED → GREEN → REFACTOR loop. It calls the existing one.
- Not a bug-fix or behavior-change path — those go through `tdd-feedback`'s BUG / SLICE
  branches directly.
- Not a code-review of prose/markdown — for non-tested artifacts there is no green gate
  to anchor a refactor; use the `/review:claude` command instead.
- **Not a reviewer of duplicated comment prose.** Being green-gated and
  behavior-preserving, this sweep can only move code; rewriting a duplicated
  rationale comment changes no test, so it falls outside the gate that makes this
  pass safe. `duplication-reviewer` in `/review:claude` Phase 3 owns that.

## Reference

- `.claude/skills/tdd-feedback/SKILL.md` — the REFACTOR HAT branch, gates, and
  approval flow this skill delegates to.
- `.claude/skills/tdd/SKILL.md` — underlying loop mechanics and exact gate wording.

## Convention note

Naming a "whole-PR final refactor" step is **our house convention**, not a cited TDD
rule. The mechanics underneath (green-suite, two-hats REFACTOR) are well-established;
the PR-wide framing is ours.
