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

### CRITICAL — only an explicit user reply advances an issue

An issue is acted on (approved/modified/skipped, dispatched, and the next issue
presented) **only** when the **user themselves** sends a message saying so. Nothing
else counts.

A **background fixer completion notification is NOT a user reply.** When you are
re-invoked by a fixer finishing (or any system/tool notification) while you are
waiting on the user's Approve/Modify/Skip for the current issue, you must:
- **Never** interpret it as approval or any decision on the current (or any) issue.
- **Never** dispatch a fixer, mark an issue approved, or move to the next issue
  because of it.
- Do the silent bookkeeping from Phase 3 and then **END YOUR TURN WITH NO
  USER-FACING TEXT AT ALL** — empty output. Keep waiting for the user's actual reply.

### Each issue is presented exactly ONCE — never re-prompt

Send a message about an issue **one time**: its single presentation. After that you
are silent until the **user** replies. When a background wake-up arrives while you
wait, do **not** send a second message about that issue in any form — no restating
it, no "still waiting on your call for Item N", no re-asking Approve/Modify/Skip, no
status, no acknowledgement. Even though such a line correctly avoids approving, it is
still forbidden: it produces a duplicate message and reads as the orchestrator talking
over itself. The screenshots show both bugs to eliminate:
- "Issue N approved. Dispatching now." (false approval from a wake-up), and
- "Still waiting on your call for Item N… Approve / Modify / Skip?" (re-prompting the
  same pending issue after a wake-up).

If you cannot point to a literal user message containing Approve / Modify / Skip for
the current issue, you have **no** decision and **nothing to say** — do the silent
bookkeeping and end the turn with empty output.

**Keep the flow clean — present only the next issue.** While walking the list, every
message you send the user contains **nothing but the next issue** (or a blocker that
needs their feedback, per Phase 3). Track all fixer state **internally and silently**.

Until **every** issue has been processed, you must **never** emit any line about an
approved issue's fixer state — not its completion, not its progress, not which files
it released, not "no blocker", not a running tally. Concretely, lines like these are
**forbidden** during the loop:
- "Item 1 fixer done (config/services.php released). No blocker."
- "Dispatching the second fixer…", "still waiting on item 3", "2 of 5 fixed"

The example above is the exact bug to avoid: while the user is on Issue 2, do not say
anything about Issue 1's fixer. The **only** reason to send the user anything other
than the next issue is a fixer **blocker** that needs their feedback — and even then,
only after the current issue is fully resolved (Phase 3). Successful completions are
surfaced **once, all together, in the Phase 6 summary** — never mid-loop.

- **Approve** → dispatch immediately (Phase 3), then move to the next item.
- **Modify** → user adjusts scope/instructions; record as approved-with-edited-
  instructions, then dispatch (Phase 3).
- **Skip** → record the reason for Phase 5.

Do **not** wait for any fixer to finish before presenting the next item.

---

## Phase 3: Fire-on-Approval Dispatch (file-claim mutex)

Maintain two structures across the Phase 2 loop:
- `claimedFiles` — files currently held by an in-flight fixer.
- `pending` — approved items blocked on a busy file.

On each **approve/modify**, determine the item's target file set (the files its
comments point at, plus any obvious sibling it will touch):

- **No overlap** with `claimedFiles` → spawn a `review-fixer` for it with the Agent
  tool, **`run_in_background: true`**, add its files to `claimedFiles`, and continue
  presenting.
- **Overlaps** an in-flight fixer's files → push to `pending` (never let two agents
  edit the same file), and continue presenting.

When a **fixer completion notification** arrives, handle it **silently** — it is a
system event, **not** a user reply, so it never approves, advances, or speaks for any
issue (see the CRITICAL rule in Phase 2). Do not report it and do not break the
issue-by-issue flow:
1. Release that fixer's files from `claimedFiles` and record its report.
2. **Drain `pending`** — spawn any queued item whose files are now all free.
   (Draining `pending` is the dispatch of items the user **already** approved earlier;
   it is not, and never triggers, a decision on the issue currently being presented.)
3. **Exception — the one allowed interruption, and never mid-issue:** if the fixer
   returned a **blocker** (couldn't complete as specified), do **not** cut into the
   issue currently in front of the user. **Finish the current issue first** — wait
   for the user's Approve/Modify/Skip and act on it. Only then present the blocker as
   plain text, and stay on it until fully resolved (re-approve with new guidance and
   re-dispatch, or skip — looping until done). Then **resume** presenting the
   remaining issues exactly where you left off. If several blockers arrive, queue
   them and present them one at a time the same way.

A successful (non-blocker) completion is never surfaced — it only updates
`claimedFiles`/`pending` behind the scenes.

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
