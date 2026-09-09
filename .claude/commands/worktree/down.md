---
name: worktree:down
description: Tear a LaborForest worktree down in one invocation — prove it is safe to abandon, run the `down` workflow, delete its Solo project, and report the orphans best-effort teardown left behind. Refuses on a dirty or unmerged branch.
---

# Worktree Down

One invocation from a finished branch to a suspended workspace:
**safety gate → `run-workflow down` → delete the Solo project → report.**

Teardown drops the workspace's MySQL database and unlinks its Herd site, so the safety
gate below is the whole point of the command.

## Input

- **Nothing** — the worktree you are standing in (`git rev-parse --show-toplevel`).
- **`FLIX-NNN`** or **a branch name** — tear down that workspace instead.

```
/worktree:down
/worktree:down FLIX-303
```

Run every `php artisan` call below **from the primary checkout** (`~/Sites/lundflix-v2`),
so a worktree with a deleted `vendor/` still reports.

---

## Phase 0: Preconditions

1. **`mcp__laborforest__*` tools are bound.** Absent → HALT, say so, and point the user
   at README's *Fallback: when the MCP doesn't answer*.
2. `mcp__laborforest__find-project-by-path` with `~/Sites/lundflix-v2` → the project
   `uuid`.
3. Read `laborforest://projects/{uuid}/workspaces` and pick the workspace: by exact
   branch, or — for a ticket id — the branch that starts with the lowercased id.

---

## Phase 1: Status gate

| State | Do |
| --- | --- |
| `ready` | Phase 2 |
| `suspended` | Nothing to tear down — a workspace that was never brought up, or one already down, has no database, no Herd site and no logs. Say that, name the GUI removal step from Phase 5, and STOP |
| `error` | STOP. Report the failing step from `php artisan lf:run-log up --dir <worktree>/.laborforest/ignored/logs`, plus `mcp__laborforest__override-workspace-status(path: "<worktree>", status: "suspended")` — leave clearing it to the user |

---

## Phase 2: Prove it is safe to abandon

**Safe = clean tree AND merged.** Shell out to git for both — LaborForest's own
`git_status` field reported `dirty` for three workspaces whose trees were clean and in
sync with origin, so read git directly.

```bash
git -C <worktree> fetch origin --prune
git -C <worktree> status --porcelain
git -C <worktree> for-each-ref --format='%(upstream:track)' refs/heads/<branch>
git -C <worktree> log --oneline --grep=<FLIX-NNN> origin/main
```

- **Clean** — `status --porcelain` prints nothing. A `??` line counts as dirty: new
  domain files are routinely untracked, and those are exactly the ones worth keeping.
- **Merged** — the upstream reads `[gone]` **and** the `--grep` finds a squash commit on
  `origin/main`. Both halves are required. A squash merge rewrites the branch into one
  new commit, so `git branch -r --contains HEAD` finds nothing and every branch commit
  reads as "ahead" — `--contains` alone calls merged work unmerged every time.
- **No `FLIX-NNN` in the branch** — the merge half cannot be evaluated, so it counts as
  unmerged and the gate refuses.

### Both hold → Phase 3.

### Either fails → refuse and stop

Report which half failed and hand the decision back. Abandoning an unmerged experiment
is legitimate, so the escape hatch exists — and **only the user opens it.** Proceed on
an explicit instruction to tear down anyway, never on your own reading of the evidence.

```
🚫 Refusing to tear down {branch}

Clean tree: ❌ {N} uncommitted file(s), {M} untracked
Merged:     ✅ squash commit {sha} on origin/main

Teardown drops `{database}` and unlinks {site URL}. Tell me to tear it down anyway and
I will.
```

---

## Phase 3: Run the workflow

```
mcp__laborforest__run-workflow(path: <worktree>, workflow: "down")
```

It only dispatches; the workflow runs asynchronously. Give it ~30 seconds, then read the
verdict:

```bash
php artisan lf:run-log down --dir <worktree>/.laborforest/ignored/logs
```

**Teardown is best-effort and exits 0 by design.** A destructive step that fails reports
`[orphaned …]` and exits 0, because a non-zero exit would force the workspace to `error`
and LaborForest offers **Remove** only on a suspended one — a failing step would make
the worktree permanently undeletable. So an orphaned database or site appears in step
**output**, not in the exit code. `lf:run-log` surfaces those `[orphaned …]` lines;
carry every one into the report.

---

## Phase 4: Delete the Solo project

Delete it without asking — it is the exact counterpart of what `/worktree:up` created,
and Solo holds no process state worth preserving for a worktree about to be removed.
Name it in the report.

1. `mcp__solo__list_projects` returns a bare array; find the entry whose `path` is the
   worktree.
2. `mcp__solo__delete_project(project_id: <id>, confirm_delete: true, confirm_stop_running: true)`.

---

## Phase 5: Report

```
✅ {branch} is down  ·  status: suspended

Dropped:   {database}
Unlinked:  {site URL}
Solo:      project deleted
{every [orphaned …] line lf:run-log printed}

Removing the worktree itself stays a GUI action: LaborForest exposes no
`remove-workspace` tool (`remove-project` deletes a whole project, not one workspace),
and it offers **Remove** only once a workspace is suspended — which this run is the only
path to. Finish in the LaborForest workspace row.
```

---

## Notes

- **User-invoked only.** This drops a database; it fires on your word alone.
- Sources this command drives without restating: the steps in
  `.laborforest/workflows/down.yaml`, README's by-hand fallback and its MCP tool table.

$ARGUMENTS
