---
name: review:run
description: Orchestrates the full PR review loop end-to-end with confirmation gates — optional cross-ticket refactor sweep, then create-pr → human → suite → process. Each stage pauses for approval before the next.
---

# Review Loop Orchestrator

Runs the review pipeline as one guided sequence. Drive each stage **in order**,
pausing at the ⏸ gates for the user. Each stage's mechanics live in its own
command — **defer to that file, do not reimplement it here.**

Loop: `[cross-ticket sweep?]` → `/review:create-pr` → `/review:human` →
`/review:suite` → `/review:process`.

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
Follow `.claude/commands/review/process.md` — triage the posted feedback, per-item
approve/consider/skip, dispatch fixers. Already interactive.

## Rules
- **One stage at a time.** Never skip a ⏸ gate.
- A stage that HALTs (no PR to review, no diff, etc.) **stops the loop** and reports
  why — it does not silently continue.
- **Defer, don't duplicate.** Each stage's logic lives in its command/skill file;
  this orchestrator only sequences and gates.

$ARGUMENTS
