# Ticket-based handoff

Subagent tasks are delegated via files in this folder. The Orchestrator only assigns **who gets which file**; it does not carry the full plan in chat.

## Convention

| Role      | Writes / Uses | File pattern |
|-----------|----------------|--------------|
| Architect | Writes plan    | `{task-slug}_plan.md` |
| Refactorer| Reads plan      | Same file + current step index from Orchestrator |
| Verifier  | Reads plan      | Same file + changed files |
| Tester    | —               | No ticket; runs tests only |

**Task-slug** = task prompt filename without path and without `.md` (e.g. `PSR-11-Container-DI-Infrastructure`).

## Flow

1. **Orchestrator** delegates to Architect with the task prompt path. Architect reads task + ROADMAP and **writes** `migration-docs/tickets/{task-slug}_plan.md` (ARCHITECT OUTPUT format). Architect’s chat output: one line, e.g. `Plan written to migration-docs/tickets/PSR-11-Container-DI-Infrastructure_plan.md`.
2. **Orchestrator** delegates to Refactorer: "Ticket: migration-docs/tickets/{task-slug}_plan.md, Step N." Refactorer **reads only that file** (and codebase); does the work; chat output: "Step N done. Files: …".
3. **Orchestrator** delegates to Verifier: same ticket path + step N + changed files. Verifier outputs PASS or FAIL only.
4. **Tester** runs tests; output PASS or FAIL (+ minimal RCA if FAIL).

## Optional: output for human

The task prompt or invocation template can specify a summary file path for human review. This is optional — the primary output is the PR itself (created via `push.mdc`).

## Git

Ticket files (`*_plan.md`) are **durable, tracked artifacts** — commit them. The Orchestrator commits the ticket right after the Architect writes it (see `.cursor/rules/orchestrator-subagent-workflow.mdc`, Rule 5), so the plan persists beyond the ephemeral cloud-agent VM and lands in the PR next to the code it describes. Do **not** gitignore `*_plan.md`.
