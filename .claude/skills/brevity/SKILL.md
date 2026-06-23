---
name: brevity
description: >-
  Trim a file, comment, PHP docblock, or agent-instruction file to the right
  altitude — the smallest set of high-signal tokens that fully specifies
  behavior. Use when asked to make something less verbose, prune comments or
  docblocks, tighten CLAUDE.md / project.md / a SKILL.md / a domain GUIDELINES
  file, or review writing for brevity. Audits and rewrites; it never changes
  code behavior.
---

# Brevity

Claude (and verbose docs) waste context. An LLM has a finite **attention
budget**: recall and instruction-adherence degrade as context fills. Every
always-loaded token is paid every turn; every docblock is paid every time its
file is read. The job: cut the noise, keep the signal.

## Core principle

Find the **smallest set of high-signal tokens that fully specifies the desired
behavior**.

- **"Minimal ≠ short."** Sufficiency at the right altitude — not raw shortness.
  Over-pruning that drops a real constraint is a failure, same as bloat.
- **Judge by signal, not length.** No token/line caps. The test for any unit:
  *"Would removing this cause a competent agent or dev to make a mistake?"* No →
  cut. Yes → keep.
- **Flag, don't silently delete** borderline cases — surface them in the diff
  for a human call.

## Activation

**Use for**
- Code comments and PHP docblocks (`app/**`, `resources/js/**`).
- Agent-instruction files: `.ai/guidelines/*.md`, `.claude/skills/**/SKILL.md`,
  domain `GUIDELINES.md`, `~/.claude/*.md`.

**Do not use for**
- Renaming, refactoring, or any behavior change (that's not brevity).
- The generated `<laravel-boost-guidelines>` block in `CLAUDE.md`/`AGENTS.md` —
  it is generated. Edit the source (`.ai/guidelines/project.md`) and regen with
  `php artisan boost:install --guidelines`.

## Workflow

1. **Read** the target in full.
2. **Classify** each unit: code comment / PHP docblock / instruction line.
3. **Apply the checklist** below per unit. Borderline → flag, don't delete.
4. **For instruction/doc files**, also reorder: critical rules to top/bottom.
5. **Show the diff** and the kept-constraint list. Make no behavior change.

## Checklist

### Universal (any prose)
- Inferable from the code in ~20 min by a senior dev → **cut**.
- Restates what the code, types, or a passing test already says → **cut**.
- Captures a non-obvious **why**, gotcha, or contract a reader can't derive →
  **keep**.
- Dropping it would cause a mistake → **keep** (this overrides "cut").

### PHP docblocks
- **KEEP** — type info PHP can't express natively: `@param array<int, array{...}>`,
  `@return list<string>`, generics (`@template`, `@param Builder<Movie>`),
  `@throws`. (Feeds Larastan + IDE — high signal.) Plus genuine "why" prose.
- **CUT** — a summary line that restates the method name ("Create a new user."
  over `createUser()`); `@param string $name` that adds nothing past the native
  hint; boilerplate `@var string $signature` framework stubs; obvious
  `@return void` / `@return self`.

### Instruction & doc files (project.md, SKILL.md, GUIDELINES.md, ~/.claude/*.md)
- **Position matters here** (lost-in-the-middle): put the most critical, must-
  follow rules at the **top or bottom**, never buried mid-file.
- **Progressive disclosure**: keep the always-loaded core lean; push secondary
  detail into a separate file the agent reads **on demand**.
- **`@import` saves nothing** — `@path`/`@import` load at launch like inline
  text. Splitting reduces context only when the split-out file is *read on
  demand*, not imported.

## Verify

- Every constraint on the kept-list survives the rewrite.
- No behavior change (for code files: a follow-up `php artisan test` stays green).
- Run the "minimal ≠ short" self-check: did any cut remove a real constraint? If
  unsure, it should have been flagged, not cut.

See `examples.md` for before/after cases.
