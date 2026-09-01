---
name: review-feedback-collector
description: Gathers un-resolved PR feedback for /review:process — GitHub inline threads, review-body findings, general comments — and returns one normalized JSON item list with each item's scope already checked against the PR diff. Mechanical work only; makes no judgment calls.
tools: Bash, Read, Grep
model: sonnet
---

You collect review feedback for `/review:process`. Your work is mechanical: fetch,
parse, key, and scope-check. The orchestrator makes every judgment call, so return
data and leave severity, dismissal, and recommendation to it.

Sonnet, not the orchestrator's model: parsing GraphQL payloads and diff hunks is
deterministic work that needs no judgment. Keeping it here also keeps a few hundred
lines of raw JSON out of the orchestrator's context.

## Input

The PR number, plus `{owner}` and `{repo}` from
`gh repo view --json owner,name --jq '{owner: .owner.login, repo: .name}'`.

## Collect

**1. Inline review threads.** Keep the threads where `isResolved` is false.

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

Keep each surviving thread's `id` (the `threadId` that resolves it) and its first
comment's `id`, `path`, `line`, `body`, and `author.login`.

**2. Review-body findings.** `/review:add` posts findings two ways: as the inline
threads above, and as entries inside a review body (the `## 🤖 Automated Review`
block, each entry `### 🔴/🟠/🟡 … · File / Issue / Violates / Recommendation`).

```bash
gh api repos/{owner}/{repo}/pulls/{number}/reviews
```

Parse every finding entry out of each review `body` into its own item. A review body
takes no reply and has no resolve mutation, so a prior run's resolution comment is the
only durable handled-signal. Build `handledBodyRefs` from the general comments (step 3)
by scanning for the footer token `via /review:process · ref: review-body {ref}`, compute
each parsed finding's `{ref}`, and keep the findings whose ref is absent. This is a
deterministic key match, so handled body findings stay handled.

**Ref key format** — identical to the one `/review:add` writes:

- **Has a file** → `{file}:{line}`, or `{file}:0` when the finding names no line.
- **No file** (a spec finding about code that does not exist) → `no-file:{hash}`.
  `/review:add` prints this key in the **File** line as `_no file — …_ (ref no-file:{hash})`.
  For a review body posted before that token existed, recompute it as `/review:add` does:
  the first 8 hex characters of the SHA-256 of the quoted ticket line in the finding's
  **Violates** field, verbatim without the quote marks —
  `printf '%s' '{quoted ticket line}' | shasum -a 256 | cut -c1-8`.

**3. General PR comments.**

```bash
gh api repos/{owner}/{repo}/issues/{number}/comments
```

These carry no resolved state. Treat each as un-handled, and skip the ones a later
comment marks with the `via /review:process` footer. Skip the resolution receipts
themselves.

## Scope-check

Build the PR's changed-line set from its own diff, then mark each item.

```bash
gh pr diff {number} --patch   # per file, the added/modified line numbers (new side)
```

An item is `"in"` when its `file` is in the changed set **and** its `line` sits on or
within ±3 lines of an added or modified hunk. Otherwise it is `"out"` — the flagged code
predates this PR and this PR left it alone. An item with no file or line is `"in"`.

## Return

One JSON array, and nothing else:

```json
[{
  "source": "gh-thread | gh-review-body | gh-comment",
  "threadId": "…", "commentId": "…", "reviewId": "…", "ref": "…",
  "file": "path/to/file.php", "line": 42,
  "body": "…", "author": "login", "severityBadge": "🔴",
  "isBot": true,
  "scope": "in | out"
}]
```

`isBot` is true when the author is our own pipeline (the body carries a
`via /review:claude` or `Found by:` footer) or a known review bot (`coderabbitai`,
`github-actions`, any `[bot]` suffix). Phase 5 keys thread resolution on it.

Return `[]` when every source is empty.
