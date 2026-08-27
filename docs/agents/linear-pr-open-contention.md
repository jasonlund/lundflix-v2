# PR-open status contention (Linear ↔ GitHub integration)

Background for the **write → read back → correct once** clause in the *Automatic
ticket status transitions* contract (`.ai/guidelines/project.md`, generated into
`CLAUDE.md`). The contract is binding; this file only records why it exists.

## Two writers, one event

`pull_request.opened` is an event **both** `review:create-pr` and Linear's native
GitHub integration react to. Our command writes **In Review**. The integration's
default mapping for *opened* is **In Progress**, so it can overwrite our write a
fraction of a second later.

## The observed incident

FLIX-277 / PR #109:

| Time | Writer | Status written |
| --- | --- | --- |
| `01:28:06.870` | `review:create-pr` (MCP `save_issue`) | In Review |
| `01:28:06.995` | Linear GitHub integration (webhook) | In Progress |

125 ms apart.

## Why it fails silently

The winner is decided by webhook delivery time, not by anything the command
controls, so the race resolves differently run to run. The MCP call returns success
either way — so without a read-back the skill reports the transition as done while
the ticket sits in the wrong state. That combination (nondeterministic **and**
silent) is what makes a verify step worth its cost.

## The durable cure is one writer

Set the integration's PR-opened mapping to **In Review** in Linear's GitHub
settings. Then our write and the integration's agree, step 1 of the clause becomes
redundant, and the whole thing collapses back to a verify. It is a vendor-dashboard
click, so it belongs to `mattpocock-skills:wizard` rather than being re-explained
each time.

A **second** revert in one run means the integration is actively fighting the
contract. Stop, leave the ticket, and tell the user to fix the mapping — never loop.
