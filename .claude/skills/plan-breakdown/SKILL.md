---
name: plan-breakdown
description: >-
  Decompose one large plan/PRD into parallelizable, DDD-clean, TDD-able Linear
  tickets, then plan each ticket's TDD slices. Use anytime you have a single big
  plan or PRD (file, Linear parent ticket, or pasted text) that covers more than
  one ticket's worth of work and needs breaking down before execution. Creates
  Linear tickets — but only after an explicit approval gate.
---

# Plan Breakdown

A PRD usually covers **many tickets' worth of work**. Splitting it into the
*right* tickets is the hard part: a bad decomposition kills parallelism (lesson —
FLIX-129/TVDB was split into 5 sub-tickets all editing one `TvdbApiService` on a
hard dependency chain → forced serial, zero parallelism gained). This skill turns
a PRD into tickets that are **TDD-able, DDD-clean, and actually parallelizable**,
then hands each ticket to `plan-slices` for slice planning.

You are the **front half** of a two-skill pipeline:

```
PRD ─plan-breakdown▶ parallel-aware tickets (Linear)
                        └─per ticket─▶ plan-slices ▶ slice backlog in ticket body
                                                    └─▶ user runs the tdd skill
```

## Priority order (hard constraint)

1. **TDD + DDD + repo conventions.** A ticket that can't be driven test-first, or
   that spans two bounded contexts, is **wrong** — no matter how well it parallelizes.
2. **Parallelizability.** Optimize throughput only *within* rule 1. Parallelization
   never overrides a domain boundary or testability.

## Side-effect discipline

This is the **only** skill in the pipeline with side-effects (Linear writes). They
live **exclusively in Phase D**, strictly behind the Phase C approval gate. Phases
A–C are pure analysis — create, modify, or relate nothing in Linear until the user
confirms. `plan-slices` (the back half) stays side-effect-free.

## Phase A — Intake + guardrails

- **Resolve the PRD source.** Accept a plan file path, a Linear parent ticket id,
  or pasted text. If the source is unclear, **prompt the user** — don't guess.
- **Load the binding constraints** as inputs to every later decision:
  - `CLAUDE.md` / `.ai/guidelines/project.md` — DDD layout (`app/Domains/*`),
    Action/exception naming, service constants, cross-domain only via `Contracts/`.
  - `.claude/skills/tdd/SKILL.md` — slice rules; `tdd-laravel-testing` / `tdd-react-testing`
    for stack conventions.
- **Map the surface.** Identify which `app/Domains/*` contexts the PRD touches and
  whether each piece is backend / frontend / full-stack.

## Phase B — Parallelization decomposition (the core IP)

- **Partition on file/seam, not feature.** Two tasks editing the same class are a
  chokepoint = serial, even if logically independent. The unit of parallelism is a
  non-overlapping file set, not a tidy feature name.
- **Build the dependency graph.** Foundation-first (shared client/base), then
  fan-out, then dependents. Distinguish:
  - **Intrinsic data deps** — X needs Y's output. Unparallelizable; serialize them.
  - **File-collision deps** — X and Y only clash because they edit one file.
    Fixable by reshaping (see collaborator extraction) — but only when it pays.
- **Flag chokepoint files** — a shared service class, `routes/`, `composer.json`,
  a migrations dir, a facade. These get a **single owner** or are **serialized**;
  never assign the same chokepoint file to two parallel tickets.
- **Collaborator extraction is a last resort, not a reflex.** Recommend splitting a
  class into collaborators **only when it buys real parallelism AND stays
  DDD-consistent with sibling code.** Don't shatter a cohesive ~360-line service
  into six classes just to parallelize — weigh it against the existing sibling
  shape (e.g. TMDB's single `TmdbApiService`). Cohesion + serial often beats
  fragmentation + parallel.
- **DDD boundary = ticket boundary.** Each sub-ticket lives in **exactly one**
  domain; any cross-domain need goes through `Contracts/`, and that contract is its
  own foundation ticket the dependents block on.
- **Per sub-ticket, produce:**
  - scope (one-paragraph what + why)
  - target files + the single domain it lives in
  - dependency edges (`blocks` / `blocked-by`)
  - a **parallel-group label** — *run-concurrently* (separate workspaces, no shared
    files) vs *stacked-serial* (shares a file/needs an upstream output)
  - a branch name (Linear convention: drop the `jasonlund/` prefix, ≤40 chars)
- **State the concurrency ceiling explicitly.** Spell out the realistic shape, e.g.
  *"foundation ticket → 3-wide fan-out → 2 dependents; tickets X and Y share file Z
  so they serialize / cost one reconcile."* This is the analysis FLIX-129 lacked.
- **Build the concurrency graph artifact.** `blocks`/`blocked-by` relations are
  pairwise edges — they do **not** make "what can I run right now, in parallel"
  legible. So always produce a standalone artifact (written to the parent in Phase
  D):
  - a **wave table** — each wave = the set of tickets runnable **concurrently**
    once the prior wave is merged (Wave 1 = foundation, Wave 2 = fan-out, …), one
    row per wave listing its ticket ids + branches.
  - a **Mermaid graph** (Linear renders ```mermaid blocks) showing the same
    dependency flow, grouped by wave with `subgraph`, so the parallel sets are
    visually obvious. Example shape:
    ````
    ```mermaid
    graph LR
      subgraph Wave1
        T1[FLIX-201 tmdb-enrichment]
      end
      subgraph Wave2
        T2[FLIX-202 watchlist-mutations]
      end
      T1 --> T2
    ```
    ````

## Phase C — Present for approval (hard gate, zero side-effects)

Show the user, and create nothing until they confirm or amend:

- the parent and every sub-ticket (title · scope · domain · files · deps ·
  parallel-group · branch)
- the **concurrency graph** (wave table + Mermaid) and the **concurrency ceiling**
- any structural recommendation (e.g. collaborator extraction) **with its cost** —
  it may send the decomposition back for an edit

**GATE:** no Linear write happens until the user approves this breakdown.

## Phase D — Create tickets (only after approval)

Use the `linear-server` MCP (`save_issue`). Per the repo Linear convention, **write
to the ticket body, never a comment**:

- Create each sub-ticket under the parent via `parentId`; inherit the parent's
  `project` and `milestone`.
- Set dependency relations with `blocks` / `blockedBy` to match the Phase B graph.
- Write each ticket's **plan** (scope + decisions + target files + domain +
  parallel-group) into its `description`. This is half of the self-contained body;
  `plan-slices` appends the other half next.
- **Always write the concurrency graph into the PARENT ticket body** — append a
  `## Concurrency` section (the Phase B wave table + Mermaid graph) to the parent's
  `description` via `save_issue`. Linear has no native parallel-group field and
  relations alone aren't legible, so the parent body is the single place the user
  reads "what to start, and when." Update it if the breakdown is amended.

## Phase E — Plan slices per ticket

For each created ticket, **in dependency order**, invoke the `plan-slices` flow
(surface classify → observable behaviors → testability gate → 2–6-test slices →
honest-RED notes → traceability). `plan-slices` **appends the slice backlog into that
same ticket body**, so one ticket = one self-contained body an executor can pick up
and run `tdd` against. Then **stop** — execution is the `tdd` skill's job, unchanged.

Phase D creates each sub-ticket in the default (Backlog) status; it sets no status
itself. Each sub-ticket reaches **Todo** through its own `plan-slices` pass here
(the *Automatic ticket status transitions* contract in `project.md`), not from
breakdown. The parent ticket's status is left untouched.

## Reference

- `.claude/skills/plan-slices/SKILL.md` — the back half; slice planning + the
  testability gate. This skill calls it per ticket.
- `.claude/skills/tdd/SKILL.md` — slice definition and the RED→GREEN→REFACTOR loop
  the tickets are ultimately executed with.
- `.claude/skills/tdd-laravel-testing` / `tdd-react-testing` — stack conventions + exact
  test commands. Reference them; don't restate.
