# Domain Docs

How the engineering skills should consume this repo's domain documentation when exploring the codebase.

## Before exploring, read these

- **`CONTEXT.md`** at the repo root.
- **`docs/adr/`**: read ADRs that touch the area you're about to work in.

If any of these files don't exist, **proceed silently**. Don't flag their absence; don't suggest creating them upfront. The `mattpocock-skills:domain-modeling` skill (reached via `mattpocock-skills:grill-with-docs` and `mattpocock-skills:improve-codebase-architecture`) creates them lazily when terms or decisions actually get resolved.

## File structure

This is a single-context repo: one `CONTEXT.md` and one `docs/adr/` at the root.

```
/
├── CONTEXT.md
├── docs/
│   ├── adr/
│   │   ├── 0001-refactor-stays-in-the-tdd-loop.md
│   │   └── 0002-database-assertions-verify-ingest-behavior.md
│   └── agents/                         ← this config
└── app/Domains/
```

Note that `app/Domains/{Domain}/` are DDD bounded contexts with their own
`GUIDELINES.md`, but domain docs stay centralised at the root: `CONTEXT.md` is
the one glossary, `docs/adr/` the one decision log.

## Use the glossary's vocabulary

When your output names a domain concept (in an issue title, a refactor proposal, a hypothesis, a test name), use the term as defined in `CONTEXT.md`. Don't drift to synonyms the glossary explicitly avoids.

If the concept you need isn't in the glossary yet, that's a signal: either you're inventing language the project doesn't use (reconsider) or there's a real gap (note it for `mattpocock-skills:domain-modeling`).

## Flag ADR conflicts

If your output contradicts an existing ADR, surface it explicitly rather than silently overriding:

> _Contradicts ADR-0007 (event-sourced orders), but worth reopening because…_
