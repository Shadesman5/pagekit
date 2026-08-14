# Ticket-based handoff (V2)

Subagent tasks are delegated via files in this folder. The Orchestrator only assigns **who gets which file**; it does not carry the full plan in chat.

**Workflow overview:** [`.cursor/WORKFLOW_SUBAGENTS.md`](../../.cursor/WORKFLOW_SUBAGENTS.md)

## Convention

| Role | Reads / Writes | File pattern |
| --- | --- | --- |
| **Architect** | Writes plan | `active/{task-slug}_plan.md` |
| **Plan-reviewer** | Reads task prompt + ticket | Same ticket (Plan gate only) |
| **Refactorer** | Reads plan | Same file + Checklist Step N from Orchestrator |
| **Verifier** | Reads plan + changed files | Production scope, or `scope: test files only` |
| **Tester** | — | No ticket; runs tests per Orchestrator message |
| **Test-writer** | Reads plan (testing notes) | Writes tests only; scope = Refactorer production files |
| **Doc-writer** | Reads ticket (reference only) | **Writes** `migration-docs/branches/phase-<X>/step-*.md`; at Finalize also CHANGELOG/README — **never** the ticket |

**Task-slug** = task prompt filename without path and without `.md`, including any `PROMPT_` prefix (e.g. `PROMPT_2_7_1_Snapshot-Three-Stage-Uninstall`). Never a ROADMAP id (`2_7_1_…`) and never the feature-branch slug.

## Ticket structure (Architect)

Each ticket in `active/` contains at minimum:

- `## ARCHITECT OUTPUT` — scope, checklist, deferred, bridges
- `## EXECUTION STATE` — checkboxes mirroring the checklist 1:1, each with `(S|M|L|XL)` size hint; **last step must be `(XL) — Review (Bugbot + Security) + E2E`**
- `## TESTING STRATEGY` — production gate, test-writer skip rules, XL Review + E2E

The **Conductor** reads `## EXECUTION STATE` only (never edits it). Step orchestrators flip `- [ ]` → `- [x]` in the **same commit** as that step's code.

## Lifecycle folders: `active/` vs `done/`

- **`active/`** — in-progress tickets. Architect writes here; Execute reads and ticks here.
- **`done/`** — completed tickets. **Finalize** runs `git mv active/{slug}_plan.md done/`.

The explicit path in each handoff is the primary guard; the folder split is defense-in-depth.

## Flow by phase

### Plan (`orchestrator-v2-plan.mdc`)

1. **Architect** — writes `migration-docs/tickets/active/{task-slug}_plan.md`. Chat: `Plan written to …`
2. **Plan-reviewer** — PASS / FAIL (FAIL → Architect re-plans)
3. **Doc-writer** — copies `branch-doc-skeleton.md` to `migration-docs/branches/phase-<X>/step-<X>-<Y>-<Z>-<kebab>.md`, fills header metadata
4. **Orchestrator** — commits ticket **and** branch doc: `docs(plan): add ticket + branch doc for <task-slug>`, push

Audit/report tasks (`<!-- conductor-mode: plan -->`): no ticket, no branch doc — report under `migration-docs/audits/` instead.

### Execute (`orchestrator-v2-step.mdc`)

Per **Checklist Step N** with size `S` / `M` / `L`:

1. **Refactorer** → **Verifier** → **Tester** (production gate)
2. **Test-writer** → **Verifier** (`scope: test files only`) → **Tester** — skip when strategy says so or no production PHP changed
3. **Doc-writer** — updates branch doc for Step N (actual results, not plan restatement)
4. **Orchestrator** — one commit: code + tests + ticket tick + branch doc; push

**Last step `(XL)`** (own batch when `batch_budget` &lt; 8):

1. **Bugbot** → findings? fix-loop → repeat until clean
2. **Security Review** → findings? fix-loop → restart from Bugbot until both clean in sequence
3. **Tester** `"final E2E run"` → FAIL? fix-loop → restart from Bugbot
4. **Doc-writer** → tick + commit + push

### Finalize (`orchestrator-v2-finalize.mdc`)

PR → CI → optional Codecov gap pass (`test-writer` → verifier → tester) → PR-Bugbot (safety net; patch-ID sync from XL usually skips) → version bump (Orchestrator) → **doc-writer** (close branch doc + CHANGELOG + README) → ROADMAP (Orchestrator) → archive ticket to `done/` → push.

## Branch doc path

Derived from the ticket's `Current Step (ROADMAP): X.Y.Z`:

`migration-docs/branches/phase-<X>/step-<X>-<Y>-<Z>-<kebab-title>.md`

Plan-reviewer verifies this is derivable at Plan gate. Schema: `migration-docs/branches/branch-doc-skeleton.md`.

## Git

Ticket files (`*_plan.md`) are **durable, tracked artifacts** — always committed. Do **not** gitignore `*_plan.md`.
