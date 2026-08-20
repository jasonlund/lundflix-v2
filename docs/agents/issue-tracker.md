# Issue tracker: Linear

Issues and specs live in Linear, team `lundflix` (identifiers `FLIX-123`). **Every
operation goes through the `mcp__linear-server__*` MCP tools** — never the `gh`
CLI, never a raw API token. The GitHub remote hosts code and PRs only; GitHub
Issues are unused.

The `## Linear (issue tracking)` section of the repo guidelines is authoritative
and outranks anything here; this file only translates it into the operations the
engineering skills name.

## Conventions

- **Create an issue**: `save_issue(team: "lundflix", title: "…", description: "…")`
  with no `id`. Markdown body, literal newlines — never escape sequences.
- **Read an issue**: `get_issue(id: "FLIX-123")`; add `list_comments` when the
  discussion matters.
- **List issues**: `list_issues(team: "lundflix", state: "Todo", label: "…",
  fields: ["id","title","status","labels","assignee"])`.
- **Update an issue**: `save_issue(id: "FLIX-123", …)`. Prefer the `patch` ops for
  surgical body edits over resending a whole description.
- **Record progress, plans, results, deviations in the description — never a
  comment.** Append or replace the body so the ticket stays the single source of
  truth. This overrides any skill instruction to "comment on the issue" with a
  record of work. The one exception is a genuine back-and-forth with a reporter
  (`mattpocock-skills:triage`'s needs-info exchange): that is dialogue, not record,
  so `save_comment` is correct there and carries triage's AI disclaimer.
- **Labels**: `save_issue(id, labels: [...])` **replaces the entire set** — read the
  current labels with `get_issue` first and resend the ones you are keeping.
  Create a missing label with `create_issue_label`.
- **Close**: `save_issue(id: "FLIX-123", state: "Done")`. Lifecycle is
  `Backlog < Todo < In Progress < In Review < Done`, plus `Canceled` / `Duplicate`;
  moves are forward-only and mostly automatic (see the lifecycle table in the repo
  guidelines). Never touch a `Canceled` or `Duplicate` ticket.
- **Branch ↔ ticket**: a branch name carries every ticket id with the owner prefix
  dropped, ≤40 chars (`flix-277-adopt-ai-hero-skills`). Resolve the ticket by
  upper-casing the leading `flix-NNN`.

## Pull requests as a triage surface

**PRs as a request surface: no.** _(Set to `yes` if external PRs should enter the
triage queue; `mattpocock-skills:triage` reads this flag.)_

PRs live on GitHub and issues live in Linear, so there is no shared number space:
a bare `#42` is always a GitHub PR, `FLIX-42` always a Linear issue.

## When a skill says "publish to the issue tracker"

Create a Linear issue on team `lundflix` with `save_issue`.

## When a skill says "fetch the relevant ticket"

`get_issue(id: "FLIX-123")`. Given only a branch or PR, extract the id per
**Branch ↔ ticket** above.

## Wayfinding operations

Used by `mattpocock-skills:wayfinder`. The **map** is a Linear issue; its tickets
are its sub-issues.

- **Map**: `save_issue(team: "lundflix", title: …, labels: ["wayfinder:map"])`,
  holding the Notes / Decisions-so-far / Fog body. Create the label with
  `create_issue_label` first if it does not exist.
- **Child ticket**: `save_issue(team: "lundflix", parentId: "FLIX-<map>",
  labels: ["wayfinder:<type>"])` — `research` / `prototype` / `grilling` / `task`.
- **Blocking**: Linear's native relations —
  `save_issue(id: <child>, blockedBy: ["FLIX-<n>"])` (append-only; drop with
  `removeBlockedBy`). Renders in Linear's UI, so no body-convention fallback.
- **Frontier query**: `list_issues(parentId: "FLIX-<map>", fields:
  ["id","title","status","assignee"])`, keep the open ones, then drop any with an
  assignee or an unfinished blocker. Relations are not a `list_issues` field, so
  check blockers per candidate with `get_issue(id, includeRelations: true)` —
  they are omitted without that flag. First in map order wins.
- **Claim**: `save_issue(id, assignee: "me")` — the session's first write.
- **Resolve**: write the answer into the ticket body, `save_issue(id, state:
  "Done")`, then append a context pointer to the map's Decisions-so-far.
