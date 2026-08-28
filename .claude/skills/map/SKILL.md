---
name: map
description: Which skill fits the situation, and what to do at a phase boundary. A router over the lundflix toolkit.
disable-model-invocation: true
---

# Map

You don't remember 34 toolkit files — 12 skills, 8 commands, 14 subagents, this
skill among them — so ask. This page names all of them and when to reach for each.
It carries no description into the agent's context and fires nothing on its own.

Keep it current: a new skill, command, or subagent that never lands here is
invisible.

## The main flow: rough idea → merged PR

```
rough ticket ─/plan:run──▶ plan-draft ─▶ plan-breakdown ─▶ plan-slices
                                                              │
                                          slice backlog in the ticket body
                                                              ▼
                                         tdd  (RED → GREEN → REFACTOR, per slice)
                                                              │
                                        every ticket done and green
                                                              ▼
                              review-tdd-cross-slice (multi-slice PRs only)
                                                              │
                                                              ▼
             /review:run ─▶ create-pr ─▶ human ─▶ suite ─▶ process ─▶ delta
```

**Planning — `/plan:run`** orchestrates all three with gates, so reach for the
individual skills only when you're re-entering partway:

- **`plan-draft`** — a ticket names WHAT but pins no HOW. Interviews you to lock
  the decisions, then replaces the ticket body. Say so explicitly and it runs in
  **synthesis mode** instead: no interview, assembled from a conversation that
  already settled things.
- **`plan-breakdown`** — the plan covers more than one ticket. Cuts vertical
  tracer-bullet tickets, builds the wave/concurrency graph, writes Linear.
- **`plan-slices`** — one ticket's plan becomes a **seam contract** plus an ordered
  TDD slice backlog. Also runs standalone.

**Execution — `tdd`.** One behavior slice per cycle, each phase in its own
subagent so tests can't be retrofitted. `tdd-laravel-testing` and
`tdd-react-testing` carry the stack conventions; the subagents read them.

**Review — `/review:run`** chains the five stages with approval gates:
`/review:create-pr` (lint, commit, push, open) → `/review:human` (orientation pass
for you) → `/review:suite` → `/review:process` (fix the approved items) → delta.
Standalone when you want one piece: `/review:claude` (multi-agent analysis),
`/review:suite` (that plus CodeRabbit), and **`/review:add`** — posts a
`/review:claude` report to the PR as one review, inline where the file/line is in
the diff. `/review:suite` calls `add` for you; run it yourself after a bare
`/review:claude`.

## On-ramps

Situations that generate work and then merge onto the flow.

- **Feedback on work already done** — a review comment, a bug report, "change X" →
  **`tdd-feedback`**. It classifies each item (BUG / SLICE / REFACTOR HAT / DIRECT)
  and routes into the existing `tdd` machinery. A `UserPromptSubmit` hook nudges it
  automatically on feedback-shaped prompts.
- **A bug that resists the first look** → `tdd-feedback`'s BUG branch hands off to
  **`mattpocock-skills:diagnosing-bugs`**, which refuses to theorise until it has a
  **tight** loop that goes **red** on this bug.
- **Every slice in a multi-slice PR is done** → **`review-tdd-cross-slice`**.
  Per-slice refactors never see the combined diff; this points the REFACTOR HAT at
  the whole PR. Single-slice PR → skip it; one ticket of many slices still qualifies.

## Upkeep

- **`agent-writing`** — authoring or tightening any document an agent consumes, and
  the cure when a skill fires unreliably (its description is a context pointer, and
  the wording is the bug).
- **`review-pipeline`** — the shared contract every reviewer cites: finding format,
  severity taxonomy, the Comment Bar, consensus rules, the endorsed-convention
  false-positive list, and how findings are worded.
- **`codebase-design`** — the vocabulary layer beneath planning, tdd, and review:
  module, interface, depth, **seam**, adapter, leverage, locality.

## Subagents (`.claude/agents/`)

Fourteen, and you never invoke one directly — the commands and skills above dispatch
them, each into its own context window.

- **Phase 3 reviewers** (`/review:claude`, in parallel): `requirements-reviewer`,
  `conventions-reviewer`, `edge-case-reviewer`, `integration-reviewer`,
  `discipline-reviewer`, `testing-reviewer`, `duplication-reviewer` — one axis each.
- **Phase 5 challengers** (`/review:claude`): `false-positive-hunter` argues the
  medium-confidence findings are wrong; `missing-defect-hunter` re-reads the PR with
  fresh eyes.
- **`coderabbit-reviewer`** — `/review:suite`'s second engine; runs the CodeRabbit
  CLI and normalizes its output into the pipeline's finding format.
- **`review-fixer`** — `/review:process` runs these in parallel, one per approved
  item or file-cluster, test-first. Each owns its files and never commits.
- **TDD trio** (`tdd`, one phase each so tests can't be retrofitted):
  `tdd-test-writer` → `tdd-implementer` → `tdd-refactorer`.

## Borrowed practice

Several skills here adapt practice from the **AI Hero** plugin
(`~/.claude/skills/mattpocock-skills/skills/`), each borrowed section closing with a
`**Source:**` line. The convention for those lines — offer to explain the origin
when you apply one — is in `CLAUDE.md` under *Borrowed practice carries a Source
line*, already in your context; it isn't repeated here.

What is local: **20 of the 35 upstream skills are user-invoked only**
(`disable-model-invocation: true`), among them `ask-matt`, `wait-what`, `to-spec`,
`to-tickets`, `grill-with-docs`, `triage`, and `wayfinder`. No skill, command, or
hook here can reach those — only you can type them. The rest are callable, and the
toolkit hands off to several by name: `diagnosing-bugs`, `prototype`, `research`,
`codebase-design`, `wizard`. (`code-review`, `tdd`, and `writing-for-agents` are
callable too, yet their practice is still inlined — see the `CLAUDE.md` section
above for why.)

## Phase boundaries

A **phase** is a chunk of work inside a session: the grilling, the implementation,
the QA. The **boundary** is the gap between two, and it is the only place the
continue / `clear` / hand-off / subagent / `compact` decision belongs — mid-phase,
either continue or split what's left into subagents.

The decision tree — five questions, top to bottom, **first yes wins** — is in
`.claude/skills/map/PHASE-BOUNDARIES.md`, beside this file. Open it at a boundary.

**Source:** the flow-map shape is adapted from `mattpocock-skills:ask-matt`.
