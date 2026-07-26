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

### Vue 2.6.12 → 2.7.16 bump, still on Yarn + Webpack + Gulp (Checklist Step 2)

| File | Change |
|---|---|
| `package.json` | `vue` `~2.6.12` → `~2.7.16`; `vue-template-compiler` removed from devDependencies (Vue 2.7 ships its own template compiler; `vue-loader` 15.11 auto-detects it). |
| `yarn.lock` | Regenerated for the `vue` bump and the `vue-template-compiler` removal. |
| `README.md` | Vue badge `2.6.12` → `2.7.16`; the three "Vue.js 2.6" prose mentions (Key Features, Technical Highlights, Frontend stack) → "Vue.js 2.7". |
| `migration-docs/branches/phase-2/step-2-4-build-inventory-webpack.txt` | Re-recorded against the post-bump build: same bundle/CSS path set as the Step 1 baseline, plus ~200 new paths under `app/assets/vue/` (the 2.7.16 asset-copy layout: `src/`, `packages/`, `types/`, `compiler-sfc/`, `dist/vue.runtime.mjs`). See Risks & Rollout Notes. |

Tests: none (test-writer: skip — ticket-wide; this step touches no production PHP under `app/`/`packages/`). Gates: Verifier FAIL on first pass (missed re-recording the committed webpack inventory for the post-2.7 asset layout, and the README Vue badge/prose) → PASS on retry; Tester — PHPUnit PASS, PHPStan PASS; coverage gap pass skipped per the ticket's `## TESTING STRATEGY` (`test-writer: skip` on all steps).

### Vite JS pipeline — entry-manifest build; webpack + Babel deleted (Checklist Step 3)

| File | Change |
|---|---|
| `scripts/bundle-entries.mjs` (new) | Entry manifest replacing the 17 deleted `webpack.config.js` files: 56 entries across the same 17 module directories, the 3 externals (`vue`/`uikit`/`uikit-util` → `Vue`/`UIkit`/`UIkit.util`), the `@installer`/`@system` aliases, and the 6 entries that publish a global (`debugbar`→`Debugbar`, `captcha-interceptor`→`Captcha`, `editor`→`Editor`, `panel-finder`+`link-storage`→`Finder`, `panel-link`→`Links`). |
| `scripts/build-js.mjs` (new) | Orchestrator: one `vite build` (JS API) per manifest entry — Rollup's `iife` format can't span a multi-input build — each self-contained (only the 3 externals), `@vitejs/plugin-vue2`, `target: 'es2017'`, `sourcemap: false`, minified; entries with no historical global still need an IIFE name for their exports, so they get a private name inside an extra wrapper scope that leaks nothing onto `window`. Bounded-concurrency pool for a normal build, one-at-a-time for `--watch` (replaces `watch-js`); asserts each entry emits exactly one file. |
| `package.json` | `compile-js` / `watch-js` now call `node scripts/build-js.mjs` (`--watch`) instead of webpack; `install` hook keeps `yarn compile-js && gulp` (decoupling is Step 5) minus the now-inapplicable `--mode=production --display=minimal` webpack flags; devDependencies gain `vite` `^7.3.6` + `@vitejs/plugin-vue2` `^2.3.4` and lose this step's slice of the webpack/babel/loader family (`webpack`, `webpack-cli`, `babel-loader`, `@babel/core`, `@babel/plugin-transform-runtime`, `@babel/preset-env`, `@babel/runtime`, `vue-loader`, `vue-style-loader`, `vue-hot-reload-api`, `css-loader`, `style-loader`, `file-loader`, `html-loader`, `json-loader`, `less-loader`, `glob`, `minimist` — gulp/eslint deps stay for Steps 4/6). |
| `yarn.lock` | Regenerated for the above (still Yarn — the pnpm swap is Step 5). |
| `app/console/src/Commands/BuildCommand.php` | The WIN/else `exec('node_modules/.bin/webpack -p')` / `exec('yarn compile-js …')` split collapses to one cross-platform `exec('node scripts/build-js.mjs')`. |
| `app/system/modules/site/app/components/template-settings.js`, `app/system/modules/widget/app/components/template-settings.js`, `app/system/modules/finder/app/components/panel-finder.vue` (2 imports) | The 4 `.html` imports in the tree get the `?raw` suffix that `html-loader` used to supply implicitly. |
| `app/installer/app/lib/version.js` | Converted from CommonJS (`exports.compare = function …`, plus a `this.php_js` reset its own comment already marked `// END REDUNDANT`) to `export default { compare }`. Not one of the ticket's 4 named `.html`-import sites — discovered because this file's CommonJS shape doesn't resolve under Vite's native ESM handling (no CJS-interop plugin in this pipeline); the dead reset lines were dropped in the same pass. |
| `.babelrc`, `webpack.config.js` (root + 17 module configs) | Deleted. |

Tests: none (test-writer: skip — this step's sole PHP delta, the one-line `exec()` swap in `BuildCommand.php`, has no testable seam per the ticket's `## TESTING STRATEGY`: no existing `BuildCommand` test, and an exec-mock would be noise). Gates: Verifier PASS; Tester — PHPUnit PASS, PHPStan PASS (covers the `BuildCommand.php` touch); build spot-check confirmed the `finder` module's `panel-finder`/`link-storage` entries emit self-registering `Finder`-named IIFEs, and no `app/bundle/` directory contains a `.css` or `.map` file; coverage gap pass skipped per the ticket's `## TESTING STRATEGY`.

---

## 🧠 Key Decisions (Rationale)

- **Inventory sort forces `LC_ALL=C` (Checklist Step 1).** The ticket's decision-2 inventory command ends in a bare `sort`; the committed file instead runs `LC_ALL=C sort`, because the default `en_US.UTF-8` collation orders the recorded paths differently than the C locale would, which would otherwise produce a large diff against Step 9's regenerated inventory that contains no actual path-set difference. Recorded in the committed file's own header comment — Step 9's regeneration command must use the same `LC_ALL=C sort` or the parity check will show a false-positive diff.
- **Vite pinned at latest 7.x, not 8 (Checklist Step 3).** `@vitejs/plugin-vue2` is archived (Vue 2 reached EOL) with a peer range capped at Vite 7; its two Vite-8-support PRs were never merged (repo archived, tracking issue locked) — the task prompt's claim that Vite-8 support had already merged was verified wrong ahead of this step. `vite@^7.3.6` + `@vitejs/plugin-vue2@^2.3.4` is the newest released pair; Vite 7 is still the previous-major security-backport line. Revisit is deferred to Step 3.3.3 (Vue 3 core), when the plugin itself is swapped for `@vitejs/plugin-vue`.
- **`vue-nestable` needed an explicit resolve alias to its UMD build (Checklist Step 3 discovery).** The package's own export map advertises an unbundled `module` build that neither ships nor declares its `vue-runtime-helpers` peer; `build-js.mjs` aliases the import straight to `vue-nestable/dist/index.umd.min.js`, the only build the package ships that is actually self-contained.
- **`version.js`'s CommonJS export needed conversion, outside the ticket's 4 named import sites (Checklist Step 3 discovery).** Decision 3's verification covered `.html`/`.json` imports and SFC `<style>` blocks, not plain-`.js` module syntax; this file's bare `exports.compare = function …` doesn't resolve under Vite's native ESM handling (no CJS-interop plugin in this pipeline), so it became `export default { compare }` in the same pass that also dropped its already-dead `this.php_js` reset (pre-marked `// END REDUNDANT`).

---

## ⚠️ Breaking Changes (Extensions)

_TBD_

---

## ⚠️ Risks & Rollout Notes

- **Step 9's audit-parity gate can only confirm 3 of its 5 named advisories (Checklist Step 1 discovery — flagged for Step 9).** The ticket's Step 9 gate expects the after-audit to show picomatch, braces, micromatch, serialize-javascript and elliptic all gone. The committed before-audit (`step-2-4-audit-yarn-before.txt`) shows only braces, micromatch and serialize-javascript actually present at `yarn audit --level moderate`; picomatch and elliptic never appear in the baseline output. Step 9 should verify removal of the three advisories that are actually present and not treat a missing picomatch/elliptic entry in the after-audit as a discrepancy.
- **Webpack baseline inventory re-recorded on the Vue 2.7 layout, not left as Step 1 committed it (Checklist Step 2).** The 2.6.12 → 2.7.16 bump changes what the webpack pipeline copies into `app/assets/vue/` (~200 new paths — `src/`, `packages/`, `types/`, `compiler-sfc/`, `dist/vue.runtime.mjs`; nothing removed, nothing changed outside that directory). `step-2-4-build-inventory-webpack.txt` is updated in place rather than left for Step 9 to reconcile, so Step 9's pnpm/Vite parity diff runs against this post-bump file, not the original pre-bump one — the file's own header records the exact breakdown against that original recording.

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
