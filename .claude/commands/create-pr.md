---
name: create-pr
description: First stage of the review loop — lint the dirty files, commit and push the branch, then open a GitHub PR whose title/body are grounded in the Linear ticket, the diff, and the commit history. Leaves a clean pushed branch with an open PR that /review-pr auto-detects.
---

# Create PR

You are running the **first** stage of the review loop:
**`/create-pr`** (lint → commit → push → open PR) → `/human-review` (human-facing
summary + ticket-scope check) → `/review-all` or `/review-pr` (generate findings)
→ `/add-to-pr` (post them) → `/process-review` (act on them).

Your job is to leave a clean, pushed branch with one open PR against `main`,
titled and described so a reviewer (human or `/review-pr`) can pick it up with no
extra context. You **stop** after opening the PR — you do not chain `/review-pr`.

## Input
- **Ticket ID** — `FLIX-XXX`, positional arg, or auto-extracted from the branch.
- Everything else is inferred from the working tree and branch.

## Example Invocation
```
/create-pr             # auto-detect ticket from branch, open PR against main
/create-pr FLIX-154    # explicit ticket
```

---

## Phase 0: Preconditions

1. **Not on `main`.** Run `git branch --show-current`. If it is `main` (or the
   branch has no commits ahead of `origin/main`), HALT and tell the user there is
   nothing to open a PR for.
2. **No PR already open.** Run `gh pr view --json number,url -q '.number' 2>/dev/null`.
   If one exists, HALT and print its URL — this branch already has a PR; direct the
   user to `/review-pr`.
3. **Ticket ID** — if not passed, follow **Ticket ID Auto-Extraction** in
   `.claude/skills/review-pipeline/SKILL.md` (branch name → PR title → null). If
   null, **prompt the user to create a Linear ticket** before proceeding (per the
   CLAUDE.md Linear rule — every branch maps to ≥1 ticket). Do not open a PR
   without a ticket unless the user explicitly waives it.

---

## Phase 1: Lint the Dirty Files

Run every linter/formatter for the files you touched, **scoped to the changed
work — never a repo-wide sweep** (a bare `vendor/bin/rector` rewrites generated
`bootstrap/cache/*` and unrelated files). "Dirty" = uncommitted changes **plus**
files this branch changed vs `origin/main`:

```bash
git diff --name-only origin/main...HEAD; git diff --name-only; git diff --name-only --cached
```
Deduplicate, keep only existing files, then split by type.

### 1a. PHP touched — in this exact order
```bash
vendor/bin/rector process <changed .php files>
vendor/bin/pint --dirty --format agent
```
Pint runs **after** Rector, to normalize what Rector reformatted.

### 1b. Frontend touched (`.ts`/`.tsx`/`.js`/`.css` under `resources/`)
```bash
npm run lint
npm run format
npm run types
```

### 1c. Re-run affected tests
Linters reorder and retype code, so re-verify green **after** 1a/1b. Filter to the
touched domain(s), not the whole suite:
```bash
php artisan test --compact --filter=<Domain>   # if PHP changed
npm test                                        # if resources/js changed
```

**Gate:** if any linter errors out (not just reformats) or any test is red, **HALT
here** — report exactly what failed and do **not** commit, push, or open a PR. A PR
only opens on a clean, green tree.

---

## Phase 2: Commit & Push

The skill owns the commit.

1. Stage everything: `git add -A`.
2. If nothing is staged (branch already fully committed and lint changed nothing),
   skip to push.
3. Commit with a ticket-prefixed subject matching the repo convention
   (`FLIX-XXX: <concise summary>`), and the standard trailer:
   ```
   FLIX-XXX: <concise summary of the change>

   Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
   ```
   Squash lint fixups into this commit rather than adding a separate "lint" commit.
4. Push and set upstream: `git push -u origin HEAD`.

---

## Phase 3: Generate PR Content

**Ground the content in three sources — do not free-hallucinate a summary.** Each
source supplies one thing:

| Source | Supplies | How |
|---|---|---|
| Linear ticket | the *why* — intent + acceptance criteria | fetch via `mcp__linear-server__get_issue` when a ticket ID is set |
| Diff | the *what* — actual changes | `git diff origin/main...HEAD` |
| Commits | the narrative / ordering | `git log --oneline origin/main..HEAD` |

### Title
`FLIX-XXX: <concise summary>` — every PR in this repo follows this. Drop the
`FLIX-XXX:` prefix only if there is genuinely no ticket.

### Body
Write the way the repo already writes PR bodies: **one tight prose paragraph**
describing what the PR does and why, in the house style (present tense, names the
key classes/files). Not a wall of bullets. If the diff spans several distinct
concerns, a short bullet list may follow the paragraph — but summary-first, always.

End the body with the standard trailer:
```
🤖 Generated with [Claude Code](https://claude.com/claude-code)
```

There is no `.github/PULL_REQUEST_TEMPLATE.md`, so this format is authoritative. If
one gets added later, fill it instead and keep the grounding sources above.

---

## Phase 4: Open the PR

Write the body to a temp file (avoids shell-escaping issues), then open a **ready**
(non-draft) PR against `main`:
```bash
gh pr create --base main --title "<title>" --body-file /tmp/create-pr-body.md
```
Delete the temp file after. Do not pass `--draft`.

---

## Phase 5: Report

```
✅ PR #{number} opened against main
   {title}

Branch pushed · {N} commit(s) · lint clean · tests green

View: {PR URL}

Next: /human-review
```

## Notes
- **Single-purpose.** Do not run `/review-pr` or any review agents — hand off.
- **Never force-push** or rewrite existing commits beyond squashing your own lint
  fixups into the commit you just made.
- If `gh pr create` fails, show the error and the generated title/body so the work
  isn't lost.

$ARGUMENTS
