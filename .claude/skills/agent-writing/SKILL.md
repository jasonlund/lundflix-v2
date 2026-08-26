---
name: agent-writing
description: >-
  Write and tighten documents an agent consumes — a SKILL.md, a command, an agent
  definition, `.ai/guidelines/project.md`, a domain `GUIDELINES.md`, code comments
  and PHP docblocks. Use when authoring or editing any of those, when asked to make
  writing less verbose or prune comments/docblocks, or when a skill fires
  unreliably and its description needs sharpening.
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
- The code itself. This skill rewrites prose and leaves behavior as it stands: an
  edit inside a `.php` file changes the comment or docblock and leaves the code it
  annotates exactly as written. Renaming and refactoring are a separate change.
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

### Context pointers

A **context pointer** is a line held in context that names out-of-context material
and encodes the condition for reaching it. A skill's `description` is the leading
example; a line in `.ai/guidelines/project.md` naming `docs/agents/domain.md` is
the same object, and so is a "see X" in a command. Its *wording*, not its target,
decides when and how reliably the agent reaches the material. A must-have target
behind a weak pointer is a variance bug — sharpen the wording before inlining
anything.

A pointer does two jobs: say what the material is, and list the **branches** that
should trigger it. It is always loaded, so it earns harder pruning than the body:

- **Front-load the leading word.**
- **One trigger per branch.** Synonyms renaming a single branch are one branch
  written twice.
- **Cut identity the body already carries.**

### The two loads

Every document and pointer spends one of two budgets:

- **Context load** — the cost on the agent's window. An always-loaded line (a
  skill description, a `project.md` pointer) spends tokens and attention every
  turn, whether or not it fires.
- **Cognitive load** — the cost on the human: which documents exist and when to
  reach for each. The human is the index. This is **not a cost to minimise: it is
  the price of human agency** — spend it where human judgement matters, remove it
  where it does not.

Material behind a pointer escapes context load for the price of the pointer's own
line; material with no pointer rides entirely on cognitive load. So neither budget
is free, and moving a cost is not the same as removing it.

**Invocation is where the trade bites.** A model-invoked skill keeps its
description loaded every turn — permanent context load — and buys agent discovery
plus reach from other skills. A user-invoked skill (`disable-model-invocation:
true`) costs zero context and spends *your* memory instead: you become the index.
Choose model-invocation only when an agent or another skill must reach it on its
own. When user-invoked skills outgrow memory, that piled-up cognitive load is
cured by a **router** skill naming the others (`/map` here). Shared reference two
user-invoked skills both need can live in neither — with no descriptions, neither
can fire the other — so push it to a plain file both point at.

### Completion criteria

Every step ends on a condition that tells the agent it is done. Two properties make
it a lever:

- **Clarity** — can the agent tell done from not-done? A vague bound ("understanding
  reached") invites **premature completion**, with attention slipping toward *being
  done*. The visible **post-completion steps** supply the pull; the criterion's
  clarity is the resistance. Defend in order: sharpen the bound first — local and
  cheap; only when it is irreducibly fuzzy *and* you observe the rush, hide the later
  steps by splitting the sequence (below).
- **Demand** — how much it requires. "Every modified model accounted for" forces
  real legwork where "produce a change list" does not. Demand binds flat reference
  too: "every rule applied" carries an exhaustiveness bar with no steps at all.

The strongest criteria are both checkable and exhaustive.

### When to split

Splitting one document into two spends one of the two loads, so cut only where the
split earns it. Two cuts:

- **By sequence** — split a run of steps where the post-completion steps tempt the
  agent to rush the one in front of it. Keeping them out of view drives more
  legwork on the current task. **Hiding only works across a real context
  boundary** — a hand-off or a subagent dispatch. An inline call leaves the later
  steps sitting in context and clears nothing, so a "split" into a new heading or
  a second file the same agent reads buys no resistance at all. Beware the
  reverse: merging sequences exposes each step's later steps to what follows,
  inviting premature completion.
- **By invocation** — split off a model-invoked skill when a distinct leading word
  should trigger it on its own (a word you actually type), or when another skill
  must reach it. You pay permanent context load for the new always-loaded
  description, so that independent reach has to be worth it.

This is the lever that shaped the flow skills here: `tdd`,
`.claude/commands/review/process.md` and `.claude/commands/plan/run.md` each cut
their sequence at a subagent dispatch, so a phase runs with its later phases out
of context. Preserve those boundaries when editing them — collapsing a dispatch
into an inline step looks like simplification and silently removes the resistance.

## Cut

### Universal (any prose)
- Inferable from the code in ~20 minutes by a senior dev → **cut**.
- Restates what the code, types, or a passing test already says → **cut**.
- Captures a non-obvious **why**, gotcha, or contract → **keep**.
- Dropping it would cause a mistake → **keep**, overriding every cut rule.

**Bound the override before you use it.** Left unbounded it defends anything: any
duplication survives by asserting some reader would err without it, and the rule
that outranks every other rule becomes the one that is never tested. Invoking it
is an argument, so it has to be checkable — name all three:

1. **Which reader** — the agent or human running *which* document, at which step.
2. **On which branch** — the concrete path through the work where the line is
   reached, not "someone, someday".
3. **What mistake** — the specific wrong action, and why the code, a test, or the
   other copy fails to prevent it.

Cannot fill all three, or the answer is "it is useful context" → the ordinary cut
rules stand. A named reader on a named branch is also the fix's specification:
often the cheaper answer is a pointer from that branch to the single source
below, not a second copy.

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
   completion criterion; re-read every context pointer — the description, and each
   line naming another doc — for branch coverage.
5. **Reorder** so critical rules sit at the top or bottom.
6. **Show the diff** and the kept-constraint list.

## Verify

- Every constraint on the kept-list survives the rewrite.
- No behavior change — for code files, `php artisan test` stays green.
- Run the minimal-≠-short self-check: did any cut remove a real constraint? Unsure
  means it should have been flagged, not cut.

See `examples.md` for before/after cases.

**Source:** the Shape half is adapted from `mattpocock-skills:writing-for-agents`
(with its `SKILL-MECHANICS.md` for the invocation cut and routers). Offer to walk
through the original when applying these levers.
