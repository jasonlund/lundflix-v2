# Skill-router hooks

Two `UserPromptSubmit` hooks nudge the agent into the right skill before it starts
editing. Both **only remind** (print to stdout → added context); neither blocks.
Both are wired in `.claude/settings.json`.

They are mutually exclusive by design — at most one fires per prompt.

| Hook | Fires on | Routes to |
|---|---|---|
| `feedback-router-reminder.sh` | Feedback / change request on existing work (review comment, bug report, "remove/rename/change X", a Conductor diff-comment attachment) | `tdd-feedback` skill |
| `tdd-activation-reminder.sh` | New feature / implementation work ("implement", "build", "add a…", "create a…", "new endpoint/page/action") | `tdd` skill |

## Why both remind rather than force

The reminder pattern keeps latency and token noise low while still lifting skill
activation. `tdd-activation-reminder.sh` first checks the feedback signal set and
**stays silent** if the prompt is feedback-shaped, so it never double-fires with
`feedback-router-reminder.sh`; feedback is always `tdd-feedback`'s job.

Each reminder walks the same 3-step gate: (1) another skill invoked this message →
follow it; (2) user said this is NOT TDD work → proceed; (3) else invoke the skill.

## Editing

Both scripts pull the prompt from the hook payload with `jq` and lower-case it
before matching. If you rename the `tdd` or `tdd-feedback` skill, update the
reminder text (the skill name is hardcoded in the heredoc) and the row above.

The feature/feedback regexes are deliberately conservative — widen them only if you
observe the skill failing to activate on real prompts.
