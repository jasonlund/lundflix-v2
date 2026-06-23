---
name: add-to-pr
description: Post non-dismissed /review-pr findings to a GitHub PR as a single review — inline comments where the file/line is in the diff, body comments otherwise.
---

# Add Review to PR

Post the findings from a `/review-pr` report to a GitHub PR as a review with
inline comments.

## Input Source

The review report comes from one of two places:

1. **File path in `$ARGUMENTS`** — if it contains a readable file path, Read that
   file as the report.
2. **Previous message in the conversation** (default) — the most recent
   `/review-pr` output.

Either way the report uses the standard format (Blocking Issues, Should Fix,
Consider, Dismissed sections). If no review output is found in either place, stop
and tell the user.

## Phase 1: Extract Review Data

1. **PR number** — from the report header (`PR Review: PR #NNN …`). If absent,
   fall back to `gh pr view --json number --jq .number` for the current branch.
2. **Source** — an optional `Source:` line just under the header names the review
   engine (e.g. `Source: CodeRabbit`, `Source: /review-pr`).
   Capture it as `{source}`; if absent, default to `` `/review-pr` ``. Use it in
   the body header and per-finding footers below so the posted review is
   attributed to the engine that produced it.
3. **Repo** — `gh repo view --json owner,name --jq '{owner: .owner.login, repo: .name}'`.
4. **Parse findings** from these sections:
   - **Blocking Issues** → severity `critical`
   - **Should Fix** → severity `major`
   - **Consider** → severity `minor`
   - **Skip "Dismissed Findings"** and **"Coverage Notes"** entirely — do not post.
5. For each finding extract: file path, line number(s) (for a range `N-M`, use the
   end line `M` for the API), issue, violation reference, recommendation, found-by.

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
Open with a header, then one block per body-only finding:
```
## 🤖 Automated Review via {source}

**Inline comments:** {count}
**Additional findings below:** {count}

### 🔴 Blocking · `category`
**File:** `path/to/file.php` (lines N-M)
**Issue:** …
**Violates:** …
**Recommendation:** …
_Found by: agent-name_

---
```
If every finding posted inline, set the body to a one-line note that all N
findings are inline above.

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
