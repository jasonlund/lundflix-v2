---
name: review:claude
description: Gate-first multi-agent PR review against lundflix standards. A skip gate runs first, then the deterministic gates (Pint/Rector/Pest/ESLint/Vitest), then four parallel reviewers in isolated context, then one validator per finding that drops everything it cannot confirm.
---

# PR Review

You orchestrate the review and never review in your own context. Every judgement
call is made by a subagent in isolated context.

## Input

- **PR number** — positional arg, or auto-detected from the current branch.
- **Ticket ID** — `FLIX-XXX`, positional arg, or extracted from the branch name /
  PR title. It names the PR in the report header; the spec axis itself belongs to
  `/review:human`.

```
/review:claude                 # auto-detect PR + ticket from branch
/review:claude 142             # explicit PR, auto-detect ticket
/review:claude FLIX-154        # auto-detect PR, explicit ticket
/review:claude 142 FLIX-154    # explicit both
```

---

## Phase 0: Resolve PR + Ticket

1. **PR number** — if not passed, follow **PR Number Auto-Extraction** in
   `.claude/skills/review-pipeline/SKILL.md`. If no PR is found, HALT and tell the
   user to open one with `/review:create-pr` (which lints, commits, pushes, and
   opens the PR), or to pass the number.
2. **Ticket ID** — if not passed, follow **Ticket ID Auto-Extraction** in the same
   contract (branch name → PR title → null).

---

## Phase 0.5: Skip Gate

Dispatch **review-skip-check** with the PR state, title, and
`gh pr diff {PR_NUMBER} --stat`. It answers SKIP or REVIEW.

- **SKIP** — print its one-line reason (closed, draft, trivial, already reviewed)
  and STOP. Every phase below stays unspent; that saving is the whole point of
  running this gate ahead of the reviewers.
- **REVIEW** — continue to Phase 1.

---

## Phase 1: Deterministic Gates

These produce facts. Save them as `DETERMINISTIC_FINDINGS`; they are auto-included
and skip validation entirely.

### 1a. Pint (style)
```bash
vendor/bin/pint --dirty --test
```
Each violation → `SEVERITY: NIT`, `CATEGORY: convention`, `SOURCE: pint`.

### 1b. Rector (modernization / safe refactors)
```bash
vendor/bin/rector --dry-run
```
Each proposed change → `SEVERITY: CONSIDER`, `CATEGORY: convention`,
`SOURCE: rector`.

### 1c. Pest (backend tests)
```bash
php artisan test --compact
```
Filter to the domains the diff touches when it sits under one or more; otherwise
run the full suite. Each failure → `SEVERITY: BLOCKING`, `CATEGORY: testing`,
`SOURCE: pest`. Architecture-test failures (domain-boundary breaks) count here too.

### 1d. ESLint — when the diff touches `resources/js/`
```bash
npm run lint
```
Errors → `SEVERITY: SHOULD_FIX`, warnings → `NIT`, `CATEGORY: convention`,
`SOURCE: eslint`.

### 1e. Vitest — when the diff touches `resources/js/`
```bash
npm test
```
Each failure → `SEVERITY: BLOCKING`, `CATEGORY: testing`, `SOURCE: vitest`.

---

## Phase 2: Context

```bash
gh pr diff {PR_NUMBER}
```
Assert exit 0 and non-empty output. Save as `PR_DIFF`.

Dispatch **review-summarizer** with `PR_DIFF`. It returns `PR_SUMMARY` (what the
PR does) and `GUIDELINE_PATHS` (the `CLAUDE.md` / `GUIDELINES.md` / `.ai/rules`
paths the changed files fall under), so each reviewer loads the rules in scope
instead of the whole guideline tree.

---

## Phase 3: Parallel Review

Dispatch four agents **in parallel**, each in isolated context:

- 2 × **review-compliance** — convention compliance against `GUIDELINE_PATHS`,
  with the Fowler smell baseline as the rubric for design friction.
- 2 × **review-bug-hunter** — bugs and logic errors.

Pass each one `PR_SUMMARY`, `GUIDELINE_PATHS`, `PR_DIFF`, and the finding format,
severity definitions, Simplified Technical English rules, and Convention Override
Rule in `.claude/skills/review-pipeline/SKILL.md`.

### The bar every reviewer applies

Flag exactly three things:

1. Code that will fail to compile or parse.
2. Code that will produce wrong results regardless of inputs.
3. A clear, unambiguous guideline violation whose exact rule you can quote.

Stay silent on everything else — style and quality concerns, anything that depends
on specific inputs or state, subjective suggestions, anything a Phase 1 gate
already owns, pre-existing issues, and anything under a lint-ignore comment.
Uncertain that an issue is real → stay silent.

Each reviewer returns **at most 400 words**.

**Bug hunters work DIFF-LOCAL.** Flag what the diff alone proves.

The two agents of a pair share a brief and a diff, so the same defect arrives
twice. Merge duplicates on (file, line ±10, category), keeping the richest
evidence and the highest severity.

---

## Phase 4: Validation

One validator per surviving finding, dispatched in parallel:

- **review-bug-validator** — every review-bug-hunter finding.
- **review-compliance-validator** — every review-compliance finding.

Pass the single finding, `PR_DIFF`, and the file it cites. Each answers CONFIRMED
or DROPPED with a one-line reason.

---

## Phase 5: Fail-Closed

A finding is validated on one condition: its own validator answered CONFIRMED.
Keep those. **Drop every finding no validator confirmed** — including one whose
validator errored, timed out, returned nothing, or answered ambiguously.
Fail-closed means dropped, never downgraded: a finding nobody could confirm never
reaches the author. Count the drops as `DROPPED` for the tally.

---

## Phase 6: Final Report

Write every line in Simplified Technical English (rules in
`.claude/skills/review-pipeline/SKILL.md`). Lead with the tally, then the defects.

```markdown
# PR Review: PR #{number}{ against {ticket_id} if present}

**{X} blocking · {Y} should-fix · {Z} consider · {D} dropped in validation**

## Spec — does it do what the ticket asked?

`/review:human` Phase 3 owns the spec axis. This review covers standards only.

## Blocking Issues (must fix before merge)

[One entry per confirmed BLOCKING finding. When there are none, write the single
line "No blocking issues."]
- **File:** `path/to/file.php` (lines N-M)
- **Issue:** [description]
- **Violates:** [the rule or convention, quoted verbatim]
- **Fix:** [specific recommendation]
- **Found by:** [agent/tool]

## Should Fix (not blocking but strongly recommended)

[Same fields. When there are none: "No should-fix defects."]

## Consider (valid concerns, author's judgment)

[Same fields. When there are none: "No further concerns."]
```

To post the report to the PR as inline comments, run `/review:add` afterward.

$ARGUMENTS
