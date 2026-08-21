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
#
# INVARIANT — when this scanner cannot confidently parse, it OVER-blocks; it
# never under-blocks. Every defect this guard has shipped had one shape: the
# scanner met a shell construct it did not model, lost track of where commands
# begin, and silently ALLOWED the text it could not place — its own harness,
# global options, long flags, cross-segment leakage, here-strings, backslash
# escapes. Each fix taught it one more token and left the next unknown one
# exploitable. So the direction of failure is inverted here: if the scan reaches
# the end of input still inside a quote or a heredoc body, that unresolved
# remainder is split on separators anyway and scanned as segments, instead of
# being dropped. Anyone extending this scanner must preserve that: a construct
# you do not model has to end the scan unresolved — and so be refused, loudly —
# never be skipped as though it had been understood. An over-block is visible and
# can be argued with; an under-block is silent and is how every bypass here
# happened.
#
# A heredoc body is tracked for the same reason and is skipped whole. The shell
# feeds those lines to the preceding command as stdin DATA — it never executes
# one of them — so a line inside a body is no more a command position than a
# line inside a quoted string; it is only a spelling the quote tracker cannot
# see. Without this, `git commit -F - <<'EOF' … EOF` was refused for writing
# `git clean -fd` in the commit MESSAGE, and this repo documents these commands
# constantly (commit messages, ADRs, skill docs, the notes above). A guard that
# refuses to let you write about a command is a guard people route around,
# which costs more than the hole it closes.
# `<<-` strips leading tabs from the body, so ITS terminator is indented too and
# an exact line match would never find it — hence the trim for that form only.
# Trimming the plain form as well would be worse than useless: a body line that
# merely reads `  EOF` would end the body early and put the remaining prose back
# in command position, which is the bug this tracking exists to fix.
heredoc_line_terminates() {
  local line=$1 delimiter=$2 strips_indent=$3

  if [[ -n $strips_indent ]]; then
    line=${line#"${line%%[![:space:]]*}"}
    line=${line%"${line##*[![:space:]]}"}
  fi

  [[ $line == "$delimiter" ]]
}

# The fail-closed half of the invariant above: text the scanner could not place
# is split with no quote, heredoc or escape tracking at all. That is the honest
# reading of a remainder whose structure is by definition unknown — every
# separator in it is treated as a separator, so a destructive command hiding
# behind one lands at the start of a segment and is still matched.
split_unparsed_remainder() {
  local text=$1 segment='' char i

  for ((i = 0; i < ${#text}; i++)); do
    char=${text:i:1}

    case $char in
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

split_into_segments() {
  local text=$1 quote='' segment='' char i
  local heredoc_delimiter='' heredoc_strips_indent='' heredoc_line=''
  local pending_delimiter='' pending_strips_indent=''
  local quote_start=0 heredoc_start=0
  local delimiter_quote j

  SEGMENTS=()

  for ((i = 0; i < ${#text}; i++)); do
    char=${text:i:1}

    # Inside the body: consume lines, emitting nothing, until one of them is the
    # terminator.
    if [[ -n $heredoc_delimiter ]]; then
      if [[ $char == $'\n' ]]; then
        if heredoc_line_terminates "$heredoc_line" "$heredoc_delimiter" "$heredoc_strips_indent"; then
          heredoc_delimiter=''
        fi

        heredoc_line=''
      else
        heredoc_line+=$char
      fi

      continue
    fi

    # A backslash escapes the character behind it: that character is literal
    # text, so it can neither open nor close a quote nor separate two commands,
    # and both characters are consumed in one step. Single quotes are the one
    # exception — bash gives a backslash no meaning inside them, so `'a\'` ends
    # at that second quote and the separator after it really does split. Reading
    # `\"` as a quote opener was the bypass this closes: `echo \"; git clean -fd`
    # runs the clean, but the scanner spent the rest of the line "inside" a
    # string that bash never opened.
    if [[ $char == '\' && $quote != "'" ]]; then
      segment+=${text:i:2}
      i=$((i + 1))
      continue
    fi

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
        quote_start=$i
        segment+=$char
        ;;
      # `<<` opens a heredoc, but only the delimiter is read here: the body does
      # not start until the current line ends, so the rest of this line stays
      # ordinary command text (`cat <<EOF | tee f` is still a pipeline).
      '<')
        # A here-string is NOT a heredoc and the two branches must never be
        # unified: `<<<word` takes its data from that one word on this line and
        # opens no body, so the NEXT line is an ordinary command position. It is
        # therefore plain text to this scanner — but all three characters have to
        # be consumed in one step, and before the bare-`<<` test. Appending just
        # one of them re-entered this case on the second `<`, where the remaining
        # `<<` reads as a heredoc opener; the parser below then took the
        # here-string's word as a delimiter and swallowed every following line
        # waiting for a terminator that never comes, disarming the guard for the
        # whole rest of the command.
        if [[ ${text:i:3} == '<<<' ]]; then
          segment+='<<<'
          i=$((i + 2))
          continue
        fi

        if [[ ${text:i:2} != '<<' ]]; then
          segment+=$char
          continue
        fi

        j=$((i + 2))
        pending_delimiter=''
        pending_strips_indent=''

        if [[ ${text:j:1} == '-' ]]; then
          pending_strips_indent=1
          ((j++))
        fi

        while [[ ${text:j:1} == ' ' || ${text:j:1} == $'\t' ]]; do
          ((j++))
        done

        # Quoting the delimiter only turns off expansion of the body; quoted or
        # bare, the body is the same data, so all three spellings are read the
        # same way and only the word itself is kept.
        if [[ ${text:j:1} == "'" || ${text:j:1} == '"' ]]; then
          delimiter_quote=${text:j:1}
          ((j++))

          while ((j < ${#text})) && [[ ${text:j:1} != "$delimiter_quote" ]]; do
            pending_delimiter+=${text:j:1}
            ((j++))
          done

          ((j++))
        else
          while ((j < ${#text})) && [[ ${text:j:1} == [A-Za-z0-9_.-] ]]; do
            pending_delimiter+=${text:j:1}
            ((j++))
          done
        fi

        if [[ -z $pending_delimiter ]]; then
          pending_strips_indent=''
        fi

        segment+=${text:i:j-i}
        i=$((j - 1))
        ;;
      ';' | '&' | '|' | '(' | ')' | $'\n')
        if [[ $char == $'\n' && -n $pending_delimiter ]]; then
          heredoc_delimiter=$pending_delimiter
          heredoc_strips_indent=$pending_strips_indent
          heredoc_start=$((i + 1))
          pending_delimiter=''
          pending_strips_indent=''
        fi

        SEGMENTS+=("$segment")
        segment=''
        ;;
      *)
        segment+=$char
        ;;
    esac
  done

  SEGMENTS+=("$segment")

  # End of input closes a body whose terminator is its last line: a heredoc need
  # not end in a newline (`cat <<EOF\ndata\nEOF` is complete), and the check
  # inside the loop only ever fires on one. Without this the ordinary documented
  # heredoc would end the scan "unresolved" and be refused by the tail below.
  if [[ -n $heredoc_delimiter ]] && heredoc_line_terminates "$heredoc_line" "$heredoc_delimiter" "$heredoc_strips_indent"; then
    heredoc_delimiter=''
  fi

  # Still inside a quote or a heredoc body means the scanner no longer knows
  # where commands begin, so per the invariant at the top the remainder is
  # scanned rather than trusted as data.
  if [[ -n $quote ]]; then
    split_unparsed_remainder "${text:quote_start}"
  elif [[ -n $heredoc_delimiter ]]; then
    split_unparsed_remainder "${text:heredoc_start}"
  fi
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
