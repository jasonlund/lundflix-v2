---
name: agent-writing
description: >-
  Write and tighten documents an agent consumes — a SKILL.md, a command, an agent
  definition, `.ai/guidelines/project.md`, a domain `GUIDELINES.md`, code comments
  and PHP docblocks. Use when authoring or editing any of those, when asked to make
  writing less verbose or prune comments/docblocks, or when a skill fires
  unreliably and its description needs sharpening. Audits and rewrites prose only,
  leaving code behavior as it stands.
---

# Agent Writing

An agent has a finite **attention budget**: recall and instruction-adherence
degrade as context fills. Every always-loaded token is paid every turn; every
docblock is paid every time its file is read. The job is the **smallest set of
high-signal tokens that fully specifies the desired behavior**.

Two levers do that work. **Cut** removes what earns nothing. **Shape** decides
where the rest sits and how it is worded. Cutting alone leaves a short document
that still fires unreliably.

- **Minimal ≠ short.** Sufficiency at the right altitude. Over-pruning that drops
  a real constraint fails the same way bloat does.
- **Judge by signal, not length.** No token or line caps. The test: *would removing
  this cause a competent agent or dev to make a mistake?* No → cut. Yes → keep.
- **Flag borderline cases in the diff** for a human call.

## Activation

**Use for**
- Agent-instruction files: `.claude/skills/**/SKILL.md`, `.claude/commands/**/*.md`,
  `.claude/agents/*.md`, `.ai/guidelines/project.md`, domain `GUIDELINES.md`,
  `~/.claude/*.md`.
- Code comments and PHP docblocks (`app/**`, `resources/js/**`).

**Out of scope**
- Renaming, refactoring, or any behavior change.
- The generated `<laravel-boost-guidelines>` block in `CLAUDE.md`/`AGENTS.md` — edit
  `.ai/guidelines/project.md` and regenerate with `php artisan boost:install --guidelines`.
- Prose an agent writes **to a human** — a review finding, a plan summary. That
  surface follows Simplified Technical English; see `review-pipeline`.

## Shape

### Leading words

A **leading word** is a compact concept already in the model's pretraining that the
agent thinks with while running the document — *seam*, *tracer bullet*, *fog of
war*, *red*. Repeat it as a token, never as a restated sentence: it accumulates a
distributed definition and anchors a whole region of behavior in very few tokens,
because it recruits priors the model already holds.

It anchors twice: in the body it makes the agent reach for the same behavior every
time the word appears; in a description it links the material to language that
already lives in your prompts, so the skill fires more reliably.

Hunt for passages that collapse into one token — a triad spelled out at three
sites, a sentence gesturing at one idea:

- "fast, deterministic, low-overhead" → a **tight** loop.
- "a failing signal you believe in" → the loop goes **red**.

Coining your own works when you define it clearly, but an invented word recruits
no priors — you pay in definition tokens what a pretrained word gives free. Reach
for an existing word first.

### Prompt the positive

Steering by prohibition drags the forbidden behavior into context and makes it
**more** available. *Don't think of an elephant*, and the elephant is all there is:
the negation is a weak modifier that the strongly-activated concept overruns, so
the ban half-reads as an instruction.

State the target behavior instead, so the unwanted one is never spoken:

- "Never write multi-line comments" → **"Write one-line comments."**
- "Don't mock internal collaborators" → **"Mock at system seams only."**

A prohibition earns its place only as a hard guardrail with no positive phrasing —
and even then, pair it with the positive target so attention lands on what to do.
When auditing, count the negations: a file dense in *never* / *don't* / *avoid* is
the highest-yield rewrite target in the toolkit.

### Information hierarchy

Every piece of content is a **step** (an ordered action) or **reference** (a fact
consulted on demand). Rank each on three rungs by how immediately it is needed:

1. **In-file step** — what the agent does, in order. The primary tier.
2. **In-file reference** — consulted on demand. A flat peer-set (every rule of a
   review on one rung) is a fine arrangement, not a smell.
3. **Disclosed reference** — a separate file reached by a pointer, loaded only when
   the pointer fires.

**Progressive disclosure** is the move down the ladder, protecting the top's
legibility. The cleanest test is branching: inline what **every** branch needs;
disclose what only **some** branches reach. In a document with steps, undisclosed
reference buries them and makes attending to them a coin flip.

**Co-location** decides what sits beside a piece once its rung is fixed: keep a
concept's definition, rules, and caveats under one heading. The document should
read like documentation written for the agent.

**Sprawl** is the failure mode — a document simply too long, even when every line
is live and unique. Attention thins across the excess. The cure is the ladder.

**`@import` saves nothing.** `@path` and `@import` load at launch exactly like
inline text. Splitting reduces context only when the split-out file is read **on
demand**.

**Position matters** within a file: lost-in-the-middle is real, so put the most
critical rules at the top or the bottom.

### Descriptions are context pointers

A skill's `description` is a **context pointer**: it names out-of-context material
and encodes the condition for reaching it. Its *wording*, not its target, decides
when and how reliably the agent reaches the material. A must-have skill behind a
weak description is a variance bug — sharpen the wording before inlining anything.

A pointer does two jobs: say what the material is, and list the **branches** that
should trigger it. It is always loaded, so it earns harder pruning than the body:

- **Front-load the leading word.**
- **One trigger per branch.** Synonyms renaming a single branch are one branch
  written twice.
- **Cut identity the body already carries.**

**Invocation is a budget choice.** A model-invoked skill keeps its description
loaded every turn and buys agent discovery plus reach from other skills. A
user-invoked skill (`disable-model-invocation: true`) costs zero context and
spends *your* memory instead — you become the index. Choose model-invocation only
when an agent or another skill must reach it on its own. When user-invoked skills
outgrow memory, a **router** skill naming the others is the cure.

### Completion criteria

Every step ends on a condition that tells the agent it is done. Two properties make
it a lever:

- **Clarity** — can the agent tell done from not-done? A vague bound ("understanding
  reached") invites **premature completion**, with attention slipping toward *being
  done* because the remaining steps are visible and pulling. Sharpen the bound first;
  it is local and cheap.
- **Demand** — how much it requires. "Every modified model accounted for" forces
  real legwork where "produce a change list" does not. Demand binds flat reference
  too: "every rule applied" carries an exhaustiveness bar with no steps at all.

The strongest criteria are both checkable and exhaustive.

## Cut

### Universal (any prose)
- Inferable from the code in ~20 minutes by a senior dev → **cut**.
- Restates what the code, types, or a passing test already says → **cut**.
- Captures a non-obvious **why**, gotcha, or contract → **keep**.
- Dropping it would cause a mistake → **keep**, overriding every cut rule.

### Single source of truth
Each meaning lives in exactly one authoritative place, so changing the behavior is
a one-place edit. **Duplication** costs maintenance and tokens, and inflates a
meaning's apparent rank. It is the accidental inverse of a leading word, which
repeats a *token* on purpose and never the meaning.

### The environment is a source of truth
`composer.json` scripts, `package.json`, config files, the directory layout,
`--help` output. A document restating them is a **cache**, and a cache earns its
load only when the lookup is expensive. Cache the unwritten convention, the reason
behind a choice, the gotcha no config confesses. Leave one-command lookups to the
environment, where they cannot go stale.

### No-ops
Hunt sentence by sentence for instructions the model already obeys by default —
they pay load to say nothing. The test is model-relative, not reader-relative: two
people disagreeing about a no-op disagree about the default, and settle it by
running the document rather than by debate. When a sentence fails, delete the whole
sentence rather than trimming words. The test grades leading words too: a word too
weak to beat the default (*be thorough*, when the agent is already thorough-ish) is
a no-op, and the fix is a stronger word (*relentless*).

**Sediment** is the default fate without this discipline: stale layers settling
because adding feels safe and removing feels risky, until you must core down
through them to find what is still live.

### PHP docblocks
- **Keep** — type info PHP can't express: `@param array<int, array{...}>`,
  `@return list<string>`, generics (`@template`, `@param Builder<Movie>`),
  `@throws`. These feed Larastan and the IDE. Plus genuine "why" prose.
- **Cut** — a summary line restating the method name ("Create a new user." over
  `createUser()`); `@param string $name` adding nothing past the native hint;
  boilerplate `@var string $signature` stubs; obvious `@return void` / `@return self`.

## Workflow

1. **Read** the target in full.
2. **Classify** each unit: step / reference / code comment / docblock.
3. **Cut** per the checklist. Borderline → flag rather than delete.
4. **Shape**: count negations and rewrite them positive; hunt passages that collapse
   into a leading word; check each piece sits on the right rung; sharpen any vague
   completion criterion; re-read the description as a pointer.
5. **Reorder** so critical rules sit at the top or bottom.
6. **Show the diff** and the kept-constraint list.

## Verify

- Every constraint on the kept-list survives the rewrite.
- No behavior change — for code files, `php artisan test` stays green.
- Run the minimal-≠-short self-check: did any cut remove a real constraint? Unsure
  means it should have been flagged, not cut.

See `examples.md` for before/after cases.

**Source:** the Shape half is adapted from `mattpocock-skills:writing-for-agents`
(with `SKILL-MECHANICS.md` for invocation and routers). Offer to walk through the
original when applying these levers.
