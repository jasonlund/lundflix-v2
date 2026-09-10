---
name: review:human
description: The human read of the branch — resolves the PR, prints the Linear diff link, waits while a person reads the diff and submits their review, then hands the submitted comments to /review:process --human-round.
---

# Human Review

You are running the **human** stage of the review loop:
`/review:create-pr` → `/review:debrief` → **`/review:human`** → `/review:suite`
(or `/review:claude`) → `/review:add` → `/review:process`.

The person reads the branch **before** the machines do. A reader who has already
seen fifteen machine findings checks their work instead of forming their own
view, and the engines otherwise burn a full pass over code a reader would have
restructured anyway. So the human goes first, and the engines review what the
human left behind.

**Any human reviews here** — the author most often, a teammate just as validly.

You do not read the diff for them, you do not defect-hunt, and you post nothing
yourself. You print the link, you wait, and you hand back what comes in.

## Input

- **PR number** — positional arg, or auto-detected from the current branch.

## Example Invocation

```
/review:human        # auto-detect the PR from the current branch
/review:human 205    # explicit PR
```

---

## Phase 0: Resolve the PR and the Repo

1. **PR number** — if not passed, follow **PR Number Auto-Extraction** in
   `.claude/skills/review-pipeline/SKILL.md`. With no PR found, HALT and tell the
   user to run `/review:create-pr` first: there is no diff to open in Linear
   until a PR is open.
2. **Repo** — `{owner}/{repo}` from
   `gh repo view --json nameWithOwner -q '.nameWithOwner'`.

Resolve both here rather than assuming `/review:debrief` ran. This command is
invoked on its own as often as it runs in the chain, and a link built from a
stale hand-off points at the wrong PR.

---

## Phase 1: Hand Over the Diff and Wait  ⏸

Print the diff link:

```
Diff: https://linear.review/{owner}/{repo}/pull/{n}
```

Then tell the reader, in plain words, what to do with it:

- Open the link and read the diff in Linear.
- Comment on the lines that need a comment. Put every point on a line: only a
  line-anchored comment reaches the pipeline, and a summary written in the review
  body is collected as nothing.
- **Submit** the review at the end. Linear syncs a review's line-anchored
  comments to the GitHub PR only when it is submitted. A review left as a draft
  stays in Linear, never reaches GitHub, and no later stage can see it.

Now stop and wait for the user. This is a ⏸ gate: run no engines, collect no
feedback, and start no other stage until the user tells you the review is
submitted (or that there was nothing to say).

---

## Phase 2: Ingest the Submitted Review

The submitted comments now sit on the PR as ordinary human feedback, so there is
no new ingest path to write. Delegate to `/review:process --human-round`, with
the PR number Phase 0 resolved.

That command owns the mechanics — collection, triage, the numbered gate, the
fixers, the replies. Defer to it. Do not restate its triage here and do not
settle any item yourself.

### When the ingest reports zero items

Zero items is not by itself a clean review. Name all three ways it happens, rather
than sliding on to the engines as though there were no feedback:

- **The review is still saved as a draft.** The likeliest cause. A draft never
  syncs to GitHub, so the pipeline cannot see it and the PR carries nothing to
  collect — the comments are on screen in Linear and nowhere else.
- **The points went into the review body.** The collector reads a review body for
  `/review:add`-shaped findings alone, so prose there yields no items. The review
  synced and the summary stayed behind.
- **There was nothing to flag.** A legitimate outcome, and the good one. A clean
  read is a result, not a failure to find something.

This stage cannot tell them apart, so say all three and offer the choice: re-check
the PR after the reader submits, or accept the clean read and move on to
`/review:suite`.

## Notes

- **Any human reviews here.** Write and speak to "the reviewer", not "the
  author" — a teammate reviewing someone else's branch is the same stage.
- **Submitted, not drafted.** The likeliest cause of an empty result, not the
  only one — Phase 2 carries all three, and reporting the draft as the whole
  explanation sends the reviewer back to a review that already synced. Say it
  before the wait and again after a zero-item ingest.
- **Read nothing for them.** Summarizing the branch is `/review:debrief`'s job,
  and defect-hunting is `/review:suite`'s. Staying in your lane keeps this stage
  a link, a wait, and a hand-off.
- **Never continue through the gate on your own.** The whole value of the stage
  is a human view formed before the machines speak.

$ARGUMENTS
