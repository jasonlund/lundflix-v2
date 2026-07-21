#!/usr/bin/env bash
#
# UserPromptSubmit hook — route NEW feature / implementation work through the `tdd` skill.
#
# Fires when a prompt looks like a request to BUILD something new (implement, add,
# build, create, scaffold a feature/endpoint/page) — the trigger for the `tdd`
# skill's RED -> GREEN -> REFACTOR loop. Injects a reminder so the agent drives the
# cycle test-first instead of hand-writing implementation.
#
# Deliberately DEFERS to feedback-router: if the prompt is feedback-shaped
# (change request on existing work), this stays silent and lets
# feedback-router-reminder.sh route it to `tdd-feedback` instead.
#
# It only REMINDS (stdout -> added context); it never blocks. Exit 0 always.

set -euo pipefail

INPUT="$(cat)"

# --- pull the user's prompt text out of the hook payload -----------------------
PROMPT="$(printf '%s' "$INPUT" | jq -r '.prompt // ""' 2>/dev/null || true)"

LOWER="$(printf '%s' "$PROMPT" | tr '[:upper:]' '[:lower:]')"

# --- defer to feedback-router: feedback on existing work is tdd-feedback's job --
# Same signal set as feedback-router-reminder.sh; if it matches, stay silent so
# the two hooks never both fire on one prompt.
if printf '%s' "$LOWER" | grep -Eq \
  'review(er)?|pr comment|code review|diff comment|this comment was left|after merge|post-merge|already merged|\bbug\b|regression|reproduce|doesn'\''t work|is broken|should (be|have been)|why (is|does|did) (this|it)|\bremove\b|\brename\b|\bchange\b'; then
  exit 0
fi

# --- signal: new-feature / implementation language -----------------------------
# Conservative: targets building something that does not exist yet, not edits to
# existing work (those are feedback-router's / tdd-feedback's territory).
if printf '%s' "$LOWER" | grep -Eq \
  '\bimplement\b|\bbuild\b|\bscaffold\b|\badd (a|an|the|support|new)\b|\bcreate (a|an|the|new)\b|\bwrite (a|an|the)\b|\bnew (feature|endpoint|page|action|command|model|migration|component)\b|\bfeature\b'; then
  cat <<'EOF'
[tdd-router] This prompt looks like NEW FEATURE / implementation work — the
trigger for the `tdd` skill (RED -> GREEN -> REFACTOR).

Before writing ANY implementation or hand-authoring tests, walk this check in order:

  1. Did the user invoke a DIFFERENT skill in THIS message?
       YES -> follow that skill; stop here.
       NO  -> continue.

  2. Did the user explicitly say this is NOT TDD work / not to use the skill in
     THIS message?
       YES -> proceed normally; stop here.
       NO  -> continue.

  3. Neither applies -> invoke the `tdd` skill NOW, FIRST.

Let the skill drive the cycle: a failing RED slice approved before code, then
GREEN, then REFACTOR — each phase in its isolated subagent. Do NOT jump straight
to writing implementation, and do NOT hand-write tests outside the skill.
EOF
fi

exit 0
