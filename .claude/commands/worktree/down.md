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

`suspended` is not one state — read `php artisan lf:run-log down --dir
<worktree>/.laborforest/ignored/logs` to tell its three apart. A `down` whose drop step
failed still exits 0 and still ends `suspended` (`ending_status: suspended`), so the one
workspace whose database survived teardown lands here too.

| State | Do |
| --- | --- |
| `ready` | Phase 2 |
| `suspended`, `No down run log …` | Never brought up: no database, no Herd site, nothing to tear down. Say that, name the GUI removal step from Phase 5, and STOP |
| `suspended`, log carries `[orphaned …]` | The last teardown left that database or site behind. `down` runs only from `ready`, so `mcp__laborforest__override-workspace-status(path: "<worktree>", status: "ready")`, then Phase 2 to retry the drop |
| `suspended`, clean log | Already down, nothing orphaned. Name the GUI removal step from Phase 5 and STOP |
| `error` | STOP. Read the verdict with `php artisan lf:run-log down --dir <worktree>/.laborforest/ignored/logs` — an aborted `down` lands in `error` too, and `lf:run-log` globs `*_{workflow}.yaml`, so asking for `up` here answers with the earlier successful provision. Fall back to `up` **only** on `No down run log …`. Report the failing step, plus `mcp__laborforest__override-workspace-status(path: "<worktree>", status: "suspended")` — leave clearing it to the user |

---

## Phase 2: Prove it is safe to abandon

**Safe = clean tree AND merged.** Shell out to git for both — LaborForest's own
`git_status` field reported `dirty` for three workspaces whose trees were clean and in
sync with origin, so read git directly.

```bash
git -C <worktree> fetch origin --prune
git -C <worktree> status --porcelain
git -C <worktree> for-each-ref --format='%(upstream:track)' refs/heads/<branch>
gh pr list --head <branch> --state merged --json number,mergedAt
```

- **Clean** — `status --porcelain` prints nothing. A `??` line counts as dirty: new
  domain files are routinely untracked, and those are exactly the ones worth keeping.
- **Merged** — the upstream reads `[gone]` **and** `gh pr list` returns a non-empty
  array. Both halves are required. A squash merge rewrites the branch into one new
  commit, so `git branch -r --contains HEAD` finds nothing and every branch commit reads
  as "ahead" — `--contains` alone calls merged work unmerged every time.
- **Ask about this branch, not this ticket.** `git log --grep=<FLIX-NNN> origin/main`
  searches every commit message in the whole history with an unanchored pattern, so
  nothing ties a hit to the branch in hand: a ticket that ships in more than one PR
  (`origin/main` already carries `FLIX-267/284:` and `FLIX-295:`) marks every later
  branch for it merged forever, and a short id matches a longer one — `FLIX-30` inside
  `FLIX-303:`. Pair either with a `[gone]` upstream and an unmerged branch passes the
  gate. `--head <branch>` cannot: `gh` is already this pipeline's tool, and it answers
  for this branch alone.
- **`gh` cannot answer** — unauthenticated, or the repo unresolvable — the merge half
  cannot be evaluated, so it counts as unmerged and the gate refuses.

### Both hold → Phase 3.

### Either fails → refuse and stop

Report which half failed and hand the decision back. Abandoning an unmerged experiment
is legitimate, so the escape hatch exists — and **only the user opens it.** Proceed on
an explicit instruction to tear down anyway, never on your own reading of the evidence.

```
🚫 Refusing to tear down {branch}

Clean tree: ❌ {N} uncommitted file(s), {M} untracked
Merged:     ✅ PR #{number}, merged {mergedAt}

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

**The two destructive steps are best-effort and exit 0 by design.** A drop or unlink that
fails reports `[orphaned …]` and exits 0, because a non-zero exit would force the
workspace to `error` and LaborForest offers **Remove** only on a suspended one — a
failing step would make the worktree permanently undeletable. So an orphaned database or
site appears in step **output**, not in the exit code. `lf:run-log` surfaces those
`[orphaned …]` lines; carry every one into the report.

**The first step, `Derive workspace env values`, is the exception — it aborts the run.**
Its failure is not tolerated on purpose: without it the drop below runs against a stale
`.env`, which after an aborted `up` still names `lundflix`, and those rows are not
restorable from the dumps. It needs `vendor/`, so on a workspace whose `vendor/` was
deleted the run stops there.

**A non-zero exit is two answers, not one — read the line before routing.**

- **`No down run log …`** is a read that landed early rather than a failed run: the log
  lands only when the run ends, and a `DROP DATABASE` plus a `herd unlink` can outlast 30
  seconds. Wait another minute and read again. Still no log → report that the run has left
  no verdict yet and hand it back; whether to wait longer or give up is the user's call,
  and Phase 3a's `Orphaned:` line would be inventing an outcome the log has not given.
- **A log naming a failing step** is that abort → Phase 3a.
- **Exit `0`** → Phase 4.

---

## Phase 3a: Aborted run — report and stop

**Stop before Phase 4.** The run aborted ahead of both destructive steps, so the database
and the Herd site are still there — deleting the Solo project now would strip the
worktree of its processes while leaving every resource this command exists to reclaim.

```
❌ down aborted at '{step name}'

{step output}

Workspace: {worktree}  ·  status: error
Orphaned:  {database}, {site URL} — the run never reached either step
Solo:      project kept
```

Fixing the cause is not enough to retry — an aborted run leaves the workspace in `error`,
which cannot launch a workflow:

```
mcp__laborforest__override-workspace-status(path: "{worktree}", status: "suspended")
```

`suspended` is the state **Remove** needs, so it is the right call when the user is
giving up on the resources. Retrying the teardown instead wants `"ready"` — the status
`down` runs from — after the cause is fixed (a missing `vendor/` → `composer install`).
Name both and let the user pick; leave the override to them.

---

## Phase 4: Delete the Solo project

Delete it without asking — it is the exact counterpart of what `/worktree:up` created,
and Solo holds no process state worth preserving for a worktree about to be removed.
Name it in the report.

1. `mcp__solo__list_projects` returns a bare array; find the entry whose `path` is the
   worktree.
2. `mcp__solo__delete_project(project_id: <id>, confirm_delete: true, confirm_stop_running: true)`.

---

## Phase 5: Success report

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
