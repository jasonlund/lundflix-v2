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

# One shell line can hold several commands, and a rule only makes sense about
# ONE of them: `git clean -ndf && git clean -fd` is a preview followed by a real
# deletion, and grepping the whole line let the preview's `-n` withdraw the
# force flag belonging to the *other* invocation — disarming the guard for the
# most ordinary usage there is. So the line is split on shell separators and
# every rule is evaluated per segment, which is what makes a flag's scope the
# invocation that carries it.
#
# Splitting also replaces the old start-of-command anchor: a segment IS a
# command position, so the patterns below just anchor to the segment start.
# That is what keeps `echo "never run git reset --hard here"` allowed — the
# segment starts at `echo`, and a git command merely *named* mid-segment is
# never at a command position.
#
# Quotes are tracked while scanning, so a separator inside a quoted string does
# NOT split. Otherwise `echo "warning; git reset --hard is bad"` would break
# into a segment that begins with the quoted `git reset --hard` and be refused
# as if it had been run — precisely the false positive the anchor existed to
# prevent. `(` and `)` split too, so a command substitution such as
# `$(git clean -fd)` is still reached.
split_into_segments() {
  local text=$1 quote='' segment='' char i

  SEGMENTS=()

  for ((i = 0; i < ${#text}; i++)); do
    char=${text:i:1}

    if [[ -n $quote ]]; then
      segment+=$char
      if [[ $char == "$quote" ]]; then
        quote=''
      fi
      continue
    fi

    case $char in
      '"' | "'")
        quote=$char
        segment+=$char
        ;;
      ';' | '&' | '|' | '(' | ')' | $'\n')
        SEGMENTS+=("$segment")
        segment=''
        ;;
      *)
        segment+=$char
        ;;
    esac
  done

  SEGMENTS+=("$segment")
}

matches() {
  printf '%s' "$1" | grep -qE "$2"
}

# A global option's value can be quoted, and a quoted value can contain spaces —
# `git -C "/tmp/my worktree" reset --hard`. Consuming the value only up to the
# first whitespace broke the prefix match there and let the subcommand behind it
# escape, so a value is a run of non-space characters in which any quoted
# stretch counts as one unit, spaces included.
VALUE="([^[:space:]\"']|\"[^\"]*\"|'[^']*')+"

# git takes global options between the binary and the subcommand, and none of
# them make the subcommand any safer — `git -C ../other-worktree reset --hard`
# wipes a whole second checkout. Matching a bare `git <subcommand>` let every
# one of those spellings through, so the prefix skips any leading run of them,
# including `-C <path>` / `-c <k=v>`, which carry their value as a separate word.
GIT="^[[:space:]]*git[[:space:]]+((-[cC][[:space:]]*${VALUE}|--[a-z-]+(=${VALUE})?)[[:space:]]+)*"

# Any run of whole flag/argument words between the subcommand and the flag being
# looked for. Each alternative ends in whitespace, so a flag can only be found
# where a word actually starts — `git branch -d feature-fix` must not read the
# `-fix` inside the branch name as a force flag.
FLAGS='([^[:space:]]+[[:space:]]+)*'

DESTRUCTIVE_PATTERNS=(
  # `--keep` belongs with `--hard`/`--merge`: it resets the index and rewrites
  # the working tree, so staged work is gone.
  "reset[[:space:]]+${FLAGS}--(hard|merge|keep)([[:space:]]|$)"
  # `--` only separates flags from pathspecs, so `checkout -- .` discards exactly
  # what `checkout .` discards.
  'checkout[[:space:]]+(--[[:space:]]+)?\.([[:space:]]|$)'
  'restore[[:space:]]+(--[[:space:]]+)?\.([[:space:]]|$)'
  'stash[[:space:]]+(drop|clear)'
)

# Force-deleting a branch throws away commits reachable from nowhere else, and
# `-d` plus `-f` does it in any spelling or order — `-df`, `-fd`,
# `--delete --force`, `-d --force`, `-f --delete`. An alternation of exact
# spellings only ever covered a couple of them, so delete-intent and force are
# matched independently and ANDed within the segment; `-D` satisfies both on its
# own. `git branch -d merged-branch` stays allowed: the commit is still in the
# reflog and this is the normal way branches go away.
BRANCH_DELETE="branch[[:space:]]+${FLAGS}(-[a-zA-Z]*[dD][a-zA-Z]*|--delete)([[:space:]]|$)"
BRANCH_FORCE="branch[[:space:]]+${FLAGS}(-[a-zA-Z]*[fD][a-zA-Z]*|--force)([[:space:]]|$)"

# `clean` is the one subcommand whose destructiveness can be cancelled by a
# later flag: `-n`/`--dry-run` only lists what would go, and git honours it even
# alongside `-f`. So force is matched first and then withdrawn, which no single
# ERE can express (POSIX has no negative lookahead). The flag may sit in a
# combined group (`-ndf`) or on its own, hence the two spellings.
CLEAN_FORCE="clean[[:space:]]+${FLAGS}(-[a-zA-Z]*f[a-zA-Z]*|--force)([[:space:]]|$)"
CLEAN_DRY_RUN="clean[[:space:]]+${FLAGS}(-[a-zA-Z]*n[a-zA-Z]*|--dry-run)([[:space:]]|$)"

block() {
  echo "BLOCKED: '$COMMAND' destroys uncommitted work and cannot be undone. Ask the user to run it themselves, or reach the same end another way (git stash, a fresh branch, git restore <specific-path>)." >&2
  exit 2
}

split_into_segments "$COMMAND"

for segment in "${SEGMENTS[@]}"; do
  if matches "$segment" "${GIT}${CLEAN_FORCE}" && ! matches "$segment" "${GIT}${CLEAN_DRY_RUN}"; then
    block
  fi

  if matches "$segment" "${GIT}${BRANCH_DELETE}" && matches "$segment" "${GIT}${BRANCH_FORCE}"; then
    block
  fi

  for pattern in "${DESTRUCTIVE_PATTERNS[@]}"; do
    if matches "$segment" "${GIT}${pattern}"; then
      block
    fi
  done
done

exit 0
