# Pagekit Modernization: Subagent Workflow (V2)

**Last updated:** 2026-08-10

Autonomous modernization runs via the **Conductor** (GitHub Actions) and the V2 Orchestrator rules. This document is the **human and agent reference** for that pipeline.

> **V1 (cursor.com/agents UI):** use `.cursor/rules/orchestrator-subagent-workflow.mdc` — same subagent
> names. Launch Input: Task prompt / Branch / Base / Issue only. Token/phase metrics for V1 UI runs are
> **not** recorded mid-Orchestrator (Cursor usage settles after the agent finishes); import manually
> after the ticket with `import-manual-agents.mjs` (see `.github/conductor/metrics/README.md`).

---

## 1. Two roles: Conductor vs. Orchestrator

| Component | Where | LLM? | Responsibility |
| --- | --- | --- | --- |
| **Conductor** | GitHub Actions (`conductor.mjs`) | No | Outer loop: one cloud-agent phase per GHA job (Plan → Execute batches → Finalize); auto-chains the next run; reads ticket checkboxes |
| **Orchestrator** | Cursor Cloud Agent (per phase) | Yes | Thin coordinator: delegates to subagents, commits, reports **exactly one line** |

**Start a run:** GitHub → Actions → **Conductor** → `workflow_dispatch` (task prompt path, issue, `batch_budget`, optional `auto_chain`).

**Chained runs:** With `auto_chain=true` (default), each GHA job runs at most one phase — PLAN, one EXECUTE batch (sized by `batch_budget` + S/M/L/XL hints in the ticket), or FINALIZE — then dispatches the next workflow run automatically. Progress still lives in ticket checkboxes; manual re-run works the same as before. Set `auto_chain=false` to pause between jobs.

**Metrics:** Each Conductor run gets an auto-generated UUID `sessionId` (chained across jobs). Token usage and phase timing are committed to `.github/conductor/metrics/` and shown on the [GitHub Pages Roadmap](https://shadesman5.github.io/pagekit/project/roadmap/). V1 UI runs can use the same store via post-hoc `import-manual-agents.mjs --push` (`source: v1-ui`) — not during the Orchestrator loop.

Operative contracts: `orchestrator-v2-plan.mdc`, `orchestrator-v2-step.mdc`, `orchestrator-v2-finalize.mdc` — each defines a **Delegation protocol** (Task `subagent_type` + structured `prompt` templates).

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
| `architect` | Plan | Ticket + checklist + `EXECUTION STATE` (S/M/L/XL) + `TESTING STRATEGY` |
| `plan-reviewer` | Plan | Gate: plan vs. task prompt / ROADMAP → PASS / FAIL |
| `doc-writer` | Plan, Execute, Finalize | Branch doc (living artifact); at Finalize also CHANGELOG + README |
| `refactorer` | Execute, Finalize (fix loops) | Production code, No Mercy |
| `verifier` | Execute, Finalize | Static review (production or `scope: test files only`) |
| `tester` | Execute, Finalize | Sole test runner (PHPUnit, PHPStan, E2E) |
| `test-writer` | Execute, Finalize | PHPUnit after green production gate (Execute); optional Codecov gap pass (Finalize step 3) |
| `bugbot` / `security-review` | Execute `(XL)` only | Pre-PR Bugbot + Security on branch diff (Task subagents) |

Batch weights: `S=1`, `M=2`, `L=4`, `XL=8`. The last checklist step is always `(XL) — Review (Bugbot + Security) + E2E` and runs alone when `batch_budget` &lt; 8 (default 6).

PR Bugbot + PR Security in Finalize are **mandatory** (`bugbot run` / `security run` + wait for new
`cursor[bot]` outcomes). Execute `(XL)` Task reviews do not replace them. CI `security-audit` is not
Cursor Security Review.

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
│  Work steps (S/M/L):                                        │
│    A) refactorer → verifier → tester           (production) │
│    B) test-writer → verifier (tests) → tester   (optional)  │
│    C) doc-writer → commit + tick                            │
│  Last step (XL) — own batch under default budget:           │
│    Bugbot ⇄ fix-loop → Security ⇄ fix-loop → E2E            │
│    → doc-writer → commit + tick                             │
└────────────────────────────────────────────────────────────┘
        │
        ▼
┌─ FINALIZE ─────────────────────────────────────────────────┐
│  Push + PR → CI gate → [Codecov gap pass] → PR Bugbot       │
│  (mandatory) → PR Security (mandatory; ≠ security-audit)   │
│  → version bump → doc-writer (close + CHANGELOG)             │
│  → ROADMAP → archive ticket to done/ → push                 │
└────────────────────────────────────────────────────────────┘
```

**State:** Progress = `- [x]` in `## EXECUTION STATE` on the ticket (one commit per Checklist Step).

**Skip test-writer (Execute)** when: the ticket marks `test-writer: skip` **or** the step changed no production PHP under `app/` / `packages/`.

**Skip coverage gap pass (Finalize step 3)** when: ticket `test-writer: skip`, no Codecov comment, or only non-testable gaps (views, config version).

**Review + E2E:** Only on the mandatory last `(XL)` Execute step — before PR/CI.

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

**Summary:** The Conductor drives Plan → Execute batches → Finalize as a chain of short GHA jobs (one cloud-agent call each). `batch_budget` (S=1, M=2, L=4, XL=8) controls how much work fits into one Execute agent; the last `(XL)` Review+E2E step runs alone under the default budget of 6. `auto_chain` controls whether the next job starts automatically. Each phase delegates to subagents; `doc-writer` maintains documentation throughout; `test-writer` adds tests after a green production gate (Execute) and optionally closes Codecov patch gaps before Bugbot (Finalize). Progress lives in ticket checkboxes and git.
