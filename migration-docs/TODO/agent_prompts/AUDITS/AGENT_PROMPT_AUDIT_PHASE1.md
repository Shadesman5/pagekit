# Task: Audit Phase 1 Completed Tasks (ROADMAP 1.1–1.14)

**For Orchestrator workflow:** Follow `.cursor/rules/orchestrator-subagent-workflow.mdc`. Delegate to **architect** first (plan), then per step: **refactorer** → **verifier** → **tester** → commit.

---

## Mandatory Rules (all agents)

- **Language:** All code, commits, docs in **English**.
- **Standards:** `.cursor/rules/pagekit-context.mdc` and `pagekit-standards.mdc` (NO compatibility layers, NO adapters, DELETE OVER WRAP, PHP 8.2+).
- **Secrets:** NEVER commit passwords/tokens/API keys; use env vars.
- **Tests:** PHPUnit path is `./app/vendor/bin/phpunit`. E2E: `npx playwright test tests/e2e/specs/01-setup/installation.spec.js` (+ feature-specific). Run after each step.
- **Commits:** Conventional Commits per `.cursor/rules/conventional-commits.mdc`; commit **per completed step**, not in one batch.

---

## Task Definition

| Field | Value |
|-------|--------|
| **Goal** | Verify Phase 1 “completed” tasks (1.1–1.14) were done correctly; fix issues found; document accurately. |
| **Branch** | from `develop` |
| **Audit report (ONLY path)** | `migration-docs/audits/{YYYY}/{MM}/phase1/{Optional: module}/AUDIT_REPORT_{date}.md` |
| **Source of truth** | `.cursor/ROADMAP.md` – step IDs, task names, status, PRs. This is authoritative for scope and tracking. |
| **Optional context (use with caution)** | `migration-docs/TODO/PHASE#1_MODERNISING.md` – historical narrative per step (branches, outcomes). **May be outdated** (not always updated when tasks diverged). Use only as a hint; always verify against actual code and docs. |
| **Deep-dive Mail (optional)** | `AGENT_PROMPT_AUDIT_AND_VERIFICATION_MAIL_MODULES.md` |

Legacy audit paths (e.g. `migration-docs/testing/PHASE1_AUDIT_REPORT.md`) are **read-only**; do not create or update there.

---

## Scope (ROADMAP IDs to audit)

Audit each step in ROADMAP order. For each step: verify docs + code + run relevant tests (infer from task name and codebase; PHPUnit path `./app/vendor/bin/phpunit`, E2E installation test as in Mandatory Rules).

| ROADMAP ID | Topic |
|------------|--------|
| 1.1 | Mailer Migration (Swift → Symfony Mailer) |
| 1.2 | PHPUnit 11 Upgrade |
| 1.3 | Security Patches |
| 1.3.5 | Dependabot Updates |
| 1.4 | Safe Minor Updates |
| 1.5 | Doctrine DBAL 3.x |
| 1.6 | PSR-11 Container |
| 1.7 | Symfony Event System |
| 1.8 | Symfony Routing |
| 1.9 | Symfony 6.4 Upgrade |
| 1.10 | PSR-6 Cache |
| 1.10.5 | E2E Testing (Playwright) |
| 1.11 | ORM Modernization |
| 1.12 | Database Migration System |
| 1.13 | Validation System Update |
| 1.13.5 | Template Security (eval removal, CSP, data-attributes) |
| 1.14 | Doctrine Annotations → PHP 8 Attributes |

---

## Workflow (Orchestrator)

1. **Architect** – Read this task + **ROADMAP** (authoritative). Optionally skim PHASE#1 for context, but treat it as possibly outdated. Output: scope, **checklist** (one item per ROADMAP ID above), TODO-Spec for any deferred/bridge, **single** audit report path.
2. **Per step (loop):**
   - **Refactorer** – For the **current** step only: use ROADMAP task name + **actual codebase and docs** to determine what to verify (PHASE#1 only as a hint; do not trust it blindly). Verify → run relevant tests → if issues, fix per aggressive modernization rules → update docs if needed → write findings into audit report section.
   - **Verifier** – Audit Refactorer output (no shims/adapters, correct ROADMAP IDs, no leftover legacy).
   - **Tester** – Run `php pagekit setup`, PHPUnit, and (if applicable) Playwright installation/feature tests.
   - **Commit** – Conventional Commit for that step; then next step.
3. **Final deliverable** – One audit report at `migration-docs/audits/{YYYY}/{MM}/phase1/AUDIT_REPORT_{date}.md` with: executive summary, per-step findings (1.1–1.14), any code/doc fixes, recommendations.

---

## Deliverables

- **Audit report:** Only at `migration-docs/audits/{YYYY}/{MM}/phase1/AUDIT_REPORT_{date}.md`.
- **Other docs:** May be updated (branches, mail/completed, security, dependencies) when discrepancies are found.
- **Code fixes:** Optional; apply only when issues found (per DELETE OVER WRAP, no compatibility layers).

---

## Related

- Module-level audit (e.g. Mail): `AGENT_PROMPT_AUDIT_AND_VERIFICATION_MAIL_MODULES.md`

**Start by delegating to the architect subagent to create the plan (checklist + scope), then execute step by step.**
