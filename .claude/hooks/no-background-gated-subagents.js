#!/usr/bin/env node
// PreToolUse(Agent) hook: forbid background dispatch of a gate-blocked subagent.
//
// Each subagent below is spawned by a dispatcher that blocks on the named gate
// immediately after the spawn, so `run_in_background: true` overlaps nothing —
// it only makes the harness wake the orchestrator on completion and nudge it to
// narrate, producing mid-loop status chatter. The skill/command prose can't stop
// that (a body is advisory), so it is enforced structurally here.
//
// Add a row whenever a new subagent is dispatched in front of a blocking gate;
// an unlisted subagent_type is allowed through untouched.
const GATED_SUBAGENTS = {
  "tdd-test-writer": "the RED gate (`tdd` Step 1)",
  "tdd-implementer": "the GREEN gate (`tdd` Step 2)",
  "tdd-refactorer": "the REFACTOR gate (`tdd` Step 3)",
  "review-fixer": "`/review:process` Phase 3",
};

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

  // Own properties only: a bare index inherits Object.prototype, so a
  // subagent_type of "constructor"/"toString"/"valueOf" would resolve truthy and
  // deny a dispatch that is not in the guarded set at all.
  const gate = Object.hasOwn(GATED_SUBAGENTS, input.subagent_type)
    ? GATED_SUBAGENTS[input.subagent_type]
    : undefined;

  if (gate && input.run_in_background === true) {
    console.log(
      JSON.stringify({
        hookSpecificOutput: {
          hookEventName: "PreToolUse",
          permissionDecision: "deny",
          permissionDecisionReason: `${input.subagent_type} must run FOREGROUND — the dispatcher blocks on ${gate} immediately after the spawn, so backgrounding overlaps nothing and only wakes the orchestrator to narrate. Re-dispatch with run_in_background omitted.`,
        },
      })
    );
  }

  // Exit 0 on every path, including the deny: Claude Code reads the decision
  // from the JSON, and a non-zero exit would surface as a hook error instead.
  process.exit(0);
});
