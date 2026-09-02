---
name: review:process
description: Third stage after /review:claude → /review:add. Collects un-resolved PR feedback (GitHub inline threads, review-body findings, general comments, Conductor diff-comments), triages it against the Linear ticket and the PR head, presents one numbered list where every item carries your recommendation and its reasoning, takes a reply of overrides and holds for every item marked DISCUSS, dispatches a foreground fixer per approval (parallel file-disjoint waves, test-first, no commit), then replies to and resolves everything it considered and prompts to commit/push.
---

# Process Review Feedback

The final stage of the review loop: `/review:create-pr` → `/review:human` →
`/review:claude` → `/review:add` → **`/review:process`**. You read back the feedback
still open on the PR, settle it with the user in **one gate**, dispatch isolated fixer
subagents, then reply to and resolve everything you considered so a future run never
re-triages it.

Fixing happens in `review-fixer` subagents. You triage, present, dispatch, verify, and
resolve.

## Input

- **PR number** — positional arg, or auto-detected from the current branch.
- **`--leave-human-open`** — reply to human-authored threads but leave them open for
  the reviewer to close. Threads our own pipeline and known bots authored still resolve.

## Example Invocation

```
/review:process                          # auto-detect PR from the current branch
/review:process 142                      # explicit PR
/review:process 142 --leave-human-open   # let humans close their own threads
```

---

## Phase 0: Collect

1. **PR number** — if not passed, follow **PR Number Auto-Extraction** in
   `.claude/skills/review-pipeline/SKILL.md`. With no PR found, HALT and tell the user
   to push the branch and open a PR, or to pass the number.
2. **Repo** — `gh repo view --json owner,name --jq '{owner: .owner.login, repo: .name}'`.
3. **Dispatch `review-feedback-collector`** with the PR number, owner, and repo. It
   returns one normalized JSON array: every un-resolved GitHub item, keyed, with each
   item's `scope` already checked against the PR diff and `isBot` set. The fetch, parse,
   ref-keying, and diff arithmetic all live there — its contract is
   `.claude/agents/review-feedback-collector.md`.
4. **Conductor diff-comments** — read these from the **current conversation's
   attachments**; the Conductor MCP cannot fetch them, so the collector cannot see them.
   Normalize each to the collector's item shape with `source: conductor`. Under
   LaborForest + Solo this source is empty and GitHub carries all the feedback.
5. **Linear ticket context (body + comments).** Resolve every ticket id in the branch
   name and fetch each with `mcp__linear-server__get_issue` **and**
   `mcp__linear-server__list_comments`. Read the body *and* the full comment thread:
   comments record **deviations from the original plan** — a decision reversed, a scope
   cut, an approach changed mid-flight. Extract those into a short list you carry into
   triage. This is authoritative project intent and it **overrides** reviewer findings,
   convention defaults, and your own priors. With a ticket unfetchable, note it and
   continue.

Zero items across every source → say so and stop.

---

## Phase 1: Triage

Work the collector's list into the Phase 2 gate list, plus the skips and dismissals that
go straight to Phase 5.

1. **Weigh origin.** Items carrying the `/review:add` footer (`via /review:claude`,
   `Found by:`) already passed false-positive-hunter and adversarial verification inside
   `/review:claude` — **trust them as they stand**. Scrutinize only *external* feedback
   (human reviewers, general comments, Conductor diff-comments) against the **Convention
   Override Rule** and "Commonly false-positived conventions" in
   `.claude/skills/review-pipeline/SKILL.md`. High-volume or low-confidence external
   feedback may go to a `false-positive-hunter` dispatch over just those items.
2. **Check each item against the Linear ticket** (Phase 0 step 5). Where the ticket
   **endorses** what a reviewer flagged — the change was a deliberate, documented
   deviation — recommend **Skip** and cite the ticket comment; a settled call stays
   settled. Where the ticket **contradicts** the code — the code drifted from a
   documented decision — recommend **Approve** even at low severity. Name the ticket
   whenever it drove the call.
3. **Check each item against the PR head.** `/review:add` posts a finding against the
   commit that was head at the time, and the work often lands before this command runs,
   so treat every finding as a claim about the past until you confirm it against the
   present. Read the flagged code at the PR's head commit —
   `gh api repos/{owner}/{repo}/contents/{path}?ref={headRefOid}` — because the PR's
   branch may not be the branch you are standing on. Where the head already resolves the
   finding, mark the item **already fixed** and name the commit that did it. Trust the
   file over the ticket: a ticket that claims a fix is a claim about the past too.
4. **Route by scope.** The collector marked each item `in` or `out`. An `out` item at
   SHOULD_FIX / CONSIDER / NIT is **recorded as a skip** with the rationale "out of scope
   — PR did not create or modify this code" and resolved in Phase 5. An `out` item at
   BLOCKING joins the Phase 2 list under its own header.
5. **Group** duplicates and relatives by `(file, line ±10, category)` per the contract's
   dedup rule. A group is presented and fixed as one unit.
6. **Sort** BLOCKING → SHOULD_FIX → CONSIDER → NIT, by the `/review:add` badge
   (🔴/🟠/🟡) where present, otherwise by the contract taxonomy.
7. **Settle the dismissals silently.** An item you judge a false positive — or one that
   arrives already labeled dismissed (a ⚫ *Dismissed as false positive* badge from
   `/review:add` or CodeRabbit) — is **recorded as a dismissal** with its rationale and
   resolved in Phase 5. A settled dismissal needs no confirmation.

   Where the dismissal is wrong about a pattern the project deliberately uses and will
   keep using (`RefreshDatabase` / `Http::preventStrayRequests()` global in
   `tests/Pest.php`; DDD model placement; service-constant base URLs), **capture a
   reinforcement** so the same false positive stops returning every run. Capture one or
   both, then carry on — Phase 6 offers them in one batch:
   - **Convention registry** — the exact one-line bullet you would add to "Commonly
     false-positived conventions" in `.claude/skills/review-pipeline/SKILL.md`, so our
     own reviewers stop raising it.
   - **External reviewer config** — for a CodeRabbit or other CLI-engine flag, the
     path-scoped rule for its config (e.g. `.coderabbit.yaml`).

Items marked `in`, every item with no file or line, the already-fixed items, and the
out-of-scope BLOCKING holds go to Phase 2. Skips and dismissals go straight to Phase 5.

---

## Phase 2: The Gate — one list, one reply, `DISCUSS` waits

Present every item once, each carrying your recommendation and the reasoning behind it.
One reply settles the list, except the items you marked `DISCUSS` — those hold the run
until the user rules on each.

**Number the items globally `1..N`. A number is assigned once and stays with its item for
the whole run**, including the Phase 6 summary.

Group by your recommendation — `APPROVE` (clear fix, just do it), `DISCUSS` (a judgment
call you want the user's eyes on), `SKIP` (you would drop it) — then `ALREADY FIXED` (the
head resolves it, so it needs a reply and no work) and the out-of-scope BLOCKING holds,
each last under its own header. Sort by severity inside each group. `DISCUSS` names a
recommendation, `CONSIDER` names a severity; keep the two apart. Print only the groups
that have items.

Fill this shape verbatim, one entry per item:

```
APPROVE

1. [BLOCKING] Add `_tmdb_id` to the upsert conflict key. (claude, coderabbit)
   app/Domains/Catalog/Actions/UpsertTmdbMovies.php:88-94
   Issue: `UpsertTmdbMovies` matches an existing row on `_imdb_id` alone. TMDB carries
          movies that hold no IMDb id, so every sync run inserts those rows again.
   Fix:   Add `_tmdb_id` to the conflict key in the `upsert()` call.
   Why:   The table has no unique index, so nothing else stops the duplicates.

DISCUSS

6. [CONSIDER] Route the crosswalk parse through `SourceId`. (coderabbit)
   app/Domains/Catalog/Actions/ImportImdbTitles.php:141
   Issue: The action validates an IMDb id with an inline regex. `SourceId` is the shared
          normalizer that every other crosswalk parse site calls.
   Fix:   Replace the inline guard with `SourceId::imdb($raw)`.
   Why:   The guard predates `SourceId` and this PR leaves the file alone. I lean skip.

SKIP

7. [NIT] Rename `$res` to `$response`. (coderabbit)
   app/Domains/Catalog/Services/TmdbApiService.php:52
   Issue: The variable holds a `Response`. Its siblings in the same class spell the
          name out in full.
   Why:   This PR leaves the line alone, so the rename is churn a reviewer must read.

ALREADY FIXED

8. [BLOCKING] Guard every step in `refresh.yaml` against the primary checkout. (claude, coderabbit)
   .laborforest/workflows/refresh.yaml:1
   Issue: `refresh` drops and reseeds the database it runs against. Run from the primary
          checkout it takes `lundflix`, whose catalog the committed dumps do not carry.
   Fixed: 39706da puts the primary-checkout `if:` on all three steps, and sweeps them
          in `LaborForestWorkflowTest`.

OUT OF SCOPE — BLOCKING (this PR did not touch this code)

9. [BLOCKING] Scope the tenant query on L40. (claude)
   app/Domains/Billing/Queries/TenantRows.php:40
   Issue: `TenantRows::all()` builds its query with no tenant clause. It returns every
          tenant's rows to whichever tenant asks.
   Fix:   Add `->where('tenant_id', $tenant->id)` to the builder.
   Why:   A fix here widens a PR that never touched this file. I lean skip — open a ticket.
```

**Line 1** carries the number, the `[SEVERITY]` tag, the fix as a command, and who flagged
it in parentheses (the finding's `Found by:` list, or the reviewer's name for external
feedback). **Line 2** is the location, written as a **bare repo-relative `path:line`** —
the terminal linkifies a bare path only, so leave backticks, quotes and `@` off it. An
item with no line stops after line 1.

The slots below line 2 carry the substance. Every item states its issue and its
disposition; `Fix` joins them wherever a change is on the table:

| Slot | Appears on | Holds |
| --- | --- | --- |
| `Issue:` | every item | Up to two sentences: what the code does, then what goes wrong. |
| `Fix:` | `APPROVE`, `DISCUSS`, out-of-scope holds | One sentence naming the concrete change. |
| `Why:` | `APPROVE`, `DISCUSS`, `SKIP`, out-of-scope holds | One sentence carrying the reason for your recommendation. |
| `Fixed:` | `ALREADY FIXED` | The commit that resolved it, and what that commit changed. |

A `DISCUSS` item and an out-of-scope hold close `Why` with the reasoning behind your lean
and then state it — **"I lean approve"** or **"I lean skip"**. The lean is your argument,
not the outcome: these are the items that wait for the user's word (below).

**Those sentence counts are the whole verbosity budget.** Six lines is a long item.

Write every slot in ASD-STE100 Simplified Technical English, using this codebase's own
terms from `CONTEXT.md`, and **lead `Issue` with the context**: name the class, command,
or file and say what it does, then say what goes wrong. The user decides on a dozen of
these cold, so a finding that assumes they already hold the code in their head costs them
a trip to the file.

- **Context first, then the defect.** "`refresh` drops and reseeds the database it runs
  against" earns the sentence that follows it.
- **One topic per sentence**, ≤25 words, active voice, present tense.
- **Recommendations are commands** — "Add a tenant scope to the query on L40."
- **One word, one meaning.** Repeat the term exactly; elegant variation costs a re-read.
- **Quoted code and quoted ticket lines stay verbatim** — they are evidence.

The full spec is *How Findings Are Written* in `.claude/skills/review-pipeline/SKILL.md`.

Then prompt once, as plain text: your `APPROVE` and `SKIP` recommendations **stand by
default**, so the user replies only with overrides, as `<approve|skip> <numbers>` lines.
**A `DISCUSS` item is the exception — name its numbers to settle it.** Close the prompt by
listing the numbers still owed, so what blocks the run is on screen:

```
Approve/skip stand as recommended — reply only with overrides.
Waiting on: 6, 9.
```

Stop and wait.

**Final buckets = your recommendations + the user's overrides.** Apply each override
line, moving exactly the numbers it names; a later override for the same number wins. An
unnamed `APPROVE`, `SKIP`, or `ALREADY FIXED` number keeps your recommendation. A bare
number list (`1 4`) approves those.

```
recommended:  approve 1 2 4 · skip 3 5 · discuss 6 (lean skip) · already fixed 7
user:         skip 2 · approve 6
final:        approve 1 4 6 · skip 2 3 5 · already fixed 7
```

Every item ends as **approve**, **skip**, or **already fixed**. **Approve** dispatches
(Phase 3); **skip** records its reason for Phase 5. **Already fixed** stands unless the
user approves the number — read that override as "the head does not resolve this", so
re-read the file before dispatching, and say what you find either way.

**A `DISCUSS` item ends only when the user names it.** Silence leaves it open, so a reply
that settles every other number still owes you these. Say which numbers remain and wait
again. Where the user asks about an item rather than ruling on it, answer in the item's
own slots — `Issue`, `Fix`, `Why` — so the answer reads like the entry it belongs to, and
add the evidence the question asks for. Then re-prompt for the numbers still owed.

The run holds here until every `DISCUSS` number is settled. That wait is the point of the
bucket: put an item in `DISCUSS` when you want the user's judgment, and put it in `SKIP`
with your reason when you are ready to decide it yourself.

---

## Phase 3: Dispatch — parallel, foreground

Every fixer is a foreground `Agent` call. A `PreToolUse` hook
(`~/.claude/hooks/no-bg-review-fixer.js`) denies a backgrounded `review-fixer`, so each
result returns inside the dispatching turn and the harness never wakes you mid-flow.

Group the dispatched items into **waves where no two items share a target file** — the
files a comment points at, plus the obvious siblings a fix will touch. Two fixers editing
one file at once corrupt each other's work. Dispatch a wave as a single message holding
parallel `Agent` calls; they run concurrently and all return before the turn continues.
Usually one wave covers everything.

Each `review-fixer` gets the item or group, its target files, the resolution to reach, and
the standing constraints: touch only its files, run only filtered tests, leave global
formatters alone, and leave committing to the orchestrator.

Hold each returned blocker until every wave is back, then settle the held blockers inline
— re-dispatch with new guidance, or skip. Phase 3 ends when every dispatched item's fixer
has returned and every blocker is settled.

---

## Phase 4: Verify centrally

1. Read the aggregate diff and confirm each dispatched item landed as specified, against
   what each fixer reported: `git diff` (or Conductor's `GetWorkspaceDiff`).
2. Run the affected suites **once, here** — the one place safe from parallel clobber:
   ```bash
   php artisan test --compact --filter={affected}   # backend, if PHP changed
   npx vitest run {affected}                        # frontend, if JS/TS changed
   vendor/bin/pint --dirty --format agent           # style fix
   ```
3. Re-dispatch a fixer for anything red or unaddressed, or surface it to the user. A red
   suite stops the run here.

---

## Phase 5: Reply + resolve everything considered

Every item you considered gets a reply — fixed, skipped, dismissed as a false positive, or
out of scope. Resolving is what stops a future run reconsidering it, so out-of-scope items
get their reply and resolve too, even though they were never presented.

- **Fixed** → a one-line summary of the change.
- **Already fixed** → the commit that resolved it and what that commit changed, so the
  reply reads as evidence rather than a claim.
- **Skipped / dismissed** → the rationale.
- **Out of scope** → the out-of-scope rationale, and for a BLOCKING hold, how the user
  chose to handle it.

Mechanics by source:

- **gh-thread** — reply, then resolve:
  ```bash
  gh api repos/{owner}/{repo}/pulls/{number}/comments -F in_reply_to={commentId} -f body='…'
  gh api graphql -F id={threadId} -f query='
  mutation($id:ID!){ resolveReviewThread(input:{threadId:$id}){ thread{ isResolved } } }'
  ```
  Every thread resolves by default. **`--leave-human-open` holds back the resolve
  mutation on threads where `isBot` is false** — those keep their reply and stay open for
  the reviewer to close, while threads our own pipeline or a known bot authored resolve
  either way.
- **gh-review-body** — a body finding takes no reply and has no resolve mutation, so post
  a general comment whose footer carries the finding's stable `{ref}` from Phase 0. That
  token is what the collector matches next run to skip it:
  ```bash
  gh api repos/{owner}/{repo}/issues/{number}/comments -f body='<result>

  _via /review:process · ref: review-body {ref}_'
  ```
  One `ref:` line per finding; batch several into one comment only when each keeps its own.
- **gh-comment** — reply only; a general comment has no resolve.
  ```bash
  gh api repos/{owner}/{repo}/issues/{number}/comments -f body='…'
  ```
- **conductor** — reply on the same file and line with the `DiffComment` tool. Conductor
  comments have no programmatic resolve.

Close every reply with the footer marker on its own line, so re-runs detect handled
general comments and body findings:

```
_via /review:process_
```

---

## Phase 6: Reinforcements, then commit

**Offer the captured reinforcements once, as one batch.** List each registry or config
edit from Phase 1 with the exact line you would add and the re-flag it stops, and ask
which to apply (all / some / none). Apply the approved ones **in your own context** —
they are docs and config edits, so a `review-fixer` is the wrong tool. Skip this step
when Phase 1 captured none.

Summarize the run:

```
✅ Processed review feedback on PR #{number}
- Addressed: {count}
- Already fixed before this run (replied, no work): {count}
- Discussed and settled with you: {count} ({addressed}/{skipped})
- Skipped: {count}
- Dismissed (false positive): {count}
- Reinforcements applied (registry/config edits to stop re-flags): {list}
- Out of scope — skipped (PR didn't touch this code): {count}
- Out of scope — BLOCKING, decided in the gate: {count} ({addressed}/{skipped})
- Human threads left open for the reviewer: {count}   # only with --leave-human-open
- Files changed: {list}
- Tests: {pass/fail summary} · Pint: {clean/fixed}
```

**Committing is the user's call.** Prompt them to commit and push. On approval, commit
with a ticket-prefixed subject plus the co-author trailer the harness supplies for this
session — a hardcoded trailer stamps a model version that rots — then push.

## Orchestration Notes

- **Dispatch every code change to a `review-fixer`** and trust its isolated work.
- **Fixers are foreground `Agent` calls**, enforced by the `no-bg-review-fixer` hook. A
  background completion would re-invoke you mid-flow and pull status chatter into the run;
  foreground calls return in-turn.
- **A wave is file-disjoint.** Two fixers sharing a file go in different waves.
- **Resolve everything you considered**, skips and dismissals included. An open thread is
  one the next run re-triages.

$ARGUMENTS
