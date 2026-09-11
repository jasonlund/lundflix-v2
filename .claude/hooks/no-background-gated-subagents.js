#!/usr/bin/env node
// PreToolUse(Agent) hook: force a gate-blocked subagent's dispatch into the
// foreground by rewriting its input to `run_in_background: false`.
//
// Each subagent below is spawned by a dispatcher that blocks on a gate right
// after the spawn, so backgrounding it overlaps nothing and only makes the
// harness wake the orchestrator to narrate. Claude Code backgrounds a subagent
// unless `run_in_background` is explicitly `false` — omitting the key is not
// foreground — so anything short of an explicit `false` is rewritten.
//
// It rewrites via `updatedInput` rather than denying so a slip costs nothing and
// it fails soft: if the fork-subagent gate is ever back on, the key is dropped
// and the call just backgrounds, where a deny would block every tdd phase.
// `updatedInput` replaces the tool input wholesale, hence the spread below.
// Long form: .claude/hooks/README.md, "The background-dispatch guard".
//
// Add a name whenever a new subagent is dispatched in front of a blocking gate;
// an unlisted subagent_type is let through untouched.
const GATED_SUBAGENTS = new Set([
  "tdd-test-writer",
  "tdd-implementer",
  "tdd-refactorer",
  "review-fixer",
]);

let raw = "";
process.stdin.on("data", (chunk) => (raw += chunk)).on("end", () => {
  let input = {};
  try {
    input = JSON.parse(raw || "{}").tool_input || {};
  } catch {
    // Fail OPEN, unlike block-destructive-git.sh: that guard stands between the
    // user and destroyed work, this one only suppresses cosmetic chatter, so a
    // parse slip that blocked every Agent call would cost more than it saves.
    process.exit(0);
  }

  if (GATED_SUBAGENTS.has(input.subagent_type) && input.run_in_background !== false) {
    console.log(
      JSON.stringify({
        hookSpecificOutput: {
          hookEventName: "PreToolUse",
          updatedInput: { ...input, run_in_background: false },
        },
      })
    );
  }

  // Exit 0 on every path: the rewrite travels in the JSON, and a non-zero exit
  // would surface as a hook error instead.
  process.exit(0);
});
