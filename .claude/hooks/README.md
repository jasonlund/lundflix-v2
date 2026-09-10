# Hooks

Four hooks, all wired in `.claude/settings.json`, in three families:

- **Two `UserPromptSubmit` skill routers** nudge the agent into the right skill
  before it starts editing. Both **only remind** — they print to stdout, which
  Claude Code adds as context, and the turn proceeds either way.
- **One `PreToolUse(Bash)` guard** decides whether a command runs at all. It
  **exits 2 to block**: the tool call is refused and the message on stderr goes
  back to the agent as the reason.
- **One `PreToolUse(Agent)` guard** decides whether a subagent dispatch runs. It
  **denies by decision**: a JSON `permissionDecision` on stdout, exit 0 in every
  path.

| Hook | Event | Fires on | Effect |
|---|---|---|---|
| `feedback-router-reminder.sh` | `UserPromptSubmit` | Feedback / change request on existing work (review comment, bug report, "remove/rename/change X", a Conductor diff-comment attachment — LaborForest and Solo have no diff-comment equivalent) | Reminds → `tdd-feedback` skill |
| `tdd-activation-reminder.sh` | `UserPromptSubmit` | New feature / implementation work ("implement", "build", "add a…", "create a…", "new endpoint/page/action") | Reminds → `tdd` skill |
| `block-destructive-git.sh` | `PreToolUse` (Bash) | A git command that destroys uncommitted work with no undo (`reset --hard/--merge/--keep`, `clean -f`, `branch -D`, `checkout .`, `restore .`, `stash drop/clear`) | Exits 2 → blocks the call; asks for a recoverable route instead |
| `no-background-gated-subagents.js` | `PreToolUse` (Agent) | A `run_in_background: true` dispatch of a subagent whose dispatcher blocks on a gate — `tdd-test-writer`, `tdd-implementer`, `tdd-refactorer`, `review-fixer` | Denies the call with a reason naming that agent's own gate; re-dispatch foreground |

## The two reminders

They are mutually exclusive by design — at most one fires per prompt.

**Why they remind rather than force.** The reminder pattern keeps latency and
token noise low while still lifting skill activation.
`tdd-activation-reminder.sh` first checks the feedback signal set and **stays
silent** if the prompt is feedback-shaped, so it never double-fires with
`feedback-router-reminder.sh`; feedback is always `tdd-feedback`'s job.

Each reminder walks the same 3-step gate: (1) another skill invoked this message →
follow it; (2) user said this is NOT TDD work → proceed; (3) else invoke the skill.

### Editing

Both scripts pull the prompt from the hook payload with `jq` and lower-case it
before matching. If you rename the `tdd` or `tdd-feedback` skill, update the
reminder text (the skill name is hardcoded in the heredoc) and the row above.

The feature/feedback regexes are deliberately conservative — widen them only if you
observe the skill failing to activate on real prompts.

## The destructive-git guard

Blocking is the point here: reflog recovers a bad commit or rebase, so only the
commands that wipe the working tree qualify. `git push` is deliberately absent —
every push goes to a feature branch a PR gates.

Two exit codes carry the semantics: **2 blocks** (a destructive match, or a hook
payload it cannot parse — it fails closed), while a **missing `jq` exits 1**, a
non-blocking error that warns and lets the command run.

Every behavior above is pinned by `tests/Feature/Hooks/BlockDestructiveGitTest.php`;
the script's own comments carry the pattern-anchoring rationale.

## The background-dispatch guard

Backgrounding a subagent buys concurrency. In front of a blocking gate there is
none to buy: the dispatcher's very next act is to wait for that phase's result, so
`run_in_background` overlaps nothing and only makes the harness wake the
orchestrator on completion and nudge it to narrate — three no-information status
lines per phase, nine per `tdd` slice. The `tdd` skill and `/review:process` both
say to dispatch foreground; a command or skill body is advisory, so this enforces it.

**Why it denies rather than exiting 2.** The structured form carries a
`permissionDecisionReason` the harness surfaces verbatim, which lets the denial name
*which* gate the caller is about to block on — the RED/GREEN/REFACTOR gates for the
`tdd` trio, `/review:process` Phase 3 for `review-fixer`. An `exit 2` would deliver a
bare stderr line with nowhere to put that. The decision travels in the JSON, so the
script **exits 0 in every path, including the deny**; a non-zero exit would surface
as a hook error instead.

A payload it cannot parse **fails OPEN** — the deliberate opposite of
`block-destructive-git.sh`.

**The guarded set is name-based** — the map at the top of the script. A new subagent
dispatched in front of a blocking gate inherits nothing and must be added there;
`/review:suite`'s backgrounded `coderabbit-reviewer` deliberately stays out, because
it genuinely overlaps `/review:claude` running concurrently.

Pinned by `tests/Feature/Hooks/NoBackgroundGatedSubagentsTest.php`, which also
asserts the registration in `.claude/settings.json` — an unwired hook is inert and
silent, so every behavior test can pass while the guard never fires; the script's own
comments carry the fail-open rationale.
