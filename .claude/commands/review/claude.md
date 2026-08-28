---
name: review:claude
description: Gate-first adversarial multi-agent PR review against Linear tickets and lundflix standards. Runs deterministic gates (Pint/Rector/Pest/ESLint/Vitest), then parallel reviewer subagents with isolated context, consensus filtering, and adversarial verification.
---

# Adversarial PR Review

You are orchestrating a structured, multi-phase code review. Deterministic tools
run first; their findings are facts. Parallel subagents with isolated context
windows then handle the judgment calls, followed by adversarial verification. The
orchestrator never reviews in its own context — it dispatches and synthesizes.

## Input
- **PR number** — positional arg, or auto-detected from the current branch.
- **Ticket ID** — `FLIX-XXX`, positional arg, or extracted from the branch name /
  PR title.

Both are optional when the current branch has an open PR. See Phase 0.

## Example Invocation

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
   user to open one with `/review:create-pr` (which lints, commits, pushes, and opens the
   PR), or pass the number.
2. **Ticket ID** — if not passed, follow **Ticket ID Auto-Extraction** in the same
   contract (branch name → PR title → null). If null, warn that requirements
   review will be skipped.

---

## Phase 1: Deterministic Gates

Run these **before** any AI review. Their findings are DETERMINISTIC confidence —
auto-included, never filtered or challenged.

### 1a. Pint (style)
```bash
vendor/bin/pint --dirty --test
```
Each style violation → `SEVERITY: NIT`, `CONFIDENCE: DETERMINISTIC`,
`CATEGORY: convention`, `SOURCE: pint`.

### 1b. Rector (modernization / safe refactors)
```bash
vendor/bin/rector --dry-run
```
Each proposed change → `SEVERITY: CONSIDER`, `CONFIDENCE: DETERMINISTIC`,
`CATEGORY: convention`, `SOURCE: rector`.

### 1c. Pest (backend tests)
Run the affected suite. If changed files sit under one or more domains, filter to
those; otherwise run the full suite:
```bash
php artisan test --compact
```
Each failure → `SEVERITY: BLOCKING`, `CONFIDENCE: DETERMINISTIC`,
`CATEGORY: testing`, `SOURCE: pest`.

If a Pest architecture-test suite exists, it runs as part of this step — treat its
failures (domain-boundary violations, etc.) as DETERMINISTIC BLOCKING findings.

### 1d. ESLint (frontend, only if the diff touches `resources/js/`)
```bash
npm run lint
```
Each error → `SEVERITY: SHOULD_FIX`, warnings → `NIT`,
`CONFIDENCE: DETERMINISTIC`, `CATEGORY: convention`, `SOURCE: eslint`.

### 1e. Vitest (frontend tests, only if the diff touches `resources/js/`)
```bash
npm test
```
Each failure → `SEVERITY: BLOCKING`, `CONFIDENCE: DETERMINISTIC`,
`CATEGORY: testing`, `SOURCE: vitest`.

Save all of the above as `DETERMINISTIC_FINDINGS`. A tool that is not applicable
(no PHP / no JS changes) is marked "n/a" in the coverage matrix, not "passed".

---

## Phase 2: Context Gathering

1. **PR diff:**
   ```bash
   gh pr diff {PR_NUMBER}
   ```
   Assert exit 0 and non-empty output. Save as `PR_DIFF`; note added/modified/
   deleted files. If the diff exceeds 500 lines, set `LARGE_DIFF=true`.

2. **Linear ticket** — if `TICKET_ID` is set, fetch it via the Linear MCP
   (description, acceptance criteria, linked docs, labels). Save as
   `TICKET_CONTEXT`. If the MCP fails, set `TICKET_CONTEXT = "LINEAR_UNAVAILABLE"`
   and tell requirements-reviewer to skip and note it at CONSIDER. If `TICKET_ID`
   is null, set `TICKET_CONTEXT = "NO_TICKET"`.

Project standards from `CLAUDE.md` are already in context; subagents inherit it.

---

## Phase 3: Parallel Review Agents

Spawn these subagents **in parallel** using the Agent tool, each in isolated
context. Pass each one: `TICKET_CONTEXT`, `PR_DIFF`, and a pointer to the finding
format + **The Comment Bar** (only-comment-if gate, narrow BLOCKING, nit cap) +
Convention Override Rule in `.claude/skills/review-pipeline/SKILL.md`. Each reviewer
runs every candidate through the Comment Bar before emitting it — a candidate that
fails any bar is dropped, not reported. If `LARGE_DIFF=true`, tell each to
prioritize the most-changed files.

1. **requirements-reviewer** — changes vs ticket acceptance criteria. **Skip if
   `TICKET_ID` is null.**
2. **conventions-reviewer** — DDD layout, Action/exception naming, cross-domain
   boundaries, service-constant rule, frontend-mirror conventions.
3. **edge-case-reviewer** — adversarial failure-mode and input analysis.
4. **integration-reviewer** — blast radius, cross-domain side effects, migrations,
   queue/job impact.
5. **discipline-reviewer** — simplicity, surgical-change, and verifiability
   discipline.
6. **testing-reviewer** — test *quality* against the `tdd-laravel-testing` /
   `tdd-react-testing` conventions (the Phase 1 gates already prove tests pass).
7. **duplication-reviewer** — cross-file repetition of code, docblocks, and
   rationale comments. Nothing else reaches duplicated *prose*: the
   `review-tdd-cross-slice` sweep is green-gated, so rewriting a comment changes
   no test.

**Timeout budget:** if an agent hasn't returned after 8 minutes, mark it
`TIMED_OUT` in the coverage matrix and proceed with the rest.

---

## Phase 4: Consensus, Dedup, Grounding

1. Combine `DETERMINISTIC_FINDINGS` with all Phase 3 findings.
2. Classify confidence and dedupe per the **Consensus Rules** in the contract.
3. Run **Mechanical Grounding Verification** (contract) on every AI-generated
   finding — discard any whose file/line doesn't resolve. DETERMINISTIC findings
   are exempt. Collect discards as `GROUNDED_DISCARDS`.
4. Route MEDIUM-confidence findings (1 reviewer, ≥ SHOULD_FIX) to Phase 5 as
   `MEDIUM_FINDINGS`; everything else is `VERIFIED_FINDINGS`.
5. **Enforce the aggregate nit cap.** Per-agent caps don't bound the total, so cap
   here: keep the **5 highest-value NITs** across all reviewers, drop the rest, and
   record `SUPPRESSED_NITS = {count}` for the Phase 6 tally ("suppressed N more").
   Gate-owned nits (formatting/style/import-order/type-hints) should never have made
   it this far — if any did, drop them, they're a reviewer defect.
6. **Split out the requirements axis — never rerank across it.** Pull every
   `CATEGORY: requirements` finding into `SPEC_FINDINGS`; everything else stays in
   the standards pool. The two axes measure different things and are **not
   commensurable**: code can follow every convention while implementing the wrong
   thing (standards pass, spec fail), or do exactly what the ticket asked in a way
   that breaks conventions (spec pass, standards fail). Merging them into one
   ranked list lets the louder category bury the other — typically a pile of
   convention findings hiding a missing acceptance criterion.

   Dedup, grounding, and adversarial verification all still apply to
   `SPEC_FINDINGS` exactly as before. This splits *presentation and ranking*, not
   rigor. Never rank a spec finding against a standards finding, and never report
   a single "worst issue" across both — report the worst **within each axis**.

   Each `SPEC_FINDINGS` item keeps its `SEVERITY`, `FILE`, and `LINE` so the
   Phase 6 Spec section can render the full field block that `/review:add` posts
   to the PR. A finding that names the absence of code has no file — it posts in
   the review body.

---

## Phase 5: Adversarial Verification

Spawn 2 challengers **in parallel**:

1. **false-positive-hunter** — argues why each `MEDIUM_FINDINGS` item might be
   wrong (misread, handled elsewhere, pre-existing, convention-endorsed, severity
   inflated). Pass `TICKET_CONTEXT`, `PR_DIFF`, `MEDIUM_FINDINGS`.
2. **missing-defect-hunter** — fresh eyes on the PR for anything every other agent
   missed. Pass `TICKET_CONTEXT`, `PR_DIFF`, and all findings so far (awareness).

Reconcile with the **Tiebreaker Rule** (contract): a finding survives unless
false-positive-hunter dismisses it AND missing-defect-hunter does not rediscover
it. Merge missing-defect-hunter's new findings into the verified set.

---

## Phase 6: Final Report

Lead with a **one-line tally** so the author gets the shape before the detail, then
the defects. State "no blocking issues" (or "no defects found") plainly when true —
don't bury a clean result. Keep each finding terse; long justification goes in a
collapsible `<details>` block, not the top line.

```markdown
# PR Review: PR #{number}{ against {ticket_id} if present}

**Spec: {S} findings · Standards: {X} blocking · {Y} should-fix · {Z} consider ·
{N} nits{, suppressed {M} more}**

## Spec — does it do what the ticket asked?

[`SPEC_FINDINGS` only, and every one of them — this section is the whole spec
axis, and `/review:add` posts the PR comments from here. If none: "Implements the
ticket as specified." If the ticket was unavailable: "No ticket — spec axis not
reviewed."]
[One entry per finding, covering (a) an acceptance criterion missing or partial,
(b) behavior in the diff nobody asked for, (c) a criterion implemented wrong.
Carry the same fields as the severity sections below, plus `Severity` — the
severity sections rank the standards pool, so a spec finding carries its own:]

- **Severity:** 🔴 BLOCKING | 🟠 SHOULD_FIX | 🟡 CONSIDER
- **File:** `path/to/file.php` (lines N-M) — write `_no file_` when the finding is
  the absence of code, e.g. an acceptance criterion nothing implements
- **Issue:** [description]
- **Violates:** [the ticket line, quoted verbatim]
- **Fix:** [specific recommendation]
- **Found by:** [agent] | **Confidence:** [HIGH/MEDIUM]

Order the entries by severity **within this section**. **This section is never
merged into the one below, and the two are never ranked against each other.** A
clean standards review does not offset a spec failure.

## Key Defects

[If no BLOCKING or SHOULD_FIX findings: "No blocking or should-fix defects found."]
[Otherwise one concise bullet per standards-pool BLOCKING/SHOULD_FIX finding —
what & where, not the fix. 🔴 BLOCKING, 🟡 SHOULD_FIX. Ordered by severity. Bury
per-finding reasoning in a `<details>` block so the bullet stays one line. The
Spec section above stands on its own.]

## Summary
- **Spec Findings:** {count}
- **Blocking Issues:** {count}
- **Should Fix:** {count}
- **Consider:** {count}
- **Dismissed:** {count}

## Coverage Matrix

| Source | Status | Findings |
|---|---|---|
| pint | ✅ ran / ⬚ n/a | {count} |
| rector | ✅ ran / ⬚ n/a | {count} |
| pest | ✅ ran / ⬚ n/a | {count} |
| eslint | ✅ ran / ⬚ n/a | {count} |
| vitest | ✅ ran / ⬚ n/a | {count} |
| requirements-reviewer | ✅ completed / ⏱ timed out / ⬚ skipped (no ticket) | {count} |
| conventions-reviewer | ✅ completed / ⏱ timed out | {count} |
| edge-case-reviewer | ✅ completed / ⏱ timed out | {count} |
| integration-reviewer | ✅ completed / ⏱ timed out | {count} |
| discipline-reviewer | ✅ completed / ⏱ timed out | {count} |
| testing-reviewer | ✅ completed / ⏱ timed out | {count} |
| duplication-reviewer | ✅ completed / ⏱ timed out | {count} |
| grounding check | ✅ {checked} checked | 🗑 {discarded} discarded |
| false-positive-hunter | ✅ completed | {dismissed} dismissed |
| missing-defect-hunter | ✅ completed | {new} new findings |

## Blocking Issues (must fix before merge)

[Standards pool. For each finding:]
- **File:** `path/to/file.php` (lines N-M)
- **Issue:** [description]
- **Violates:** [requirement/convention with quote]
- **Fix:** [specific recommendation]
- **Found by:** [agent/tool] | **Confidence:** [DETERMINISTIC/HIGH/MEDIUM]

## Should Fix (not blocking but strongly recommended)

[Same format]

## Consider (valid concerns, author's judgment)

[Same format]

## Grounding Failures

[Only if GROUNDED_DISCARDS is non-empty — one bullet each: original finding +
`GROUNDING_FAIL: {reason}`]

## Dismissed Findings

[For each dismissed: original finding · dismissed by (false-positive-hunter /
convention override) · reason]

## Coverage Notes

[From missing-defect-hunter — areas not fully covered, suggested follow-up.]
```

## Orchestration Notes

- **Do not summarize subagent work in main context** — trust the isolated context.
- If a subagent fails or times out, note it in the coverage matrix and proceed.
- Every finding must trace to a ticket requirement, a `CLAUDE.md`/convention rule,
  or deterministic tool output. No citable authority → it doesn't ship.
- DETERMINISTIC findings are never filtered or challenged.

To post the report to the PR as inline comments, run `/review:add` afterward.

$ARGUMENTS
