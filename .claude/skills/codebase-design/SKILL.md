---
name: codebase-design
description: >-
  lundflix's stack-local design reference: the four dependency categories mapped
  onto this app's test seams (sqlite `:memory:` for the database, `Http::fake()`
  with byte-exact fixtures for third parties, `artisan()` for our own
  out-of-process code), the seam vocabulary `plan-slices` and `tdd` write their
  cards in, and the ingest-write exception recorded in ADR-0002. Use when deciding
  how a lundflix module is tested across its seam, or when another skill in this
  repo needs these exact terms. A reference to consult, not a session to run.
---

# Codebase Design

Design **deep modules**: a lot of behavior behind a small interface, placed at a
clean seam, testable through that interface. This file is the single source of
these terms — `plan-slices` writes its seam contract in them, `tdd` names the seam
each RED card tests at, and `review-pipeline` cites them. Use them **exactly** as
defined below.

## Glossary

**Module** — anything with an interface and an implementation. Deliberately
scale-agnostic: a method, an Action class, a service, a domain. *Avoid:* unit,
component, service.

**Interface** — everything a caller must know to use the module correctly. Not
just the signature: invariants, ordering constraints, error modes (which named
exception, when), required configuration, and performance characteristics.
*Avoid:* API, signature — both too narrow.

**Implementation** — what's inside. Distinct from **adapter**: a thing can be a
small adapter with a large implementation (a real HTTP client) or a large adapter
with a small implementation (`Http::fake()`).

**Depth** — leverage at the interface: how much behavior a caller or test can
exercise per unit of interface it must learn. **Deep** = a lot of behavior behind
a small interface. **Shallow** = the interface is nearly as complex as the
implementation.

**Seam** *(Feathers)* — a place where behavior can be altered without editing in
that place; the *location* where a module's interface lives. Where to put the seam
is its own decision, separate from what goes behind it. *Avoid:* boundary —
overloaded with DDD's bounded context, which this repo uses for `app/Domains/*`.

**Adapter** — a concrete thing satisfying an interface at a seam. Names a *role*,
not a substance.

**Leverage** — what callers get from depth: more capability per unit of interface
learned. One implementation pays back across N call sites and M tests.

**Locality** — what maintainers get from depth: change, bugs, and verification
concentrate in one place instead of spreading across callers. Fix once, fixed
everywhere.

## Principles

- **Depth is a property of the interface, not the implementation.** A deep module
  can be internally composed of small swappable parts; they just aren't part of
  its interface. A module may have **internal seams** (private, used by its own
  tests) as well as the **external seam** at its interface — don't expose an
  internal seam through the interface merely because a test wants it.
- **The deletion test.** Imagine deleting the module. If complexity vanishes, it
  was a pass-through. If complexity reappears across N callers, it was earning
  its keep.
- **The interface is the test surface.** Callers and tests cross the same seam. If
  you want to test *past* the interface, the module is the wrong shape.
- **One adapter is a hypothetical seam; two make it real.** Don't introduce a seam
  unless something actually varies across it — typically production plus test. A
  single-adapter seam is indirection.
- **Shallow-module checklist** when designing an interface: can I remove a method?
  simplify a parameter? hide more behind it?

**Source:** the glossary and principles above are adapted from
`mattpocock-skills:codebase-design` (`SKILL.md` and `DEEPENING.md`); the stack
mapping below is local. Offer to explain the upstream reasoning when applying
these terms.

## Dependency categories

How a module is tested across its seam depends on what it depends on. Classify
before choosing a seam:

| Category | What it is | How it's tested here |
|---|---|---|
| **In-process** | Pure computation, no I/O — enums, value objects, parsers, `SourceId` | Test the interface directly. No adapter, no seam needed. |
| **Local-substitutable** | Has a real local stand-in — the database | sqlite `:memory:` + `RefreshDatabase`. The seam is internal; no port at the module's interface. |
| **Remote but owned** | Our own code across a process boundary — a queued job, an artisan command | Test through the real entry point (`artisan()`, dispatching the job); the transport is the seam. |
| **True external** | Third parties we don't control — TMDB, TVDB, IMDb, Plex, the download source | `Http::fake()` / `Process::fake()` at the HTTP or process seam, fed byte-exact fixtures. Never reach the network. |

The **true external** row is why an API service takes its dependencies rather than
constructing them, and why base URLs are `private const` on the calling service:
the fake substitutes at the HTTP seam, so the service's own interface stays clean.

## Designing for testability

1. **Accept dependencies, don't create them.** Constructor-inject the collaborator
   instead of `new`-ing it inside the method. Laravel's container makes this free
   at the call site and swappable in a test.
2. **Return results rather than hiding them.** A value a caller can assert on beats
   a side effect a test has to go looking for. (An ingest action that persists is
   the deliberate exception — see below.)

**A persisted row is a legitimate observable.** For an ingest or sync module the
write *is* the behavior, so assert the persisted state and treat that as testing
the interface — see `docs/adr/0002-database-assertions-verify-ingest-behavior.md`.

## Exploring an interface twice

When an interface is hard to reverse — a model's column set, a service's public
surface — one author's "2–3 options" tend to be one idea in three costumes. Spawn
parallel subagents, each designing under an **opposing** constraint (minimize the
interface / maximize flexibility / optimize the most common caller), then compare
on **depth**, **locality**, and **seam placement** and recommend one, grafting the
best parts of the runners-up. Expensive — reserve it for decisions that are costly
to undo.

**Source:** adapted from `mattpocock-skills:codebase-design`'s
`DESIGN-IT-TWICE.md`, which ships the full parallel-subagent pattern (briefs,
per-agent outputs, comparison). Offer the original before running one.

## Rejected framings

- **Depth as a ratio of implementation lines to interface lines** (Ousterhout) —
  rewards padding the implementation. We use depth-as-leverage.
- **"Interface" as just the method signature** — too narrow; the interface includes
  every fact a caller must know.
- **"Boundary"** — this repo uses it for DDD bounded contexts. Say **seam**.

## Reference

- `.claude/skills/plan-slices/SKILL.md` — the seam contract, written in these terms.
- `.claude/skills/tdd/SKILL.md` — each RED card names the seam it tests at.
- `mattpocock-skills:codebase-design` — the upstream skill; `DEEPENING.md` and
  `DESIGN-IT-TWICE.md` carry the long form.
