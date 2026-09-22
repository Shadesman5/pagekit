# Step 2.7.1b — Package Module Boundary

<!-- Branch doc for Roadmap Step 2.7.1b.
     Path: migration-docs/branches/phase-2/step-2-7-1b-package-module-boundary.md -->

**Branch:** `feature/package-module-boundary`
**ROADMAP Step:** 2.7.1b (Package Module Boundary)
**GitHub Issue:** [#287](https://github.com/Shadesman5/pagekit/issues/287)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-09-22 00:39
**Completed:** _TBD_

---

## 🎯 Overview

The `package` module exists as an empty skeleton beside `installer`. All three boots register `app/package/index.php`. `system` and `installer` require `package`. `PackageModule::main()` registers no services. Package classes, routes, and the admin surface still live in `installer`.

---

## ✅ What Changed

### Module skeleton (Checklist Step 1)

Nothing moved. The directory, the class main, and the tooling that has to see the directory are in place so later steps can `git mv` into a module the boots already load.

| File | Change |
|---|---|
| `app/package/index.php` (new) | Manifest `package`: `'main' => Pagekit\Package\PackageModule`, `require` `application`, `migration`, `system/intl`, `system/view`, resource `package:`. No routes, menu, permissions, or config. |
| `app/package/src/PackageModule.php` (new) | Final `PackageModule`. `main()` returns `null` and registers nothing. |
| `app/system/app.php`, `app/console/app.php`, `app/installer/app.php` | Register `app/package/index.php` ahead of `app/installer/index.php`. |
| `app/system/index.php`, `app/installer/index.php` | `'package'` added to `require`, after `migration`. Installer still owns the package services, routes, and admin menu. |
| `composer.json` | PSR-4 `Pagekit\Package\` → `app/package/src`, beside `Pagekit\Installer\`. |
| `phpstan.neon` | Analyse path `app/package`. |
| `phpunit.xml.dist` | Coverage include `app/package`. No `phpunit.xml` beside the dist file. |
| `app/modules/application/src/Tests/bootstrap.php` | Deleted the stale `Pagekit\Package\` → `/app/modules/package/src` mapping. |

#### Tests (Checklist Step 1)

| File | Change |
|---|---|
| `tests/Unit/Package/PackageModuleBoundaryTest.php` (new) | Manifest graph (`package` requires neither `installer` nor `system`; both of those require `package`), each boot file registers the manifest once, a scan of `app/package` and `app/installer/src` reports `Pagekit\System\` only outside the package tree and fails an in-memory fixture that contains such a line, `main()` adds no services, Composer / PHPStan / PHPUnit name `app/package`, and the application test bootstrap no longer maps `/app/modules/package/src`. Asserts membership, not list order. Including a manifest binds `$app` first — the system manifest's events capture it. |

Gates: Verifier (production) PASS; Tester PHPUnit+PHPStan PASS; test-writer done after one retry (first Tester FAIL: including `app/system/index.php` tripped `failOnWarning` on unbound `$app`) → Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

---

## 🧠 Key Decisions (Rationale)

- **List position is not the contract.** `app/package/index.php` is registered ahead of `app/installer/index.php`, and `'package'` sits after `'migration'` in both `require` arrays. `ModuleManager::register()` only discovers manifests; `resolveModules()` walks requirements by name. `PackageModuleBoundaryTest` asserts those arrays contain the entry, never an index or a relative order.

---

## 💥 Breaking Changes (Extensions)

None. The module is an empty skeleton; no package class, route, or permission has moved.

---

## ⚠️ Risks & Rollout Notes

`system` and `installer` fail module resolution if `app/package/index.php` is absent. `main()` registers no services.

---

## 🔐 Security & Data Impact

None.

---

## 🛡️ No-Mercy Compliance

The stale `Pagekit\Package\` mapping to a directory that does not exist was deleted, not left beside the new PSR-4 path. The main is the class, with an empty `main()` — no closure and no service registration until the registrations move. No alias.

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: Step 1 — production Verifier PASS; Tester PHPUnit+PHPStan PASS. test-writer: first Tester FAIL (`PackageModuleBoundaryTest` included `app/system/index.php` and tripped `failOnWarning` on unbound `$app`). Retry binds `$app` before the include. Verifier (test files) PASS; Tester PHPUnit+PHPStan PASS.

---

## 📋 Phase 1 Audit Closure

_TBD / None_

---

## 👤 Maintainer action

<!-- Human-only follow-ups the maintainer must do (ruleset flips, real Docker/Apache
     verification, secrets, etc.). Not ROADMAP deferrals — those go under Deferred. -->

_TBD / None_

---

## 📚 Deferred / Out-of-Scope

<!-- Future ROADMAP/PHASE work, explicit non-goals, bridges. Do NOT put maintainer
     Manual Work here — that belongs under Maintainer action above. -->

_TBD / None_

---

## 📌 Follow-on (ROADMAP)

_TBD / None_

---

## 🧊 Parked (unplanned)

<!-- Filled by the post-close review after Finalize: what the finished work left unowned,
     one bullet per finding with the ROADMAP step whose area it belongs to. Doc-writer leaves None. -->

_TBD / None_

---

## 🧹 Cleanup

<!-- Removed in passing (deleted files, dropped baseline/ignore entries, dead code). Doc-writer from
     the handover; the post-close review adds what the diff shows and the handover missed. -->

_TBD / None_

---

## 🛡️ Audit

<!-- No-Mercy leftovers of the shipped diff that have no owner (forward-debt tags, added baseline
     entries, ANOMALIES patterns), each with the ROADMAP step that resolves it. Post-close review. -->

_TBD / None_

---

## 🎁 Bonus

<!-- Work delivered beyond the ticket. Doc-writer from the handover; the post-close review adds
     what the diff shows and the handover missed. -->

_TBD / None_

---

## 🔍 Research

<!-- The verified facts behind each DECISION the post-close review raised — symbols, call chain,
     what each exit deletes or adds — so the maintainer can decide without re-reading the tree. -->

_TBD / None_

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/active/PROMPT_2_7_1b_Package-Module-Boundary_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_7_1b_Package-Module-Boundary.md`
- Predecessor: Step 2.7.1a — Atomic MySQL Restore (Shadow Cut-over)
- Successor: Step 2.7.1c — Runtime Composer Removal

---

## 📊 <Step-specific appendix>

<!-- Narrative/structural notes only. Never a metrics table (coverage %, MSI, test counts): quality
     numbers are CI-owned — link the sticky quality-report comment + dashboard instead. -->

_TBD — remove this section if not applicable._
