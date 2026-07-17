---
name: human-review
description: Human-facing orientation pass before the automated engines — diffs the branch, summarizes in plain language what the PR does, and checks it against the Linear ticket, flagging any deviation from the ticket's scope.
---

# Human Review

You are running the **human-orientation** stage of the review loop:
`/create-pr` → **`/human-review`** → `/review-all` (or `/review-pr`) →
`/add-to-pr` → `/process-review`.

This stage is for the **human**, not the machine. Before the adversarial
multi-agent engines burn tokens finding defects, give the author a plain-language
readout of *what this branch actually does* and *how it lines up with the ticket
it claims to close*. The single most important output is **scope deviation**: work
that shipped but the ticket never asked for, and acceptance criteria the ticket
asked for but the diff doesn't deliver.

You **do not** post anything to GitHub, run linters/tests, or spawn reviewer
subagents. You read, you compare, you tell the user. Keep it conversational — this
is a briefing, not a `=== FINDING ===` report.

## Input
- **PR number** — positional arg, or auto-detected from the current branch.
- **Ticket ID** — `FLIX-XXX`, positional arg, or extracted from the branch / PR
  title.

Both are optional when the branch has an open PR. See Phase 0.

## Example Invocation
```
/human-review                 # auto-detect PR + ticket from branch
/human-review 205             # explicit PR, auto-detect ticket
/human-review FLIX-205        # auto-detect PR, explicit ticket
/human-review 205 FLIX-205    # explicit both
```

---

## Phase 0: Resolve PR + Ticket

1. **PR number** — if not passed, follow **PR Number Auto-Extraction** in
   `.claude/skills/review-pipeline/SKILL.md`. A PR is **not required** — if none
   exists, fall back to the local branch (`git diff origin/main...HEAD`) and note
   in the report that there's no open PR yet (suggest `/create-pr`).
2. **Ticket ID** — if not passed, follow **Ticket ID Auto-Extraction** in the same
   contract (branch name → PR title → null). If null, run the summary anyway and
   state plainly that ticket-alignment is **skipped** — there's nothing to check
   scope against.

---

## Phase 1: Gather the Change

Ground the readout in the same three sources `/create-pr` uses — **read them, do
not guess**:

| Source | Supplies | How |
|---|---|---|
| Diff | the *what* — actual changes | `gh pr diff {PR}` if a PR exists, else `git diff origin/main...HEAD` |
| Commits | the *narrative* / ordering | `git log --oneline origin/main..HEAD` |
| Ticket | the *why* — intent + acceptance criteria | `mcp__linear-server__get_issue` when a ticket ID is set |

Assert the diff is non-empty. Note added / modified / deleted files. If the diff
is large (> ~500 lines), lead with the highest-signal files and say you're
summarizing the rest.

If the Linear MCP is unavailable, say so and run the summary without alignment
(don't invent the ticket's contents).

---

## Phase 2: Summarize What We Did

Write a tight, plain-language account of the branch — the way you'd brief a
teammate looking at it for the first time. Group by **concern**, not by file:
"adds X action", "wires Y into the Filament panel", "backfills the Z column". Name
the key classes/files so the reader can jump in. Present tense, house style —
prose first, a short bullet list only if the change spans several distinct
concerns.

This is not a changelog dump of every hunk. It's *what the branch accomplishes*.

---

## Phase 3: Check Against the Ticket

Only if a ticket ID resolved and its contents loaded. Compare the diff against the
ticket's description and acceptance criteria and sort every material change into:

- **✅ On scope** — a change the ticket asked for. (Summarize, don't enumerate
  every line.)
- **⚠️ Deviation — shipped, not in the ticket** — code in this branch that no
  acceptance criterion covers. Reasonable incidental work (a rename, a lint fix)
  is fine to note lightly; a whole feature the ticket never mentioned is a real
  deviation, call it out.
- **❌ Gap — in the ticket, not shipped** — an acceptance criterion the diff
  doesn't appear to satisfy. Say which, and where you'd expect to see it.

For each deviation and gap, give the file(s) and a one-line why-it-matters. Don't
moralize — surface it and let the author decide.

Per the CLAUDE.md Linear rule (*"work deviates → confirm first, then update the
ticket and mark it a deviation"*), close this phase by reminding the user to
reconcile any real deviation on the ticket **before** merge — either fold it into
scope or split it out.

---

## Phase 4: Brief the User

Print a conversational briefing (this is the whole deliverable — nothing is
posted):

```markdown
# Human Review: PR #{number}{ against {ticket_id} if present}

## What this branch does
{plain-language summary from Phase 2}

## Ticket alignment
{✅ on-scope / ⚠️ deviations / ❌ gaps from Phase 3 —
 or "No ticket resolved — alignment skipped." }

## Before you kick off the automated review
- {deviations/gaps to reconcile on the ticket, if any}
- {anything that looked off but isn't defect-hunting — that's /review-all's job}

Next: /review-all  (or /review-pr for the in-house engine only)
```

If there are no deviations and no gaps, say so in one line — a clean
ticket-to-diff match is the good outcome, not a reason to manufacture concerns.

## Notes
- **Human-facing only.** No GitHub posting, no linters, no reviewer subagents,
  no commits — this stage informs, the later stages act.
- **Don't defect-hunt.** Correctness/edge-case/convention review is `/review-all`'s
  job. Staying in your lane keeps this stage fast and cheap. If something genuinely
  alarming jumps out, mention it in one line and defer to the review engines.
- **Ground everything** in the diff, commits, and ticket — never describe work the
  diff doesn't show or ticket scope you didn't read.

$ARGUMENTS
