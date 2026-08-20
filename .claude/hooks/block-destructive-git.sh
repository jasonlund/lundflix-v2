#!/bin/bash
# PreToolUse(Bash) guard: refuse the git commands that destroy uncommitted work
# with no undo. Reflog recovers a bad commit or a bad rebase; nothing recovers a
# `reset --hard` over a dirty tree or a `clean -fd`.
#
# `git push` is deliberately ABSENT from this list. The lundflix flow pushes per
# workstream, and every push here goes to a feature branch that a PR gates.
#
# Adapted from mattpocock-skills:git-guardrails-claude-code, narrowed to the
# unrecoverable subset.

INPUT=$(cat)
COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command')

# Each pattern is anchored to a command position: the start of the line, or just
# after a shell separator. Without the anchor, `grep -qE 'git reset --hard'` also
# matches the string inside `echo "git reset --hard"`, so writing docs about the
# command, or grepping for it, would be blocked as if it had been run.
ANCHOR='(^|[;&|(]|&&|\|\|)[[:space:]]*'

DESTRUCTIVE_PATTERNS=(
  'git[[:space:]]+reset[[:space:]]+[^;&|]*--(hard|merge)'
  'git[[:space:]]+clean[[:space:]]+-[a-zA-Z]*f'
  'git[[:space:]]+branch[[:space:]]+-D'
  'git[[:space:]]+checkout[[:space:]]+\.([[:space:]]|$)'
  'git[[:space:]]+restore[[:space:]]+\.([[:space:]]|$)'
  'git[[:space:]]+stash[[:space:]]+(drop|clear)'
)

for pattern in "${DESTRUCTIVE_PATTERNS[@]}"; do
  if echo "$COMMAND" | grep -qE "${ANCHOR}${pattern}"; then
    echo "BLOCKED: '$COMMAND' destroys uncommitted work and cannot be undone. Ask the user to run it themselves, or reach the same end another way (git stash, a fresh branch, git restore <specific-path>)." >&2
    exit 2
  fi
done

exit 0
