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
  - `CONTEXT.md` + any `docs/adr/` entries touching the area (`docs/agents/domain.md`).
    Ticket titles and descriptions use the glossary's vocabulary; flag an ADR
    conflict explicitly instead of silently overriding it. Missing files → proceed
    silently.
- **Map the surface.** Identify which `app/Domains/*` contexts the PRD touches and
  whether each piece is backend / frontend / full-stack.

## Phase B — Parallelization decomposition (the core IP)

**Cut vertically first, then partition.** Verticality decides *what a ticket is*;
parallelism only decides how the tickets are scheduled. Get this backwards and you
produce layer-shaped tickets that parallelize beautifully and deliver nothing.

- **Each ticket is a tracer bullet** — a narrow but **complete** path through every
  layer it touches (migration → model → action → command/route → UI), not one
  layer's slice across many features.
- **The demoable-alone test.** Can this ticket be demonstrated or verified on its
  own once merged? No → it's horizontal; fold it into the vertical tickets it
  serves. A "add heartbeat output everywhere" ticket fails this test; the same
  heartbeats folded into each leg's own ticket pass it (FLIX-270, where this test
  produced a materially better decomposition).
- **Size to one fresh context window.** A ticket an executor can't hold at once
  gets split, regardless of how cleanly it parallelizes.
- **Prefactor first.** "Make the change easy, then make the easy change" — when a
  restructure would make every downstream ticket simpler, it is its own **first**
  ticket that the others block on, not work smuggled into whichever ticket trips
  over it.

### Wide refactors — the exception to vertical slicing

A **wide refactor** is one mechanical change (rename a column, retype a shared
symbol) whose blast radius fans across the codebase, so a single edit breaks
hundreds of call sites and **no vertical slice can land green**. Don't force it
into a tracer bullet. Sequence it **expand–contract**:

1. **Expand** — add the new form beside the old; nothing breaks.
2. **Migrate** — move call sites in batches sized by blast radius (per domain, per
   directory), each batch its own ticket blocked by the expand. CI stays green
   batch to batch because the old form still exists.
3. **Contract** — delete the old form once no caller remains, blocked by every
   migrate batch.

If even the batches can't stay green alone, keep the sequence but let them share
an integration branch that all block a final integrate-and-verify ticket; green is
promised only there.

**Source:** the tracer-bullet verticality test, the demoable-alone criterion,
one-context-window sizing, prefactor-first, and the expand–contract sequence for
wide refactors are adapted from `mattpocock-skills:to-tickets`. That skill is
user-invoked only (`disable-model-invocation: true`), so nothing here can call
it — the practice is inlined rather than delegated. Offer to explain the upstream
reasoning when one of these decides a decomposition call. The rules below —
chokepoints, waves, DDD boundaries — are this repo's own.

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
  - **What to build** — the end-to-end behavior this ticket makes work, from the
    caller's or user's perspective. **No file paths, no code snippets here**: they
    go stale fast and describe the edit rather than the outcome.
  - **Acceptance criteria** — a checkbox list of what "done" means, each
    independently checkable. This is what review checks the finished work against;
    a prose scope paragraph gives `/review:human`'s spec pass nothing to verify.
  - **Target** — the concrete files + the single domain it lives in. Paths live
    *here*, deliberately: this block is the input to chokepoint detection and wave
    assignment. Label it as such — it may go stale, and that's an accepted cost.
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

- the parent and every sub-ticket (title · what it delivers · acceptance criteria ·
  domain · files · deps · parallel-group · branch)
- the **concurrency graph** (wave table + Mermaid) and the **concurrency ceiling**
- any structural recommendation (e.g. collaborator extraction, a prefactor ticket,
  an expand–contract sequence) **with its cost** — it may send the decomposition
  back for an edit

Ask three things outright, since they're the ones that go wrong silently:
granularity (too coarse / too fine), whether each ticket's blockers **genuinely**
gate it rather than merely relating to it, and whether any pair should be merged
or split.

**GATE:** no Linear write happens until the user approves this breakdown.

## Phase D — Create tickets (only after approval)

Use the `linear-server` MCP (`save_issue`). Per the repo Linear convention, **write
to the ticket body, never a comment**:

- Create each sub-ticket under the parent via `parentId`; inherit the parent's
  `project` and `milestone`.
- Set dependency relations with `blocks` / `blockedBy` to match the Phase B graph.
- Write each ticket's **plan** (What to build + acceptance criteria + decisions +
  Target files + domain + parallel-group) into its `description`. This is half of
  the self-contained body; `plan-slices` appends the other half next.
- **Set no labels.** Readiness is carried by the ticket's **status**, per the
  *Automatic ticket status transitions* contract — a `ready-for-agent`-style label
  alongside it would be a second readiness signal that can disagree with the first.
  Triage labels belong to the triage queue, which feeds *into* planning.
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
