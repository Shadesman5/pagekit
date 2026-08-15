# Pagekit Modernization: Subagent Workflow

**Last updated:** 2026-08-14

Index for Conductor V2 and the Orchestrator. Operative contracts live in the files this page names — do not copy them here.

## 1. Conductor vs Orchestrator

| | Where | LLM? | Does |
|---|---|---|---|
| **Conductor** | GitHub Actions (`conductor.mjs`) | No | Outer loop: one cloud-agent phase per job (Plan → Execute batches → Finalize); auto-chains; reads ticket checkboxes |
| **Orchestrator** | Cursor Cloud Agent (per phase) | Yes | Spawns subagents, commits, reports **one line** |

**Start:** Actions → **Conductor** → `workflow_dispatch` (task prompt, issue, `batch_budget`, optional `auto_chain`).

**V1 (cursor.com/agents UI):** `.cursor/rules/orchestrator-subagent-workflow.mdc` — same subagents, one session. Full-ticket V1 metrics import after merge (`v1-metrics`). A Conductor XL handoff imports on the tick commit — see `.github/conductor/metrics/README.md`.

## 2. Three names (do not mix)

| Name | What it is | What it is not |
|---|---|---|
| **Prompt basename** | Task-prompt filename without `.md`, **including** `PROMPT_` | A ROADMAP id, a branch slug |
| **Ticket** | `migration-docs/tickets/active/<prompt-basename>_plan.md` | Named after `slug` / `branch` |
| **Branch slug** | Conductor `slug` / `branch` → `feature/<slug>` only | The ticket filename |

Example: prompt `PROMPT_2_7_1_Snapshot-Three-Stage-Uninstall.md` → ticket `…/PROMPT_2_7_1_Snapshot-Three-Stage-Uninstall_plan.md`. Branch may be `feature/snapshot-three-stage-uninstall`.

| Term | Meaning |
|---|---|
| **Roadmap Step X.Y** | One `.cursor/ROADMAP.md` row → one prompt → one ticket → one PR |
| **Checklist Step N** | One Architect checkbox. In the ticket loop, "Step N" means this. |

## 3. Pipeline

```
Task Prompt + ROADMAP
        │
        ▼
PLAN     architect → plan-reviewer ⇄ FAIL → architect
         PASS → doc-writer → commit + push
        │
        ▼
EXECUTE  per batch (Conductor) / all remaining steps (V1)
         S/M/L: refactorer → verifier → tester → [test-writer…] → doc-writer → tick
         last (XL): Conductor **stops** (`handoff_xl`, default on) — `/review-bugbot` /
         `/review-security` are not on the Cloud Agents API yet (CLI coming soon).
         Run XL on cursor.com/agents (V1): Bugbot → Security → E2E → doc-writer → tick.
         The tick commit imports that V1 parent agent's tokens into the Conductor
         session (`import-xl-handoff-metrics.yml`). Re-dispatch Conductor with the
         same `session_id` for FINALIZE. (`handoff_xl=false` launches XL as a cloud batch.)
        │
        ▼
FINALIZE PR → CI → [coverage] → PR Bugbot → PR Security
         → version (product only) → CHANGELOG/ROADMAP → archive ticket
```

Weights: `S=1` `M=2` `L=4` `XL=8`. Last checklist step is always `(XL)` Review + E2E; alone when `batch_budget` < 8 (default 6).

Progress = `- [x]` in ticket `## EXECUTION STATE`. Skip test-writer when the ticket says so or the step changed no production PHP under `app/` / `packages/`.

## 4. Where to edit behaviour

| Concern | File |
|---|---|
| Product DNA / No Mercy | `.cursor/rules/pagekit.mdc` (always on) |
| PHP / frontend | `.cursor/rules/php.mdc`, `frontend.mdc` |
| V2 Plan / Execute / Finalize | `.cursor/rules/orchestrator-v2-plan.mdc` (etc.) |
| V1 one-session delta | `.cursor/rules/orchestrator-subagent-workflow.mdc` |
| Subagent role | `.cursor/agents/<name>.md` |
| Commit + PR + bump gate | `.cursor/skills/push/SKILL.md`, `version-bump` |
| PR labels | `.cursor/rules/github-labels.mdc` |
| Ticket format | `migration-docs/tickets/README.md` |
| Branch doc schema | `migration-docs/branches/branch-doc-skeleton.md` |
| Conductor driver | `.github/conductor/conductor.mjs`, `conductor.yml` |

## 5. Paths

| Artifact | Path |
|---|---|
| Task prompt | `migration-docs/TODO/agent_prompts/*.md` |
| Ticket | `migration-docs/tickets/{active,done}/<prompt-basename>_plan.md` |
| Branch doc | `migration-docs/branches/phase-<X>/step-<X>-<Y>-<Z>-<kebab>.md` |
| Feature branch | Conductor-owned `feature/<slug>` |

## 6. Subagent models

Edit models in `.cursor/agents/<name>.md`; keep this table in sync.

| Agent | Model (frontmatter) |
|---|---|
| `architect` | claude-fable-5 (Max thinking) |
| `refactorer` | claude-opus-5 (Max thinking) |
| `doc-writer` | grok-4.6 |
| `plan-reviewer` | claude-fable-5 (Max thinking) |
| `verifier` | claude-opus-5 (Max thinking) |
| `tester` | grok-4.6 |
| `test-writer` | claude-opus-5 (Max thinking) |
