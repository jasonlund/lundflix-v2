---
name: review:add
description: Post non-dismissed /review:claude findings to a GitHub PR as a single review — inline comments where the file/line is in the diff, body comments otherwise.
---

# Add Review to PR

Post the findings from a `/review:claude` report to a GitHub PR as a review with
inline comments.

## Input Source

The review report comes from one of two places:

1. **File path in `$ARGUMENTS`** — if it contains a readable file path, Read that
   file as the report.
2. **Previous message in the conversation** (default) — the most recent
   `/review:claude` output.

Either way the report uses the standard format (Spec, Blocking Issues, Should Fix,
Consider, Dismissed sections). A report from an engine that reviews standards only
(CodeRabbit) carries no Spec section. If no review output is found in either place,
stop and tell the user.

## Phase 1: Extract Review Data

1. **PR number** — from the report header (`PR Review: PR #NNN …`). If absent,
   fall back to `gh pr view --json number --jq .number` for the current branch.
2. **Source** — an optional `Source:` line just under the header names the review
   engine (e.g. `Source: CodeRabbit`, `Source: /review:claude`).
   Capture it as `{source}`; if absent, default to `` `/review:claude` ``. Use it in
   the body header and per-finding footers below so the posted review is
   attributed to the engine that produced it.
3. **Repo** — `gh repo view --json owner,name --jq '{owner: .owner.login, repo: .name}'`.
4. **Parse findings** from these sections. Every posted finding belongs to one of
   two **axes** — `spec` (does it do what the ticket asked) and `standards` — and
   keeps that axis through Phases 2–4:
   - **Spec — does it do what the ticket asked?** → axis `spec`. Each entry names
     its own severity: `🔴 BLOCKING` → `critical`, `🟠 SHOULD_FIX` → `major`,
     `🟡 CONSIDER` → `minor`. Its **Violates** field holds the quoted ticket line.
     An entry whose **File** reads `_no file_` (or is absent) is a finding about
     code that does not exist — carry it with no file/line, keep its quoted ticket
     line as the finding's identity (Phase 3, **Body-finding ref key**), and post it
     in the review body per Phase 2.3.
   - **Blocking Issues** → axis `standards`, severity `critical`
   - **Should Fix** → axis `standards`, severity `major`
   - **Consider** → axis `standards`, severity `minor`
   - Prose lines in place of entries ("Implements the ticket as specified.", "No
     blocking or should-fix defects found.") mean that section has zero findings.
   - **Skip "Dismissed Findings"**, **"Key Defects"** (it restates findings the
     severity sections already carry), and **"Coverage Notes"** entirely — do not
     post.
5. For each finding extract: axis, severity, file path, line number(s) (for a range
   `N-M`, use the end line `M` for the API), issue, violation reference,
   recommendation, found-by.

## Phase 2: Determine Commentable Lines

1. Fetch the diff: `gh pr diff {number}`.
2. Parse it into a map of commentable `(file, line)` pairs. GitHub only allows
   inline comments on lines in the diff hunks:
   - Track the file from `+++ b/` headers and line numbers from
     `@@ -old,count +new,count @@` headers.
   - `+` (added) and ` ` (context) lines are commentable on the RIGHT side.
   - `-` (removed) lines are not commentable with `line`.
3. For each finding with a file+line: in the diff → **inline comment**; not in the
   diff → **body comment**. Findings without file/line → **body comment**.

## Phase 3: Build the Review Payload

### Severity badges
| Severity | Badge |
|----------|-------|
| critical | 🔴 **Blocking** |
| major | 🟠 **Should Fix** |
| minor | 🟡 **Consider** |

Both axes use this one badge table, so `/review:process` sorts every posted
comment by severity.

### Axis marker

A `spec` finding fills the category slot with `spec`, so its comment reads
`🟠 **Should Fix** · spec` and the reader sees which axis flagged it. A
`standards` finding fills that slot with its own category (`convention`,
`testing`, …). The badge ranks a finding **within** its axis; the two axes stay
side by side and neither outranks the other.

### Inline comments
For each inline-eligible finding:
```json
{
  "path": "path/to/file.php",
  "line": 50,
  "side": "RIGHT",
  "body": "🔴 **Blocking** · `category`\n\n**Issue:** …\n\n**Violates:** …\n\n**Recommendation:** …\n\n---\n_Found by: agent-name · via {source}_"
}
```
For a range, add `"start_line": <start>` and `"start_side": "RIGHT"` (only when
the start differs from `line`).

### Review body
Open with a header, then the body-only findings — **spec first, under its own
heading**, then the standards ones. The two axes keep separate headings here, so
a reader sees a spec defect even when the standards list is long:
```
## 🤖 Automated Review via {source}

**Inline comments:** {count}
**Additional findings below:** {count}

## Spec — does it do what the ticket asked?

### 🟠 Should Fix · `spec`
**File:** _no file — the ticket line has no implementation_ (ref `no-file:{hash}`)
**Issue:** …
**Violates:** "{quoted ticket line}"
**Recommendation:** …
_Found by: requirements-reviewer_

---

## Standards

### 🔴 Blocking · `category`
**File:** `path/to/file.php` (lines N-M)
**Issue:** …
**Violates:** …
**Recommendation:** …
_Found by: agent-name_

---
```
Include a heading only when that axis has body findings. If every finding posted
inline, set the body to a one-line note that all N findings are inline above —
naming the spec count separately, e.g. "All 6 findings are inline above (1 spec,
5 standards)."

### Body-finding ref key

Every body finding carries one **ref key** that identifies it across runs.
`/review:process` records the key when it resolves the finding and matches on it to
skip that finding next run (its Phase 0 step 4), so two distinct findings must
produce two distinct keys and the same finding must produce the same key every run.
The format — stated identically in `.claude/commands/review/process.md`:

- **Has a file** → `{file}:{line}`, and `{file}:0` when the finding names no line.
- **No file** → `no-file:{hash}`, where `{hash}` is the first 8 hex characters of
  the SHA-256 of the quoted ticket line in that finding's **Violates** field, taken
  verbatim without the surrounding quote marks:
  ```bash
  printf '%s' '{quoted ticket line}' | shasum -a 256 | cut -c1-8
  ```

The ticket line is what makes a no-file finding unique and it is copied verbatim
from the ticket, so the hash is both finding-specific and stable run to run. Print
the key in that finding's **File** line, as the template above shows, so
`/review:process` reads it rather than recomputing it.

## Phase 4: Post the Review

Write the payload to a temp file (avoids shell-escaping issues), then:
```bash
gh api repos/{owner}/{repo}/pulls/{number}/reviews --method POST --input /tmp/review-payload.json
```
Payload:
```json
{ "event": "COMMENT", "body": "<body>", "comments": [<inline array>] }
```
- Use `event: "COMMENT"` only — never `APPROVE`/`REQUEST_CHANGES` (the user's
  call).
- Delete the temp file after.

## Phase 5: Report Results

```
✅ Review posted to PR #{number}
- {X} inline comments
- {Y} findings in review body
- {S} of the above on the spec axis
- {Z} dismissed findings (not posted)

View: {PR URL}
```

## Error Handling

- If the API rejects an inline position as invalid, move that comment to the
  review body and retry.
- If there are 0 postable findings, say "No non-dismissed findings to post" and
  stop.
- If the call fails entirely, show the error and the payload.

$ARGUMENTS
