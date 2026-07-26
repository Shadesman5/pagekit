# Step 2.4 — Build Tools (pnpm + Vite)

<!-- Branch doc for Roadmap Step 2.4.
     Path: migration-docs/branches/phase-2/step-2-4-build-tools-pnpm-vite.md -->

**Branch:** `feature/build-tools-pnpm-vite`
**ROADMAP Step:** 2.4 (Build Tools — pnpm + Vite)
**GitHub Issue:** [#159](https://github.com/Shadesman5/pagekit/issues/159)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-07-26 00:25
**Completed:** _TBD_

---

## 🎯 Overview

_TBD_

---

## ✅ What Changed

### Baseline ground truth — webpack inventory + yarn audit snapshot (Checklist Step 1)

| File | Change |
|---|---|
| `migration-docs/branches/phase-2/step-2-4-build-inventory-webpack.txt` (new) | Committed ground truth for the pnpm/Vite parity check: the gitignored build-output path set from the Yarn 1 + Webpack 4 + Gulp pipeline — 56 JS bundles under `**/app/bundle/`, the 2 compiled stylesheets (installer + system theme), and the asset copies for uikit/vue/flatpickr/lodash/tinymce/marked/codemirror — captured via the ticket's decision-2 inventory command with `LC_ALL=C` added to `sort` (see Key Decisions). Step 9 regenerates this inventory on the new pipeline and diffs it against this file; the path set must come back identical. |
| `migration-docs/branches/phase-2/step-2-4-audit-yarn-before.txt` (new) | Committed baseline `yarn audit --level moderate` output (exit code 30 — the expected non-failure baseline result), taken before any dependency change. In-file header records which of the five webpack-transitive advisories named by the ticket's Step 9 gate (picomatch, braces, micromatch, serialize-javascript, elliptic) actually appear at this level — see Risks & Rollout Notes. |

Tests: none (test-writer: skip — ticket-wide; this step touches no production PHP under `app/`/`packages/`). Gates: Verifier PASS; Tester — PHPUnit PASS, PHPStan PASS; coverage gap pass skipped per the ticket's `## TESTING STRATEGY` (`test-writer: skip` on all steps).

---

## 🧠 Key Decisions (Rationale)

- **Inventory sort forces `LC_ALL=C` (Checklist Step 1).** The ticket's decision-2 inventory command ends in a bare `sort`; the committed file instead runs `LC_ALL=C sort`, because the default `en_US.UTF-8` collation orders the recorded paths differently than the C locale would, which would otherwise produce a large diff against Step 9's regenerated inventory that contains no actual path-set difference. Recorded in the committed file's own header comment — Step 9's regeneration command must use the same `LC_ALL=C sort` or the parity check will show a false-positive diff.

---

## ⚠️ Breaking Changes (Extensions)

_TBD_

---

## ⚠️ Risks & Rollout Notes

- **Step 9's audit-parity gate can only confirm 3 of its 5 named advisories (Checklist Step 1 discovery — flagged for Step 9).** The ticket's Step 9 gate expects the after-audit to show picomatch, braces, micromatch, serialize-javascript and elliptic all gone. The committed before-audit (`step-2-4-audit-yarn-before.txt`) shows only braces, micromatch and serialize-javascript actually present at `yarn audit --level moderate`; picomatch and elliptic never appear in the baseline output. Step 9 should verify removal of the three advisories that are actually present and not treat a missing picomatch/elliptic entry in the after-audit as a discrepancy.

---

## 🔐 Security & Data Impact

_TBD / None_

---

## 🛡️ No-Mercy Compliance

_TBD_

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: _TBD / None_

---

## 📋 Phase 1 Audit Closure

_TBD_

---

## 📚 Deferred / Out-of-Scope

_TBD_

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/active/PROMPT_2_4_Build-Tools-pnpm-Vite_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_4_Build-Tools-pnpm-Vite.md`
- Predecessor: Step 2.3 — Docker Dev Experience & Image Hygiene
- Successor: Step 2.5 — Docker Production Image & Deploy

---

## 📊 <Step-specific appendix>

<!-- Narrative/structural notes only. Never a metrics table (coverage %, MSI, test counts): quality
     numbers are CI-owned — link the sticky quality-report comment + dashboard instead. -->

_TBD — remove this section if not applicable._
