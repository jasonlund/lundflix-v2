---
name: worktree:up
description: Take a Linear ticket to a provisioned LaborForest worktree in one invocation — derive the branch, add the workspace, register it in Solo, run the `up` workflow, and read the verdict off the run log.
---

# Worktree Up

One invocation from ticket to running workspace:
**derive branch → `add-workspace` → register in Solo → `run-workflow up` → verify from
the run log.**

## Input

- **`FLIX-NNN`** — the ticket the branch is for. Several tickets: `FLIX-301,FLIX-302`.
- **A bare branch name** — the fallback for a branch that already exists.
- **`--workspace <name>`** — the Solo workspace to register under. Default `lundflix`.

```
/worktree:up FLIX-303
/worktree:up FLIX-301,FLIX-302
/worktree:up flix-303-consolidate-the-lab
```

## Fixed paths

| Thing | Value |
| --- | --- |
| primary checkout | `~/Sites/lundflix-v2` |
| worktree | `~/Sites/lundflix-v2-<branch>` |
| run logs | `<worktree>/.laborforest/ignored/logs/` |

Run every `php artisan` call below **from the primary checkout**: a fresh worktree has
no `vendor/` until the workflow's Composer step succeeds, so artisan cannot boot there.

---

## Phase 0: Preconditions

1. **`mcp__laborforest__*` tools are bound.** Absent → HALT, say the LaborForest MCP is
   not bound in this session, and point the user at README's *Fallback: when the MCP
   doesn't answer* for the by-hand path. Guessing at the GUI is worse than stopping.
2. `mcp__laborforest__find-project-by-path` with `~/Sites/lundflix-v2` → the project
   `uuid` the later calls take.

---

## Phase 1: Derive the branch

**Ticket id input** — the branch is computed, never written by hand:

1. `mcp__linear-server__get_issue` for each id. The **first** id's title is the branch
   title; the ids go in comma-separated.
2. **Strip the title to `[A-Za-z0-9 ]` before you substitute it.** An apostrophe —
   `Fix the reviewer's comment bar` — closes the single quote, bash never runs the
   command, and `$BRANCH` is empty for the worktree path and `add-workspace` below.
   `Str::slug` discards every character outside that set anyway, so the branch is
   identical either way.
3. Capture the branch, title sanitized:
   ```bash
   BRANCH=$(php artisan lf:branch-name FLIX-301,FLIX-302 'Fix the reviewers comment bar')
   ```
   `lf:branch-name` prints the bare branch and nothing else, so `$(…)` captures it
   exactly. It owns the shape — the ids, then a title slug of at most 20 characters cut
   at a word boundary — so take its answer verbatim. The 40-character trim is a separate
   downstream guard on the *workspace* slug, not this budget. Deriving a name in prose
   is what this command exists to stop.

**Bare branch name input** — use it as given and skip the derivation.

The worktree is `~/Sites/lundflix-v2-$BRANCH`.

---

## Phase 2: Resume or create

Read `laborforest://projects/{uuid}/workspaces` and find the workspace whose branch is
`$BRANCH`. Act on its `status`:

| State | Do |
| --- | --- |
| no workspace | `mcp__laborforest__add-workspace(uuid: <project uuid>, branch: $BRANCH, base_branch: "main")`, then Phase 3 |
| `suspended` | Phase 3 — this is the state `up` runs from, so resume |
| `ready` | already provisioned. Report its site and path, and STOP: `up` requires `suspended` |
| `error` | STOP. Report the last run log's failing step and the override call from Phase 6a — leave clearing the status to the user |

---

## Phase 3: Register in Solo

Register **before** running the workflow. `solo.yml` is committed, so it is present the
moment the worktree exists, and an `up` that fails then leaves a usable Solo project to
repair from.

1. `mcp__solo__list_workspaces` → the id of the workspace named `lundflix` (or the
   `--workspace` value).
2. **That name resolves to nothing → HALT and list the workspace names that do exist.**
   Workspace assignment is create-time only, so a project created in the wrong one
   cannot be moved afterwards.
3. `mcp__solo__list_projects` returns a bare array; find the entry whose `path` is the
   worktree. **One exists → skip creation**, report `Solo: project already registered
   ({branch})` and go to Phase 4. A retry after Phase 6a re-enters here with that
   registration still in place, and a second `create_project` for the same path either
   errors out — halting before the workflow runs — or leaves a duplicate.
4. `mcp__solo__create_project(path: <worktree>, name: $BRANCH, workspace_id: <resolved id>)`.

---

## Phase 4: Run the workflow

```
mcp__laborforest__run-workflow(path: <worktree>, workflow: "up")
```

It returns a run id and dispatches — the workflow then runs asynchronously inside the
app, so the return says nothing about the outcome. **The run log is the verdict.**

`up` takes about **4 minutes**. Wait the full 4 minutes before the first read; reading
at 30 seconds finds the previous run's log or none at all.

---

## Phase 5: Read the verdict

```bash
php artisan lf:run-log up --dir <worktree>/.laborforest/ignored/logs
```

Exit code is the answer: `0` succeeded, non-zero did not. The command names the failing
step and prints any orphan lines a step reported.

`No up run log …` is a read that landed early rather than a failed run — the 4 minutes
is an estimate. Wait another minute and read again before reporting a failure.

---

## Phase 6a: Failed run — report and stop

**Stop here and hand the repair to the user.** Repairs are heterogeneous — a stale local
`main`, a missing `vendor/`, MySQL down — so an unprompted retry spends another 4
minutes to produce an identical-looking second failure.

```
❌ up failed at '{step name}'

{step output}

Workspace: {worktree}  ·  status: error
Solo:      project registered ({branch})

Fixing the cause is not enough to retry — a failed run leaves the workspace in `error`,
and only `ready`/`suspended` can launch a workflow. Clear it with:
  mcp__laborforest__override-workspace-status(path: "{worktree}", status: "suspended")
```

---

## Phase 6b: Success report

The log's **Derive workspace env values** step carries the site, database and path —
read all three off it rather than reconstructing them.

```
✅ {branch} is up

Site:      {site URL}
Database:  {database name}
Path:      {worktree}
Solo:      registered in workspace "{workspace}"

One step left, and only you can take it: `solo.yml` commands start **untrusted**, so
every process sits stopped — `npm:dev` included, despite its `auto_start: true`. Trust
them in Solo's UI and the dev server starts.
```

---

## Notes

- **User-invoked only.** This creates a database and a Herd site; it fires on your word
  alone.
- Sources this command drives without restating: the steps in
  `.laborforest/workflows/up.yaml`, README's by-hand fallback and its MCP tool table.

$ARGUMENTS
