# Task: Audit Single Step (ROADMAP X.Y)

**Use this template for per-step audits.** Copy, rename (e.g. `AGENT_PROMPT_AUDIT_STEP_1_5.md`), and replace `X.Y` and the topic with the target step. One prompt = one step = full concentration.

**For Orchestrator workflow:** Follow `.cursor/rules/orchestrator-subagent-workflow.mdc`. Delegate to **architect** first (plan for this step only), then **refactorer** → **verifier** → **tester** → commit.

---

## Mandatory Rules (all agents)

- **Language:** All code, commits, docs in **English**.
- **Standards:** `.cursor/rules/pagekit.mdc` (No Mercy) and `php.mdc` (PHP 8.5+, PSR-12).
- **Secrets:** NEVER commit passwords/tokens/API keys; use env vars.
- **Tests:** PHPUnit path is `./app/vendor/bin/phpunit`; E2E as needed. Run after the step.
- **Commits:** Conventional Commits; one commit for this step.

---

## Task Definition

| Field | Value |
|-------|--------|
| **Goal** | Audit **only** ROADMAP step **X.Y** (replace with e.g. 1.5). Verify it was done correctly; fix issues found; document accurately. |
| **Branch** | `audit/phase1-verification` (or your audit branch) |
| **Audit report** | Append to `migration-docs/audits/{YYYY}/{MM}/phase1/AUDIT_REPORT_{date}.md` – **section for step X.Y only**. |
| **Source of truth** | `.cursor/ROADMAP.md` – step X.Y, task name, status. |
| **Optional context (use with caution)** | `migration-docs/TODO/PHASE#1_MODERNISING.md` – may be outdated; always verify against code and docs. |

---

## Scope (this run)

- **Only step:** ROADMAP **X.Y** – [replace with task name, e.g. "Doctrine DBAL 3.x"].
- Do not touch other steps. Verify docs + code + run relevant tests for this step only; write findings into the audit report section for X.Y.

---

## Deliverables

- **Audit report:** One section (step X.Y) in `migration-docs/audits/{YYYY}/{MM}/phase1/AUDIT_REPORT_{date}.md`.
- **Other docs/code:** Update only if discrepancies found for this step (per aggressive modernization rules).
- **Commit:** One Conventional Commit for this step.

**Start by delegating to the architect subagent to create the plan for step X.Y only, then execute.**
