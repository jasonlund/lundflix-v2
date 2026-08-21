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
#
# Every behavior below is pinned by tests/Feature/Hooks/BlockDestructiveGitTest.php.

INPUT=$(cat)

# A missing jq is a property of the MACHINE, not of this call: constant, and
# fixed once by installing jq. Blocking on it (exit 2) would refuse EVERY Bash
# call for that user until they worked out why — a worse outcome than the hole
# it closes. So the missing-interpreter case exits 1, which Claude Code treats
# as a non-blocking error: the warning is shown to the user and the command
# still runs. Input that fails to parse WITH jq present is the opposite case —
# anomalous, per-call, and capable of hiding a destructive command — so it
# fails closed below.
if ! command -v jq >/dev/null 2>&1; then
  echo "WARNING: jq is not installed, so the destructive-git guard cannot read this command and is standing down for it. Install jq to restore the guard." >&2
  exit 1
fi

# `// empty` turns a missing key into no output at all, which `-e` reports as a
# non-zero status — so malformed JSON, empty stdin and a payload carrying no
# .tool_input.command all land here together. Previously each of those yielded
# an empty/`null` command that matched no pattern and exited 0, making a broken
# payload indistinguishable from a safe command.
if ! COMMAND=$(printf '%s' "$INPUT" | jq -re '.tool_input.command // empty' 2>/dev/null); then
  echo "BLOCKED: the destructive-git guard could not read .tool_input.command from the hook payload, so it cannot tell whether this command is safe. Refusing rather than guessing." >&2
  exit 2
fi

matches() {
  printf '%s' "$COMMAND" | grep -qE "$1"
}

# Each pattern is anchored to a command position: the start of the line, or just
# after a shell separator. Without the anchor, `grep -qE 'git reset --hard'` also
# matches the string inside `echo "git reset --hard"`, so writing docs about the
# command, or grepping for it, would be blocked as if it had been run.
ANCHOR='(^|[;&|(]|&&|\|\|)[[:space:]]*'

# git takes global options between the binary and the subcommand, and none of
# them make the subcommand any safer — `git -C ../other-worktree reset --hard`
# wipes a whole second checkout. Matching a bare `git <subcommand>` let every
# one of those spellings through, so the prefix skips any leading run of them,
# including `-C <path>` / `-c <k=v>`, which carry their value as a separate word.
GIT='git[[:space:]]+((-[cC][[:space:]]*[^[:space:]]+|--[a-z-]+(=[^[:space:]]+)?)[[:space:]]+)*'

DESTRUCTIVE_PATTERNS=(
  # `--keep` belongs with `--hard`/`--merge`: it resets the index and rewrites
  # the working tree, so staged work is gone.
  'reset[[:space:]]+[^;&|]*--(hard|merge|keep)'
  'branch[[:space:]]+([^;&|]*[[:space:]])?(-[a-zA-Z]*D[a-zA-Z]*|--delete[[:space:]]+--force|--force[[:space:]]+--delete)([[:space:]]|$)'
  # `--` only separates flags from pathspecs, so `checkout -- .` discards exactly
  # what `checkout .` discards.
  'checkout[[:space:]]+(--[[:space:]]+)?\.([[:space:]]|$)'
  'restore[[:space:]]+(--[[:space:]]+)?\.([[:space:]]|$)'
  'stash[[:space:]]+(drop|clear)'
)

# `clean` is the one subcommand whose destructiveness can be cancelled by a
# later flag: `-n`/`--dry-run` only lists what would go, and git honours it even
# alongside `-f`. So force is matched first and then withdrawn, which no single
# ERE can express (POSIX has no negative lookahead). The flag may sit in a
# combined group (`-ndf`) or on its own, hence the two spellings.
CLEAN_FORCE='clean([[:space:]]+[^;&|]*)?[[:space:]]+(-[a-zA-Z]*f[a-zA-Z]*|--force)([[:space:]]|$)'
CLEAN_DRY_RUN='clean([[:space:]]+[^;&|]*)?[[:space:]]+(-[a-zA-Z]*n[a-zA-Z]*|--dry-run)([[:space:]]|$)'

block() {
  echo "BLOCKED: '$COMMAND' destroys uncommitted work and cannot be undone. Ask the user to run it themselves, or reach the same end another way (git stash, a fresh branch, git restore <specific-path>)." >&2
  exit 2
}

if matches "${ANCHOR}${GIT}${CLEAN_FORCE}" && ! matches "${ANCHOR}${GIT}${CLEAN_DRY_RUN}"; then
  block
fi

for pattern in "${DESTRUCTIVE_PATTERNS[@]}"; do
  if matches "${ANCHOR}${GIT}${pattern}"; then
    block
  fi
done

exit 0
