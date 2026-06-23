---
name: review-pipeline
description: Shared contract for all review agents — finding format, severity taxonomy, consensus rules, grounding, and lundflix conventions. Referenced by /review-pr and its reviewer/hunter agents.
---

# Review Pipeline — Shared Agent Contract

## Finding Format

Every finding MUST use this exact block format:

```
=== FINDING ===
SEVERITY: BLOCKING | SHOULD_FIX | CONSIDER | NIT
CONFIDENCE: 0.0-1.0
FILE: path/to/file.php
LINE: N or N-M
CATEGORY: correctness | security | performance | convention | testing | architecture | requirements
FINDING: [One sentence description]
SOURCE: [your-agent-name]
EVIDENCE: [specific code reference and reasoning — quote the code]
RECOMMENDATION: [concrete fix — not "consider refactoring"]
=== END FINDING ===
```

No-findings response:

```
=== NO FINDINGS ===
CATEGORY: [your-category]
SOURCE: [your-agent-name]
SUMMARY: [What was checked and why it passed]
=== END NO FINDINGS ===
```

## Severity Definitions

| Severity | Meaning | Examples |
|----------|---------|----------|
| BLOCKING | Must fix before merge | Security vulnerability, data loss, broken functionality, failing test, unimplemented acceptance criteria |
| SHOULD_FIX | Strongly recommended | Logic error, missing edge case, convention violation affecting maintainability, missing test for critical path |
| CONSIDER | Author's judgment | Style preference, minor performance, alternative approach, pre-existing issue |
| NIT | Trivial | Typo, formatting, naming suggestion |

## Review Authority Rules

1. **Every finding must cite an authority.** One of: ticket requirement, a
   `CLAUDE.md` / project-guideline rule, a codebase convention, a deterministic
   tool result (Pint/Rector/Pest), or a security best practice.
2. **If you can't cite the authority, the finding doesn't belong in the report.**
3. **Pre-existing issues** not introduced by this PR: severity-capped at CONSIDER.
   Note them but don't block the PR over them.
4. **Don't be pedantic.** Minor style preferences aren't findings. The goal is
   catching real issues.
5. **Quote specific code** for every finding. Vague references like "the
   validation logic" are not evidence.

## Project Conventions (lundflix)

This is a Laravel + Inertia (React) app organized by **Domain-Driven Design**.
When reviewing, check changes against these standards (full detail in `CLAUDE.md`):

**Architecture**
- Domain code lives under `app/Domains/{Domain}/` with namespace
  `App\Domains\{Domain}\...`. Non-domain infra/UI (`app/Http`, `app/Filament`,
  `app/Providers`) stays at `app/` root and calls *into* domains.
- A domain never imports another domain's `Models` or internals — the only
  cross-domain entry point is that domain's `Contracts/` (interfaces) or a
  published `Service`.
- `Common` is the shared kernel: only incredibly stable shared concepts (value
  objects, enums, contracts, DTOs). It depends on nothing domain-specific. Keep
  it small.
- Create a subfolder only when there is something to put in it — no empty
  scaffolding.

**Action classes**
- Single-purpose actions in `App\Domains\{Domain}\Actions`, named `VerbNoun` in
  PascalCase with **no `Action` suffix** (`CreateUser`, not `Create` or
  `CreateUserAction`). Standalone actions expose one `handle()` method; actions
  bound to a framework contract keep the interface's method name.

**Exceptions**
- Explicitly named exception classes, **one class per distinct failure**, named
  for the failure, in `App\Domains\{Domain}\Exceptions`. Never funnel multiple
  unrelated failures through a single catch-all exception. A static named
  constructor (`::at($path)`) is fine — one-failure-per-class is the rule, not
  the factory style.

**Configuration**
- Fixed, public third-party base URLs are **service constants** (`private const`
  on the calling service), not `env`/`config`. Reserve `config`/`env` for
  secrets, credentials, and values that genuinely differ per environment.

**File creation**
- Files are created via `php artisan make:*` and land in the DDD structure
  (domain path passed in the name). Hand-written boilerplate where a generator
  exists is a smell.

**Frontend (Inertia + React)**
- `resources/js/` mirrors the backend domains: `common/` (generic, no domain
  knowledge), `modules/{domain}/` (reusable domain UI/logic), `pages/` (Inertia
  entry points by URL; page-local components only). PascalCase components,
  `Page`/`Layout` suffixes, kebab-case dirs.

**Testing** — see the dedicated `laravel-testing` and `react-testing` skills.

## Convention Override Rule

Before flagging a code pattern, reviewer agents MUST check whether `CLAUDE.md`,
project guideline files, or this contract explicitly endorse that pattern. If the
pattern is documented as the project standard, it is **NOT a finding** — even if
it contradicts general best practices. Flagging an endorsed pattern is itself a
defect in the review.

**Commonly false-positived conventions** (endorsed — do not flag):
- Models under `app/Domains/{Domain}/Models/` — intentional DDD layout, not a
  misplacement.
- Fixed third-party base URLs as `private const` on a service — intentional, not
  "should be config".
- Many small named exception classes for one domain — intentional
  (one-failure-per-class), not over-engineering.
- Action classes named `VerbNoun` with no `Action` suffix — intentional naming.
- Multiple near-identical tests that each assert one action — intentional (AAA,
  one Act per test), not duplication to be merged.
- Domain calling another domain only through a `Contracts/` interface — intended
  boundary, not indirection to remove.
- Feature tests with no per-file `Http::preventStrayRequests()` — it is applied
  **globally** in `tests/Pest.php` via `pest()->extend(TestCase::class)
  ->beforeEach(fn () => Http::preventStrayRequests())->in('Feature')`. Adding it
  per-file is redundant, not a missing safeguard. (NB: in this repo
  `->use(RefreshDatabase::class)` is currently **commented out** in `Pest.php`, so
  `RefreshDatabase` is *not* globally applied — do not extend this dismissal to a
  missing-`RefreshDatabase` flag unless that line is un-commented.)

## Consensus Rules (Used by Orchestrator, Not Agents)

| Confidence | Rule | Action |
|------------|------|--------|
| DETERMINISTIC | Pint, Rector, Pest findings | Auto-include, never filtered |
| HIGH | Same issue flagged by 2+ independent AI reviewers (deduped by file ±10 lines + category) | Auto-include without challenge |
| MEDIUM | Flagged by exactly 1 AI reviewer, severity ≥ SHOULD_FIX | Route to adversarial verification |
| LOW | Flagged by exactly 1 reviewer, severity < SHOULD_FIX | Auto-downgrade to CONSIDER |

Deduplication: Match on (file, line range ±10 lines, category). Additionally,
merge findings from different agents with the same FILE, same CATEGORY, and
substantially the same recommended fix regardless of line distance. When multiple
reviewers flag the same issue, keep the richest evidence and highest severity.

## Tiebreaker Rule (Phase 5)

If false-positive-hunter dismisses a finding that missing-defect-hunter
independently rediscovers at SHOULD_FIX or higher, the finding survives at the
severity assigned by missing-defect-hunter (minimum SHOULD_FIX). A finding must be
dismissed by the adversarial agent AND absent from the fresh-eyes review to be
removed from the final report.

## Mechanical Grounding Verification

Before routing AI-generated findings to adversarial verification, programmatically
verify that each finding references real code. **DETERMINISTIC findings are
exempt** — they come from tools that already verified the code.

For each AI-generated finding, check:

1. **File exists:**
   ```bash
   test -f "{FILE}" && echo "EXISTS" || echo "MISSING"
   ```
   If MISSING: **discard the finding** with reason `GROUNDING_FAIL: file does not exist at {FILE}`

2. **Line/range validity:**
   ```bash
   wc -l < "{FILE}"
   ```
   Parse LINE as a single integer N or a range N-M:
   - If LINE cannot be parsed: **discard** with `GROUNDING_FAIL: non-numeric line reference ({LINE})`
   - N and M (if present) must be positive integers (≥ 1); for ranges N ≤ M
   - N ≤ total lines, and M ≤ total lines (if present)
   If any check fails: **discard** with `GROUNDING_FAIL: invalid line reference ({LINE}) for file with {total} lines`

Only these two checks. No fuzzy text matching — agents routinely paraphrase
evidence, making text matching unreliable.

Report grounding results in the coverage matrix:
```
| Grounding Check | {total_checked} checked | {discarded} discarded |
```

## Ticket ID Auto-Extraction

When a review command is invoked without an explicit ticket ID, attempt
extraction in this order (first match wins):

1. **Branch name:** Run `git branch --show-current` and apply case-insensitive
   regex `(?i)(?<![A-Za-z])FLIX-\d+`. Take the **first match only** and normalize
   to uppercase.
2. **PR title:** Run `gh pr view --json title -q .title` and apply the same regex
   (normalize to uppercase). Use PR title only (not body — PR descriptions
   routinely mention multiple related tickets).
3. **No match:** Set TICKET_ID to null. Skip requirements-reviewer. Warn: "No
   ticket ID found. Running without requirements review."

## PR Number Auto-Extraction

When `/review-pr` is invoked without an explicit PR number:

```bash
gh pr view --json number -q .number
```

- Success (exit 0 + numeric output): use as PR_NUMBER
- Failure: HALT with a message directing the user to push the branch and open a
  PR, or pass the PR number explicitly.
