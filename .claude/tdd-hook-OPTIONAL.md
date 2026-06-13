# Optional: TDD skill-activation hook (NOT installed)

We deliberately ship the TDD workflow **without** a hook. The `tdd` skill
auto-activates on feature work and can be invoked explicitly ("use tdd"). Add the
hook below **only if** you observe the skill failing to activate reliably.

## What the hook does

A `UserPromptSubmit` hook injects a mandatory evaluation before every response,
forcing Claude to consider skills instead of jumping straight to code:

1. **EVALUATE** — state YES/NO for each available skill, with reasoning.
2. **ACTIVATE** — call the `Skill()` tool for the relevant skill(s).
3. **IMPLEMENT** — only after activation.

In the source write-up (alexop.dev, citing Scott Spence across 200+ prompts) this
raised skill activation from ~20% to ~84%.

## Why it's off by default

- Runs on **every** prompt (latency + token noise on unrelated chatter).
- Adds a runtime dependency (the script + its interpreter) and a timeout to manage.
- For a workflow you invoke deliberately, explicit activation is enough.

## How to add it later

1. Create `.claude/hooks/user-prompt-skill-eval.ts` (or `.sh`) that prints the
   MANDATORY SKILL ACTIVATION SEQUENCE text above to stdout. Keep it fast
   (< the configured timeout).
2. Register it in `.claude/settings.json`:

```json
{
  "hooks": {
    "UserPromptSubmit": [
      {
        "matcher": "",
        "hooks": [
          {
            "type": "command",
            "command": "npx tsx \"$CLAUDE_PROJECT_DIR/.claude/hooks/user-prompt-skill-eval.ts\"",
            "timeout": 5
          }
        ]
      }
    ]
  }
}
```

3. If the project has no Node toolchain, write the hook as a plain shell script and
   call it directly instead of via `npx tsx`.

Source: https://alexop.dev/posts/custom-tdd-workflow-claude-code-vue/
