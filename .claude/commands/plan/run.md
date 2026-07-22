---
name: plan:run
description: Orchestrates the planning pipeline end-to-end with gates — plan-draft (interview → concrete plan) → plan-breakdown (PRD → parallel tickets) → plan-slices (per-ticket TDD backlog). Single-ticket input skips breakdown. Stops at the backlog; hands off to the tdd skill.
---

# Planning Pipeline Orchestrator

Runs the three planning skills as one guided sequence, pausing at each skill's
natural gate. **Defer all mechanics to the skills** — this orchestrator only
sequences and branches.

Pipeline: `plan-draft` → (`plan-breakdown` → `plan-slices`) → run the `tdd` skill.

## Input
- A **Linear ticket id**, a **plan file path**, or **pasted rough notes**. If
  unclear, prompt — never invent one.

## Sequence

### Stage 1: draft  ⏸
Invoke the `plan-draft` skill — interview the rough ticket into one concrete,
decision-locked plan (architecture, files, decisions; zero TDD concern). It is
interactive and ends with the locked plan.

### Stage 2: branch on scope
Judge how much work the locked plan covers:

- **More than one ticket's worth** → Stage 3a.
- **A single ticket** → skip breakdown, go Stage 3b.

### Stage 3a: breakdown  ⏸
Invoke the `plan-breakdown` skill — decompose into parallel-aware, DDD-clean Linear
tickets + the concurrency graph. It holds a **hard approval gate before any Linear
write** (built in) and calls `plan-slices` per ticket in its own Phase E. Let it
run its gates.

### Stage 3b: slices  ⏸
Single-ticket path only: invoke the `plan-slices` skill against the one ticket/plan
— it appends the ordered TDD Slice Backlog to the ticket body / plan file. **Pause**
so the user reviews the testability findings before execution.

### Handoff
Stop at the backlog. Tell the user to invoke the `tdd` skill to execute the first
slice. Do nothing further — execution is the `tdd` skill's job.

## Rules
- **One stage at a time**; honor each skill's own gate.
- **Defer, don't duplicate** — the skills own their logic; this file only routes.

$ARGUMENTS
