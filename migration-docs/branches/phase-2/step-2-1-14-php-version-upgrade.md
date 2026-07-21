# Step 2.1.14 — PHP Version Upgrade (8.2 → 8.5)

**Branch:** `feature/php-version-upgrade`
**ROADMAP Step:** 2.1.14 (PHP Version Upgrade (8.2 → 8.5))
**GitHub Issue:** [#231](https://github.com/Shadesman5/pagekit/issues/231)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-07-21 22:38
**Completed:** _TBD_

---

## 🎯 Overview

_TBD_

---

## ✅ What Changed

### <Theme> (Checklist Steps N–M)

| File | Change |
|---|---|
| `path/to/file.php` | _TBD_ |

_TBD_

---

## 🧠 Key Decisions (Rationale)

_TBD / None_

---

## ⚠️ Breaking Changes (Extensions)

_TBD_

---

## ⚠️ Risks & Rollout Notes

_TBD / None_

---

## 🔐 Security & Data Impact

_TBD / None_

---

## 🛡️ No-Mercy Compliance

_TBD_

---

## ✅ Verification (links only)

- CI run: _TBD_
- Notable deviations: _TBD / None_

---

## 📋 Phase 1 Audit Closure

_TBD_

---

## 📚 Deferred / Out-of-Scope

**Plan note — PHASE Deferred sync amendments (Architect):** present for §2.2 and §2.9; land with this ticket commit.

- **Step 2.2 (CI/CD Pipeline)** — docs-site quality dashboard matrix-key alignment (`quality-dashboard.js` + `quality-snapshot.demo.json` still render 8.2/8.3 PHPUnit legs; rebuilt against live snapshot in 2.2). Version SSoT guard already in §2.2 What. *(PHASE §2.2 amended)*
- **Step 2.9 (Phase 2 Closeout)** — PHPUnit doc-comment metadata deprecations (`ConfigManagerTest::testGet`, `MigrationServiceTest`) + `phpunit.xml.dist` `failOn*` flips; PHPUnit major (12/13) evaluation after metadata cleanup (stays `^11.0` here). *(PHASE §2.9 amended)*
- **Step 2.3 (Docker Production)** — root `Dockerfile` multi-stage/Alpine redesign (this ticket only aligns `FROM` version; already in §2.3 What, no amendment).
- **Non-goals:** Symfony 7 (4.2), DBAL 4 (4.3), Property Hooks / Autowiring / Fail-Fast (2.8.x), coverage-ratchet raise (2.9), PHP 8.5 syntax feature-tourism.
- **Bridges:** None.

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/active/PROMPT_2_1_14_PHP-Version-Upgrade_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_14_PHP-Version-Upgrade.md`
- Predecessor: Step 2.1.13 — TinyMCE Security Patch (~5.10.9)
- Successor: Step 2.2 — CI/CD Pipeline
