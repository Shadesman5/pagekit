# Step 2.4.1 — Webroot Modernization (adopt `public/`)

<!-- Branch doc for Roadmap Step 2.4.1.
     Path: migration-docs/branches/phase-2/step-2-4-1-webroot-modernization.md -->

**Branch:** `feature/webroot-modernization`
**ROADMAP Step:** 2.4.1 (Webroot Modernization (public/))
**GitHub Issue:** [#243](https://github.com/Shadesman5/pagekit/issues/243)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-07-27 17:34
**Completed:** _TBD_

---

## 🎯 Overview

_TBD_

---

## ✅ What Changed

### Baseline & inventory ground truth (Checklist Step 1)

| File | Change |
|---|---|
| `migration-docs/branches/phase-2/step-2-4-1-webroot-inventory-before.txt` | New — committed inventory (1,682 lines) of today's git-ignored, build-produced served files (Vite bundles, vendor asset copies under `app/assets/`, editor asset copies, LESS-compiled CSS) via `git ls-files --others --ignored --exclude-standard`; the pre-migration ground truth for Step 8's `public/`-prefixed parity check. |

Tests: none (test-writer: skip — no production PHP under `app/`/`packages/`). Gates: Verifier PASS; Tester — PHPUnit PASS, PHPStan PASS.

### Build outputs + publication pass + storage symlink → `public/` (Checklist Step 2)

| File | Change |
|---|---|
| `scripts/paths.mjs` (new) | Shared build-path module: `root` (repo root) and `webroot`/`published(target)` (resolves a served-relative path to its `public/`-prefixed absolute location) — the one place every build script now gets its filesystem roots from. |
| `scripts/publish.mjs` (new) | New publication pass. `publishStatics()` copies each module/package/theme tree's committed servable files (`assets/`, `css/`, `js/`, `images/`, `fonts/`; module/package-root `*.ico/.jpg/.png/.svg`; `app/**/*.{js,css}` outside the bundle/asset-copy dirs) into `public/` under the same relative path, skipping `assets.mjs`'s own copy destinations and the never-served `languages/less/node_modules/src/vendor/views` directories (decision 3d). `watchStatics()` re-runs it on change (chokidar, 100 ms debounce). `linkStorage()` creates `public/storage` (POSIX symlink to `../storage`; Windows junction fallback), warning instead of failing when `symlink()` is unavailable (decision 6). |
| `scripts/assets.mjs` | The 7 vendor-package copy destinations (uikit, vue, flatpickr, lodash, TinyMCE, marked, CodeMirror) now resolve through `published()` instead of the repo root; exports `assetDests` so `publish.mjs` can exclude them while walking the same trees. |
| `scripts/bundles.mjs` | Vite `outDir` for every bundle entry now resolves through `published()`, so the 56 IIFE bundles (17 module groups) land under `public/<module>/app/bundle/`; `entry.output`/bundle-entry semantics unchanged. |
| `scripts/styles.mjs` | Compiled CSS output path now resolves through `published()`; every stylesheet root's LESS `paths` gains `node_modules` + `node_modules/uikit/src/less/components`, so uikit `@import`s and `url()` refs resolve against the installed package instead of the relocating `app/assets/uikit` copy (decision 4). |
| `scripts/build.mjs`, `scripts/build-assets.mjs` | `linkStorage()` + `publishStatics()` now run first, ahead of `copyAssets()`/`buildBundles()`/`buildStyles()`. |
| `scripts/watch.mjs` | `linkStorage()` + `watchStatics()` added to the watch startup sequence, ahead of `copyAssets()`/`watchStyles()`/`watchBundles()`. |
| `app/installer/assets/less/installer.less`, `app/installer/assets/less/theme/uikit.less`, `app/system/modules/theme/assets/less/theme.less`, `app/system/modules/theme/assets/less/theme/uikit.less`, `packages/pagekit/theme-one/less/theme.less` | Hardcoded `@uikit-path`/`@image-path` variables pointing at the relocating `app/assets/uikit` copy removed; uikit LESS imports and `url()` refs now resolve `uikit/src/less/...` / `uikit/src/images/...` directly against `node_modules` via `styles.mjs`'s new LESS paths (decision 4). |
| `packages/pagekit/theme-one/css/theme.css` (untracked) | Compiled output untracked per decision 3c; the build regenerates it under `public/packages/pagekit/theme-one/css/theme.css` only — LESS source (`less/theme.less`) unchanged in place. |
| `.gitignore` | New `/public/*` ignore, with `!/public/index.php` / `!/public/.htaccess` exceptions for the two files Checklist Steps 4/5 will add. |
| `eslint.config.js` | `public/` added to the ignored-paths list alongside `storage/`/`tmp/` — build output, not lintable source. |

Tests: none (test-writer: skip — Node build scripts only; the `frontend` CI job + Step 8 parity are the coverage). Gates: Verifier PASS (2 non-blocking notes — see Risks & Rollout Notes); Tester — PHPUnit PASS, PHPStan PASS.

---

## 🧠 Key Decisions (Rationale)

_TBD / None_

---

## 💥 Breaking Changes (Extensions)

_TBD / None_

---

## ⚠️ Risks & Rollout Notes

- **Vue baseline inventory over-counts vs. the fresh `public/` copy (Checklist Step 2 — flagged for Step 8 parity).** The Step 1 baseline captured `app/assets/vue/` (418 entries) before this step moved the copy destination to `public/app/assets/vue/`; the old, git-ignored destination had accumulated leftover files from earlier builds that `scripts/assets.mjs` never pruned (overwrites/adds in place, never deletes). The fresh `public/app/assets/vue/` copy (223 entries) matches the installed `vue@2.7.16` package exactly, including its `.ts` compiler sources. Step 8's parity check must treat the ~195-entry shrinkage as pre-existing baseline staleness, not a publication regression.
- **`app/modules/debug/assets/vendor/highlight/` is not published (Checklist Step 2 — flagged for Step 8 parity).** Decision 3d names `app/modules/debug/assets/**` as a tree that must publish in full, but `scripts/publish.mjs`'s `PRIVATE_DIRS` exclusion treats any directory literally named `vendor` as a never-served PHP/Composer source tree, so this module's own vendored front-end library (a highlight.js copy, currently unreferenced by any `$view->script()`/`style()` call site) is skipped too. Needs a rule adjustment before Step 8's parity check can close cleanly.

---

## 🔐 Security & Data Impact

_TBD / None_

---

## 🛡️ No-Mercy Compliance

- **Rule 4 (Delete over wrap) — Checklist Step 2.** `packages/pagekit/theme-one/css/theme.css`'s tracked compiled copy is untracked outright rather than kept as a committed fallback beside the new `public/`-only build output (decision 3c); the uikit-relative LESS variables (`@uikit-path`/`@image-path` and their `@internal-*-image` consumers) are deleted rather than kept as a dead alias once the imports resolve straight from `node_modules` (decision 4).

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: _TBD / None_

---

## 📋 Phase 1 Audit Closure

_TBD / None_

---

## 👤 Maintainer action

_TBD / None_

---

## 📚 Deferred / Out-of-Scope

- **Steps 2.7 / 2.8 / 2.9** — `PHASE_2_MODERNISING.md` §2.7, §2.8, §2.9 amended in this plan with the deferred webroot consequences: DB-less extension-fallback file kept out of the now-public `storage/` tree (2.7), runtime-installed/uploaded package assets need a `public/` publisher on install/enable (2.8), release artifacts must recreate the `public/storage` symlink and prune stale published assets (2.9).

_TBD_

---

## 📌 Follow-on (ROADMAP)

_TBD / None_

---

## 🧊 Parked (unplanned)

_TBD / None_

---

## 🧹 Cleanup

_TBD / None_

---

## 🛡️ Audit

_TBD / None_

---

## 🎁 Bonus

_TBD / None_

---

## 🔍 Research

_TBD / None_

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/active/PROMPT_2_4_1_Webroot-Modernization_plan.md` (moves to `done/` at Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_4_1_Webroot-Modernization.md`
- Predecessor: Step 2.4 — Build Tools (pnpm + Vite)
- Successor: Step 2.5 — Docker Production Image & Deploy

---

## 📊 <Step-specific appendix>

<!-- Narrative/structural notes only. Never a metrics table (coverage %, MSI, test counts): quality
     numbers are CI-owned — link the sticky quality-report comment + dashboard instead. -->

_TBD — remove this section if not applicable._
