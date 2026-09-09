#!/usr/bin/env bash
#
# UserPromptSubmit hook — tell the agent when the session runs unattended.
#
# Fires only when the hook payload's `permission_mode` is exactly
# `bypassPermissions` (`--dangerously-skip-permissions`), where nobody is watching
# and a skill's human-approval gate would stall the run forever. Injects a notice
# so the agent skips the ask-a-human gates while every correctness gate still holds.
#
# Detection FAILS CLOSED: an absent field, an unparseable payload, a missing jq, or
# any other mode all mean no notice, leaving the gated (attended) path in force.
# The asymmetry is the point — a false positive strips a human's approval from a
# loop that writes code, while a false negative only asks a question that was going
# to be asked anyway.
#
# It only NOTIFIES (stdout -> added context); it never blocks. Exit 0 always.

set -euo pipefail

INPUT="$(cat)"

# A missing jq exits 0, unlike block-destructive-git.sh's exit 1: that hook has a
# guard to stand down from and wants the user told, whereas this one only reminds.
# Anything non-zero here would surface an error on a turn that is otherwise fine.
command -v jq >/dev/null 2>&1 || exit 0

MODE="$(printf '%s' "$INPUT" | jq -r '.permission_mode // empty' 2>/dev/null || true)"

[ "$MODE" = "bypassPermissions" ] || exit 0

cat <<'EOF'
[unattended-mode] permission_mode=bypassPermissions — this session runs unattended.
Skill approval gates that exist only to ask a human (the `tdd` Step 1 RED plan card,
tdd-feedback's route confirmation, review-tdd-cross-slice's sweep approval) do NOT
apply: write the contract to chat and proceed. Every correctness gate still applies —
RED must fail for the right reason, GREEN must pass, REFACTOR must stay green.
EOF

exit 0
