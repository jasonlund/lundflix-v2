---
name: review-pipeline
description: Shared contract for all review agents — finding format, severity taxonomy, the Comment Bar, model tiers, and lundflix conventions. Referenced by /review:claude and every reviewer and validator it dispatches.
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

## How Findings Are Written — Simplified Technical English

Every `FINDING`, `EVIDENCE`, `RECOMMENDATION`, and `SUMMARY` field, and every line
of the Phase 6 report, is written in **ASD-STE100 Simplified Technical English**.
The reader scans 30 findings to decide what to fix. Controlled language makes that
scan fast; ordinary prose makes it slow.

The rules that bind here:

- **One topic per sentence.** A sentence carrying two ideas becomes two sentences.
- **Short sentences.** Up to 20 words for an instruction, 25 for a description.
- **Active voice.** "The query returns every tenant's rows", not "rows are returned
  for every tenant".
- **Simple tenses.** Present for what the code does; past for what a run produced.
- **One word, one meaning; one thing, one word.** Pick the term and repeat it
  exactly. Elegant variation costs the reader a re-read to confirm two words mean
  one thing.
- **Use the glossary.** Name domain concepts as `CONTEXT.md` names them, and
  interfaces as `codebase-design` names them.
- **Noun clusters up to three words.** "download source markup drift" is the limit.
- **Paragraphs up to six sentences.**
- **Write recommendations as commands.** "Add a tenant scope to the query on L40."
- **Literal words, glossary terms, statements.** Every word carries its plain
  meaning, every domain term comes from the glossary, and every point is a
  statement.

Write full sentences with articles. Telegraphic style ("query missing scope, add
one") reads fast and lands ambiguously, so it is not the target here.

Two things stay verbatim and are never rewritten into STE: **quoted code** in
`EVIDENCE`, and a **quoted ticket line** in a requirements finding. Both are
evidence; paraphrasing them destroys their value.

Take the standard's rules, not its approved word list — that dictionary is built for
aircraft maintenance, and the glossary rule above already names our terms.

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
   modified**. Stay silent on a pre-existing issue in untouched code, even a real
   one — diff-locality is what bounds this review. Breadth across the repo belongs
   to CodeRabbit in `/review:suite`, and `/review:process` keeps an out-of-scope
   bucket for the ones another engine or a human raises.
3. **Category bar — objective defect.** It's a bug, security, correctness, data,
   or cross-system/integration issue. Style, formatting, naming taste, and
   "alternative approach" preferences are **not** defects — cap them at NIT, or say
   nothing.
4. **Ownership bar — not already owned.** A deterministic gate
   (Pint/Rector/ESLint/Vitest/Pest) or an endorsed convention does not already own
   it. Repeating a gate or flagging an endorsed pattern is itself a review defect
   (see Convention Override Rule).

## Severity Definitions

| Severity | Meaning | Examples |
|----------|---------|----------|
| BLOCKING | Must fix before merge — **reserved for**: incorrect logic that breaks behavior, unscoped/tenant-leaking queries, secrets or PII in logs, non-backward-compatible migrations, a DDD-boundary break that changes behavior, a failing test, an unimplemented acceptance criterion. If a finding is not on this list, it is **not** BLOCKING. | Query missing a tenant scope; migration drops a column with no fallback; secret logged in plaintext |
| SHOULD_FIX | Strongly recommended — a real defect that isn't on the BLOCKING list | Logic error on an edge case, missing test for a critical path, convention violation affecting maintainability |
| CONSIDER | Author's judgment | Minor performance, alternative approach, a design trade-off worth naming |
| NIT | Trivial — **the only home for style/naming/taste** | Naming suggestion, a non-gate-owned readability tweak |

**Style is silence, not a NIT.** Style, formatting, import order, and type hints a
deterministic gate already owns are **silence** (bar 4). A NIT is the home for the
taste call no gate owns, and `/review:claude`'s per-reviewer word cap bounds how
many of those reach the report.

## Review Authority Rules

1. **Every finding must cite an authority.** One of: ticket requirement, a
   `CLAUDE.md` / project-guideline rule, a codebase convention, a deterministic
   tool result (Pint/Rector/Pest), or a security best practice.
2. **If you can't cite the authority, the finding doesn't belong in the report.**
3. **A pre-existing issue is silence** — see the scope bar above.
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

**Comments & docblocks**
- Comments capture a non-obvious *why*; flag ones that restate the *what* the
  code or a passing test already says. Docblocks keep only type info PHP can't
  express (generics, `@throws`) and genuine "why" prose — flag summary lines
  restating the method name, redundant `@param`/`@var`, and framework type stubs.

**Frontend (Inertia + React)**
- `resources/js/` mirrors the backend domains: `common/` (generic, no domain
  knowledge), `modules/{domain}/` (reusable domain UI/logic), `pages/` (Inertia
  entry points by URL; page-local components only). PascalCase components,
  `Page`/`Layout` suffixes, kebab-case dirs.

**Testing** — see the dedicated `tdd-laravel-testing` and `tdd-react-testing` skills.

## Smell Baseline (judgement calls only)

A shared vocabulary for design friction, so reviewers name the same thing the same
way instead of inventing one-off phrasings. Fowler, *Refactoring* ch.3. **Three
rules bind every entry, without exception:**

- **The repo overrides.** A documented convention always wins. Where `CLAUDE.md`,
  `.ai/guidelines/project.md`, or this contract endorses something the baseline
  would flag, **stay silent** — see the Convention Override Rule below.
- **Always a judgement call, never a hard violation.** Report as "possible Feature
  Envy". A smell name is a label, not evidence; cite the `file:line` showing the
  behavior in every case.
- **The cap follows the basis, not the phenomenon.** The smell name is the whole
  basis of the finding → cap it at **CONSIDER**. The finding also stands on its own
  as an objective defect that clears the Comment Bar → it takes the severity the
  Severity Definitions table gives that defect, and the smell name is vocabulary
  for the recommendation. Example: "possible Speculative Generality" alone is
  CONSIDER; "the `$strategy` parameter added at L12 has one caller, which passes one
  value, and the ticket asks for none" is graded as the defect it is. Grade the
  grounded defect, cap the bare label.

Each reads *what it is* → *the fix*:

- **Mysterious Name** — a name that doesn't reveal what it does or holds. → rename;
  if no honest name comes, the design is murky.
- **Duplicated Code** — the same logic shape in more than one hunk. → extract, call
  from both.
- **Feature Envy** — a method reaching into another object's data more than its
  own. → move it onto the data it envies.
- **Data Clumps** — the same few fields always travelling together. → bundle into
  one type (a DTO under `Data/`, a value object under `Common/ValueObjects/`).
- **Primitive Obsession** — a string or int standing in for a domain concept. →
  give the concept its own small type (an enum, a value object).
- **Repeated Switches** — the same `match`/`if`-cascade on the same type recurring
  across the change. → polymorphism, or one shared map.
- **Shotgun Surgery** — one logical change forcing scattered edits across the diff.
  → gather what changes together.
- **Divergent Change** — one file edited for several unrelated reasons. → split so
  each module changes for one reason.
- **Speculative Generality** — abstraction, parameters, or hooks for needs the
  ticket doesn't have. → delete; inline back until a real need shows.
- **Message Chains** — long `a->b()->c()->d()` navigation the caller shouldn't
  depend on. → hide the walk behind one method.
- **Middle Man** — a class or method that mostly just delegates onward. → cut it,
  call the real target. (**Not** a `Contracts/` interface: that indirection is the
  endorsed cross-domain boundary.)
- **Refused Bequest** — a subclass ignoring or overriding most of what it inherits.
  → drop the inheritance, use composition.

Design vocabulary for the recommendation — seam, interface, depth, adapter:
`.claude/skills/codebase-design/SKILL.md`.

**Source:** adapted near-verbatim from the smell baseline in
`mattpocock-skills:code-review`, including the repo-overrides rule. It lives inline
because every Phase 3 reviewer needs the whole baseline in context to name a smell
the same way; one Skill call per reviewer costs more than the text and arrives too
late to shape the finding. Offer to walk through the upstream two-axis review when a
reader asks where the baseline comes from.

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
- A test verifying an ingest/sync write with `assertDatabaseHas` / `assertDatabaseCount`
  / `assertDatabaseMissing` — this **is** behavior verification for these modules, so
  treat it as the endorsed pattern and stay silent. Reasoning:
  `docs/adr/0002-database-assertions-verify-ingest-behavior.md`; test conventions:
  `tdd-laravel-testing`.
- Fixed third-party base URLs as `private const` on a service — intentional, not
  "should be config".
- The catalog schedule's non-overlap by **offset timing**, not a shared mutex
  (`routes/console.php`). `catalog:sync-imdb` at 06:00 sits between `catalog:sync`'s
  00:00/12:00 starts, and each carries its own per-event `withoutOverlapping()`.
  FLIX-273 evaluated a cross-command shared mutex, rejected it, and wrote down the
  residual it accepted. That `withoutOverlapping()` is per-event is the known
  premise, not an oversight — do not propose a shared lock. (The lock *expiry*
  is a separate matter and is set explicitly on both entries.)
- Many small named exception classes for one domain — intentional
  (one-failure-per-class), not over-engineering.
- Action classes named `VerbNoun` with no `Action` suffix — intentional naming.
- Multiple near-identical tests that each assert one action — intentional (AAA,
  one Act per test), not duplication to be merged.
- Domain calling another domain only through a `Contracts/` interface — intended
  boundary, not indirection to remove.
- An ingest/mirror domain's Models declaring `belongsTo` **directly** onto
  `Catalog\Models\*` via a crosswalk id (`_imdb_id`/`_tmdb_id`/`_tvdb_id`) — the
  Download→Catalog precedent, endorsed per-ticket. `PlexLibrary`'s `PlexMovie`,
  `PlexShow`, `PlexSeason`, and `PlexEpisode` are the current instances. This is a
  deliberate exception to the "only through `Contracts/`" rule above, not a
  boundary violation to route through a contract.
- Feature tests with no per-file `uses(RefreshDatabase::class)` or
  `Http::preventStrayRequests()` — **both** are applied **globally** to the Feature
  suite in `tests/Pest.php` via `pest()->extend(TestCase::class)
  ->use(RefreshDatabase::class)->beforeEach(fn () => Http::preventStrayRequests())
  ->in('Feature')`. Declaring either per-file is redundant, not a missing safeguard.
- A `// Act & Assert` label — the ` & ` collapse is the **sanctioned** AAA form
  when the act and the assertion are one expression (typically
  `expect(fn () => ...)->toThrow(...)`), guarded by
  `tests/Unit/TestCommentStandardTest.php`. Splitting the block is a regression.
- A non-domain `tests/Feature/{Category}/` directory (`Architecture/`, `Database/`,
  `Hooks/`, `Http/`) — "tests mirror the domain tree" governs tests **of domain
  code**. A test whose subject is infra (a migration, a hook, framework behavior)
  has no domain owner; a migration spanning several domains has no non-arbitrary
  one. Filing it under a domain would be the violation.
- Third-party account identifiers (ids, usernames, emails) inside an **exception
  message** — those exceptions are `report()`ed and never thrown, so the message
  reaches the operator's log only while the user sees generic lang-file copy.
  Carrying the detail that says which account failed and why is the design, not
  PII leakage.
- A test file calling `DB::` / `Http::` / any facade **without** the matching
  `use Illuminate\Support\Facades\*` import — Laravel's `AliasLoader` registers the
  global facade aliases, so the unqualified call resolves and runs. It is a style
  inconsistency at most, **never** a fatal and never BLOCKING. Before claiming any
  "this will throw at runtime", check the suite: a green run is proof the path
  executes.
- New tests carrying **no** `// Arrange` / `// Act` / `// Assert` labels in a file
  whose existing style is unlabeled (e.g. `TvdbUpdatesTest`, most of
  `TmdbApiServiceTest`) — `tests/Unit/TestCommentStandardTest.php` polices the
  *form* of labels that are present, not their presence. Matching the surrounding
  file is correct; flag only a file that mixes both styles inconsistently within
  itself.
- A domain importing `App\Domains\Common\Data\*` — `Common` is the documented
  shared kernel and explicitly holds DTOs, so depending on one is the intended
  direction. The "only through `Contracts/`" rule governs reaching into another
  **domain's** internals, not the kernel. Do **not** ask for a `Common\Data\*`
  carrier to be republished behind `Common\Contracts` or a service: it adds an
  interface with no second implementation behind it. (`Identity\Data\VerifiedPlexIdentity`
  → `Common\Data\PlexAccount` is the current instance.)
- `array_key_exists()` left as the native call — `.ai/guidelines/project.md` lists
  it under "**Stay native — do NOT 'fix' these**", because `Arr::exists($array, $key)`
  swaps the argument order and the positional Rector map cannot express it. It is
  also the correct choice where a key holding `null` must still count as present,
  which `isset()` would miss. Suggesting the `Arr::` swap is a review defect.

## Model Selection

An agent's `model:` frontmatter follows its role, not its convenience. Four rules,
enforced by `tests/Unit/AgentModelPolicyTest.php`:

1. **Triage pins `haiku`** — `review-skip-check` returns a skip/no-skip call and
   `review-summarizer` describes a diff. Both are mechanical, so they run on the
   cheapest tier.
2. **Compliance and mechanical wrappers pin `sonnet`** — `review-compliance` and
   `review-compliance-validator` match code against a written rule set, and
   `coderabbit-reviewer` shells a CLI and reshapes its output. All three judge
   against something already written down, so the middle tier carries them.
3. **Bug work and write-side agents `inherit`** — `review-bug-hunter`,
   `review-bug-validator`, `review-fixer`, and the `tdd-*` trio run on whatever
   model the session runs. Finding a real defect is the hardest judgement in the
   pipeline, and a write-side agent produces code the session owns.
4. **Never stamp a model version in prose, a commit trailer, or docs.** The harness
   supplies the co-author trailer per session and it tracks the model on its own; a
   hand-written stamp selects nothing and is guaranteed to go stale.

Both pinned tiers name the bare **alias**, so the pin tracks the current model
instead of a dated snapshot the harness eventually stops dispatching.

## Ticket ID Auto-Extraction

When a review command is invoked without an explicit ticket ID, attempt
extraction in this order (first match wins):

1. **Branch name:** Run `git branch --show-current` and apply case-insensitive
   regex `(?i)(?<![A-Za-z])FLIX-\d+`. Take the **first match only** and normalize
   to uppercase.
2. **PR title:** Run `gh pr view --json title -q .title` and apply the same regex
   (normalize to uppercase). Use PR title only (not body — PR descriptions
   routinely mention multiple related tickets).
3. **No match:** Set TICKET_ID to null and warn: "No ticket ID found. Running
   without ticket context."

## PR Number Auto-Extraction

When `/review:claude` is invoked without an explicit PR number:

```bash
gh pr view --json number -q .number
```

- Success (exit 0 + numeric output): use as PR_NUMBER
- Failure: HALT with a message directing the user to push the branch and open a
  PR, or pass the PR number explicitly.
