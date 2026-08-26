# Phase boundaries

A **phase** is a chunk of work inside a session: the grilling, the implementation,
the QA. It ends when you think *"ok, we're done with that."* The **boundary** is the
gap between two, and it is the only place this decision belongs — mid-phase, either
continue or split what's left into subagents.

Work the tree top to bottom. **First yes wins.**

**1. Can you continue in this session?** Yes when the next phase needs this one as a
**primary source**, or enough window remains for it to fit. Grilling →
implementation is the standard yes: implementation wants the reasoning verbatim, not
a summary of it. Continue costs nothing and loses nothing, so rule it out first.

**2. Is this context irrelevant to what comes next?** Then `/clear` — the cheapest
move on the board, and the old session stays resumable. Getting it wrong is one-way:
clear a *relevant* context and the **why** behind the work is gone, and reading the
diff back never returns it.

**3. Do you need to hand off?** Only for a **new harness**, a **new directory or
repo**, a **colleague**, or forking a side task found **mid-phase**. What a handoff
buys is portability. Nothing travelling means no handoff. In Conductor, a new
workspace is the usual answer here.

**4. Can the task run AFK?** Scoped tightly enough to need no steering → a
**subagent**, leaving this session untouched. Automated review is the standard case.

**5. Otherwise `/compact`.** Relevant context, same harness, same directory, and you
stay in the loop. Pass it an instruction (`/compact we're going to QA this area`) so
the summary keeps what the next phase needs.

`/compact` is the **default, not the first reach** — the four questions above it are
cheaper or more precise. Every move except Continue turns a **primary source** into a
**secondary** one: full-but-noisy becomes lossy-but-roomy. That trade is why question
1 comes first. Compacting *mid*-phase loses the thread.

These are judgement calls. The value is in asking them in order, at the boundary.

**Source:** adapted from `mattpocock-skills:ask-matt`'s `PHASE-BOUNDARIES.md`.
