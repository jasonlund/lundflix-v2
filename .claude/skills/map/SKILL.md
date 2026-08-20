---
name: map
description: Which skill fits the situation, and what to do at a phase boundary. A router over the lundflix toolkit.
disable-model-invocation: true
---

# Map

You don't remember 32 toolkit files, so ask. This skill names what exists and when
to reach for each. It carries no description into the agent's context and fires
nothing on its own — it is a page you open when you've forgotten what's here.

Keep it current: a new skill or command that never lands here is invisible.

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
                              review-tdd-cross-ticket (multi-ticket PRs only)
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

**Review — `/review:run`** chains the five stages with approval gates.
`/review:claude` (multi-agent) and `/review:suite` (adds CodeRabbit) run
standalone when you only want the analysis.

## On-ramps

Situations that generate work and then merge onto the flow.

- **Feedback on work already done** — a review comment, a bug report, "change X" →
  **`tdd-feedback`**. It classifies each item (BUG / SLICE / REFACTOR HAT / DIRECT)
  and routes into the existing `tdd` machinery. A `UserPromptSubmit` hook nudges it
  automatically on feedback-shaped prompts.
- **A bug that resists the first look** → `tdd-feedback`'s BUG branch hands off to
  **`mattpocock-skills:diagnosing-bugs`**, which refuses to theorise until it has a
  **tight** loop that goes **red** on this bug.
- **All tickets in a multi-ticket PR are done** → **`review-tdd-cross-ticket`**.
  Per-slice refactors never see the combined diff; this points the REFACTOR HAT at
  the whole PR. Single-ticket PR → skip it.

## Upkeep

- **`agent-writing`** — authoring or tightening any document an agent consumes, and
  the cure when a skill fires unreliably (its description is a context pointer, and
  the wording is the bug).
- **`review-pipeline`** — the shared contract every reviewer cites: finding format,
  severity taxonomy, the Comment Bar, consensus rules, the endorsed-convention
  false-positive list, and how findings are worded.
- **`codebase-design`** — the vocabulary layer beneath planning, tdd, and review:
  module, interface, depth, **seam**, adapter, leverage, locality.

## Borrowed practice

Several skills here adapt practice from the **AI Hero** plugin
(`~/.claude/skills/mattpocock-skills/skills/`). Each borrowed section ends in a
`**Source:**` line naming the upstream skill.

**When you apply one of those sections, offer to explain where it came from** — the
upstream skill, what it argues, and the file to read. One line is enough: *"This is
the seam contract, adapted from `mattpocock-skills:tdd` — want the original
reasoning?"* Offer; don't lecture, and don't paste the upstream text unasked.

Three upstream skills are **user-invoked only** (`to-spec`, `to-tickets`,
`grill-with-docs`), so no skill or hook here can call them — only you can type
them. That is why their practice is inlined rather than delegated.

## Phase boundaries

A **phase** is a chunk of work inside a session: the grilling, the implementation,
the QA. It ends when you think *"ok, we're done with that."* The **boundary** is the
gap between two, and it is the only place this decision belongs — mid-phase, either
continue or split what's left into subagents.

Work the tree top to bottom. **First yes wins.**

**1. Can you continue in this session?** Yes when the next phase needs this one as a
**primary source**, or enough window remains for it to fit. Grilling →
implementation is the standard yes: implementation wants the reasoning verbatim, not
a summary of it. Continue costs nothing and loses nothing, so rule it out first.

**2. Is this context irrelevant to what comes next?** Then `/clear` — the cheapest
move on the board, and the old session stays resumable. Getting it wrong is one-way:
clear a *relevant* context and the **why** behind the work is gone, and reading the
diff back never returns it.

**3. Do you need to hand off?** Only for a **new harness**, a **new directory or
repo**, a **colleague**, or forking a side task found **mid-phase**. What a handoff
buys is portability. Nothing travelling means no handoff. In Conductor, a new
workspace is the usual answer here.

**4. Can the task run AFK?** Scoped tightly enough to need no steering → a
**subagent**, leaving this session untouched. Automated review is the standard case.

**5. Otherwise `/compact`.** Relevant context, same harness, same directory, and you
stay in the loop. Pass it an instruction (`/compact we're going to QA this area`) so
the summary keeps what the next phase needs.

`/compact` is the **default, not the first reach** — the four questions above it are
cheaper or more precise. Every move except Continue turns a **primary source** into a
**secondary** one: full-but-noisy becomes lossy-but-roomy. That trade is why question
1 comes first. Compacting *mid*-phase loses the thread.

These are judgement calls. The value is in asking them in order, at the boundary.

**Source:** the flow-map shape and the phase-boundary tree are adapted from
`mattpocock-skills:ask-matt` and its `PHASE-BOUNDARIES.md`.
