# Task Invocation Template

Use this template to invoke the Orchestrator workflow. The main agent will delegate to Architect → Refactorer → Verifier → Tester and commit per step.

---

## How to Use

1. Open the **Task Prompt** for your step (e.g. `PROMPT_Complete_Template_Security_Modernization.md`).
2. Copy the invocation block below and replace the placeholders.
3. Paste into the main remote agent.
4. The agent will follow the Orchestrator workflow automatically (rules apply when agent_prompts are in context).

---

## Invocation Block (Copy & Paste)

```
TASK INVOCATION

Execute the task defined in: @PROMPT_Complete_Template_Security_Modernization.md

Workflow: Orchestrator (Architect → Refactorer → Verifier → Tester per step)
Reference: @ROADMAP.md

Rules:
- One step at a time (sequential)
- Commit per completed step (Conventional Commits)
- Do NOT batch commits until end of task
```

---

## For Other Tasks

Replace the task file reference:

```
Execute the task defined in: @PROMPT_[YourTaskName].md
```

Examples:
- `@PROMPT_DOCTRINE_ATTRIBUTES_MIGRATION.md`
- `@PSR-11-Container-Stage1-Core.md`
- `@PROMPT_SYMFONY_VALIDATOR_OPTIMIZED.md`

---

## Multiple Prompts (Sequential)

If you have several prompts for one step (e.g. Phase 1, Phase 2):

1. Invoke first: `Execute @PROMPT_Phase1.md` → wait for completion
2. Invoke second: `Execute @PROMPT_Phase2.md` → wait for completion

Each invocation runs the full Orchestrator workflow for that prompt. Commits happen per step within each prompt.

---

## Technical Note (Cursor)

Subagents live in `.cursor/agents/` (architect, refactorer, verifier, tester). The main agent invokes them in sequence. The orchestrator rule (`.cursor/rules/orchestrator-subagent-workflow.mdc`) applies when task prompts are in context (e.g. via @-mention). For rules to apply, the task prompt file must be in context.
