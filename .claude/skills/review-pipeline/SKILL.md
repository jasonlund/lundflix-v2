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

## The Comment Bar — only comment if

A candidate is worth a comment **only when it clears ALL four bars**. Fail any one
and you stay silent — a wrong or low-value comment costs more trust than a missed
nit. Even frontier reviewers are wrong on ~1 in 3 comments, so doubt yourself
before speaking.

1. **Evidence bar — behavior, not naming.** The finding cites a **`file:line` in
   the source showing the actual behavior**, not an inference from a name, a type,
   or "this is probably". "`processPayment()` probably double-charges" fails;
   "`charge()` is called at L40 and again at L57 with no idempotency guard" clears.
   Naming-based guesses are the #1 false-positive source — if your only evidence is
   what something is *called*, drop it.
2. **Scope bar — this diff.** The finding is about code the PR **added or
   modified**. A real bug in untouched code is allowed **only** tagged pre-existing
   (severity-capped at CONSIDER per rule 3 below). Do not re-audit the repo or
   re-litigate settled design.
3. **Category bar — objective defect.** It's a bug, security, correctness, data,
   or cross-system/integration issue. Style, formatting, naming taste, and
   "alternative approach" preferences are **not** defects — cap them at NIT and
   respect the nit cap, or say nothing.
4. **Ownership bar — not already owned.** A deterministic gate
   (Pint/Rector/ESLint/Vitest/Pest) or an endorsed convention does not already own
   it. Repeating a gate or flagging an endorsed pattern is itself a review defect
   (see Convention Override Rule).

## Severity Definitions

| Severity | Meaning | Examples |
|----------|---------|----------|
| BLOCKING | Must fix before merge — **reserved for**: incorrect logic that breaks behavior, unscoped/tenant-leaking queries, secrets or PII in logs, non-backward-compatible migrations, a DDD-boundary break that changes behavior, a failing test, an unimplemented acceptance criterion. If a finding is not on this list, it is **not** BLOCKING. | Query missing a tenant scope; migration drops a column with no fallback; secret logged in plaintext |
| SHOULD_FIX | Strongly recommended — a real defect that isn't on the BLOCKING list | Logic error on an edge case, missing test for a critical path, convention violation affecting maintainability |
| CONSIDER | Author's judgment | Minor performance, alternative approach, pre-existing issue |
| NIT | Trivial — **the only home for style/naming/taste** | Naming suggestion, a non-gate-owned readability tweak |

**Nit cap.** Report **at most 5 NITs** across the whole review. Found more? Post
the 5 highest-value and note "plus N similar nits" in the summary — never inline
the rest. Style, formatting, import order, and type hints owned by a gate are **not
nits, they are silence** (bar 4).

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

**Comments & docblocks** (owned by `conventions-reviewer`)
- Comments capture a non-obvious *why*; flag ones that restate the *what* the
  code or a passing test already says. Docblocks keep only type info PHP can't
  express (generics, `@throws`) and genuine "why" prose — flag summary lines
  restating the method name, redundant `@param`/`@var`, and framework type stubs.

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

**Default-silent on deliberate choices.** Beyond documented conventions: if a
pattern is **consistent with how the surrounding code already does it**, or reads
as an intentional decision the author made on purpose, stay silent unless it clears
the Comment Bar as an objective defect. Your stance is a pragmatic verifier —
verify the author's *intent* and hunt real *failure modes*; do not second-guess
architecture or taste. Adversarial energy is aimed at bugs and at your own false
positives, never at the author's judgment.

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
- Feature tests with no per-file `uses(RefreshDatabase::class)` or
  `Http::preventStrayRequests()` — **both** are applied **globally** to the Feature
  suite in `tests/Pest.php` via `pest()->extend(TestCase::class)
  ->use(RefreshDatabase::class)->beforeEach(fn () => Http::preventStrayRequests())
  ->in('Feature')`. Declaring either per-file is redundant, not a missing safeguard.

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

The false-positive-hunter's verdict is **binding**: a finding it defeats **with
evidence** (shows the behavior is handled elsewhere, misread, pre-existing,
convention-endorsed, or unsupported by a `file:line` citation) is **dropped
entirely — not down-severitied.** A weakened-but-alive finding is a compromise that
ships noise; either the defense holds and it dies, or it doesn't and the finding
stands at full severity.

**One exception — independent rediscovery.** If false-positive-hunter dismisses a
finding that missing-defect-hunter *independently* rediscovers at SHOULD_FIX or
higher, the finding survives at missing-defect-hunter's severity (minimum
SHOULD_FIX): two independent reviewers seeing the same defect outweighs one
dismissal. Absent that rediscovery, a defeated finding is removed from the report.

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
