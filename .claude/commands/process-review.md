---
name: process-review
description: Third stage after /review-pr → /add-to-pr. Reads un-resolved PR feedback (GitHub inline threads + general comments + Conductor diff-comments), triages it, presents each item with a recommendation for approve/modify/skip, fires a background fixer per approval (file-claim mutex, test-first via tdd-feedback, no commit), then resolves every considered thread and prompts to commit/push.
---

# Process Review Feedback

You are orchestrating the final stage of the review loop:
`/review-pr` (generate findings) → `/add-to-pr` (post them to the PR) →
**`/process-review`** (act on them). You read back the feedback that is still
open on the PR, decide with the user what to act on, dispatch isolated fixer
subagents to do the work, then **resolve everything you considered** so a future
run never reconsiders it — and finally prompt to commit/push.

You never edit code in your own context. You triage, present, dispatch, verify,
and resolve. The fixing happens in `review-fixer` subagents.

## Input
- **PR number** — positional arg, or auto-detected from the current branch.

## Example Invocation
```
/process-review        # auto-detect PR from the current branch
/process-review 142    # explicit PR
```

---

## Phase 0: Resolve PR + Gather Un-resolved Feedback

1. **PR number** — if not passed, follow **PR Number Auto-Extraction** in
   `.claude/skills/review-pipeline/SKILL.md`. If no PR is found, HALT and tell the
   user to push the branch and open a PR (or pass the number).
2. **Repo** — `gh repo view --json owner,name --jq '{owner: .owner.login, repo: .name}'`.
3. **GitHub inline review threads** — fetch via GraphQL and keep only the unresolved ones:
   ```bash
   gh api graphql -F owner={owner} -F repo={repo} -F pr={number} -f query='
   query($owner:String!,$repo:String!,$pr:Int!){
     repository(owner:$owner,name:$repo){
       pullRequest(number:$pr){
         reviewThreads(first:100){
           nodes{
             id isResolved isOutdated
             comments(first:50){ nodes{ id author{login} body path line originalLine } }
           }
         }
       }
     }
   }'
   ```
   Discard threads where `isResolved == true`. For each surviving thread, keep its
   `id` (the `threadId` used to resolve it) and the first comment's `id`, `path`,
   `line`, `body`, `author`.
4. **GitHub PR review bodies** — `/add-to-pr` posts findings two ways: inline
   comments (the review threads from step 3) **and** body findings written into the
   review's body (the `## 🤖 Automated Review` block, with per-finding
   `### 🔴/🟠/🟡 … · File / Issue / Violates / Recommendation` entries). Fetch the
   review bodies and parse each entry into its own item:
   ```bash
   gh api repos/{owner}/{repo}/pulls/{number}/reviews
   ```
   Parse every finding entry out of each review `body`. A review body has no resolve
   mutation and **cannot take a direct reply**, so its only durable "handled" signal
   is a resolution comment we posted on a prior run. Build a `handledBodyRefs` set:
   scan the general PR comments (step 5) for the footer ref token
   `via /process-review · ref: review-body {file}:{line}` (Phase 5). Compute the same
   `{file}:{line}` ref for each parsed body finding and **skip any whose ref is in
   `handledBodyRefs`** — this is a deterministic key match, not a fuzzy text match, so
   handled body findings are reliably not re-picked-up. Items with no ref match are
   un-handled. **Body findings are first-class — present, fix, and comment their
   result exactly like inline ones**; only the resolve mechanic differs (Phase 5).
5. **GitHub general PR comments** (not tied to a diff line):
   ```bash
   gh api repos/{owner}/{repo}/issues/{number}/comments
   ```
   These have no resolved state. Treat each as un-handled **unless** a later comment
   in the thread carries the `via /process-review` footer marker (Phase 5) — that
   marks it already handled; skip it. (Comments that are themselves `/process-review`
   resolution receipts are skipped, not re-triaged.)
6. **Conductor diff-comments** — read from the **current conversation's attachments**
   (the Conductor MCP cannot fetch them). If none are attached, note that and move on.
7. Normalize every kept item to:
   `{ source: gh-thread | gh-review-body | gh-comment | conductor, threadId?, commentId?, reviewId?, file?, line?, body, author, severityBadge? }`.
   If there are zero un-resolved items across all sources, say so and stop.

---

## Phase 1: Triage (classify origin → classify scope → dismiss false positives → group → sort)

1. **Classify origin.** Items posted by `/add-to-pr` carry its footer
   (`via /review-pr` / `Found by:`). Those already passed false-positive-hunter +
   adversarial verification inside `/review-pr` — **trust them; do not re-run FP
   scrutiny.** Only *external* feedback (human reviewers, general comments, Conductor
   diff-comments) gets scrutiny: judge it inline against the **Convention Override
   Rule** and "Commonly false-positived conventions" in
   `.claude/skills/review-pipeline/SKILL.md`. If the external feedback is high-volume
   or low-confidence, you **may** spawn `false-positive-hunter` over just those items.
2. **Classify scope (in-scope vs out-of-scope).** This PR is only responsible for
   code it created or modified. Build the PR's changed-line set from its own diff:
   ```bash
   gh pr diff {number} --patch   # parse per-file the added/modified line numbers (new side)
   ```
   For each item: it is **in-scope** when its `file` is in the changed set **and** its
   `line` falls on or within ±3 lines of an added/modified hunk. An item with a `file`
   the PR never touched — or a `line` far from any changed hunk — is **out-of-scope**:
   the flagged code predates this PR and the PR did not modify it. (Items with **no
   file/line** — general comments, broad Conductor notes — are not scope-checked; keep
   them in-scope and present them normally.)
   - **Out-of-scope, non-urgent** (SHOULD_FIX / CONSIDER / NIT) → **drop from the main
     flow**. Do not present it. Record it as a skip with the rationale "out of scope —
     PR did not create or modify this code" (resolved in Phase 5).
   - **Out-of-scope, urgent** (BLOCKING) → do **not** present it inline either. Hold it
     in an `outOfScopeUrgent` list for the separate round in **Phase 3.5**, after the
     main flow has fully run through.
3. **Group** duplicate / related items by `(file, line ±10, category)` per the
   contract's dedup rule. A group is presented and fixed as one unit.
4. **Sort** BLOCKING → SHOULD_FIX → CONSIDER → NIT. Use the `/add-to-pr` badge
   (🔴/🟠/🟡) when present; otherwise assign severity per the contract taxonomy.
5. **Auto-dismiss anything explicitly labeled dismissable.** Any item the
   orchestrator dismisses as a false positive — or that arrives **already carrying a
   dismissed/dismissable label** (e.g. an `/add-to-pr` or CodeRabbit finding under a
   ⚫ *Dismissed as false positive* badge) — is **dropped from the Phase 2
   presentation flow**, exactly like out-of-scope non-urgent items. Do **not** present
   it for confirmation: there is no value in asking the user to confirm a dismissal
   that is already settled. Record it as a dismissal with its rationale (resolved in
   Phase 5). If the dismissal is an endorsed-pattern FP with a worthwhile
   reinforcement, capture the suggested reinforcement for the Phase 6 batched offer —
   do **not** gate the loop on it.

Only **in-scope, non-dismissed** items (plus no-file/line items) flow into Phase 2's
presentation loop. Out-of-scope and auto-dismissed items are handled by their paths
above — never presented inline.

---

## Phase 2: Present Each Item — with Your Opinion

Walk the triaged list in severity order, **one item (group) at a time**. Present
each item as **plain text in your message and then stop and wait for the user's
reply** — do **not** use `AskUserQuestion`, menus, or any dialog tool. For each,
give the user **your own recommendation on the fix**, not just the reviewer's words:

- What the comment asks for (quote the relevant line).
- Your read: do you agree? Is there a better/cheaper fix? Should the scope be
  narrower? Do you think it should be skipped (e.g. endorsed-convention false
  positive)?
- The concrete change you'd make if approved.

### Endorsed-pattern dismissals — capture a reinforcement, don't present

Dismissed-as-FP items are **not presented** (Phase 1 auto-dismisses them). But when a
dismissal is wrong about a pattern the project deliberately uses and **will keep
using** (e.g. `RefreshDatabase` / `Http::preventStrayRequests()` applied globally in
`tests/Pest.php` rather than per-file; DDD model placement; service-constant base
URLs), a plain skip lets the **same false positive come back every run**. So while
triaging, **capture a reinforcement** for it — but do not interrupt the loop to ask.

Capture a concrete, minimal reinforcement — usually one or both of:
- **Convention registry** — add the pattern to "Commonly false-positived
  conventions" under the **Convention Override Rule** in
  `.claude/skills/review-pipeline/SKILL.md`, so our own reviewer agents never
  re-raise it. Note the exact one-line bullet you'd add.
- **External reviewer config** — if the flag came from CodeRabbit (or another CLI
  engine), a path-scoped instruction in its config (e.g. `.coderabbit.yaml`) telling
  it not to flag missing per-file setup under `tests/` because it's global in
  `Pest.php`. Note the rule.

These reinforcements are collected silently and offered **once, batched, in Phase 6** —
never mid-loop. A reinforcement is a docs/config edit, so on the user's Phase 6
approval you apply it **directly in your own context** — do NOT spawn a `review-fixer`
(those are for code).

End each presented item's message by asking the user to reply **Approve**, **Modify**
(with their adjustments), or **Skip**, and wait for their response before continuing.

### The two kinds of turn — and the one hard rule

Every turn in this loop is one of two kinds. Decide which BEFORE writing anything:

1. **User-reply turn** — the last message is the **user** sending Approve / Modify /
   Skip. This is the *only* turn that acts on an issue: record the decision, drain any
   queued fixers (Phase 3), dispatch this item's fixer, then present the **next**
   issue. Prose belongs here, and only here.
2. **Wake-up turn** — the last event is a **fixer finishing** or any other
   system/tool notification. This turn does **internal bookkeeping only and emits zero
   prose**: free the finished fixer's files and record its report (Phase 3), then
   **end the turn** — no tool call needed, no text, empty output. It does **not**
   dispatch anything, does **not** drain `pending`, does **not** touch any issue.

**The hard rule: a wake-up turn never produces a user-facing token.** Not a status,
not an acknowledgement, not "draining", not "moving to item 5", not a re-prompt of the
pending issue, not a meta-note that the silence feels odd. The empty turn is always
correct — if it ever feels "confusing" or like it "needs explaining," that feeling is
the bug, not a reason to override it. A wake-up carries no decision, so you have
nothing to say.

Why this holds: the wake-up turn has **no action to narrate** because dispatch and
queue-draining are deferred to the next user-reply turn (Phase 3). Nothing happens on
a wake-up except freeing a file — so write nothing.

### Each issue is presented exactly ONCE

Send a message about an issue **one time**: its single presentation, in a user-reply
turn. Never restate it, re-ask Approve/Modify/Skip, or give a "still waiting" status
on a later turn.

**Present only the next issue.** A user-reply turn's message contains **nothing but
the next issue** (or a fixer blocker, per Phase 3). All fixer state — completions,
progress, files released, tallies — is tracked **internally and silently** and
surfaced once, together, in the Phase 6 summary. Never mid-loop.

- **Approve** → on this same user-reply turn, run the Phase 3 steps (drain, then
  dispatch this item), then present the next item.
- **Modify** → user adjusts scope/instructions; record as approved-with-edited-
  instructions, then dispatch via the Phase 3 steps.
- **Skip** → record the reason for Phase 5.

Do **not** wait for any fixer to finish before presenting the next item.

---

## Phase 3: Fire-on-Approval Dispatch (file-claim mutex)

Maintain two structures across the Phase 2 loop:
- `claimedFiles` — files currently held by an in-flight fixer.
- `pending` — approved items blocked on a busy file.

Dispatch and queue-draining both happen **only on a user-reply turn** — never on a
wake-up (see Phase 2's two-turn rule). This is what makes wake-ups silent: a finishing
fixer just frees its files; nothing is dispatched or narrated until the user next
speaks.

**On a fixer-completion wake-up (silent turn):** release that fixer's files from
`claimedFiles`, record its report, and — if it returned a **blocker** — append it to a
`blockers` queue. Then end the turn. Do **not** dispatch, drain, or surface anything.

**Terminal exception (the loop is over, so silence no longer applies).** Once **every
issue has already been presented and decided**, there is no pending user decision to
talk over — the presentation loop is done. From that point a wake-up **may** drain
`pending` and dispatch queued fixers (still without narrating), so the queue finishes
even though no further user turn will come. When the **last** in-flight fixer reports
and `pending` is empty, that wake-up proceeds straight into Phase 3.5 / Phase 4 (which
do speak — that is the end of the run, not mid-loop chatter).

**On each user-reply turn, before presenting the next issue**, run these steps in
order (all silent — no narration of any of them):
1. **Drain `pending`** — for every queued item whose files are now all free (thanks to
   completions recorded on intervening wake-ups), spawn its `review-fixer` and move its
   files into `claimedFiles`. These are items the user approved earlier.
2. **Dispatch the just-decided item** (if Approve/Modify): compute its target file set
   (files its comments point at, plus any obvious sibling it will touch). **No overlap**
   with `claimedFiles` → spawn a `review-fixer` with the Agent tool,
   **`run_in_background: true`**, add its files to `claimedFiles`. **Overlaps** an
   in-flight fixer's files → push to `pending` (never let two agents edit the same
   file).
3. **Surface blockers — after the current issue is fully resolved, never mid-issue.**
   If `blockers` is non-empty, finish acting on the current issue first, then present
   the queued blockers as plain text one at a time, staying on each until resolved
   (re-approve with new guidance and re-dispatch, or skip). Then resume presenting the
   remaining issues where you left off.
4. **Present the next issue.**

A successful (non-blocker) completion is never surfaced — it only frees files behind
the scenes, consumed silently by the next user-reply turn's drain.

Each `review-fixer` gets: the item/group (with any modified instructions), the
target files, the resolution it must reach, and a reminder that it runs **in
parallel** with other fixers (touch only its files, only filtered tests, no global
formatters, **never commit**).

The loop ends when **every item has been presented** AND every spawned fixer has
reported back AND `pending` is empty.

---

## Phase 3.5: Out-of-Scope Urgent Round (separate, after the main flow)

Only after the Phase 2/3 loop has **fully drained** (every in-scope item presented,
every fixer reported, `pending` empty), handle `outOfScopeUrgent` — the BLOCKING
items the PR did not create or modify. If the list is empty, skip this phase.

Present these as a **clearly separated round** so the user knows they are extra,
pre-existing problems this PR is not responsible for. Lead the round with a one-line
banner, e.g.:

> The items below are **out of scope** for this PR — the code was not created or
> modified here — but flagged as urgent (BLOCKING). Decide each separately.

Then walk `outOfScopeUrgent` one item at a time with the **same mechanics as Phase 2
and Phase 3**: present your recommendation, wait for an explicit **Approve / Modify /
Skip** user reply (a wake-up is never a reply), and on approval dispatch a
`review-fixer` through the **same file-claim mutex** (`claimedFiles` / `pending`).
For these, your default recommendation should usually be **Skip** (open a separate
ticket/PR) unless the user wants it fixed here — but the call is theirs.

Skips here are recorded for Phase 5 with the rationale "out of scope (urgent) —
deferred / handle separately" (or the user's reason). The phase ends under the same
condition as Phase 3: every held item presented, every fixer reported, `pending`
empty.

---

## Phase 4: Review & Verify (final sweep, once the loop drains)

1. Read the aggregate diff and confirm each approved item was addressed as specified:
   ```bash
   git diff
   ```
   (or the Conductor `GetWorkspaceDiff` tool). Compare against what each fixer
   reported.
2. Run the affected suites **once, centrally** — this is the one place safe from
   parallel clobber:
   ```bash
   php artisan test --compact --filter={affected}   # backend, if PHP changed
   npx vitest run {affected}                         # frontend, if JS/TS changed
   vendor/bin/pint --dirty --format agent            # style fix
   ```
3. Anything not green or not addressed → re-dispatch a fixer for it, or surface it
   to the user. Do not proceed to resolution with a red suite.

---

## Phase 5: Comment + Resolve on the PR (resolve EVERYTHING considered)

For **every** item you considered — approved-and-fixed, skipped, dismissed-as-FP, or
out-of-scope (both the silently-dropped non-urgent ones and any urgent ones the user
skipped in Phase 3.5) — leave a reply and resolve it. Resolving is what guarantees a
future `/process-review` won't reconsider it. Out-of-scope items still get a reply +
resolve even though they were never presented inline — otherwise the next run
re-triages them every time.

- **Approved & fixed** → reply with a one-line summary of the change.
- **Skipped / dismissed-FP** → reply with the rationale.
- **Out-of-scope** → reply with the out-of-scope rationale (the PR did not create or
  modify this code), and for urgent ones note they were surfaced separately and how
  the user chose to handle them.

Reply + resolve mechanics by source:

- **gh-thread** — reply, then resolve:
  ```bash
  gh api repos/{owner}/{repo}/pulls/{number}/comments -F in_reply_to={commentId} -f body='…'
  gh api graphql -F id={threadId} -f query='
  mutation($id:ID!){ resolveReviewThread(input:{threadId:$id}){ thread{ isResolved } } }'
  ```
- **gh-review-body** (body finding) — no thread to reply to and no resolve mutation,
  so post a general PR comment whose footer carries the **stable ref token** keyed on
  the finding's `{file}:{line}` (this is what Phase 0 matches on to skip it next run):
  ```bash
  gh api repos/{owner}/{repo}/issues/{number}/comments -f body='<result>

  _via /process-review · ref: review-body {file}:{line}_'
  ```
  Use `{file}:0` when the body finding has no line. One ref per body finding (batch
  several into one comment only if each gets its own `ref:` line).
- **gh-comment** (general) — reply only (cannot be resolved):
  ```bash
  gh api repos/{owner}/{repo}/issues/{number}/comments -f body='…'
  ```
- **conductor** — reply on the same file/line with the `DiffComment` tool (there is
  no programmatic resolve for Conductor comments).

Every reply ends with the footer marker on its own line so re-runs detect handled
general comments and body findings:
```
_via /process-review_
```

---

## Phase 6: Batched Reinforcement Offer + Prompt to Commit/Push

**Offer captured reinforcements (once, batched).** If Phase 1 captured any
endorsed-pattern reinforcements for auto-dismissed FPs, present them now as a single
batch — list each suggested registry/config edit with the exact line you'd add and the
re-flag it stops. Ask the user once which to apply (all / some / none). Apply approved
ones **directly in your own context** (docs/config edits, never a `review-fixer`).
Track which you applied for the summary. If none were captured, skip this step.

Summarize the run:
```
✅ Processed review feedback on PR #{number}
- Addressed: {count}
- Skipped: {count}
- Dismissed (false positive): {count}
- Reinforcements applied (registry/config edits to stop re-flags): {list}
- Out of scope — skipped (PR didn't touch this code): {count}
- Out of scope — urgent, surfaced separately: {count} ({addressed}/{skipped})
- Files changed: {list}
- Tests: {pass/fail summary} · Pint: {clean/fixed}
```

**Do not auto-commit.** Prompt the user to commit + push. On approval, commit with
the required trailer and push:
```
Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
```

## Orchestration Notes

- **Do not fix code in your own context** — dispatch `review-fixer` subagents and
  trust their isolated work.
- The file-claim mutex is the only thing preventing parallel write conflicts —
  never spawn a second fixer for a file already claimed.
- Resolve **everything you considered**, including skips and dismissals — a thread
  left open is a thread the next run will re-triage.

$ARGUMENTS
