---
name: review:process
description: Third stage after /review:claude → /review:add. Reads un-resolved PR feedback (GitHub inline threads + general comments + Conductor diff-comments), triages it, shows a numbered severity-grouped overview with a per-item approve/consider/skip recommendation that stands by default (the user replies only with overrides), presents the considered items in full one at a time, dispatches a foreground fixer per approval (parallel file-disjoint waves, test-first via tdd-feedback, no commit), then resolves every considered thread and prompts to commit/push.
---

# Process Review Feedback

You are orchestrating the final stage of the review loop:
`/review:create-pr` → `/review:human` (human summary + ticket-scope check) → `/review:claude`
(generate findings) → `/review:add` (post them to the PR) →
**`/review:process`** (act on them). You read back the feedback that is still
open on the PR, decide with the user what to act on, dispatch isolated fixer
subagents to do the work, then **resolve everything you considered** so a future
run never reconsiders it — and finally prompt to commit/push.

You never edit code in your own context. You triage, present, dispatch, verify,
and resolve. The fixing happens in `review-fixer` subagents.

## Input
- **PR number** — positional arg, or auto-detected from the current branch.

## Example Invocation
```
/review:process        # auto-detect PR from the current branch
/review:process 142    # explicit PR
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
4. **GitHub PR review bodies** — `/review:add` posts findings two ways: inline
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
   `via /review:process · ref: review-body {file}:{line}` (Phase 5). Compute the same
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
   in the thread carries the `via /review:process` footer marker (Phase 5) — that
   marks it already handled; skip it. (Comments that are themselves `/review:process`
   resolution receipts are skipped, not re-triaged.)
6. **Conductor diff-comments** — read from the **current conversation's attachments**
   (the Conductor MCP cannot fetch them). If none are attached, note that and move on.
7. **Linear ticket context (body + comments).** Resolve every ticket id in the branch
   name and fetch each with `mcp__linear-server__get_issue` **and**
   `mcp__linear-server__list_comments`. Read the **body and the full comment thread** —
   comments frequently record **deviations from the original plan** (a decision reversed,
   a scope cut, an approach changed mid-flight, a "we decided not to do X"). Extract these
   documented decisions/deviations into a short list you carry into triage. This is
   **authoritative project intent**: it **overrides** reviewer findings, convention
   defaults, and your own priors when you form recommendations (Phase 2) — see Phase 1
   step 1.5. If a ticket can't be fetched, note it and continue.
8. Normalize every kept item to:
   `{ source: gh-thread | gh-review-body | gh-comment | conductor, threadId?, commentId?, reviewId?, file?, line?, body, author, severityBadge? }`.
   If there are zero un-resolved items across all sources, say so and stop.

---

## Phase 1: Triage (classify origin → classify scope → dismiss false positives → group → sort)

1. **Classify origin.** Items posted by `/review:add` carry its footer
   (`via /review:claude` / `Found by:`). Those already passed false-positive-hunter +
   adversarial verification inside `/review:claude` — **trust them; do not re-run FP
   scrutiny.** Only *external* feedback (human reviewers, general comments, Conductor
   diff-comments) gets scrutiny: judge it inline against the **Convention Override
   Rule** and "Commonly false-positived conventions" in
   `.claude/skills/review-pipeline/SKILL.md`. If the external feedback is high-volume
   or low-confidence, you **may** spawn `false-positive-hunter` over just those items.
1.5. **Check every item against the Linear ticket body + comments (Phase 0 step 7).**
   Documented decisions and **deviations from the original plan** are authoritative
   project intent and **override anything else** — reviewer findings, convention
   defaults, and your own priors. For each item, ask: does the ticket (body or a comment)
   already decide this? If the ticket **endorses** what the reviewer flagged (the change
   was a deliberate, documented deviation), lean **Skip** and cite the ticket comment in
   the rationale — do not let a reviewer re-litigate a settled call. If the ticket
   **contradicts** the flagged code (the code drifted from a documented decision), lean
   **Approve** even for a low-severity flag. Carry this into your Phase 2 recommendation
   and name the ticket source when it drove the call.
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
4. **Sort** BLOCKING → SHOULD_FIX → CONSIDER → NIT. Use the `/review:add` badge
   (🔴/🟠/🟡) when present; otherwise assign severity per the contract taxonomy.
5. **Auto-dismiss anything explicitly labeled dismissable.** Any item the
   orchestrator dismisses as a false positive — or that arrives **already carrying a
   dismissed/dismissable label** (e.g. an `/review:add` or CodeRabbit finding under a
   ⚫ *Dismissed as false positive* badge) — is **dropped from the Phase 2
   presentation flow**, exactly like out-of-scope non-urgent items. Do **not** present
   it for confirmation: there is no value in asking the user to confirm a dismissal
   that is already settled. Record it as a dismissal with its rationale (resolved in
   Phase 5). If the dismissal is an endorsed-pattern FP with a worthwhile
   reinforcement, capture the suggested reinforcement for the Phase 6 batched offer —
   do **not** gate the loop on it.

Only **in-scope, non-dismissed** items (plus no-file/line items) flow into Phase 2's
overview list. Out-of-scope and auto-dismissed items are handled by their paths
above — never presented inline.

---

## Phase 2: Overview Gate — list everything, collect numbers

Before presenting anything in full, show the user the **whole** in-scope,
non-dismissed list at a glance so they can clear the easy calls in one reply.

1. **Number each item (group) globally `1..N`** and print one bulleted list, **grouped
   first by your recommendation** (Approve → Consider → Skip), and **within each group by
   severity** (Blocking → Should Fix → Consider → Nit). Print the recommendation as a
   header for its group; one line per item under it:

   ```
   APPROVE

   N. [SEVERITY] (agent, agent) — 1–2 sentence description. path/to/file.ext:15-20
   ```

   The three group headers are your own recommendation — `APPROVE` (clear fix, just do
   it), `CONSIDER` (needs discussion / a judgment call / possible false positive worth a
   look), `SKIP` (you'd drop it — e.g. endorsed-convention FP, not worth the churn) — so
   the user can accept your calls at a glance. Omit a header whose group is empty.

   Per line, in order: the global number, the `[SEVERITY]` tag, **which agents flagged
   it** in parentheses (the finding's `Found by:` list — e.g. `(claude, coderabbit)`; use
   the reviewer/human name for external feedback, or omit the parens when unknown), the
   1–2 sentence description, then the location **last**. Write the location as a **bare
   repo-relative `path:line` (or `path:start-end`) with no backticks, quotes, or
   `@`** — the terminal only linkifies it clickable when it's bare. Include it **where
   the item has a line**; omit it for general comments / broad notes not tied to a line.
   Keep each to 1–2 sentences — this is a skim list, not the full write-up. Out-of-scope
   and auto-dismissed items are **not** listed (Phase 1 already routed them). The
   recommendation-grouped headers already convey your disposition at a glance — do **not**
   append a summary line of numbers-by-bucket; it reads like an override example and
   muddies your actual recommendation.

2. **Prompt once, as plain text** (no `AskUserQuestion` / menus): your recommendations
   **stand as-is by default** — tell the user to reply **only with overrides** for items
   they want to move, as `<approve|consider|skip> <numbers>` lines. Anything they don't
   mention keeps its recommended bucket; an empty reply (or "looks good" / "go") accepts
   every recommendation unchanged. Then stop and wait for their reply.

3. **Resolve the final buckets = recommendations + the user's overrides.** Start from
   your recommended buckets, then apply each override line, moving exactly the named
   numbers to the named bucket (a later override for the same number wins). Numbers the
   user never names stay where you recommended. Example:

   ```
   recommended:  approve 1 2 4 · skip 3 5 · consider 6
   user:         skip 2 · consider 4
   final:        approve 1 · skip 2 3 5 · consider 6 4
   ```

   A bare number list with no verb (`1 4`) means **approve those**. The three resolved
   buckets are **Approve** (immediate), **Consider** (→ Phase 2b), **Skip**.

4. **Act on the resolved buckets:**
   - **Approve** → dispatch as **parallel foreground fixers** now (Phase 3). Because the
     `Agent` calls are foreground, their results return **within this turn** — the whole
     Approve batch is finished before you continue. No full presentation.
   - **Consider** → Phase 2b, one item at a time.
   - **Skip** → record the reason for Phase 5; nothing else.

   Foreground dispatch is the point: **nothing runs in the background**, so the harness
   never re-invokes you on a fixer completion and there is no silent-wake-up flow to get
   wrong. If the Consider bucket is empty, go straight to Phase 3.5.

---

## Phase 2b: Full Presentation — the *consider* items only

Reached after every Approve-bucket fixer has already returned (they were foreground, so
nothing from that batch is still running). Walk the **consider** bucket in severity
order, **one item (group) at a time**.

**Renumber locally for this phase.** The Phase 2 numbers were global across all buckets;
do **not** carry them here (they produce nonsense like "Item 10 of 8"). Count the
consider bucket as `Y` and present each as **`x of Y`** in consider order (`1 of Y`,
`2 of Y`, …). Lead each item's message with that `x of Y` header.

Present each item as **plain text in your message and then stop and wait for the user's
reply** — do **not** use `AskUserQuestion`, menus, or any dialog tool. For each, give
the user **your own recommendation on the fix**, not just the reviewer's words:

- What the comment asks for (quote the relevant line). Cite the location as a bare
  repo-relative `path:line` (no backticks/quotes) so it's clickable — here and anywhere
  you name a file/line to the user.
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

### On the user's reply

- **Approve / Modify** → dispatch this item's fixer as a **foreground `Agent` call**
  (Phase 3) with any modified instructions. It returns **inside this turn**; if it
  returns a **blocker**, resolve it inline (re-dispatch with new guidance, or skip)
  before moving on. Then present the next item.
- **Skip** → record the reason for Phase 5, then present the next item.

**Present each item exactly once.** One presentation, then wait for the user. There are
no background wake-ups here, so there is never a reason to re-send, restate, or "still
waiting" an item. Your messages carry **only** the next item (or a fixer blocker you're
resolving) — never fixer status, progress, or tallies; the Phase 6 summary reports all
of that once, at the end.

---

## Phase 3: Dispatch — parallel, foreground (no background, no wake-ups)

Every approved fixer runs as a **foreground `Agent` call** — never
`run_in_background`. This is enforced by a `PreToolUse` hook
(`~/.claude/hooks/no-bg-review-fixer.js`), which denies any backgrounded
`review-fixer`; the rule is not optional, so don't try to background fixers to
"let them overlap." Foreground means each result returns inside the dispatching
turn, so the harness never wakes you on a completion and there are no wake-up
turns that would emit status chatter.

**Approve bucket (Phase 2 overview) — dispatch in file-disjoint waves.** Two fixers
must never edit the same file at once, so group the approved items into **waves** where
no two items in a wave share a target file (the files a comment points at, plus any
obvious sibling it will touch). Dispatch one wave as a **single message with parallel
`Agent` calls**; they run concurrently and all return before the turn continues. Then
dispatch the next wave. Usually there is only one wave. Collect each fixer's result;
hold any **blocker** and, once all waves have returned, resolve the held blockers inline
(re-dispatch with guidance, or skip) before starting Phase 2b.

**Consider items (Phase 2b) — one foreground call each**, dispatched on the user's
Approve/Modify and returning before you present the next item (see Phase 2b). No waves
needed — one at a time can't collide.

Each `review-fixer` gets: the item/group (with any modified instructions), its target
files, the resolution to reach, and the standard constraints — touch only its files,
only filtered tests, no global formatters, **never commit**. Fixers within a wave run
**in parallel**; each still touches only its own files.

Dispatch is done when every approved item's fixer has returned (foreground, so this is
automatic) and every blocker is resolved. Then: Phase 2b (if consider items remain),
else Phase 3.5.

---

## Phase 3.5: Out-of-Scope Urgent Round (separate, after the main flow)

Only after the main flow is complete (every in-scope item decided and every approved
fixer returned), handle `outOfScopeUrgent` — the BLOCKING items the PR did not create
or modify. If the list is empty, skip this phase.

Present these as a **clearly separated round** so the user knows they are extra,
pre-existing problems this PR is not responsible for. Lead the round with a one-line
banner, e.g.:

> The items below are **out of scope** for this PR — the code was not created or
> modified here — but flagged as urgent (BLOCKING). Decide each separately.

Then walk `outOfScopeUrgent` one item at a time with the **same mechanics as Phase 2b
and Phase 3**: present your recommendation, wait for an explicit **Approve / Modify /
Skip** user reply, and on approval dispatch a **foreground** `review-fixer` that returns
before the next item. For these, your default recommendation should usually be **Skip**
(open a separate ticket/PR) unless the user wants it fixed here — but the call is theirs.

Skips here are recorded for Phase 5 with the rationale "out of scope (urgent) —
deferred / handle separately" (or the user's reason). The phase ends once every held
item has been presented and its fixer has returned.

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
future `/review:process` won't reconsider it. Out-of-scope items still get a reply +
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

  _via /review:process · ref: review-body {file}:{line}_'
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
_via /review:process_
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

**Do not auto-commit.** Prompt the user to commit + push. On approval, commit with a
ticket-prefixed subject plus the co-author trailer the harness supplies for this
session — never hardcode one, it stamps a model version that rots — then push.

## Orchestration Notes

- **Do not fix code in your own context** — dispatch `review-fixer` subagents and
  trust their isolated work.
- **All fixers are foreground `Agent` calls — never `run_in_background`** (enforced by
  the `no-bg-review-fixer` `PreToolUse` hook). Background completions would re-invoke
  the orchestrator mid-flow and the harness would nudge it to emit status chatter;
  foreground calls return in-turn, so there are no wake-ups to police.
- **File-disjoint waves prevent parallel write conflicts** — never put two fixers that
  touch the same file in one parallel wave; dispatch the second in a later wave.
- Resolve **everything you considered**, including skips and dismissals — a thread
  left open is a thread the next run will re-triage.

$ARGUMENTS
