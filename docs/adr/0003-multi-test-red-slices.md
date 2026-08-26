# Multi-test RED slices

`.claude/skills/tdd/SKILL.md` mandates a RED slice of 2–6 tests covering one
behavior surface, which `mattpocock-skills:tdd` names an anti-pattern —
"horizontal slicing", against its rule of one seam, one test, one minimal
implementation per cycle. The trade-off is the isolation this repo buys instead:
each RED phase runs in its own subagent, so cycling per test multiplies subagent
spawns across a single behavior surface, and the slice still covers one
*behavior* — the property horizontal slicing actually violates.
