# Ticket-based handoff (V2)

The Orchestrator assigns **who gets which file**; it does not carry the full plan in chat.

Pipeline index: [`.cursor/WORKFLOW_SUBAGENTS.md`](../../.cursor/WORKFLOW_SUBAGENTS.md)

## Names

**Prompt basename** = task-prompt filename without `.md`, including any `PROMPT_` prefix (e.g. `PROMPT_2_7_1_Snapshot-Three-Stage-Uninstall`). That is the **ticket** slug. Never a ROADMAP id (`2_7_1_…`) and never the feature-branch slug.

## Who writes what

| Role | Reads / Writes |
|---|---|
| **Architect** | Writes `active/{prompt-basename}_plan.md` |
| **Plan-reviewer** | Reads task prompt + ticket |
| **Refactorer** | Reads ticket + Checklist Step N |
| **Verifier** | Ticket + changed files (production or `scope: test files only`) |
| **Tester** | No ticket; runs tests |
| **Test-writer** | Reads testing notes; writes tests for Refactorer production files |
| **Doc-writer** | Writes branch docs; at Finalize CHANGELOG/README — **never** the ticket |

## Ticket structure (Architect)

- `## ARCHITECT OUTPUT` — scope, checklist, deferred, bridges
- `## EXECUTION STATE` — checkboxes 1:1 with the checklist, each `(S|M|L|XL)`; **last step must be `(XL) — Review (Bugbot + Security) + E2E`**
- `## TESTING STRATEGY` — production gate, test-writer skip rules, XL Review + E2E

The **Conductor** reads `## EXECUTION STATE` only. Step orchestrators flip `- [ ]` → `- [x]` in the **same commit** as that step's code. While `handoff_xl` is on (default), Conductor stops before the last `(XL)` step — run that Review + E2E on cursor.com/agents, then re-dispatch Conductor for Finalize. Ticking `(XL)` on a Conductor branch auto-imports the V1 parent agent's tokens into that session (`import-xl-handoff-metrics.yml`); do not add `v1-metrics` to the Conductor Finalize PR.

## Folders

- **`active/`** — in progress. Architect writes; Execute ticks.
- **`done/`** — Finalize `git mv`s here.

## Branch doc path

From the ticket's `Current Step (ROADMAP): X.Y.Z`:

`migration-docs/branches/phase-<X>/step-<X>-<Y>-<Z>-<kebab-title>.md`

Plan-reviewer checks this at Plan gate. Schema: `migration-docs/branches/branch-doc-skeleton.md`.

## Git

Ticket files (`*_plan.md`) are tracked artifacts. Do **not** gitignore them.
