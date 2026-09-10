# Hooks

Five hooks, all wired in `.claude/settings.json`, in three families:

- **Two `UserPromptSubmit` skill routers** nudge the agent into the right skill
  before it starts editing. Both **only remind** — they print to stdout, which
  Claude Code adds as context, and the turn proceeds either way.
- **One `UserPromptSubmit` session-mode notice** tells the agent nobody is
  watching. Same remind-only mechanism, different subject: it reads the session,
  not the prompt.
- **Two `PreToolUse` guards** — one on `Bash`, one on `AskUserQuestion` — decide
  whether a tool call runs at all. Both **exit 2 to block**: the call is refused and
  the message on stderr goes back to the agent as the reason.

| Hook | Event | Fires on | Effect |
|---|---|---|---|
| `feedback-router-reminder.sh` | `UserPromptSubmit` | Feedback / change request on existing work (review comment, bug report, "remove/rename/change X", a Conductor diff-comment attachment — LaborForest and Solo have no diff-comment equivalent) | Reminds → `tdd-feedback` skill |
| `tdd-activation-reminder.sh` | `UserPromptSubmit` | New feature / implementation work ("implement", "build", "add a…", "create a…", "new endpoint/page/action") | Reminds → `tdd` skill |
| `unattended-mode-notice.sh` | `UserPromptSubmit` | `permission_mode` is `bypassPermissions` — the session was started with `--dangerously-skip-permissions`, so nobody is watching | Notices → skill approval gates that only ask a human do not apply |
| `block-destructive-git.sh` | `PreToolUse` (Bash) | A git command that destroys uncommitted work with no undo (`reset --hard/--merge/--keep`, `clean -f`, `branch -D`, `checkout .`, `restore .`, `stash drop/clear`) | Exits 2 → blocks the call; asks for a recoverable route instead |
| `block-ask-user-question.sh` | `PreToolUse` (AskUserQuestion) | Any call to the `AskUserQuestion` picker — questions in this repo are plain markdown rounds in the chat | Exits 2 → blocks the call; names the canonical round format instead |

## The two skill reminders

They are mutually exclusive by design — at most one of the two fires per prompt.
(The unattended-mode notice below is independent of both: it reads the session's
permission mode rather than the prompt, so it can fire alongside either.)

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

## The unattended-mode notice

It **notices, never blocks** — and the asymmetry is the whole design. The notice
names three ask-a-human gates — the `tdd` Step 1 RED plan card, `tdd-feedback`'s
route confirmation, `review-tdd-cross-slice`'s sweep approval — and lifts those
three only, so an AFK run over an approved slice backlog stops stalling at slice 1
for an approval that will never come. The list is closed on purpose: the planning
skills stay gated, because `plan-draft`'s interview *is* the work and running it
unattended would lock decisions nobody made. It cannot lift a *correctness* gate
either: RED still has to fail for the right reason, GREEN to pass, REFACTOR to
stay green.

**Fail closed.** The notice prints only on the literal `bypassPermissions` — the
one mode requiring an explicit `--dangerously-skip-permissions` opt-in. An absent
`permission_mode`, an unparseable payload, a missing `jq`, and every other mode
(`default`, `plan`, `acceptEdits`, `auto`, `dontAsk`) all print nothing, which
leaves the human approval in force. A false positive silently strips a person's
approval from a loop that writes code; a false negative just asks. That is also
why this hook exits **0** when `jq` is missing where `block-destructive-git.sh`
exits 1: silence here is already the safe answer.

`UserPromptSubmit` is deliberately the only event. It fires at the top of every
turn, so the notice is fresh as of the moment work is requested. A `SessionStart`
hook would go stale the instant someone shift-tabs the mode, and wiring both would
create two sources that can disagree. The trade-off: a mode toggled *mid-turn*
isn't seen until the next prompt — accepted, because leaving bypass mid-loop is a
deliberate act by someone who is, by definition, present. The same transience costs
one more thing: an auto-compaction mid-run summarizes the notice away, so `tdd` stops
and says so instead of entering plan mode for a human who isn't there, and the
operator's next prompt re-fires the hook.

**Editing.** The heredoc hardcodes six skill names — `tdd`, `tdd-feedback` and
`review-tdd-cross-slice` as the gates it lifts, and `plan-draft`, `plan-breakdown`
and `plan-slices` as the ones it does not. Rename any of the six and you must update
the notice text and the row above; nothing tests these names.

`tests/Feature/Hooks/UnattendedModeNoticeTest.php` pins every branch above, plus
the registration itself — a hook written and never wired is a silent failure no
other test can see.

## The destructive-git guard

Blocking is the point here: reflog recovers a bad commit or rebase, so only the
commands that wipe the working tree qualify. `git push` is deliberately absent —
every push goes to a feature branch a PR gates.

Two exit codes carry the semantics: **2 blocks** (a destructive match, or a hook
payload it cannot parse — it fails closed), while a **missing `jq` exits 1**, a
non-blocking error that warns and lets the command run.

Every behavior above is pinned by `tests/Feature/Hooks/BlockDestructiveGitTest.php`;
the script's own comments carry the pattern-anchoring rationale.

## The picker guard

This one **fails open**: a payload it cannot parse exits 0 and the call proceeds —
the deliberate opposite of the guard above, which fails closed on the same input.
The asymmetry is irreversibility. Nothing recovers a `reset --hard` over a dirty
tree, so refusing on doubt is worth it there; a picker call costs at most one
question asked the wrong way, while failing closed would jam **every** tool call in
the session on a single bad payload. A **missing `jq` also exits 0** — that is a
property of the machine, so any other exit would nag on every call until it lands.

Pinned by `tests/Feature/Hooks/BlockAskUserQuestionTest.php`; the rule it enforces
is *Asking the user a question* in `.ai/guidelines/project.md`.
