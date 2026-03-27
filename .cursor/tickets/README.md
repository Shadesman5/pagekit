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

1. **Orchestrator** delegates to Architect with the task prompt path. Architect reads task + ROADMAP and **writes** `.cursor/tickets/{task-slug}_plan.md` (ARCHITECT OUTPUT format). Architect’s chat output: one line, e.g. `Plan written to .cursor/tickets/PSR-11-Container-DI-Infrastructure_plan.md`.
2. **Orchestrator** delegates to Refactorer: "Ticket: .cursor/tickets/{task-slug}_plan.md, Step N." Refactorer **reads only that file** (and codebase); does the work; chat output: "Step N done. Files: …".
3. **Orchestrator** delegates to Verifier: same ticket path + step N + changed files. Verifier outputs PASS or FAIL only.
4. **Tester** runs tests; output PASS or FAIL (+ minimal RCA if FAIL).

## Optional: output for human

The task prompt or invocation template can specify a summary file path for human review. This is optional — the primary output is the PR itself (created via `push.mdc`).

## Git

Ticket files (`*_plan.md`) are ephemeral per run. You can add `*.md` (or `*_plan.md`) under `.cursor/tickets/` to `.gitignore` if you do not want to commit them; or keep them for audit.
