#!/bin/bash
# PreToolUse guard: refuse the AskUserQuestion picker. Every question an agent
# puts to the user in this repo is plain markdown in the chat — the picker
# truncates the reasoning behind a recommendation and forces one shot at a fixed
# option set.
#
# The deny message below is the only place this rule reaches an agent at the
# moment it gets it wrong, so it names the alternative rather than just refusing.
#
# Every behavior below is pinned by tests/Feature/Hooks/BlockAskUserQuestionTest.php.

INPUT=$(cat)

# This hook FAILS OPEN — the deliberate opposite of block-destructive-git.sh,
# which refuses a payload it cannot parse. That hook guards an irreversible act;
# nothing recovers a `reset --hard` over a dirty tree, so refusing on doubt is
# worth it there. A picker call is not irreversible: the worst a miss costs is
# one question asked the wrong way, while failing closed here would jam EVERY
# tool call in the session on a single unparseable payload. Read the divergence
# as weighed, not as an inconsistency.
#
# A missing jq stands down for the same reason, and more so: it is a property of
# the machine, constant until someone installs jq, so any non-zero exit would
# nag on every tool call for that user.
if ! command -v jq >/dev/null 2>&1; then
  exit 0
fi

# `// empty` collapses a missing key into no output, which `-e` reports as
# non-zero — so malformed JSON, empty stdin and a payload carrying no .tool_name
# all land here together and let the call through.
if ! TOOL_NAME=$(printf '%s' "$INPUT" | jq -re '.tool_name // empty' 2>/dev/null); then
  exit 0
fi

if [[ $TOOL_NAME == 'AskUserQuestion' ]]; then
  echo "BLOCKED: questions go to the user as plain markdown in the chat — a continuously numbered round, each question carrying its recommendation and the reasoning behind it. Never a picker, menu, or dialog tool. See \"Asking the user a question\" in .ai/guidelines/project.md for the verbatim round format." >&2
  exit 2
fi

exit 0
