---
name: review:run
description: Orchestrates the full PR review loop end-to-end with confirmation gates — optional cross-ticket refactor sweep, then create-pr → human → suite → process → delta review of the fixes. Each stage pauses for approval before the next.
---

# Review Loop Orchestrator

Runs the review pipeline as one guided sequence. Drive each stage **in order**,
pausing at the ⏸ gates for the user. Each stage's mechanics live in its own
command — **defer to that file, do not reimplement it here.**

Loop: `[cross-ticket sweep?]` → `/review:create-pr` → `/review:human` →
`/review:suite` → `/review:process` → `[delta review]`.

## Input
- **PR number** — optional, auto-detected from the branch.
- **Ticket ID** — `FLIX-XXX`, optional.

## Sequence

### Stage 0: Cross-ticket refactor sweep (conditional)
Decide whether the branch spans **more than one ticket** — inspect
`git diff origin/main...HEAD --stat`, the branch name, and the commit history.

- **Multi-ticket** → invoke the `review-tdd-cross-ticket` skill (whole-PR REFACTOR
  sweep). It runs its **own** green-precondition + approval gate — let it.
- **Single-ticket** → skip and say so (the slice's own REFACTOR already covered it).

### Stage 1: create-pr  ⏸
If the branch has **no open PR**, follow `.claude/commands/review/create-pr.md`
(lint → commit → push → open). Show the drafted title/body and **pause for
approval before opening**. If a PR already exists, skip this stage.

### Stage 2: human  ⏸
Follow `.claude/commands/review/human.md` — plain-language summary of the branch +
ticket-scope check. **Pause** so the user reads it before the engines dig for
defects.

### Stage 3: suite
Follow `.claude/commands/review/suite.md` — runs both engines (`/review:claude` +
CodeRabbit) and posts each to the PR as its own review. No pause — this is the
machine work.

### Stage 4: process  ⏸
**Before dispatching a single fixer, record the pre-fix commit:**
```bash
PRE_FIX_SHA=$(git rev-parse HEAD)
```
Then follow `.claude/commands/review/process.md` — triage the posted feedback,
per-item approve/consider/skip, dispatch fixers. Already interactive.

### Stage 5: delta review  ⏸
**The fixes Stage 4 just landed have never been reviewed by anything.** The engines
in Stage 3 saw the pre-fix tree; the fixers that changed it were isolated subagents
each seeing one item. A bad fix ships with the same blast radius as any other bug,
and nothing upstream is looking at it. This stage closes that.

**Review only what we implemented — never re-review the whole PR.**

1. **Compute the delta.** `git diff {PRE_FIX_SHA}..HEAD`. Empty (every item skipped,
   or Stage 4 never ran) → skip this stage and say so.
2. **Re-run the deterministic gates** (Pint, Rector scoped to the changed files, the
   full Pest suite). Stage 4's fixers only ran filtered tests.
3. **Dispatch ONE focused reviewer** over that delta — `edge-case-reviewer` by
   default, since fix regressions are overwhelmingly failure-mode bugs rather than
   convention drift. Give it:
   - the delta diff **as the only thing in scope** — state plainly that the base
     implementation was already reviewed and its findings resolved, so re-raising
     anything outside the delta is out of scope;
   - **what each fix changed and what it could plausibly break** — this is the
     highest-value part of the prompt. A fix that narrows a query can strand rows; a
     fix that reorders guards can change what runs on an early return; a fix that
     swaps a sort key can drop a tie-break. Name the specific suspicion per item.
   - the ticket context, and the pipeline contract's finding format + Comment Bar.
4. **Route any findings back through Stage 4's mechanics** — present each with your
   own recommendation, take Approve/Modify/Skip, dispatch a foreground fixer, commit.
5. **Then re-enter Stage 5 on the *new* delta.** Loop until a pass comes back clean.
   **Cap at 3 rounds**; if round 3 still finds real defects, stop and tell the user
   the fixes are churning — that is a signal to rethink the approach, not to keep
   patching.

Findings here are usually few. A clean pass is the expected outcome and takes one
agent over a small diff — cheap relative to shipping a regression introduced by a
fix the user already approved.

## Rules
- **One stage at a time.** Never skip a ⏸ gate.
- A stage that HALTs (no PR to review, no diff, etc.) **stops the loop** and reports
  why — it does not silently continue.
- **Defer, don't duplicate.** Each stage's logic lives in its command/skill file;
  this orchestrator only sequences and gates. Stage 5 is the one exception — it has
  no command file of its own, so its mechanics live here.
- **Every commit the loop produces gets reviewed before the loop ends.** Stage 5
  applies that to Stage 4's fixes; if you ever land code outside a stage, it needs
  the same treatment.

$ARGUMENTS
