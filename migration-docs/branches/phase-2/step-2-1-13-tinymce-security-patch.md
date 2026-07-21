# Step 2.1.13 — TinyMCE Security Patch (~5.10.9)

**Branch:** `feature/step-2-1-13-tinymce-security-patch`
**ROADMAP Step:** 2.1.13 (TinyMCE Security Patch (~5.10.9))
**GitHub Issue:** [#230](https://github.com/Shadesman5/pagekit/issues/230)
**Pull Request:** [#234](https://github.com/Shadesman5/pagekit/pull/234)
**Status:** ✅ Complete
**Started:** 2026-07-21 14:48
**Completed:** 2026-07-21 15:02

---

## 🎯 Overview

Bumps the editor TinyMCE dependency from `~5.5.1` → `~5.10.9` (resolved
`5.10.9`, the final community 5.x release). Commit surface is `package.json` +
`yarn.lock` only; gulp-refreshed editor assets remain gitignored. No PHP or
custom plugin/skin edits.

---

## ✅ What Changed

### TinyMCE `~5.5.1` → `~5.10.9` (Checklist Step 1)

| File | Change |
|---|---|
| `package.json` | `"tinymce": "~5.5.1"` → `"tinymce": "~5.10.9"`. |
| `yarn.lock` | `tinymce@~5.10.9` resolves `5.10.9` (integrity updated); no other lock entries touched. |

Tests: none (test-writer skip — JS dependency bump only; no production PHP under `app/` / `packages/`).

On-disk after `yarn install`: `node_modules/tinymce` = `5.10.9`; gulp copy
refreshed `app/system/modules/editor/app/assets/tinymce/` (gitignored). No
contingency CSS in `tinymce_skin/`.

---

## 🧠 Key Decisions (Rationale)

None — pure dependency bump; no API or call-site changes.

---

## ⚠️ Breaking Changes (Extensions)

None. Same TinyMCE 5.x major; custom plugins (`PluginManager.add`,
`editor.ui.registry.addToggleButton`) unchanged.

---

## ⚠️ Risks & Rollout Notes

`yarn audit` still reports 7 TinyMCE advisories on 5.10.9 (final community 5.x).
No further v5 patch exists; remaining XSS classes need CSP / major upgrade
(Deferred). Webpack-locked transitive advisories unchanged (expected non-zero
`yarn audit` exit).

---

## 🔐 Security & Data Impact

TinyMCE unique `yarn audit` advisories: **16 → 7**
(`/tmp/yarn-audit-before.json` → `/tmp/yarn-audit-after.json`). Nine patched by
5.10.9; seven remain (including high-severity media/iframe-class XSS fixed
upstream only in TinyMCE ≥ 6.8.1). No data-model or auth changes.

---

## 🛡️ No-Mercy Compliance

Compliant. No compatibility layers, adapters, or Rule 5 forward-debt tags —
dependency version only.

---

## ✅ Verification (links only)

| Gate | Result |
|---|---|
| CI — PHP Quality | ✅ success |
| Coverage gap pass | skipped — Step 1 `test-writer: skip`; PR diff is `package.json` + `yarn.lock` only (no production PHP under `app/` / `packages/`); no Codecov testable gaps |
| Cursor Bugbot | ✅ clean |
| E2E | ✅ PASS |
| Finalize fix-loop | none |

**CI run:** https://github.com/Shadesman5/pagekit/actions/runs/29841884838

**Notable deviations:** Plan expected tinymce advisories ~13 → ≤1 after 5.10.9;
live `yarn audit` was **16 → 7** unique IDs. Residual set is still
unpatchable on community 5.x — Deferred (CSP / TinyMCE 6+) unchanged in
intent.

**Step 1 gates (Execute):**
- Verifier (production): PASS
- Tester (PHPUnit + PHPStan): PASS — 718 tests, 2039 assertions (5 skipped, 2
  deprecations); PHPStan OK
- Tester (final E2E): PASS — smoke (`php pagekit setup` + `php pagekit list`);
  E2E installation (1), authentication (14), dashboard (10)

---

## 📋 Phase 1 Audit Closure

None

---

## 📚 Deferred / Out-of-Scope

- Step 3.2.1 (Template Pre-compilation / CSP) — CSP `frame-src`/`object-src`
  for TinyMCE iframe XSS unfixed on v5.
- Step 2.4 (Build Tools) — webpack-4-locked transitive advisories clear with
  pnpm+Vite.
- Non-goals: TinyMCE 6+/7+ major (Step 5.1 editor decision); UIkit (3.1);
  Webpack 5 itself (2.4).

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/done/PROMPT_2_1_13_TinyMCE-Security-Patch_plan.md` (archive after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_13_TinyMCE-Security-Patch.md`
- Predecessor: Step 2.1.12 — Residual `mixed` narrowing
- Successor: Step 2.1.14 — PHP Version Upgrade (8.2 → 8.5)
