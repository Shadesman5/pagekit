# Orchestrator Workflow V2 — Stateless Per-Step Pipeline (Design Proposal)

> **Status:** 🟡 Implemented, awaiting first real run — the agent/rule **contracts** (`plan-reviewer`;
> `architect` size hints + `EXECUTION STATE`; phase rules `orchestrator-v2-plan`/`-step`/`-finalize`),
> the `preCompact` **hook**, the ticket **`active/`/`done/`** folders, **`CODEOWNERS`**, and the
> **Conductor** itself (`.github/workflows/conductor.yml` + `.github/conductor/conductor.mjs`, REST /
> zero-dep) are in place. It has **not been run yet** (the v1 API is beta — verify response field
> names on the first dispatch). Until a green run, **V1 (`orchestrator-subagent-workflow.mdc`) remains
> authoritative**.
> **Author:** Design draft for discussion
> **Scope:** This document describes a redesign of the **agent pipeline** (the meta-workflow that
> modernizes Pagekit), **not** the Pagekit product code. It supersedes nothing yet; the current
> [`orchestrator-subagent-workflow.mdc`](../../.cursor/rules/orchestrator-subagent-workflow.mdc)
> stays authoritative until V2 is accepted and rolled out.
> **Constraint baseline:** Cursor **Ultra** plan, autonomous workflows are started **in the Cursor
> Cloud environment** and **never on a local machine**.
> **Audience:** This document is a **human reference**. The agent-facing rules
> (`.cursor/rules/orchestrator-v2-*.mdc`, `.cursor/agents/*.md`) are **self-contained and deliberately
> do NOT link here** — agents follow path-trails, and pulling a long doc into an Orchestrator's
> context is exactly what V2 avoids.

## Decision Log (2026-06-28)

Resolved after reviewing the live CI configuration and the Cursor API:

1. **`[skip ci]` / CI cost** — The PHP Quality workflow is **branch-scoped to `main`/`develop` + PRs**
   (see §6). Per-step pushes to a `feature/*` branch therefore **never trigger CI to begin with** —
   `[skip ci]` is redundant for them and is kept only as a defensive safeguard. CI runs **once** when
   the Finalize agent opens the PR. `[skip ci]` is genuinely useful only on **Finalize mini-loop fix
   commits** to an already-open PR (and must be omitted on the last commit, or a required check stays
   `Pending` and blocks merge).
2. **Conductor host** — **GitHub Actions** (decided). Self-chaining cloud agents remain a documented
   pure-in-cloud fallback (§9).
3. **Granularity** — **Step batching by Architect-provided size hints** (S/M/L), not context-%.
   A reliable, tunable "stop at 40–50 % context" signal is not exposed to an agent; the robust lever
   is a deterministic batch size `K` derived from size hints, made safe by atomic per-step commits
   (§4.4). An optional `preCompact` "wrap up" safety valve is available but secondary.
4. **Architect** — Runs as **Step 0** (not manual) and is gated by a new **Plan-Reviewer** agent that
   checks the plan against the original requirement/prompt before the step loop starts (§5, §7). This
   mirrors the Refactorer ↔ Verifier loop at the planning level.

## Decision Log (2026-06-29)

5. **Conductor vs Orchestrator separation (corrected).** The **Orchestrator** is the main cloud agent
   the Conductor launches **per phase** (Plan / each Execute batch / Finalize). It owns the inner
   subagent loops (Architect ⇄ Plan-Reviewer; Refactorer → Verifier → Tester) and commits/ticks/
   pushes — exactly the V1 coordinator, just no longer long-lived/global. The **Conductor** (GitHub
   Actions, no LLM) drives **only** the outer loop: read checkboxes → compute batch → launch one
   Orchestrator → wait → repeat → Finalize. It does **not** handle normal FAILs; it reacts only to an
   Orchestrator's `ESCALATE` (repeated identical FAIL) or a fatal/run error (§5, §5.2).
6. **Batch budget — configurable, default 6** (weights `S=1, M=2, L=4`). Budget 6 lets an `M` pair
   with an `L` (`2+4`) so a medium step isn't stranded before a large one (and `S+L = 5` still fits);
   two `L`s never share an agent (`8 > 6`). Raising to 7 also allows `S+M+L` (7) at more context per
   agent. It is a tuning knob — measure per-run token usage and adjust. See §4.4.
7. **`preCompact` safety valve — implemented now** (`.cursor/hooks.json`). On context compaction it
   writes `/tmp/precompact-stop`; the Execute-phase Orchestrator stops after the current step, making
   aggressive batching safe (§4.4).
8. **Conductor = REST (zero-dependency)**, built in `.github/conductor/conductor.mjs`. When actually
   implementing, plain `fetch` against the documented v1 endpoints proved safer than the beta SDK:
   verified field names, no `@cursor/sdk` version drift, and **no npm supply chain** for the conductor
   (nothing to `npm install`). The SDK (`@cursor/sdk`) stays an easy drop-in later for its typed
   errors/ergonomics — just swap the `api()`/`poll()` helpers.
9. **Cursor Automations are a *trigger* option, not the loop.** They can only *start* agents (cron /
   GitHub events / Slack / webhook). The Conductor remains the sequencer; GitHub Actions already
   covers triggering (`workflow_dispatch` / label / schedule), so Automations are **dropped** unless a
   non-GitHub entry point (e.g. Slack) is wanted later.
10. **Finalize stays a separate agent by default** (mergeable into the last Execute batch as an opt-in
    `INLINE_FINALIZE`), because Bugbot + Final Test + mini-loops are the heaviest/most failure-prone
    part; a fresh agent is safer (§5, §12).
11. **Branch management — Conductor-owned (not `environment.json`/`install.sh`).** The Conductor
    creates `feature/{slug}` once (from the task-slug) before the Plan phase and launches **every**
    phase with `workOnCurrentBranch: true` + `startingRef: feature/{slug}`, so Cursor pushes straight
    to it and **no `cursor/*` auto-branch is created**. Branch naming is a launch-parameter concern;
    `install.sh` runs inside the VM *after* the branch is chosen and cannot rename it (§6.4).
12. **Model is chosen per launch — including `auto`.** `POST /v1/agents` takes `model.id`, so the
    Conductor sets the Orchestrator's model per phase; `model.id = "auto"` lets the server pick (the
    API equivalent of the IDE's *Auto*). **Subagents keep their own models** from their
    `.cursor/agents/*.md` frontmatter, so a cheap/`auto` coordinator doesn't weaken the thinking work
    (§8.1, §11).
13. **Rules are self-contained & agent-lean.** The V2 phase rules **do not** reference this design doc
    or the V1 rule — agents follow path-trails and that would blow context. This document is a
    **human-only** reference; everything an Orchestrator needs is inline in its rule, and subagent
    behavior lives in the subagent files (no duplication).
14. **Branch-verify first.** Each Orchestrator's **first** action is to verify it is on the named
    branch and `git checkout` it if not (cloud agents can switch/create branches freely; only renaming
    is impossible). The Conductor passes the branch **name** in the launch prompt (§6.4).
15. **Observability + live control.** Conductor logs per-run token usage + artifacts and surfaces
    flaky env starts; hard stop = cancel the Actions run (script cancels the in-flight cloud run),
    soft stop/pause via `conductor:stop` / `conductor:pause` labels checked at phase boundaries (§8.6).
16. **Agent lifecycle.** No auto-archive on completion; optional cron archive of >30–60-day runs
    (reversible); Cursor does not auto-archive (§8.6).
17. **GitHub hardening.** Conductor is `workflow_dispatch`-only (collaborators only), with minimal
    `GITHUB_TOKEN` permissions and SHA-pinned actions; `CODEOWNERS` guards `.github/**` + `.cursor/**`
    so workflow/rule changes require owner review (§9).

---

## 1. Problem Statement

### 1.1 Current architecture (V1)

Today a single **Orchestrator** (the main Cloud Agent the user starts) drives one Roadmap Step end to end:

```
Task Prompt (one Roadmap Step X.Y)
   │
   ▼  ARCHITECT (subagent)  → migration-docs/tickets/{slug}_plan.md
   ▼  LOOP per Checklist Step:  REFACTORER → VERIFIER → TESTER → commit
   ▼  LOCAL BUGBOT REVIEW (once per PR)
   ▼  EARLY PUSH  → CI (PHP Quality) + gh pr create
   ▼  FINAL TEST  → wait on CI + Playwright E2E
   ▼  FINALIZE    → branch doc + version bump + CHANGELOG + ROADMAP → second push
```

The Orchestrator is already a thin dispatcher (one-line handoffs, one-line subagent returns).

### 1.2 The bottleneck

Even with terse handoffs, the Orchestrator's context grows **monotonically** over a ticket:

- Every subagent invocation returns at least one line that lands in the Orchestrator's context.
- Every **fail-loop** (Verifier FAIL → Refactorer → Verifier → Tester …) adds unbounded extra turns.
- A long ticket (many Checklist Steps × occasional loops) accumulates a large, ever-growing transcript
  in **one** agent — driving cost (Cloud Agents always run in Max Mode) and reducing reliability the
  longer the run goes.

The root cause is structural: **one long-lived agent holds the state of the entire ticket in its
context window.**

---

## 2. Goals & Non-Goals

### 2.1 Goals

1. **Bounded context per agent.** No single agent should accumulate the transcript of more than a
   small **batch** of Checklist Steps (plus their fail-loops).
2. **Externalize the loop.** The "for each Checklist Step" loop should live in **plain code with zero
   LLM context**, not inside an agent's head.
3. **Durable, inspectable progress.** Restarting after a crash must be trivial and must not re-do
   completed steps.
4. **No local execution.** The driver must run in GitHub Actions and/or the Cursor Cloud — never on
   the user's machine.
5. **Preserve the existing role separation.** Architect / Refactorer / Verifier / Tester / Bugbot
   responsibilities and the No-Mercy rules stay exactly as they are.
6. **CI runs once per PR**, as today — not once per Checklist Step.

### 2.2 Non-Goals

- Changing what the existing subagents *do* (their `.cursor/agents/*.md` definitions are reused).
- Changing the Roadmap / ticket philosophy (one Roadmap Step = one Ticket = one PR).
- Parallelizing Checklist Steps. The pipeline stays **sequential** (steps depend on each other).
- Building a general-purpose workflow engine. This is a thin, purpose-built conductor.

---

## 3. Core Design

Four ideas combine:

1. **Two distinct roles: Conductor (code) vs Orchestrator (cloud agent).** The **Conductor** is plain
   code in GitHub Actions with **zero LLM context**; it drives only the **outer** loop. The
   **Orchestrator** is the main cloud agent — the same thin coordinator as in V1 — but launched
   **fresh per phase** (Plan / each Execute batch / Finalize) instead of living for the whole ticket.
   The Orchestrator spawns the subagents and runs the inner loops; the Conductor never touches code.

2. **A gated plan (Plan phase).** The Conductor launches a Plan-phase Orchestrator that runs the
   **Architect ⇄ Plan-Reviewer** loop until the plan is approved, then commits it. Only an approved
   plan unlocks the Execute phase.

3. **Git is the state store.** Progress is persisted in the ticket's `EXECUTION STATE` checkboxes
   (committed + pushed). A fresh Orchestrator reads the current state from the freshly cloned branch —
   it needs no memory of prior phases.

4. **The Conductor owns the outer loop + escalation only.** It reads the checkboxes + size hints,
   computes the next batch, launches one Execute-phase Orchestrator, waits, repeats, then launches
   Finalize. It handles **only** escalations (an Orchestrator reporting `ESCALATE` after a repeated
   identical FAIL) and fatal errors — never normal FAIL→retry, which the Orchestrator does internally.

```
┌──────────────────────────────────────────────────────────────────┐
│ CONDUCTOR  (plain code, 0 LLM context — GitHub Actions)            │
│  outer loop only: read checkboxes + size hints → compute batch →   │
│  launch 1 Orchestrator (cloud agent) per phase → poll → repeat.    │
│  Reacts only to ESCALATE / fatal errors, never to normal FAILs.    │
└──────────────────────────────────────────────────────────────────┘
        │ POST /v1/agents (fresh Orchestrator) ... poll ... repeat
        ▼
  ┌───────────────────────────┐ ┌──────────────────────────┐ ┌─────────────────────┐
  │ ORCHESTRATOR — Plan phase  │ │ ORCHESTRATOR — Execute    │ │ ORCHESTRATOR —       │
  │  ARCHITECT ⇄ PLAN-REVIEWER │→│  phase (1 batch ≤ budget) │→│ Finalize phase       │
  │  loop → commit ticket,     │ │  per step: refactorer→    │ │  Bugbot → PR → Final │
  │  push                      │ │  verifier→tester→commit→  │ │  Test → version/     │
  │                            │ │  tick→push                │ │  CHANGELOG/ROADMAP   │
  └───────────────────────────┘ └──────────────────────────┘ └─────────────────────┘
     each Orchestrator spawns the SAME subagents internally (via the Task tool)
```

The Orchestrator is exactly today's coordinator **minus the outer loop** (and, in the Execute phase,
capped at one batch). The outer loop moved out into the Conductor.

---

## 4. State Model — The Ticket as a State Machine

### 4.1 Single source of truth

The Architect's ticket (`migration-docs/tickets/active/{task-slug}_plan.md`) becomes the **state machine**.
A dedicated, machine-readable block tracks execution state with GitHub-style checkboxes **and a size
hint per step**:

```
## EXECUTION STATE (maintained by step orchestrators)
<!-- size hints: S = small, M = medium, L = large/loop-risk -->
- [x] Step 1 (S) — <title>
- [x] Step 2 (S) — <title>
- [ ] Step 3 (M) — <title>
- [ ] Step 4 (L) — <title>
```

Rules:

- **Architect** writes all steps unchecked (`- [ ]`) and assigns each a size hint `(S|M|L)`.
- A step is completed in **one commit**: the step orchestrator flips `- [ ]` → `- [x]` for that step
  **in the same commit as the step's code** (after Tester PASS), then pushes. There is **no
  self-referential commit SHA** in the ticket — a commit cannot contain its own hash, and it is
  unnecessary (`git log -- <ticket>` shows which commit ticked which step; the Conductor only reads
  `[ ]` vs `[x]`).
- The **Conductor** never edits the ticket; it only *reads* it to compute the next batch.

### 4.2 Why a state file instead of "infer from commits"

Inferring progress from commit messages/counts is fragile (mini-loop fix commits, amended commits,
doc commits all confuse a counter). An explicit checkbox list is **robust** (one boolean per step),
**human-readable**, **crash-safe** (re-running the Conductor skips checked steps), and is **already a
durable artifact** (tickets are committed and land in the PR, per V1 Rule 5).

### 4.3 How "knowing where to continue" works

| Concern | Mechanism |
| --- | --- |
| *Which steps are next?* | Conductor reads the unchecked `- [ ] Step N` items + their size hints and computes the next batch; it passes the exact step numbers in the agent prompt. |
| *Does the next agent see prior work?* | Each step commits **and pushes** to the feature branch, so the next fresh cloud agent clones the up-to-date branch. |
| *Why `[skip ci]` on those pushes?* | Defensive only — feature-branch pushes don't trigger CI anyway (see §6). |

### 4.4 Step size hints & batching (resolves "one agent per step is too much")

**The problem:** one fresh cloud agent per *mini* step wastes cold-start overhead (clone +
`install.sh` build). One agent per *large/loop-heavy* step is exactly what we want.

**The lever:** a deterministic **batch size**, not a context percentage.

- The **Architect tags each step `S` / `M` / `L`** (L also denotes "loop-risk"). This info is cheap
  for the Architect — it already reasons about each step's scope.
- The **Conductor batches greedily up to a budget**: weights `S=1`, `M=2`, `L=4`, filling a batch up
  to `BATCH_BUDGET` (configurable, **default 6**). Consequences at budget 6:
  - Consecutive small/medium steps share **one** agent (amortized cold start), e.g. `M+M+S = 5`,
    `S+S+L = 6`.
  - An `M` (2) can pair with an `L` (`2+4 = 6`), and an `S` with an `L` (`1+4 = 5`) — so a small or
    medium step is **never stranded** as its own agent just because the next step is large.
  - Two `L`s never share an agent (`8 > 6`); a lone `L` runs alone — full fresh context for the risky
    work, which is exactly what loop-risk steps want.
  - Raising to **7** additionally allows `S+M+L` (7); higher budgets trade bounded context for fewer
    cold starts. **This is a tuning knob** — instrument per-run token usage (`GET /v1/agents/{id}/usage`)
    and adjust. The `preCompact` valve (below) is the backstop if a batch balloons. Defaults live in
    the Conductor config, not in prompts.
- **Why this is safe at any boundary:** because each step is **atomically committed + pushed +
  ticked**, the batch boundary is purely an optimization. If an agent does 2 of 3 planned steps and
  stops, the next fresh agent simply continues from the first unchecked box. No work is lost.

**Why not "stop at 40–50 % context":**

- An agent cannot **reliably and tunably** read its own live context-fill percentage to branch on it
  mid-run; self-estimates are unreliable.
- The one concrete, cloud-supported "context is filling" signal is the **`preCompact` hook** (fires
  just before auto-compaction) — but that is *near-full* (~the harness's compaction threshold), not a
  tunable 40–50 %.
- The API exposes token usage post-hoc (`GET /v1/agents/{id}/usage`), but the Conductor cannot cleanly
  *interrupt* a step mid-flight without aborting uncommitted work.

**Safety valve (implemented):** `.cursor/hooks.json` registers a `preCompact` command hook that runs
`touch /tmp/precompact-stop` when the run approaches context compaction. The Execute-phase
Orchestrator checks that file **after finishing each step** (commit + tick + push) and, if present,
stops and reports `Batch stopped (preCompact)` instead of starting another step; the Conductor then
continues with a fresh agent. This caps context even if a batch balloons, making budget-5 batching
safe. (The hook is harmless to V1 and to local IDE agents — nobody else reads the sentinel, and each
fresh cloud agent starts with a clean `/tmp`.)

### 4.5 Ticket lifecycle folders: `active/` and `done/`

Tickets are split into two subfolders so a fresh agent cannot accidentally read an unrelated ticket:

- **`active/`** holds in-progress tickets. The Architect writes `migration-docs/tickets/active/{task-slug}_plan.md`; step orchestrators read + tick it there.
- **`done/`** holds completed tickets. **Finalize** runs `git mv active/{slug}_plan.md done/` as part of its commits, so a merged PR leaves the ticket in `done/`.

This is a **ticket-level lifecycle marker** — orthogonal to the per-step checkbox state (§4.1) and to the context-pressure sentinel (§4.4). The primary cross-read guard remains the explicit ticket path in each handoff; the folder split keeps the `active/` namespace minimal as defense-in-depth. (Implemented alongside the V2 agent contracts; historical tickets were archived into `done/` in one batch.)

---

## 5. Component Responsibilities

| Component | Runs where | Holds LLM context? | Responsibility |
| --- | --- | --- | --- |
| **Conductor** | GitHub Actions runner | **No** (plain code) | **Outer loop only.** Reads the ticket's `EXECUTION STATE` checkboxes + size hints, computes the next batch, launches **one Orchestrator cloud agent per phase** (Plan / Execute batch / Finalize) via the API, polls until done, repeats. Handles **only** `ESCALATE` (relaunch a fresh Orchestrator) and fatal/run errors. Never runs subagents, never reads code, never handles normal FAILs. |
| **Orchestrator** | Cloud agent (fresh **per phase**) | Yes (1 phase) | The main cloud agent — the V1 thin coordinator, no longer global. Spawns subagents via the Task tool, runs the inner loops for its phase (Plan: Architect ⇄ Plan-Reviewer; Execute: Refactorer → Verifier → Tester per step; Finalize: Bugbot/CI/E2E), commits/ticks/pushes, and reports a one-line result (`… done` / `ESCALATE` / `Finalized`). Rules: `orchestrator-v2-{plan,step,finalize}.mdc`. |
| **Architect** (subagent) | Inside the Plan Orchestrator | Yes (own) | Reads task prompt + ROADMAP, writes the ticket with the checklist **+ `EXECUTION STATE` size hints**, returns the path. (`.cursor/agents/architect.md`.) |
| **Plan-Reviewer** (subagent) | Inside the Plan Orchestrator | Yes (own) | **NEW.** Audits the plan vs. the original requirement: coverage (no missing requirement / no scope drift), ROADMAP alignment, decomposition quality, size hints, audit findings. `PASS` / `FAIL` + bullets. (`.cursor/agents/plan-reviewer.md`.) |
| **Refactorer / Verifier / Tester / Bugbot** (subagents) | Inside the Execute / Finalize Orchestrator | Yes (own) | **Unchanged** from V1. Spawned by the Orchestrator. |

Key property: the **Conductor** is the only component that survives across all phases, and it holds
**no LLM context at all**. Every LLM-bearing component (Orchestrator + its subagents) is short-lived
and single-purpose.

### 5.1 Plan-Review gate (the Plan-phase Orchestrator owns this loop)

This directly answers *"did the Architect actually build the plan the requirement asked for?"* The
loop runs **inside the Plan-phase Orchestrator** (a cloud agent), not in the Conductor. The
Plan-Reviewer is to the Architect what the Verifier is to the Refactorer:

```
Plan-phase Orchestrator (cloud agent):
   ARCHITECT (subagent) → writes ticket (checklist + EXECUTION STATE size hints)
   PLAN-REVIEWER (subagent) → reads {task prompt / requirement} + {ticket}
        ├─ PASS  → Orchestrator commits + pushes the ticket, then reports "Plan ready"
        └─ FAIL  → Orchestrator re-delegates to Architect with the verbatim feedback ↺
   (if the SAME FAIL recurs 3× → Orchestrator reports ESCALATE; see §5.2)
```

The Conductor's role here is minimal: launch the Plan Orchestrator and, on `ESCALATE`, launch a fresh
one. The Plan-Reviewer uses a strong reviewing model (Opus tier, like the Verifier); its output
discipline matches the Verifier's: `PASS`, or `FAIL` + a short bullet list.

### 5.2 Escalation & stuck-detection (who handles which failure)

The division of failure handling is the heart of the Conductor/Orchestrator split:

- **Normal FAIL → handled by the Orchestrator, internally.** Verifier/Tester/Plan-Reviewer FAIL →
  the Orchestrator re-delegates to the Refactorer/Architect with the verbatim feedback and re-runs
  the inner loop. The Conductor is not involved.
- **Repeated identical FAIL → `ESCALATE` to the Conductor.** The Orchestrator tracks consecutive
  **identical** FAILs; after **3** it stops and returns `ESCALATE: <reason>`. The Conductor launches
  a **fresh** Orchestrator for the same phase/work (fresh context frequently breaks a stuck loop).
- **Second escalation → human.** After `MAX_ESCALATIONS` (default **2**) for the same phase, the
  Conductor hard-stops and flags for human review.
- **Fatal/run error** (run `ERROR`, `409 agent_busy`, repeated startup failure) → Conductor stops +
  human; no blind retries that could duplicate cloud runs.

Why this matters: normal fix-loops never cost a cold start (they stay inside one Orchestrator), while
a genuinely stuck loop is broken by a *fresh* Orchestrator instead of burning unbounded tokens in a
single growing context — the failure-mode version of the same "bounded context" principle.

---

## 6. Branch & CI Strategy (verified against `php-quality.yml`)

### 6.1 What the live CI actually triggers on

```yaml
# .github/workflows/php-quality.yml
on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main, develop]
concurrency:
  group: ${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: true
```

**Implication:** PHP Quality runs **only** for pushes to `main`/`develop` and for PRs targeting
`main`/`develop`. It does **not** run on pushes to `feature/*` branches.

### 6.2 What this means for V2 (and for the `[skip ci]` idea)

| Phase | Branch action | CI behaviour |
| --- | --- | --- |
| Step 0 (Architect/Plan-Reviewer) | commit ticket, push to `feature/{slug}` | **none** (branch not main/develop, no PR) |
| Each Execute-phase batch (Orchestrator) | commit per step, tick box, push | **none** (same reason) |
| Finalize → Early Push + `gh pr create` (base `develop`) | open PR | **CI runs once** (`pull_request: opened`) |
| Finalize → mini-loop fix commits on the open PR | push to PR head | `pull_request: synchronize` → CI re-runs; `cancel-in-progress` keeps only the latest |

So the original worry ("CI runs on every per-step push") **does not apply to this repo** — the branch
filter already prevents it. `[skip ci]` is therefore:

- **Redundant** on Step-0 and per-step feature-branch pushes (kept only as a cheap safeguard in case
  the triggers ever broaden to feature branches).
- **Genuinely useful** on **Finalize mini-loop fix commits** to an already-open PR, to avoid a CI
  re-run per fix. ⚠️ The **last** commit before merge must **not** carry a skip token, or the required
  check stays `Pending` and blocks merge.

### 6.3 GitHub `[skip ci]` reference (platform built-in, GA since 2021)

Recognized tokens (any commit in a push, or the **HEAD** commit of a PR):
`[skip ci]`, `[ci skip]`, `[no ci]`, `[skip actions]`, `[actions skip]`. Trailer alternative:
`skip-checks: true` (two blank lines before it; use `git commit --cleanup=verbatim` to preserve).
**Only `push` and `pull_request` events are affected** (not `pull_request_target`,
`workflow_dispatch`, etc.). A skipped **required** check stays `Pending` → blocks merge until a
non-skip commit is pushed. Source: GitHub Docs "Skipping workflow runs".

### 6.4 Branch creation & naming (Conductor-owned)

The **Conductor creates `feature/{slug}` once** (from the task-slug), before the Plan phase, in its
GitHub Actions checkout — e.g. `git checkout -b feature/{slug} <base> && git push -u origin
feature/{slug}`. It then launches **every** phase Orchestrator with `workOnCurrentBranch: true` and
`startingRef: "feature/{slug}"`, so Cursor pushes **directly to that branch** and **never creates a
`cursor/*` auto-branch** (those appear only with the default `workOnCurrentBranch: false`). The PR is
opened only at Finalize. The Conductor also passes the branch **name** in each launch prompt, and the
Orchestrator's first action is to **verify/checkout** it (defensive — see the phase rules).

> **Why not `environment.json` / `install.sh`?** Branch naming is a *launch-parameter* concern
> (`startingRef` + `workOnCurrentBranch`), decided by the Conductor when it calls the API.
> `install.sh` runs **inside** the cloud VM *after* the branch is already chosen, so it cannot rename
> it. The `cursor/*` names you see today come only from **manual** IDE launches (V1, platform
> default); once the Conductor drives launches, naming is consistently `feature/{slug}`.

---

## 7. Execution Flow (end to end)

```
TRIGGER  (GitHub UI "Run workflow" / issue label / a bootstrap cloud agent calling `gh workflow run`)
   │
   ▼ CONDUCTOR starts (GitHub Actions) — checks out the feature branch
   │
   ├─ PLAN PHASE:
   │     launch ORCHESTRATOR (plan) ──► runs ARCHITECT ⇄ PLAN-REVIEWER internally,
   │                                    commits + pushes the approved ticket
   │     Conductor waits → "Plan ready" → pull branch │ "ESCALATE" → relaunch fresh (≤2) → else human
   │
   ▼ EXECUTE LOOP (Conductor):
   │   pull branch → read EXECUTION STATE → unchecked steps + size hints → compute next batch (≤ 5)
   │      ├─ batch exists → launch ORCHESTRATOR (execute) for those steps
   │      │     orchestrator: per step → refactorer→verifier→tester→commit→tick→push
   │      │     Conductor waits for the run:
   │      │        ├─ "Batch done"                 → loop (pull, next batch)
   │      │        ├─ "Batch stopped (preCompact)" → loop (fresh agent finishes the rest)
   │      │        ├─ "ESCALATE"                   → relaunch fresh (≤2) → else human
   │      │        └─ run ERROR / fatal            → stop + human
   │      └─ none left → break
   │
   ▼ FINALIZE PHASE (Conductor):
   │     launch ORCHESTRATOR (finalize) ──► Bugbot → Early Push (opens PR → CI) → Final Test
   │                                        → version/CHANGELOG/ROADMAP → archive ticket → push
   │     (optional INLINE_FINALIZE: the last Execute orchestrator does this instead)
   │
   ▼ Conductor exits. User reviews & merges the PR (no auto-merge).
```

---

## 8. Cursor API Mapping (verified, June 2026)

All confirmed against the official docs (see §13). The v1 Cloud Agents API is **public beta**.

### 8.1 Launching a fresh Orchestrator (per phase)

- **Endpoint:** `POST /v1/agents` — creates a durable agent (`bc-…`) **and** enqueues its first run
  (`run-…`). Fresh, empty context every time — exactly what each batch needs.
- **Required:** `prompt.text`, plus `repos[].url`.
- **Relevant fields:** `repos[].startingRef = "feature/{slug}"`; `workOnCurrentBranch: true`;
  `skipReviewerRequest: true`; `autoCreatePR: true` **only** on Finalize.
- **Model (per launch):** `model.id` is set on **each** launch, so the Conductor can give each phase a
  different model. `model.id = "auto"` lets the server pick (the API equivalent of the IDE's *Auto*).
  **Subagents keep their own models** from their `.cursor/agents/*.md` frontmatter, so a cheap/`auto`
  Orchestrator coordinator does **not** weaken the Architect/Verifier/Refactorer work. Valid IDs come
  from `Cursor.models.list()` / `GET /v1/models`.
- **Response:** `agent.id` (`bc-…`), `run.id` (`run-…`), `agent.status: ACTIVE`.

### 8.2 Waiting for completion (no v1 webhooks yet)

- **Poll:** `GET /v1/agents/{id}/runs/{runId}` → status
  `CREATING → RUNNING → FINISHED | ERROR | CANCELLED | EXPIRED`. Terminal runs also expose
  `durationMs`, `result`, `git.branches[]`.
- **Or stream (SSE):** `GET /v1/agents/{id}/runs/{runId}/stream` (supports `Last-Event-ID` resume).
- v1 **webhooks are "coming soon"** → V2 **polls** (simplest in a CI conductor). Legacy v0 webhooks
  (`statusChange`, only `FINISHED`/`ERROR`, HMAC-SHA256) are the event-driven alternative if desired.

### 8.3 Fresh agent vs follow-up (critical distinction)

| Want | Call | Context |
| --- | --- | --- |
| **New batch, empty context** (our case) | `POST /v1/agents` (SDK `Agent.create` / `Agent.prompt`) | fresh |
| Continue same conversation | `POST /v1/agents/{id}/runs` (SDK `agent.send` / `Agent.resume`) | preserved |

V2 deliberately uses **only the fresh-agent path** for batches; follow-ups would re-introduce the
context-growth problem we are solving.

### 8.4 Constraints to respect

- **One active run per agent** → `409 agent_busy`. One fresh agent per batch, so moot — but the
  Conductor must not double-launch.
- **Rate limits** per team (HTTP `429`); sequential launches stay well below them; back off on `429`.
- **`stop` hook does NOT run in cloud agents.** End-of-agent self-chaining via a `stop` hook is not
  available in the cloud — the Conductor (or an explicit API call, §9.3) must own sequencing.
  `preCompact`, `beforeShellExecution`, `subagentStart/Stop` **do** run in cloud agents.

### 8.5 Authentication on the Ultra plan

- **Service accounts are Enterprise-only** → not available on Ultra. V2 uses a **User API Key**
  (Cursor Dashboard → API Keys), billed to the user; Ultra's budget covers sequential short-lived
  agents comfortably.
- The key is provided **only** as a **GitHub Actions secret** — never hardcoded. Name it to match
  `[A-Z_][A-Z0-9_]*` (e.g. `CURSOR_API_KEY`); see the secret-name caveat in `AGENTS.md`.

### 8.6 Observability, control & lifecycle (Conductor)

**Observability (logged per phase):**

- **Token usage** — `GET /v1/agents/{id}/usage` (`inputTokens`, `outputTokens`, `cacheRead/WriteTokens`,
  `totalTokens`). Logged after each run → use it to tune `BATCH_BUDGET`.
- **Artifacts** — `GET /v1/agents/{id}/artifacts` (+ `…/download?path=`). The Conductor logs the
  artifact list (best-effort) for inspection.
- **Flaky env starts** — a bad cold boot (e.g. a missing lockfile, a `php`/`composer` hiccup) surfaces
  in the run result and in the Cursor Cloud environment view. These rarely block the work; the
  Conductor logs the run's final result + agent URL so you can spot them. Nice-to-have, not gating.

**Control & abort (live intervention):**

- **Hard stop** — cancel the Conductor's **GitHub Actions run** (Actions → run → *Cancel workflow*).
  The script traps the cancellation signal and cancels the in-flight cloud run via
  `POST /v1/agents/{id}/runs/{runId}/cancel` before exiting, so nothing is left running.
- **Soft stop / pause** — the Conductor checks the **tracking issue's labels** at every phase
  boundary: `conductor:stop` → cancel + exit; `conductor:pause` → poll until the label is removed,
  then resume. Intervene without killing the workflow.
- **Per-run cancel** — any single run can be cancelled via the API (`409 run_not_cancellable` once
  terminal).

**Agent lifecycle / archiving:**

- The Conductor does **not** archive or delete agents on completion — runs stay visible for review.
- Cursor does **not** auto-archive (not documented). Optional: a separate scheduled GitHub Action
  could `POST /v1/agents/{id}/archive` runs older than 30–60 days to declutter the dashboard. Archive
  is **soft/reversible** (`…/unarchive`); it does not delete history.
- Whether runs feed model training depends on your Cursor **privacy settings**, not on keeping agents
  around — don't rely on "keep the agent" for that.

---

## 9. Hosting the Conductor — Decision: GitHub Actions

Nothing runs on the user's machine. The Conductor lives in CI.

### 9.1 Primary (decided): GitHub Actions Conductor

A `workflow_dispatch`- (or issue-label-) triggered workflow runs the conductor loop on GitHub's
runners and launches cloud agents via the API.

- ✅ Off the user's machine; fully observable (CI logs == run log).
- ✅ No extra server, no public webhook endpoint (the conductor **polls**).
- ✅ Natural fit with the existing GitHub-centric flow (PRs, CI, labels); the repo already uses
  `workflow_dispatch` (`issue-cleanup.yml`) and PAT secrets (`PROJECT_TOKEN`) as precedent.
- ✅ `CURSOR_API_KEY` as a GitHub Actions secret.
- Entry points (all non-local):
  - **"Run workflow"** in the GitHub UI with ticket path / branch as inputs, **or**
  - apply a **label** to the tracking issue (workflow listens for it), **or**
  - a tiny **bootstrap cloud agent** (started the usual way in Cursor Cloud) calls `gh workflow run`
    as its final act — keeps the *entry* in the Cursor Cloud habit while the *loop* runs in Actions.

### 9.2 Fallback: self-chaining cloud agents (100 % in-cloud)

Each Orchestrator, as its last action, calls `POST /v1/agents` for the next phase/batch (API key as a
**Cursor Cloud secret**), then exits.

- ✅ Zero external infrastructure; entirely in the Cursor Cloud.
- ➖ **Not an officially documented pattern**; build defensively with a max-batch guard + the checkbox
  state to prevent re-launching completed work.
- ➖ Harder to observe than CI logs; the `stop` hook can't encapsulate it in the cloud (§8.4), so the
  "launch next" call must be an explicit final step in the agent's instructions.

Keep this documented as the pure-in-cloud option if GitHub Actions ever becomes undesirable.

---

## 10. Repository Changes — Status

**Implemented (agent/rule contracts + folders + hook):**

1. **Plan-Review gate** — `.cursor/agents/plan-reviewer.md` (NEW subagent).
2. **Architect extended** — `.cursor/agents/architect.md` now emits the `## EXECUTION STATE` checkbox
   block **with `S/M/L` size hints**, and writes to `tickets/active/`.
3. **Phase rules** — `.cursor/rules/orchestrator-v2-plan.mdc`, `-step.mdc`, `-finalize.mdc` (the
   Orchestrator's per-phase contracts; the outer loop is the Conductor's, not in these rules).
4. **`preCompact` hook** — `.cursor/hooks.json` writes `/tmp/precompact-stop` (§4.4 safety valve).
5. **Ticket lifecycle** — `tickets/active/` + `tickets/done/`; Finalize archives via `git mv`;
   historical tickets migrated to `done/`. Shared subagents (architect/refactorer/verifier) + the V1
   rule + invocation template updated to `active/`.

**Implemented (runtime):**

6. **Conductor** — `.github/workflows/conductor.yml` (secure `workflow_dispatch`) +
   `.github/conductor/conductor.mjs` (REST, zero-dep): branch creation, per-phase Orchestrator
   launches, `EXECUTION STATE` parsing, size-hint batching (budget 6), polling, `ESCALATE`/fatal
   handling, label-based stop/pause, SIGTERM cancel, token-usage logging. **Not yet run** — verify v1
   response field names on the first dispatch.
7. **GitHub hardening** — `.github/CODEOWNERS` added (enable "Require review from Code Owners" in
   branch protection); `AGENTS.md` updated for the two credentials.

**Pending:**

8. **First real run** + tuning (`BATCH_BUDGET`, escalation counts) from logged token usage; then flip
   the default from V1 to V2 and reference this design from the ROADMAP.

> Per the project's "no compatibility layers" philosophy: V2 **replaces** V1 once the Conductor is
> live. Until then V1 (`orchestrator-subagent-workflow.mdc`) remains the authoritative runnable
> workflow; the V2 rules are inert (`alwaysApply: false`, launched only by the Conductor).

---

## 11. Cost & When to Use V2

- **Per-agent cold start.** Each fresh cloud agent pays VM provisioning + repo clone +
  `.cursor/install.sh` (Pagekit: composer install + `yarn install` postinstall build). Cached
  environment snapshots mitigate this; size-hint **batching** (§4.4) amortizes it across small steps.
- **Break-even.** V2 wins clearly for **long tickets with loops**. Batching removes the "mini-step
  overhead" objection, so V2 is reasonable across ticket sizes; a trivial 1–2 step ticket may still
  be cheaper as a single classic agent.
- **Model choice.** Launch the Orchestrator with a cheap/fast model (or `model.id = "auto"`), set
  per-launch by the Conductor. The thinking models stay in the subagents (Architect, Plan-Reviewer,
  Refactorer, Verifier) via their own frontmatter — so the cheap coordinator cuts only the
  coordination overhead, not quality.
- **Cloud agents always run in Max Mode** (billed at the model's API pricing); savings come from
  **less accumulated context per agent**, not from leaving Max Mode.

---

## 12. Failure Handling, Idempotency & Open Questions

### 12.1 Failure handling

Normal FAILs (Verifier/Tester/Plan-Reviewer) are handled **inside the Orchestrator** (re-delegate to
Refactorer/Architect) and never reach the Conductor. The Conductor only sees terminal results:

| Orchestrator result / event | Conductor action |
| --- | --- |
| `… done` / `Plan ready` / `Finalized` | Proceed (pull branch; next phase/batch, or finish). |
| `Batch stopped (preCompact) after Step N` | Normal — launch a fresh Execute Orchestrator for the remaining unchecked steps. |
| `ESCALATE: <reason>` (repeated identical FAIL) | Launch a **fresh** Orchestrator for the same phase; after `MAX_ESCALATIONS` (2) → stop + human. |
| Run `ERROR` / startup `CursorAgentError` | If `isRetryable` → one bounded retry; else stop + human. Never blind-retry (duplicate cloud runs). |
| `409 agent_busy` | One run per agent — treat as a bug, abort. |
| `429` rate limit | Exponential backoff, then retry. |
| `FINISHED` but a box still unchecked | Treat as partial; the next batch continues from the first unchecked box (atomic-commit property). |
| Finalize CI red | Handled inside the Finalize Orchestrator's mini-loop (as V1); `[skip ci]` on intermediate fix commits, never the last. |

### 12.2 Idempotency

Re-running the Conductor is always safe: it recomputes open steps from the ticket and skips checked
ones. This is the crash-recovery story.

### 12.3 Resolved (this round) + remaining open

**Resolved:** batch weights `S=1,M=2,L=4`, **budget default 6, configurable** (Decision 6);
`preCompact` valve **implemented** (Decision 7); Conductor built **REST, zero-dep** (Decision 8);
**Automations dropped** (Decision 9); Finalize **separate by default**, inline opt-in (Decision 10);
branch = **Conductor-created `feature/{slug}`** (Decision 11); **model per launch incl. `auto`,
subagents keep own models** (Decision 12); rules **self-contained** (Decision 13); escalation =
**3 identical FAILs → ESCALATE**, **2 escalations → human** (§5.2).

**Still open (decide during Conductor build):**

1. **`MAX_ESCALATIONS` / retry counts + `BATCH_BUDGET`** — confirm defaults (2 escalations; 1 retry on
   a retryable run-error; budget 6) against real runs; tune from per-run token usage.
2. **Result-string + checkbox parsing contract** — the Conductor parses the Orchestrator's final
   line, so keep the sentinels stable (`Plan ready:`, `Batch done.`, `Batch stopped (preCompact)`,
   `Finalized.`, `ESCALATE:`) and the `EXECUTION STATE` checkbox format strict — a malformed
   result/ticket should fail loudly rather than mis-sequence.

---

## 13. References

- Cursor — Cloud Agents API v1: https://cursor.com/docs/cloud-agent/api/endpoints
- Cursor — Cloud Agents API v0 (legacy, webhooks): https://cursor.com/docs/cloud-agent/api/v0
- Cursor — Webhooks (v0 only): https://cursor.com/docs/cloud-agent/api/webhooks
- Cursor — Automations: https://cursor.com/docs/cloud-agent/automations
- Cursor — Hooks (cloud support matrix; `stop` not in cloud; `preCompact` is): https://cursor.com/docs/hooks
- Cursor — Cloud Agents overview: https://cursor.com/docs/cloud-agent
- Cursor — Cloud Agent setup (environment.json, secrets, snapshots): https://cursor.com/docs/cloud-agent/setup
- Cursor — TypeScript SDK: https://cursor.com/docs/sdk/typescript
- Cursor — APIs overview (auth, rate limits): https://cursor.com/docs/api
- Cursor — Service accounts (Enterprise only): https://cursor.com/docs/account/enterprise/service-accounts
- GitHub — Skipping workflow runs (`[skip ci]` tokens, event scope): https://docs.github.com/actions/managing-workflow-runs/skipping-workflow-runs

---

## 14. TL;DR

Two roles: a **context-free Conductor** (GitHub Actions) drives the **outer** loop only — read the
ticket checkboxes + size hints, launch **one Orchestrator cloud agent per phase** (Plan → Execute
batches → Finalize), and react only to `ESCALATE`/fatal. The **Orchestrator** is the familiar V1 thin
coordinator, just **fresh per phase**: it spawns the subagents and runs the inner loops (Architect ⇄
Plan-Reviewer; Refactorer → Verifier → Tester), handling normal FAILs itself and escalating only when
the **same** FAIL repeats 3×. State lives in the ticket's `EXECUTION STATE` (git = store); batching
uses weights `S=1,M=2,L=4` up to **budget 5**, made safe by the **implemented `preCompact`** valve. CI
is branch-scoped (main/develop + PRs), so per-step pushes cost no CI. The Conductor is **REST /
zero-dep** in GitHub Actions with the **user API key as a GitHub Actions secret** (Ultra → no service
accounts).
