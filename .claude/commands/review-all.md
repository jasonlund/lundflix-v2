---
name: review-all
description: Two-engine PR review — runs /review-pr (multi-agent) plus the CodeRabbit CLI in parallel, then posts each engine's findings to the GitHub PR as its own source-attributed review via /add-to-pr.
---

# Two-Engine PR Review

You orchestrate **two independent reviewers** over one PR and land each one's
findings on GitHub as a **separate, source-attributed review**:

1. **`/review-pr`** — the in-house adversarial multi-agent review (runs top-level
   here, because it spawns its own subagent fleet and can't be nested).
2. **CodeRabbit CLI** — dispatched to the `coderabbit-reviewer` subagent.

The CLI subagent does the dump-heavy normalization in **isolated context** and
returns a canonical report file. **You (the orchestrator) do all the posting** —
the subagent never touches GitHub. Both always run; a failing engine is skipped,
never fatal.

## Input
- **PR number** — positional arg, or auto-detected from the current branch.
- **Ticket ID** — `FLIX-XXX`, optional; passed straight through to `/review-pr`.

## Example Invocation
```
/review-all                 # auto-detect PR + ticket
/review-all 142             # explicit PR
/review-all 142 FLIX-154    # explicit PR + ticket
```

---

## Phase 0: Resolve PR + Run Dir

1. **PR number** — if not passed, follow **PR Number Auto-Extraction** in
   `.claude/skills/review-pipeline/SKILL.md`. If no PR is found, HALT and tell the
   user to push the branch and open a PR (or pass the number). CodeRabbit can
   review locally, but `/add-to-pr` needs the PR — so a PR is required.
2. **Base** = `main`.
3. **Run dir** — compute a unique scratch dir and create it:
   ```bash
   RUN_DIR=".context/review-all/pr${PR}-$(date +%s)"; mkdir -p "$RUN_DIR"; echo "$RUN_DIR"
   ```
   Use the absolute path when handing it to subagents.

---

## Phase 1: Dispatch the CodeRabbit CLI subagent (background)

Spawn it with `run_in_background: true` so it churns while `/review-pr` runs:

- `subagent_type: coderabbit-reviewer` — prompt with `PR_NUMBER`, `RUN_DIR`
  (absolute), `BASE=main`.

It returns a `=== … REPORT ===` block with `STATUS: OK|FAILED`, `REPORT_FILE`,
and `COUNTS`. Do not block on it yet.

---

## Phase 2: Run /review-pr inline (top-level)

Invoke the **`review-pr`** skill, passing through the same PR number and ticket
id. Let it run its full pipeline and emit its markdown report.

Then **persist that report** so it can be posted uniformly:
1. Take the report `/review-pr` just produced (the `# PR Review: PR #<n> …`
   markdown).
2. Insert a `Source: /review-pr` line immediately under the `# PR Review:` header.
3. Write it to `"$RUN_DIR/reviewpr.report.md"`.

---

## Phase 3: Join the CLI subagent

Collect the CodeRabbit subagent's final report block (it has completed by now):
- `STATUS: OK` → record its `REPORT_FILE` and counts.
- `STATUS: FAILED` → record the reason; it will be skipped in Phase 4.

---

## Phase 4: Post both (you do this — one /add-to-pr per engine)

For each engine that produced a report file (`reviewpr.report.md`,
`coderabbit.report.md`), invoke the **`add-to-pr`** skill with the **report file
path as the argument**, once per file:

```
/add-to-pr <RUN_DIR>/reviewpr.report.md
/add-to-pr <RUN_DIR>/coderabbit.report.md
```

This produces **two separate `COMMENT` reviews** on the PR, each attributed via
its `Source:` header (`via /review-pr` / `via CodeRabbit`). Skip any engine whose
subagent returned `FAILED`. Post sequentially (not in parallel) so the two reviews
land cleanly.

---

## Phase 5: Summary

```
✅ /review-all on PR #{number}

| Engine     | Status | Blocking | Should Fix | Consider | Review |
|------------|--------|----------|------------|----------|--------|
| review-pr  | ✅     | …        | …          | …        | <url>  |
| CodeRabbit | ✅     | …        | …          | …        | <url>  |

Reports: {RUN_DIR}/
```

List any failed engine with its one-line reason. Do not commit or push — this
command only reviews and posts.

$ARGUMENTS
