---
name: tdd-feedback
description: >-
  Routing feedback and change requests on already-completed work into the correct
  TDD phase. Use when addressing code-review comments, bug reports, or change
  requests after a feature/cycle is done ("reviewer said", "fix this bug", "change
  X after merge"). Classifies each item → bug / behavior change / pure cleanup /
  non-code → dispatches to the tdd RED/GREEN/REFACTOR subagents and gates.
---

# TDD Feedback Router

This skill is a **classifier + router**, not a new loop. The RED → GREEN → REFACTOR
machinery lives in `.claude/skills/tdd/SKILL.md` — this skill only decides *which
phase* a piece of feedback enters, then dispatches into the **existing** tdd
subagents (`tdd-test-writer`, `tdd-implementer`, `tdd-refactorer`) and the **same
gates**. Do not duplicate the loop; defer all spawn/gate mechanics to `tdd`.

The `tdd` skill fires on "implement / build / add" (new work). This one fires on
feedback against work that is already done. They classify on **different axes**:
tdd's Step 0 classifies the *stack* (backend / frontend / full-stack); this skill
classifies the *feedback type* (bug / behavior change / cleanup / non-code).

## When this activates

- Work is **clearly TDD-driven** (an active tdd cycle, or the user invoked this skill
  / said "use tdd-feedback") → route on any review / bug / change-request language.
- **Otherwise → confirm first.** When it is not clear this work runs via TDD, ask the
  user before routing — do not hijack an ordinary edit.

## Approval before dispatch

**Every code branch shows its classification + a plan card before any subagent runs.**
The user confirms the chosen route. Only the DIRECT (non-code) branch skips approval.

## Hard rules

- **Bug → test-first, ALWAYS.** A failing reproducer (RED) goes in before the fix
  (Beck's regression-test pattern). Never patch the code first.
- **Cleanup → refactor only against a GREEN suite.** Two hats: never mix structural
  and behavioral change in one pass.
- **Behavior change → a new slice**, one at a time, plan-card approved. Never bolt it
  onto a refactor.
- **Ambiguous / mixed feedback → SPLIT it.** Route each item separately. Don't batch
  a "fix this bug and also add X and rename Y" comment as one cycle.

## Classifier

```
Feedback item in →
 ├─ Behavior wrong / something broken?              → BUG
 │     full tdd cycle; RED = a failing reproducer test
 │     RED tdd-test-writer · GREEN tdd-implementer · REFACTOR tdd-refactorer
 │     show classification + RED plan card (EnterPlanMode/ExitPlanMode) → dispatch
 │
 ├─ New / changed behavior?                          → SLICE
 │  ("also do X", "change the validation")
 │     show classification → hand to the normal tdd loop (its Step 1–3),
 │     starting at a new RED plan card
 │
 ├─ Pure structural cleanup?                         → REFACTOR HAT
 │  (rename, extract, env→const, dedup)
 │     PRECONDITION GATE: run the full suite, show it GREEN now
 │     show classification + plan card → on approval:
 │     tdd-refactorer only — no new test, behavior-preserving
 │     POST GATE: still green (subagent shows the run)
 │     A test breaks → it tested implementation, not behavior; fix the test, flag it
 │
 └─ Non-code? (docs, comments, prose naming)         → DIRECT
       handle directly, no cycle, no card
```

## Routing table

| Class        | Subagent(s)                                      | Gates                                              | Approval shown            |
|--------------|--------------------------------------------------|----------------------------------------------------|---------------------------|
| BUG          | `tdd-test-writer` → `tdd-implementer` → `tdd-refactorer` | RED fails for right reason · GREEN passes · stays green | RED plan card        |
| SLICE        | normal tdd loop (Step 1–3)                       | same three tdd gates                                | RED plan card             |
| REFACTOR HAT | `tdd-refactorer` only                            | precondition green · post-refactor still green     | green-run THEN plan card  |
| DIRECT       | none                                             | none                                               | none                      |

## Reference

- See `.claude/skills/tdd/SKILL.md` for loop mechanics, exact gate wording, and the
  plan-card flow — do not duplicate them here.
- **Integration:** run `review-comments` first to gather + group feedback, then invoke
  this skill **per item**.

## Convention note

The **SLICE branch** — treating a behavior-change review comment as a new RED slice —
is *our* convention extending Canon TDD, not an authoritative cited rule. The BUG
(regression test) and REFACTOR (green-suite, two-hats) branches are well-established;
the behavior-change routing is our house style.
