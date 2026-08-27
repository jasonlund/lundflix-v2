# Hooks

Three hooks, all wired in `.claude/settings.json`, in two families:

- **Two `UserPromptSubmit` skill routers** nudge the agent into the right skill
  before it starts editing. Both **only remind** — they print to stdout, which
  Claude Code adds as context, and the turn proceeds either way.
- **One `PreToolUse(Bash)` guard** decides whether a command runs at all. It
  **exits 2 to block**: the tool call is refused and the message on stderr goes
  back to the agent as the reason.

| Hook | Event | Fires on | Effect |
|---|---|---|---|
| `feedback-router-reminder.sh` | `UserPromptSubmit` | Feedback / change request on existing work (review comment, bug report, "remove/rename/change X", a Conductor diff-comment attachment) | Reminds → `tdd-feedback` skill |
| `tdd-activation-reminder.sh` | `UserPromptSubmit` | New feature / implementation work ("implement", "build", "add a…", "create a…", "new endpoint/page/action") | Reminds → `tdd` skill |
| `block-destructive-git.sh` | `PreToolUse` (Bash) | A git command that destroys uncommitted work with no undo (`reset --hard/--merge/--keep`, `clean -f`, `branch -D`, `checkout .`, `restore .`, `stash drop/clear`) | Exits 2 → blocks the call; asks for a recoverable route instead |

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
