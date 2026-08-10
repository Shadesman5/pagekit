# Multiple Prompts (Sequential)

Launch fields for a single Orchestrator run live in
[`.cursor/rules/orchestrator-subagent-workflow.mdc`](rules/orchestrator-subagent-workflow.mdc) § Input
(Task prompt, Branch, Base, GitHub issue).

If you have several task prompts to run one after another (e.g. Phase 1, then Phase 2):

1. Invoke first: follow the rule with `@PROMPT_Phase1.md` → wait for completion
2. Invoke second: follow the rule with `@PROMPT_Phase2.md` → wait for completion

Each invocation runs the full Orchestrator workflow for that prompt. Commits happen per checklist
step within each prompt. V1 token/phase metrics are a **manual post-ticket** step (not Orchestrator) —
see `.github/conductor/metrics/README.md` (`import-manual-agents.mjs`).
