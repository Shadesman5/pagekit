# Pagekit Modernization: Subagent Workflow (V2)

**Last updated:** 2026-07-13

Autonomous modernization runs via the **Conductor** (GitHub Actions) and the V2 Orchestrator rules. This document is the **human and agent reference** for that pipeline.

> **Manual IDE runs:** Rare — use `.cursor/rules/orchestrator-subagent-workflow.mdc` (V1; same subagent names).

---

## 1. Two roles: Conductor vs. Orchestrator

| Component | Where | LLM? | Responsibility |
| --- | --- | --- | --- |
| **Conductor** | GitHub Actions (`conductor.mjs`) | No | Outer loop: Plan → Execute batches → Finalize; reads ticket checkboxes; launches fresh cloud agents |
| **Orchestrator** | Cursor Cloud Agent (per phase) | Yes | Thin coordinator: delegates to subagents, commits, reports **exactly one line** |

**Start a run:** GitHub → Actions → **Conductor** → `workflow_dispatch` (task prompt path, issue, base branch).

Operative contracts: `orchestrator-v2-plan.mdc`, `orchestrator-v2-step.mdc`, `orchestrator-v2-finalize.mdc`.

---

## 2. Terminology

| Term | Meaning |
| --- | --- |
| **Roadmap Step X.Y** | One modernization task in `.cursor/ROADMAP.md` → **one task prompt = one ticket = one PR** |
| **Checklist Step N** | One item in the Architect's checklist inside the ticket — Execute iterates over these |

Inside the per-ticket loop, plain "Step N" always means **Checklist Step N**.

---

## 3. Subagents (`.cursor/agents/`)

| Agent | Phase | Role |
| --- | --- | --- |
| `architect` | Plan | Ticket + checklist + `EXECUTION STATE` (S/M/L) + `TESTING STRATEGY` |
| `plan-reviewer` | Plan | Gate: plan vs. task prompt / ROADMAP → PASS / FAIL |
| `doc-writer` | Plan, Execute, Finalize | Branch doc (living artifact); at Finalize also CHANGELOG + README |
| `refactorer` | Execute, Finalize (fix loops) | Production code, No Mercy |
| `verifier` | Execute, Finalize | Static review (production or `scope: test files only`) |
| `tester` | Execute, Finalize | Sole test runner (PHPUnit, PHPStan, E2E) |
| `test-writer` | Execute, Finalize | PHPUnit after green production gate (Execute); optional Codecov gap pass (Finalize step 3) |

Bugbot in the cloud: **PR Bugbot** (Finalize), not a local subagent.

---

## 4. Pipeline (end-to-end)

```
Task Prompt + ROADMAP
        │
        ▼
┌─ PLAN ─────────────────────────────────────────────────────┐
│  architect → plan-reviewer (⇄ on FAIL)                     │
│  PASS → doc-writer (branch doc skeleton) → commit + push   │
└────────────────────────────────────────────────────────────┘
        │
        ▼
┌─ EXECUTE (per batch, Conductor) ───────────────────────────┐
│  Per Checklist Step N:                                      │
│    A) refactorer → verifier → tester           (production) │
│    B) test-writer → verifier (tests) → tester   (optional)  │
│    C) [E2E on last step] → doc-writer → commit + tick       │
└────────────────────────────────────────────────────────────┘
        │
        ▼
┌─ FINALIZE ─────────────────────────────────────────────────┐
│  Push + PR → CI gate → [Codecov gap pass] → PR Bugbot       │
│  → version bump → doc-writer (close branch doc + CHANGELOG) │
│  → ROADMAP → archive ticket to done/ → push                 │
└────────────────────────────────────────────────────────────┘
```

**State:** Progress = `- [x]` in `## EXECUTION STATE` on the ticket (one commit per Checklist Step).

**Skip test-writer (Execute)** when: the ticket marks `test-writer: skip` **or** the step changed no production PHP under `app/` / `packages/`.

**Skip coverage gap pass (Finalize step 3)** when: ticket `test-writer: skip`, no Codecov comment, or only non-testable gaps (views, config version).

**E2E:** On the **last Execute step** (all other checkboxes already `[x]`), **before** PR/CI — not on every step.

---

## 5. Writing task prompts

- **Keep prompts slim** — reference ROADMAP and rules; do not duplicate them.
- **Scope:** ROADMAP step ID(s), goal, output paths.
- **One Checklist Step = one commit** — decompose the Architect checklist accordingly.
- **Audits:** `<!-- conductor-mode: plan -->` in the prompt → report + docs-only PR only (no executable ticket).
- **Per-step audit template:** `migration-docs/TODO/agent_prompts/AGENT_PROMPT_AUDIT_STEP_TEMPLATE.md`

**Normal:** One ROADMAP step = one task prompt.  
**Exception:** One prompt for many steps (e.g. full Phase 1 audit) — only when explicitly intended.

---

## 6. Key paths

| Artifact | Path |
| --- | --- |
| Task prompt | `migration-docs/TODO/agent_prompts/*.md` |
| Ticket (active) | `migration-docs/tickets/active/{task-slug}_plan.md` |
| Ticket (done) | `migration-docs/tickets/done/{task-slug}_plan.md` |
| Branch doc | `migration-docs/branches/phase-<X>/step-<X>-<Y>-<Z>-<kebab>.md` |
| Branch doc skeleton | `migration-docs/branches/branch-doc-skeleton.md` |
| Feature branch | `feature/{task-slug}` (Conductor-owned) |

`task-slug` = task prompt filename without path or `.md`.

---

## 7. Subagent models (frontmatter)

| Agent | Model (as of 2026-07) |
| --- | --- |
| `architect` | claude-opus-4-8 (Max thinking) |
| `refactorer` | claude-opus-4-8 (Max thinking) |
| `doc-writer` | claude-opus-4-8 (Max thinking) |
| `plan-reviewer` | claude-fable-5 (Max thinking) |
| `verifier` | claude-fable-5 (Max thinking) |
| `tester` | claude-opus-4-6 (Max thinking) |
| `test-writer` | composer-2.5 |

Edit models in `.cursor/agents/<name>.md`; keep this table in sync.

---

## 8. Quick reference

| You want to… | Do this… |
| --- | --- |
| Start a ticket | Conductor `workflow_dispatch` with task prompt + issue |
| Change subagent behavior | Edit `.cursor/agents/<name>.md` |
| Change workflow phases | Edit `orchestrator-v2-*.mdc` and optionally `conductor.mjs` |
| Ticket / handoff conventions | `migration-docs/tickets/README.md` |
| Branch doc format | `migration-docs/branches/branch-doc-skeleton.md` |
| Push / version / PR metadata | `push.mdc`, `github-labels.mdc` |

---

**Summary:** The Conductor drives Plan → Execute batches → Finalize. Each phase delegates to subagents; `doc-writer` maintains documentation throughout; `test-writer` adds tests after a green production gate (Execute) and optionally closes Codecov patch gaps before Bugbot (Finalize). Progress lives in ticket checkboxes and git.
