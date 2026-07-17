---
name: plan-draft
description: >-
  Turn a rough Linear ticket (a few wishlist bullets) into one concrete,
  decision-locked implementation plan through an interactive interview. Use when a
  ticket names WHAT is wanted but leaves every HOW open — endpoints, model columns,
  service signatures, data shapes, cadence — and needs those pinned down before it
  can be decomposed or sliced. Architecture/files/decisions only: zero TDD concern,
  zero ticket-splitting. The front-most of the three planning skills; its output
  feeds `plan-breakdown` or `tdd-plan`.
---

# Plan Draft

A rough ticket is a **wishlist, not a plan** — "add episode/season models",
"sync command to keep shows up to date" (FLIX-197) names outcomes but pins no
decisions. The two downstream skills both assume the concrete plan already exists:
`plan-breakdown` decomposes *a written PRD/plan*, and `tdd-plan` slices *a finished
plan written with zero TDD concern*. Feed a rough ticket straight into either and
you get garbage — there is nothing concrete to partition or slice.

This skill fills that gap. It **interviews the user** to turn a rough ticket into a
single concrete implementation plan, then **replaces the ticket body** with it.

You are the **front half of the front half** — the drafting stage:

```
rough ticket ─plan-draft▶ concrete plan (replaces ticket body)
                             ├─multi-ticket?─▶ plan-breakdown ▶ tdd-plan ▶ tdd
                             └─single ticket?─▶ tdd-plan ▶ tdd
```

## What this skill is — and is NOT

- **IS:** an interactive planner. Its entire value is the interview — surfacing
  every decision the bullets leave implicit and driving each to an answer the user
  **explicitly confirms**. Architecture, target files/domains, data shapes, locked
  decisions, open risks.
- **IS NOT** a TDD planner. Never mention slices, tests, RED/GREEN, or testability
  seams — that is `tdd-plan`'s whole job, and it *expects* a plan written with zero
  TDD concern as its input. Stay silent on testing.
- **IS NOT** a decomposer. Never split into sub-tickets, create Linear tickets,
  build a wave/dependency graph, or assign branches — that is `plan-breakdown`
  Phase B–D. You produce **one** plan for **one** ticket.
- **IS NOT** an executor. No code, no scaffolding, no `make:*`. Stop at the plan.

## Never invent a decision

The interview exists because these answers are the user's to make. Propose options
with a recommendation, but **do not lock a decision the user did not confirm**. A
plan full of assumptions is worse than the rough ticket — it launders guesses as
settled intent. When unsure, ask.

## Phase A — Intake + ground

- **Resolve the ticket.** Default input is a Linear ticket id; read its current
  body via the `linear-server` MCP. If the input is unclear, prompt — don't guess.
- **Load the binding constraints** so the plan is DDD-shaped from the first draft:
  `CLAUDE.md` / `.ai/guidelines/project.md` (domain layout `app/Domains/*`,
  Action/exception naming, service constants, cross-domain only via `Contracts/`,
  the raw-source `_{source}_{rawAttr}` column convention).
- **Read the ground truth before proposing anything** (standing lessons):
  - **Read referenced/V1 implementations** the ticket points at — never plan a
    feature blind when prior code exists.
  - **Inspect the real data/source payloads** the feature consumes before designing
    columns or shapes — fixtures and schemas mirror byte-exact reality, not a guess
    at it. (e.g. hit the real TVDB episodes/seasons response before choosing model
    columns.)
- **Map the surface.** Which single `app/Domains/*` context this lives in, and
  whether each piece is backend / frontend / full-stack.

## Phase B — Surface the open decisions (gap analysis)

The core analysis. Read each rough bullet and expand it into the **concrete
decisions it silently defers**. Do not answer them yet — enumerate them. For
FLIX-197 the bullets "get episodes / create models / seed service / sync command"
hide, e.g.:

- Which exact TVDB endpoints; are seasons a separate call or embedded in episodes?
- Season as its own model+table or a column on episodes?
- Each model's columns, casts, keys, relationships, and raw-source columns.
- The seed service's method signatures and where it lives (`Contracts/` vs internal).
- Sync trigger, cadence, idempotency, and what "most recent" means.

Group the gaps into **coherent decision clusters** (one workstream each) so they
can be approved a cluster at a time, not big-bang.

## Phase C — Interview to lock (interactive, phased)

Walk the clusters **one at a time**. Per cluster: state the decision, give 2–3
concrete options with a **recommendation and its reasoning**, and get the user's
pick. Use `AskUserQuestion` / the Conductor plan UI for structured choices.

- **Phased approval** — lock one workstream, then move to the next; never dump the
  whole plan for one big yes/no. The user can amend an earlier lock at any point.
- Record each **locked decision** with the rationale, so downstream (and the ticket
  reader) sees *why*, not just *what*.

Per-cluster confirmation locks the *decisions*; it is not final approval of the
plan. Nothing is written to Linear in this phase.

## Phase D — Present the full plan (hard gate)

Once every cluster is locked, **assemble the complete plan and show it to the user
in full, in chat** — the exact markdown body that will land in the ticket (the
structure below). This is the whole plan in one view, not another per-cluster
summary: the user reads it end-to-end and catches anything the piecemeal interview
missed. Structure:

```markdown
## Overview
<what + why, one paragraph — the outcome the rough bullets asked for>

## Locked Decisions
- <decision> — <the choice> · <why>
  ...

## Target
- **Domain:** app/Domains/<Context>
- **Files:** <models, actions/services, migrations, command, routes — concrete paths>

## Data Shapes
<model columns + casts + relationships; API request/response shapes; raw-source columns>

## Open Risks / Deferred
<anything intentionally left for downstream, with a note on who owns it>
```

Keep it a **plan, not tests** — no slices, no test files, no TDD language.

**GATE:** ask for explicit approval of the full plan. On any amendment, revise and
re-present the full plan. **Nothing is written to Linear until the user approves
this complete plan.**

## Phase E — Replace the ticket body

Only after Phase D approval, write the approved plan into the **same ticket body**,
**replacing** it (`linear-server` `save_issue` with `description` — repo rule: the
ticket body is the single source of truth; write there, never a comment). Preserve
any original acceptance intent by folding it into the Overview. Write it verbatim
as approved — do not re-plan or add at this step.

## Phase F — Stop and route

Confirm the body is replaced, then **recommend the next skill** and stop — do not
invoke it:

- Plan spans **more than one ticket's worth** of work / multiple domains or seams →
  recommend **`plan-breakdown`** (it decomposes into parallel tickets, then calls
  `tdd-plan` per ticket).
- Plan is **a single ticket** → recommend **`tdd-plan`** directly (slice this one
  ticket's plan into a TDD backlog).

State which and why in one line. Create nothing else, write no further Linear
changes, and never enter breakdown or slicing yourself.

## Reference

- `.claude/skills/plan-breakdown/SKILL.md` — next step for multi-ticket plans;
  decomposes into parallel-aware tickets. Consumes this skill's output.
- `.claude/skills/tdd-plan/SKILL.md` — next step for single-ticket plans; slices a
  finished plan into a TDD backlog. Expects exactly the zero-TDD plan this produces.
- `CLAUDE.md` / `.ai/guidelines/project.md` — DDD layout, naming, raw-source column
  convention that shape every locked decision.
