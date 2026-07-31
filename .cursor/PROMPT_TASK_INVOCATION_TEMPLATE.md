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

Execute the task defined in: @PROMPT_X_Y.md
GitHub Issue: #XXX (PR will "Closes #XXX" and metadata block will reference it)

Workflow: Orchestrator (Architect → per-step Refactorer/Verifier/Tester/Commit → last XL Review+E2E → Early Push + PR → CI wait → Finalize)
Rule: @orchestrator-subagent-workflow.mdc
Push: @push.mdc (version bump, CHANGELOG, ROADMAP closure)
Reference: @ROADMAP.md
Tickets: migration-docs/tickets/active/ (Architect writes active/{task-slug}_plan.md; completed tickets move to done/ at Finalize; delegate by file path only)

Rules:
- One step at a time (sequential)
- Commit per completed step (Conventional Commits)
- Do NOT batch commits until end of task
- Last checklist step must be `(XL) — Review (Bugbot + Security) + E2E`:
  1. Bugbot → Security (fix-loops until both clean) → Tester `"final E2E run"`
  2. Early Push + PR creation (Orchestrator) → triggers CI (remote Bugbot usually skips via patch-ID sync)
  3. Orchestrator waits on CI (`gh pr checks`); re-E2E only after a CI fix-loop
  4. Finalize (Orchestrator, push.mdc): branch documentation + version bump → CHANGELOG → ROADMAP closure incl. PR# + Phase 1 audit closures → second push
  5. Do NOT merge.
- PR must include "Closes #XXX" and the issue number in the metadata block.

Token discipline (Orchestrator):
- Delegate to Architect immediately. Do NOT read the full task prompt or ROADMAP yourself.
- Use the Architect's checklist as-is (from ticket file). Do NOT re-interpret, merge or reorder steps.
- After Refactorer: delegate to Verifier immediately. Do NOT read or verify changed files yourself.
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

**Ticket workflow:** Plans are written to `migration-docs/tickets/active/{task-slug}_plan.md` by the Architect (completed tickets are moved to `migration-docs/tickets/done/` at Finalize). The Orchestrator delegates by file path ("Ticket: migration-docs/tickets/active/…_plan.md, Step N"); subagents read only the ticket (and code), which keeps chat short and avoids redundant context. See `migration-docs/tickets/README.md`. If you need a human-readable summary (e.g. PR doc), set "Output for human (optional): path/to/file.md" in the invocation block or in the task prompt.
