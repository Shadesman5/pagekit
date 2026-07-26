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

### LESS + assets + CLDR node scripts; Gulp and the standalone package toolchains deleted (Checklist Step 4)

| File | Change |
|---|---|
| `scripts/bundles.mjs` (new), `scripts/build-js.mjs` (rewritten) | Verifier FAIL on first pass: to let the new `watch.mjs` reuse the Step 3 JS-bundle build as a library, the first attempt kept everything in `build-js.mjs` and gated its own `main()` behind an `import.meta.filename === process.argv[1]` "am I the entry point" check, so the same file could double as an importable module. That guard silently no-ops — no error, nothing built — on a Node release without `import.meta.filename` or whenever the running script's path differs from `argv[1]` (a symlinked invocation, for instance). Fix on retry: the guard is dropped rather than hardened. `buildBundles`/`watchBundles` (Step 3's pool/concurrency runner, per-entry Vite config, `assertSingleBundle`) move unchanged into library module `bundles.mjs`, which has no top-level execution; `build-js.mjs` keeps only argument parsing and unconditionally calls the library — safe, because a CLI wrapper is by construction never imported. |
| `scripts/styles.mjs` (new), `scripts/build-css.mjs` (new) | Same split applied from the start for the new LESS pipeline: `styles.mjs` exports `buildStyles`/`watchStyles` (LESS compile via `less.render`, banner construction, chokidar-debounced rebuild) with no top-level execution; `build-css.mjs` is the thin `--watch`-aware CLI wrapper. |
| `scripts/watch.mjs` (new) | Single orchestrator replacing `watch-js`/`watch-less`/`watch-all`: starts `watchStyles()` first (cheap) before awaiting `watchBundles()` (one Vite watcher per bundle entry, slower to come up) — the reason `bundles.mjs`/`styles.mjs` had to become plain, side-effect-free library modules. |
| `scripts/copy-assets.mjs` (new) | Ports the gulp `assets` task: the same 7 `node_modules` packages to the same destinations (`app/assets/`, the editor module's `app/assets/`), preserving the `lodash*.js`-only filter and the case-sensitive `Codemirror` package name. Overwrites files in place without clearing the destination first, so the committed TinyMCE skin directory survives; dotfiles (repository leftovers of the published package, e.g. linter configs) are filtered out as never served. |
| `scripts/cldr.mjs` (new) | Ports the gulp `cldr` task and fixes both bugs named in the ticket's decision 10: the missing `$` in the formats-file template literal, and the dead formats source itself (`app/assets/vue-intl/dist/locales/`, a path the old assets task never populated) → `node_modules/vue-intl/dist/locales/`. Run once per the ticket's instruction. |
| `app/system/languages/**/formats.json` (46 updated, 21 new) | Output of the `cldr.mjs` run above — locale display-name/date-and-number-format data only. Diff reviewed per decision 10 and found plausible; committed. |
| `package.json` | Script surface moves to the decision-7 target shape: `compile-js`/`watch-js`/`compile-less`/`watch-less`/`watch-all`/`assets`/`gulp` are replaced by `build` (js + assets + css), `build:js`/`build:css`/`build:assets`, and a single `watch`; `cldr` now calls the ported script. `install` still runs `yarn build` (decoupling the hook is Step 5). `chokidar` moves `dependencies` → `devDependencies`. Removes `gulp`, the `gulp-*` family (`gulp-eslint`, `gulp-header`, `gulp-less`, `gulp-plumber`, `gulp-rename`), `merge-stream`, `npm-run-all`. `lint-watch`/`eslint-watch` are untouched — that pair is Step 6's ESLint-config scope. |
| `yarn.lock` | Regenerated for the above — the deleted gulp family's transitive tree accounts for most of a ~2,700-line shrink. Still Yarn; the pnpm swap is Step 5. |
| `gulpfile.js`, `packages/pagekit/theme-one/gulpfile.js`, `packages/pagekit/theme-one/package.json`, `packages/pagekit/blog/package.json` | Deleted outright. The two package-level files carried only dead marketplace-era `archive`/`install` scripts on webpack-4/gulp/babel deps that the root pipeline had already superseded (theme-one's LESS root folds into `styles.mjs`'s 3-root list; blog ships no LESS of its own). |
| `packages/pagekit/theme-one/css/theme.css` | Regenerated via `styles.mjs`; byte-identical rule body, one banner change — the old gulp banner template always rendered a `copyright` segment between the version and license that theme-one's `composer.json` has never populated, leaving a stray double space in that slot; the new banner helper drops empty segments instead of rendering them blank, so the gap collapses. |
| `migration-docs/branches/phase-2/step-2-4-build-inventory-webpack.txt` | Corrected: 195 `app/assets/vue/` paths removed (see Risks & Rollout Notes) — Step 2's re-recording had picked up the Vue 2.6.12 dist tree still sitting under the newer 2.7.16 one, because neither the old gulp `assets` task nor its `copy-assets.mjs` replacement clears a destination before writing into it. |

Tests: none (test-writer: skip — ticket-wide; this step touches no production PHP under `app/`/`packages/`). Gates: Verifier FAIL once (`build-js.mjs`'s entry-point guard was a silent-no-op risk under older Node / symlinked invocation — see Key Decisions) → refactorer retry (`bundles.mjs`/`styles.mjs` library split, thin CLI wrappers) → PASS; Tester — PHPUnit PASS, PHPStan PASS; the tracked-file diff (theme-one `theme.css`, `formats.json` locale data, webpack inventory) matches what this step's scripts were expected to produce, and the regenerated `theme.css` banner carries a real title/version/license line (no `undefined`); coverage gap pass skipped per the ticket's `## TESTING STRATEGY`.

### pnpm swap — packageManager pin, install/build decoupled; Yarn deleted (Checklist Step 5)

| File | Change |
|---|---|
| `package.json` | `"packageManager": "pnpm@11.17.0"` added; `engines` drops the `yarn: "1.x"` entry, `node` range becomes `"^20.19.0 \|\| >=22.12.0"` (Vite 7 floor); `preinstall` script becomes `npx only-allow pnpm` (was `node ./app/scripts/checkYarn.js`); the `install` lifecycle hook (`yarn build`) is deleted — install and build are now separate commands (decision 6); the `resolutions` block (`chokidar` `^3.4.2`, `source-map-resolve` `^0.6.0`) is dropped — `chokidar`'s direct devDependency range already matched its override (no-op), and `source-map-resolve` has had no consumer since Step 4 deleted the gulp chain that was its only path in (confirmed absent from `pnpm-lock.yaml`). |
| `pnpm-workspace.yaml` (new) | pnpm 11's `allowBuilds` map (successor to `pnpm.onlyBuiltDependencies`): `esbuild: true` (its postinstall unpacks the platform binary the Vite transform pipeline executes — needed), `core-js: false` (its postinstall only prints a funding banner — declined). |
| `pnpm-lock.yaml` (new) | Generated by `pnpm install`; the three GitHub-fork deps (`Codemirror`, `JSONStorage`, `vue-intl` — uatrend forks) resolve under pnpm 11 defaults, pinned to the same commit tarballs the deleted `yarn.lock` used. |
| `yarn.lock` (deleted) | Superseded by `pnpm-lock.yaml`. |
| `app/scripts/checkYarn.js` (deleted) | Superseded by the `preinstall: npx only-allow pnpm` guard. |
| `.gitattributes` | `yarn.lock text eol=lf` → `pnpm-lock.yaml text eol=lf`. |
| `.prettierignore` | `yarn.lock` → `pnpm-lock.yaml` in the ignored-lock-files list. |

Tests: none (test-writer: skip — ticket-wide; this step touches no production PHP under `app/`/`packages/`). Gates: Verifier PASS; Tester — PHPUnit PASS, PHPStan PASS; coverage gap pass skipped per the ticket's `## TESTING STRATEGY` (`test-writer: skip` on all steps).

### ESLint 10 flat config + format-once Prettier sweep; airbnb/Babel lint stack deleted (Checklist Step 6)

| File | Change |
|---|---|
| `eslint.config.js` (new) | Flat-config replacement for `.eslintrc`: `js.configs.recommended` + `eslint-plugin-vue`'s `flat/vue2-recommended` preset (the Vue-2-targeted preset, matching the frozen 2.7 runtime) + `eslint-config-prettier/flat` last. Ports from `.eslintrc` the script-tag globals (`_`, `Vue`, `UIkit`, `Theme`, `marked`, `flatpickr`, `grecaptcha`, `$pagekit`, `$editor`, `CodeMirror`, `tinyMCE`) and the non-formatting custom rules (`eqeqeq` smart, `guard-for-in`, `no-console` allow warn/error, `no-unused-expressions`, `no-unused-vars`, the three deliberate-pattern opt-outs `no-control-regex`/`no-prototype-builtins`/`no-useless-escape`); folds `.eslintignore`'s patterns into the top-level `ignores` array (adds `storage/`/`tmp/`, matching what `.prettierignore` already excluded; drops the `.git`/`node_modules` entries flat config ignores by default). Two Vue rules the codebase can't clear are disabled once, centrally, each with a one-line justification comment: `vue/multi-word-component-names` tree-wide (Pagekit's single-word component names are a PHP-view/theme contract) and `vue/no-reserved-component-names` scoped to `packages/pagekit/theme-one/js/theme.js` only (`uk-header` is UIkit's element registry, not Vue's). Scoped overrides keep `no-console` on for `scripts/`/`.github/`/`.cursor/`/`tests/`/`playwright.config.js` output and set `sourceType: 'commonjs'` + a `jQuery` global for the remaining CommonJS files (`tests/**/*.js`, `.cursor/**/*.js`, `playwright.config.js`). |
| `.eslintrc`, `.eslintignore` (deleted) | Superseded outright by `eslint.config.js` — no dual-config transition period. |
| `package.json` | ESLint `^7.10.0` → **`^10.8.0`**; `eslint-plugin-vue` `^7.20.0` → `^10.10.0`; `vue-eslint-parser` `^7.1.1` → `^10.4.1`; `eslint-config-prettier` `^6.13.0` → `^10.1.8`; `@eslint/js` `^10.0.1` and `globals` `^17.7.0` added (flat config's replacement for the old `env` block). Removed: `babel-eslint`, `eslint-config-airbnb-base`, `eslint-plugin-import`, `eslint-watch`, `eslint-webpack-plugin` — the whole airbnb/babel-parser lint stack. `lint` script drops `--ext .js,.vue` (flat config scopes via `ignores`, not a CLI flag); `lint-watch` script deleted alongside its `eslint-watch` dependency. |
| `pnpm-lock.yaml` | Regenerated for the above — net ~2,150-line shrink; the deleted airbnb/babel-eslint transitive tree was larger than the `eslint-plugin-vue` 10 + `vue-eslint-parser` 10 tree replacing it. |
| `.prettierignore` | Re-shaped for the format-once sweep, not just appended to: a new header records the format-once policy; the old explicit entries (`app/bundle/`, `app/assets/`, `packages/*/app/bundle/`, `pnpm-lock.yaml`, `composer.lock`, `*.min.css`) are replaced by the same glob shape `eslint.config.js` uses (`**/assets/`, `**/bundle/`, `**/vendor/`) plus blanket `*.less`/`*.css`/`*.html`/`*.json`/`*.yml`/`*.yaml`/`.prettierrc` exclusions — styles, markup, and data/config files stay out of Prettier's JS/Vue scope; `pnpm-lock.yaml` stays excluded under the new blanket `*.yaml` rule. |
| `.git-blame-ignore-revs` (new) | Created with the usage-header comment and a placeholder line for the format-commit entry. The commit SHA can't be known before the Orchestrator commits this step, so the hash itself is appended in Checklist Step 7 — see Risks & Rollout Notes. |
| 187 modified + `eslint.config.js` = 188 `.js`/`.mjs`/`.vue` files across `app/`, `packages/`, `scripts/`, `tests/`, `.github/`, `.cursor/`, `docs-site/` | One-shot Prettier format (2-space indent, single quotes, no trailing comma — `.prettierrc` unchanged) over the full lint scope. Beyond reformatting, the sweep also removed 19 now-dead inline `// eslint-disable-line` suppressions for rules the new config no longer enables (`no-cond-assign` x7, `no-multi-assign` x3, `prefer-const` x2, `no-shadow` x2, `vue/no-unused-components` x2, one each of `no-continue`/`no-new-func`/`no-sequences`); the one suppression for a still-active rule (`no-console` on the dynamic `console[type](...)` call in `app/system/app/lib/theme.js`'s `log` helper) survives unchanged. |

Tests: none (test-writer: skip — ticket-wide; this step touches no production PHP under `app/`/`packages/`). Gates: Verifier PASS — format-once sweep and flat config reviewed, residual findings fixed or justified in config (no inline suppressions added; two chronically-violated Vue opinion rules disabled centrally per decision 12); Tester — PHPUnit PASS, PHPStan PASS; coverage gap pass skipped per the ticket's `## TESTING STRATEGY` (`test-writer: skip` on all steps).

---

## 🧠 Key Decisions (Rationale)

- **Inventory sort forces `LC_ALL=C` (Checklist Step 1).** The ticket's decision-2 inventory command ends in a bare `sort`; the committed file instead runs `LC_ALL=C sort`, because the default `en_US.UTF-8` collation orders the recorded paths differently than the C locale would, which would otherwise produce a large diff against Step 9's regenerated inventory that contains no actual path-set difference. Recorded in the committed file's own header comment — Step 9's regeneration command must use the same `LC_ALL=C sort` or the parity check will show a false-positive diff.
- **Vite pinned at latest 7.x, not 8 (Checklist Step 3).** `@vitejs/plugin-vue2` is archived (Vue 2 reached EOL) with a peer range capped at Vite 7; its two Vite-8-support PRs were never merged (repo archived, tracking issue locked) — the task prompt's claim that Vite-8 support had already merged was verified wrong ahead of this step. `vite@^7.3.6` + `@vitejs/plugin-vue2@^2.3.4` is the newest released pair; Vite 7 is still the previous-major security-backport line. Revisit is deferred to Step 3.3.3 (Vue 3 core), when the plugin itself is swapped for `@vitejs/plugin-vue`.
- **`vue-nestable` needed an explicit resolve alias to its UMD build (Checklist Step 3 discovery).** The package's own export map advertises an unbundled `module` build that neither ships nor declares its `vue-runtime-helpers` peer; `build-js.mjs` aliases the import straight to `vue-nestable/dist/index.umd.min.js`, the only build the package ships that is actually self-contained.
- **`version.js`'s CommonJS export needed conversion, outside the ticket's 4 named import sites (Checklist Step 3 discovery).** Decision 3's verification covered `.html`/`.json` imports and SFC `<style>` blocks, not plain-`.js` module syntax; this file's bare `exports.compare = function …` doesn't resolve under Vite's native ESM handling (no CJS-interop plugin in this pipeline), so it became `export default { compare }` in the same pass that also dropped its already-dead `this.php_js` reset (pre-marked `// END REDUNDANT`).
- **Library/CLI split replaces a fragile entry-point guard, applied to both build scripts (Checklist Step 4).** `watch.mjs` needs the JS-bundle and LESS builders as plain library calls (`watchBundles()`, `watchStyles()`); the first refactorer pass kept everything in `build-js.mjs` and gated its own `main()` behind an `import.meta.filename === process.argv[1]` "am I the entry point" check so the same file could double as an importable module. The Verifier FAILed it: that check silently evaluates false — no error, nothing runs — on a Node release without `import.meta.filename` or whenever the running script's path differs from `argv[1]` (a symlinked invocation, for instance). The retry doesn't harden the guard, it removes the need for one: `bundles.mjs`/`styles.mjs` are pure libraries with zero top-level execution, and `build-js.mjs`/`build-css.mjs` are thin CLI wrappers that always run unconditionally — a CLI wrapper is by construction never imported, so there is nothing left to guard against.
- **`pnpm-workspace.yaml`'s `allowBuilds` approves `esbuild` only, not `core-js` (Checklist Step 5).** `pnpm install` surfaced two build-script requests, not just the ticket's named `esbuild` case; `core-js`'s postinstall only prints a funding banner with no build output the tree needs, so it is explicitly denied (`false`) rather than left off the map or blanket-allowed — one reviewed exception granted, one reviewed and declined, per the ticket's decision 5 instruction to approve only what is needed.
- **ESLint pinned at 10, not the task prompt's named 9 (Checklist Step 6).** ESLint 9 hits EOL 2026-08-06 — days after this step landed — so pinning it would have shipped a dead major immediately; `eslint@^10.8.0` + `eslint-plugin-vue@^10.10.0` (peers ESLint 10; ships the `flat/vue2-recommended` preset this config uses) + `vue-eslint-parser@^10.4.1` is the newest compatible set. `eslint-config-airbnb-base` has no flat-config support, so the port does not chase its rule set rule-by-rule — only the non-formatting custom rules and script-tag globals carry over from `.eslintrc` (see What Changed).

---

## ⚠️ Breaking Changes (Extensions)

_TBD_

---

## ⚠️ Risks & Rollout Notes

- **Step 9's audit-parity gate can only confirm 3 of its 5 named advisories (Checklist Step 1 discovery — flagged for Step 9).** The ticket's Step 9 gate expects the after-audit to show picomatch, braces, micromatch, serialize-javascript and elliptic all gone. The committed before-audit (`step-2-4-audit-yarn-before.txt`) shows only braces, micromatch and serialize-javascript actually present at `yarn audit --level moderate`; picomatch and elliptic never appear in the baseline output. Step 9 should verify removal of the three advisories that are actually present and not treat a missing picomatch/elliptic entry in the after-audit as a discrepancy.
- **Webpack baseline inventory re-recorded on the Vue 2.7 layout, not left as Step 1 committed it (Checklist Step 2).** The 2.6.12 → 2.7.16 bump changes what the webpack pipeline copies into `app/assets/vue/` (~200 new paths — `src/`, `packages/`, `types/`, `compiler-sfc/`, `dist/vue.runtime.mjs`; nothing removed, nothing changed outside that directory). `step-2-4-build-inventory-webpack.txt` is updated in place rather than left for Step 9 to reconcile, so Step 9's pnpm/Vite parity diff runs against this post-bump file, not the original pre-bump one — the file's own header records the exact breakdown against that original recording.
- **That same file needed a second correction — 195 stale Vue 2.6.12 paths removed (Checklist Step 4 discovery).** The Step 2 re-recording above turned out to still be wrong, in a different way: it was taken from a working tree where `app/assets/vue/` had been built once on 2.6.12 (Step 1's baseline commands) and then rebuilt on 2.7.16 without the directory ever being cleared first, so 195 files that exist only in the 2.6.12 distribution survived underneath the 2.7.16 one and were recorded as if the new pipeline has to reproduce them too. It cannot — they aren't shipped by `vue@2.7.16`, and no asset copy (gulp's original task or its `copy-assets.mjs` replacement, neither of which clears a destination before writing) can recreate a file its source package doesn't ship. Corrected in this step's commit rather than left for Step 9's parity diff to surface as an unexplained failure; the file's own header now documents the exact count and cause.
- **`.git-blame-ignore-revs` is committed without its hash yet (Checklist Step 6 — resolved in Step 7).** The file records the format-once policy and its own usage comment but can't reference the format-commit SHA before the Orchestrator commits this step's diff. Ticket decision 12 already plans for Step 7 to append the hash via `git log`, so Step 7's diff should show the file gaining a line, not being authored fresh — not an oversight if seen that way.

---

## 🔐 Security & Data Impact

_TBD / None_

---

## 🛡️ No-Mercy Compliance

- **Rule 4 (Delete over wrap) — Checklist Step 4:** `gulpfile.js` and the three package-level toolchain files (`theme-one/gulpfile.js`, `theme-one/package.json`, `blog/package.json`) deleted outright, no transitional wrapper; theme-one's LESS root folds directly into `styles.mjs`'s 3-root list rather than keeping a second, parallel compile path alive. The `gulp`/`gulp-*`/`merge-stream`/`npm-run-all` dependency chain is removed from `package.json` in the same commit, not left installed-but-unused.
- **Rules 2 & 4 (No adapters / Delete over wrap) — Checklist Step 6:** `.eslintrc`, `.eslintignore`, and the ESLint 7 + `eslint-config-airbnb-base` + `babel-eslint` + `eslint-plugin-import` chain are deleted outright in the same commit that adds the flat `eslint.config.js` — no rule-by-rule airbnb parity shim, no dual-config transition period. The two Vue rules the codebase can't clear (`vue/multi-word-component-names`, `vue/no-reserved-component-names`) are disabled once, centrally, with an inline justification comment in the config, rather than sprayed as per-file `// eslint-disable` overrides (ticket decision 12).

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
