# Task: Step 2.1 Completion & Documentation Audit (Static Analysis & Code Quality)

<!-- conductor-mode: plan -->

Verify that ROADMAP **Step 2.1 (Static Analysis & Code Quality Tools)** and **all** its sub-steps
**2.1.1 – 2.1.14** are genuinely complete, correctly implemented, and accurately documented — and that
every deferred / residual item ("Restschulden") is routed to a real future step. Then reconcile the
parent GitHub **Issue #147** (and its sub-issues), whose top-level metadata is stale.

This is a **read-only** audit: investigate and report only. Do **not** modify application code, docs,
ROADMAP/PHASE files, or the GitHub issues. Record every correction as a ready-to-apply proposal **inside
the report**.

---

## Rules

- **Read-only:** change no application code and no tracked docs. The only file you create is the audit report.
- **Language:** English.
- **Standards (your yardstick):** `.cursor/rules/pagekit.mdc`, `.cursor/rules/php.mdc`,
  `.cursor/ROADMAP.md` (step IDs), `migration-docs/TODO/MODERNISATION_STRATEGY.md` (Pagekit DNA).
- **Verify, don't trust:** treat every claim in `PHASE_2_MODERNISING.md`, the branch docs, the ROADMAP row,
  and Issue #147 as a **hypothesis** to confirm against the actual code/config on the current tree.
- **Evidence:** every finding cites at least one `path:line` plus the search/observation that found it.
- **Tooling:** PHPUnit is `./app/vendor/bin/phpunit`; PHPStan is `./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M` (custom vendor-dir under `app/vendor`). Do **not** run the full E2E suite.
- **GitHub:** read issues with `gh issue view <n> --repo Shadesman5/pagekit`. Do **not** edit any issue.
- **Secrets:** never echo or commit a secret value; a hardcoded secret is a **Critical** finding recorded without the value.

---

## Deliverable

- **Report:** `migration-docs/audits/{YYYY}/{MM}/AUDIT_REPORT_STEP_2.1_{YYYY-MM-DD}.md`
- **Commit message:** `docs(audit): step 2.1 completion audit {date}`
- Do **not** edit ROADMAP / PHASE / GitHub issues here — propose all changes *inside the report* (§6 + §9).

---

## Source of truth & references

- `.cursor/ROADMAP.md` — Step 2.1 tracking rows (Status / Audit / PR), the 5 rules.
- `migration-docs/TODO/PHASE_2_MODERNISING.md` §2.1 (sub-step table + per-step entries) — **itself under audit**; verify against code.
- Per-sub-step branch docs: `migration-docs/branches/phase-2/step-2-1-*.md` (each `✅` row points to one).
- `CHANGELOG-NEW.md` — released changes per sub-step / version.
- GitHub **Issue #147** (parent) and its sub-issues (e.g. `#230` → 2.1.13, `#231` → 2.1.14); note each sub-issue's state and whether its PR merged.
- `phpstan.neon` (level + baseline policy note), `phpstan-baseline.neon`, `.github/workflows/php-quality.yml` (quality gates), `composer.json`.

---

## Verification yardstick — three checks per sub-step

For **each** sub-step 2.1.1 – 2.1.14, answer with evidence:

- **A. Reality** — does the code/config on the current (PHP 8.5) tree actually reflect the claimed outcome?
- **B. Docs** — is the `PHASE_2` entry + the branch doc + the ROADMAP row (Status/Audit/PR) accurate, present, and mutually consistent? Does a branch doc exist where a `✅` row promises one?
- **C. Forward debt** — does every `Forward` / `Deferred` / `Out of scope` item in that sub-step route to a **real** future-step section (exists in a `PHASE_*` file), or is it silently dropped / orphaned?

Classify each sub-step: **✅ verified** · **⚠️ minor discrepancy** (doc/metadata drift) · **❌ material gap** (claim not backed by code, or missing deliverable).

---

## Scope — per-sub-step reality checks (extend as needed)

Cross-check ROADMAP status first, then verify on code. Suggested probes (record the exact query next to each finding):

| Sub-step | Reality check (examples — verify, don't assume) |
|---|---|
| 2.1.1 Tooling Setup & Baseline | quality tools present in `composer.json` require-dev; PSR-12/CS-Fixer config exists. |
| 2.1.2 CI/CD Integration & Quality Gates | `php-quality.yml` runs the gates on PRs; jobs green in latest run. |
| 2.1.3 `strict_types` Migration | `rg -L -t php --files-without-match 'declare\(strict_types=1\)' app packages` → expect ~none (list exceptions). |
| 2.1.4–2.1.6 PHPStan Level 6/7/8 | `phpstan.neon` `level: 8`; `phpstan analyse` exit 0; count baseline blocks + suppressed errors. |
| 2.1.7 QueryBuilder API Standardization | ORM QueryBuilder API consistent; no leftover ad-hoc query patterns. |
| 2.1.8 Infection Mutation Testing | Infection configured; MSI / Covered-MSI threshold present; scope documented. |
| 2.1.9 Test Coverage Expansion | CI coverage floor (`MIN_LINE_COVERAGE`) + Codecov config present; PHPUnit total. |
| 2.1.10 Entity Presentation Layer | `ModelServiceLocator` gone; presenters (`PostPresenter`, `NodePresenter`) carry URL/access/comment presentation. |
| 2.1.11 EntityManager DI | no static Active-Record API (`::query()`/`::find()`/`::where()` on entities), no EM singleton; repositories via DI. |
| 2.1.12 Residual `mixed` narrowing | avoidable `mixed` narrowed; remaining `mixed` justified. |
| 2.1.13 TinyMCE Security Patch | `package.json` tinymce `~5.10.9`; branch doc `step-2-1-13-*` exists. |
| 2.1.14 PHP Version Upgrade (8.2→8.5) | `composer.json` `^8.5` + `platform.php` `8.5.0`; `index.php` + installer guard `8.5`; CI matrix `8.5`; **no own-code runtime deprecation on 8.5** — confirm the recent follow-ups landed (InstallerIO explicit-nullable, `PDO::MYSQL_ATTR_INIT_COMMAND` → `\Pdo\Mysql`, `PropertyTrait` transient store) and the **ORM eager-load fix** (`QueryBuilder::getRelations()` via `getRepository()->query()`) + its regression test. |

**Cross-cutting current-state checks** (these are the Issue #147 acceptance criteria — verify each holds today):
PHPStan **Level 8** enforced + green · `declare(strict_types=1)` at ~100 % · quality gates active on every PR · core modules at the coverage floor · Infection scope/threshold active.

---

## Issue #147 reconciliation (the parent issue has stale info)

Verify and produce a **copy-paste-ready corrected body** for #147, covering at least:

- **Sub-step count:** the issue says "All **9** sub-steps" — there are now **14** (2.1.1 – 2.1.14).
- **PHP floor:** "PHP **8.2+**" is stale — the runtime minimum is now **8.5** (Step 2.1.14).
- **"Current State (Feb 2026)" table:** stale (file counts, `strict_types` %, "PHPStan Not installed", "Infection Not installed", "Test files 39"). Replace with the verified current baseline or mark as historical.
- **Acceptance criteria:** all boxes are `[ ]` although the work is done — determine which can be ticked (with evidence).
- **State:** the issue is `OPEN`. Decide + justify whether Step 2.1 is **closeable** (all sub-steps `✅` in ROADMAP **and** their PRs merged). Note the state of each sub-issue (e.g. `#230`, `#231`) and any unmerged PR (e.g. PR `#238` for 2.1.14) that blocks closure.

Deliver: a bullet list of every stale item + the corrected issue body + the exact acceptance boxes to tick. (Applying it is an explicit follow-up — do **not** edit the issue in this run.)

---

## Residual-debt ledger

Collect **every** piece of deferred / forward / out-of-scope work originating in Step 2.1, and route each to its target future step:

- The `Forward` / `Out of scope` lines in each 2.1.x `PHASE_2` entry and branch doc (e.g. Infection CI wiring → 2.2; MSI ratchet, coverage breadth → 2.9; raw-entity `jsonSerialize()` → 4.4; ORM/request-cache invalidation → 4.5; property-hooks path → 2.8.x).
- The **Step 2.8.3 candidates** recently added to `PHASE_2` (InstallerIO implicitly-nullable burndown, PHPStan logic-hygiene baseline burndown, MenuHelper synthetic-root sentinel, `#[\Override]` adoption) — confirm each has a real home and is not a duplicate.
- **PHPStan baseline entries that are genuine debt** — distinguish these from the *accepted-by-design* view-template `variable.undefined` false positives documented in the `phpstan.neon` header note (those are **not** debt).

For each ledger item: origin sub-step · evidence (`path:line`) · target future step (does its `PHASE_*` section exist?) · severity (Critical/High/Medium/Low) · disposition (**existing step** | **propose new sub-step** with suggested ID | **accept**). Flag **orphans** (no future-step home).

---

## Flag / TODO reconciliation

Collect every in-code flag referencing Step 2.1.* — `TODO`, `AUDIT FIX Step 2.1`, `TEMPORARY BRIDGE … 2.1`, `Must be refactored in Step 2.1` — and classify each: **live** (correctly forward-points to open work) · **stale** (its step is already done — should be removed) · **orphan** (no matching ROADMAP step). Suggested query: `rg -t php -n 'TODO|AUDIT FIX|TEMPORARY BRIDGE|Must be refactored'`.

---

## Report structure

Write to `migration-docs/audits/{YYYY}/{MM}/AUDIT_REPORT_STEP_2.1_{YYYY-MM-DD}.md`:

1. **Header** — date, PHP version, ROADMAP `Current Step`, standards + source-of-truth.
2. **Current-State Baseline** — run and record:
   ```bash
   php -v
   grep -c "message:" phpstan-baseline.neon                                            # baseline blocks
   grep -oP 'count:\s*\K[0-9]+' phpstan-baseline.neon | awk '{s+=$1} END{print s+0}'    # suppressed errors
   ./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M 2>&1 | tail -3   # L8 clean?
   ./app/vendor/bin/phpunit --colors=never 2>&1 | grep -E '^(OK \(|Tests:)'            # unit test total
   rg -L -t php --files-without-match 'declare\(strict_types=1\)' app packages | wc -l # strict_types gaps
   ```
   plus ROADMAP current step. (No full E2E run.)
3. **Executive Summary** — is Step 2.1 **truly complete**? RAG (✅/⚠️/❌) per sub-step; counts of discrepancies + residual-debt items; is #147 closeable (yes/no + blocker); 3–7 key takeaways.
4. **Per-Sub-Step Verification Matrix** — one row per sub-step: `Sub-step | Claimed outcome | A: Reality (✅/⚠️/❌) + evidence | B: Docs OK? | C: Forward debt routed? | Notes`.
5. **Documentation Accuracy Findings** — `PHASE_2` §2.1 vs reality, missing/incorrect branch docs, ROADMAP tracking-row correctness (Status/Audit/PR columns), CHANGELOG gaps.
6. **Issue #147 Reconciliation** — stale-item list + copy-paste-ready corrected body + acceptance boxes to tick + closeability decision + sub-issue/PR states.
7. **Residual Debt & Deferred-Work Ledger** — the table defined above.
8. **Flag / TODO Reconciliation** — table: flag → `path:line` → live | stale | orphan.
9. **Proposed ROADMAP / PHASE / Issue changes** — concrete proposals only (new sub-step IDs, scope edits, status flips). Do not apply.
10. **Appendix** — methodology + the exact ripgrep/PHPStan/gh queries used (reproducibility).

---

## Success criteria

- Every sub-step 2.1.1 – 2.1.14 verified against **code + docs** with hard evidence (`path:line` + query) and an ✅/⚠️/❌ verdict.
- Every claim-vs-reality discrepancy and every documentation drift is flagged.
- Issue #147 stale info fully enumerated, with a ready-to-apply corrected body and a justified closeability decision.
- Residual debt fully inventoried and each item routed to a future step (or flagged orphan); the 2.8.3 candidates and real PHPStan-baseline debt included, false-positive suppressions excluded.
- Every in-code Step-2.1 flag reconciled (live / stale / orphan).
- **No application code, docs, ROADMAP/PHASE, or GitHub issue changed** — only the report written.
- Recommendations respect Pagekit DNA (no bloat, no over-engineering, no WordPress/Laravel-ism).

**Start by delegating breadth-first, read-only investigation to `explore` subagents as needed, then synthesize the findings into the report. Chat output: ONE line — `Report written to <report path>`.**
