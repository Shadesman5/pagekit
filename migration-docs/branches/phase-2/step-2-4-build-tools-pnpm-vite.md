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

### CI on pnpm with an explicit build step; ESLint/Prettier now blocking full-tree (Checklist Step 7)

| File | Change |
|---|---|
| `.github/workflows/frontend.yml` (job `frontend`) | Adds a `pnpm/action-setup` step (pinned by commit SHA, matching the `actions/checkout`/`actions/setup-node` pinning already used in the same job) ahead of `actions/setup-node`; `cache: yarn` → `cache: pnpm`; `yarn install --frozen-lockfile` → `pnpm install --frozen-lockfile`. The separate "Build JS bundles (webpack, production)" and "Build styles (gulp)" steps collapse into one "Build frontend assets" step (`pnpm build` — decision 6). The advisory diff-scoping machinery is deleted outright: checkout's `fetch-depth: 0`, the "Fetch PR base ref" step, and the "Determine changed frontend files" step. ESLint and Prettier drop `continue-on-error` and the changed-files scoping, running full-tree as `pnpm lint` / `pnpm exec prettier --check .`. Header comment rewritten from the old advisory-gate rationale to the new blocking-everywhere one. |
| `.github/workflows/e2e.yml` (jobs `e2e-smoke`, `e2e-merge`), `nightly.yml` (job `e2e-viewports`), `e2e-weekly.yml` (job `e2e-sweep`) | Same `pnpm/action-setup` step + `cache: pnpm` added to every job. The single "Install Node dependencies and build assets" step — which relied on Yarn's `install` lifecycle hook to also build — splits into an explicit "Install Node dependencies" (`pnpm install --frozen-lockfile`) step and a separate "Build frontend assets" (`pnpm build`) step, per decision 6; step comments rewritten to describe the explicit build instead of the old install-hook side effect. |
| `.git-blame-ignore-revs` | Step-6 format-commit hash (`e44e0f91573bd5f75a58ad76c9ce8398148ee696`) appended below the existing usage header and policy comment, resolving the placeholder Step 6 left — see Risks & Rollout Notes. |

Tests: none (test-writer: skip — ticket-wide; this step touches no production PHP under `app/`/`packages/`). Gates: Verifier PASS; Tester — PHPUnit PASS, PHPStan PASS; all 4 workflows confirmed running on pnpm with an explicit build step, ESLint/Prettier confirmed blocking full-tree (no `continue-on-error`, no diff-scoping), and the five frozen job names (`frontend`, `e2e-smoke`, `e2e-merge`, `e2e-viewports`, `e2e-sweep`) verified unchanged; coverage gap pass skipped per the ticket's `## TESTING STRATEGY` (`test-writer: skip` on all steps).

### Agent env, Docker and docs realigned to pnpm/Vite; zero-reference grep sweep clears remaining yarn/webpack/gulp strays (Checklist Step 8)

| File | Change |
|---|---|
| `.cursor/Dockerfile`, `.cursor/install.sh`, `.cursor/modernize-helper.sh`, `.cursor/README.md` | Cloud-agent image and bootstrap: Debian's `npm install -g yarn@1.22` → `npm install -g pnpm@11.17.0` (Debian's Node ships no corepack, so the pin has to go through npm); `install.sh` gains an idempotent pnpm bootstrap guard ahead of Composer (same `command -v` pattern as the existing rg/jq/composer guards — needed because a cold-booted agent runs off the old yarn-only snapshot until the image itself is rebuilt), swaps `yarn install --frozen-lockfile` for `pnpm install --frozen-lockfile` plus a new explicit `pnpm build` step (installs no longer build — decision 6), and the tool-verification tail gains a `pnpm --version` line; `modernize-helper.sh`'s `watch_assets()` function and its own `--help` text move from `yarn watch-all`/"Webpack watch mode" to `pnpm watch`/"asset watcher (JS + LESS)"; the German dev-cheatsheet `.cursor/README.md` gets the same command swap plus updated "Wichtige Dateien" bullets for the Dockerfile and `install.sh`. |
| `.cursor/rules/pagekit-context.mdc`, `.cursor/rules/pagekit-files.mdc`, `.cursor/rules/pagekit-standards.mdc`, `.cursor/rules/conventional-commits.mdc`, `.cursor/skills/github-issue-creator/SKILL.md` | Rule/skill prose only, no rule logic changed: `pagekit-context.mdc`'s frontend-stack bullets move "Vue.js 2.6" → "2.7" and the build-pipeline goal "Webpack 4" → "Vite, per-module IIFE bundles"; `pagekit-files.mdc` and `pagekit-standards.mdc` update their frontmatter `description` text ("Vue.js 2.6 patterns" → "2.7", "Always run yarn compile-js" → "pnpm build"); `conventional-commits.mdc`'s example commit swaps `build(webpack): optimize bundle splitting` for `build(vite): optimize bundle output`; the issue-creator skill's label-keyword table drops `Webpack` from the `frontend` row in favor of `Vite`. |
| `.cursorignore`, `.github/dependabot.yml`, `.htaccess` | Comment/config text caught by the grep sweep, no behavioral change to any of the three: `.cursorignore`'s CSS-ignore comment "(gulp/webpack output)" → "(build output)" (the `*.css`/`*.less` globs are unchanged); `dependabot.yml`'s comment above the `npm` ecosystem block "npm/Yarn" → "the npm ecosystem (pnpm lockfile, JavaScript)" (the ecosystem block itself is untouched — it already covers `pnpm-lock.yaml` natively, per the ticket's "No changes" list); `.htaccess`'s restricted-file regex drops the now-deleted `gulpfile.js`/`webpack.config.js` names and adds the two new pnpm manifests `pnpm-lock.yaml`/`pnpm-workspace.yaml` to the list of files Apache must refuse to serve. |
| `AGENTS.md` | Overview sentence now names pnpm/Vite/Node scripts instead of Webpack/Gulp; the Services table collapses the four separate yarn rows (ESLint, webpack build, gulp build, watch-all) into `pnpm build` / `pnpm watch` / `pnpm lint` / `pnpm exec prettier --check .` / `pnpm cldr`; the "yarn install triggers a full production build" caveat is replaced by an install/build-decoupled explanation (`pnpm install` only installs, `pnpm build` builds, the `preinstall` guard rejects non-pnpm installs, Node engines range spelled out); the "~13k pre-existing ESLint errors" caveat is replaced by the new blocking-lint-and-Prettier reality plus a pointer to `git config blame.ignoreRevsFile .git-blame-ignore-revs`. |
| `README.md` | ~23 lines across Key Features/Technical Highlights (Node range, Yarn line → pnpm/Corepack line, "Build Tools: Webpack 4" → "pnpm workspace with Vite bundling and Node-based LESS/asset scripts"), the Docker and manual-setup walkthroughs (`yarn install` → `corepack enable && pnpm install`; the two-command `yarn compile-js --mode=production && yarn compile-less` → one `pnpm build`), the Frontend Development section (watch/build/lint command blocks rewritten for the new script surface: `pnpm watch`; `pnpm build`/`build:js`/`build:css`/`build:assets`; `pnpm lint` / `pnpm lint --fix` / `pnpm exec prettier --check .` / `--write`), and the Command Reference + Docker Commands blocks (`yarn watch-js`/`watch-less`/`watch-all` collapse to one `pnpm watch` line, `npm run test:e2e*` → `pnpm test:e2e*` plus a new `pnpm test:smoke` line, the standalone `yarn assets` line dropped since `pnpm build:assets` now covers it). |
| `docker-compose.yml` | `node` service `command`: `yarn install && yarn watch-all` → `corepack enable && pnpm install && pnpm build && pnpm watch` (adds the explicit build step per decision 6); the comment above moves from the old "don't npm-install yarn, it's baked into the image" caveat to explaining the corepack activation. |
| `packages/pagekit/theme-one/composer.json` | `archive.exclude` drops `"gulpfile.js"` and `"package.json"` — both files were deleted from this package in Checklist Step 4 but this list still named them; see Risks & Rollout Notes. |
| `scripts/styles.mjs` | One comment edited: a historical aside ("the Gulp pipeline relied on the working directory for the same thing") next to the LESS `paths` option is dropped, leaving only the forward-looking reason for the setting. |
| `tests/e2e/README.md`, `tests/e2e/TEST_PLAN_ANALYSIS_2025.md` | `tests/e2e/README.md`'s Prerequisites line and every `npm install`/`npm run test:e2e*` command become `pnpm add -D …`/`pnpm test:e2e*`; `TEST_PLAN_ANALYSIS_2025.md`'s one Dependabot bullet renames "NPM/Yarn" to "npm/pnpm". |

Tests: none (test-writer: skip — ticket-wide; this step touches no production PHP under `app/`/`packages/`). Gates: Verifier PASS — the zero-reference grep gate (`rg -in 'yarn|webpack|gulp' --hidden -g '!.git' -g '!node_modules'`) came back with hits only in the ticket's named allow-list plus two additional archival/registry classes not literally listed there (see Risks & Rollout Notes); every documented command in the touched files names only scripts that exist in `package.json`; Tester — PHPUnit PASS, PHPStan PASS; coverage gap pass skipped per the ticket's `## TESTING STRATEGY` (`test-writer: skip` on all steps).

### Verification — clean-room inventory/audit parity confirmed; VInput SFC export bug fixed after a final-E2E catch (Checklist Step 9)

| File | Change |
|---|---|
| `migration-docs/branches/phase-2/step-2-4-build-inventory-vite.txt` (new) | Regenerated per decision 2 from a clean room (`git clean -Xdf` over the 5 ignored build-output roots + `rm -rf node_modules`, then `pnpm install --frozen-lockfile && pnpm build`): 1487 non-comment path lines, identical to the Step 4-corrected `step-2-4-build-inventory-webpack.txt` baseline (`diff` empty). Header also records the two tracked-output checks the ignored-path inventory itself can't cover: `packages/pagekit/theme-one/css/theme.css` came back byte-identical (`git status` clean) and the committed `tinymce_skin/` directory survived the asset-copy overwrite. |
| `migration-docs/branches/phase-2/step-2-4-audit-pnpm-after.txt` (new) | `pnpm audit` after the full swap: 67 advisories → 8. All 59 resolved advisories trace to now-deleted tooling (webpack, babel, css-loader, gulp-less, npm-run-all, the old ESLint 7 chain, `vue-template-compiler`); the 8 remaining are runtime, not tooling — 7 `tinymce` advisories (editor stays pinned `~5.10.9`, unchanged scope) plus 1 low-severity `vue` ReDoS advisory that `yarn audit --level moderate` had filtered out of the before-audit, not newly introduced. |
| `app/system/app/components/validation.vue` | `VInput`'s named export dropped; the component is now exported only as the module default (`ValidationObserver` stays a named export). Fixes a rendering bug the final E2E gate caught — see Key Decisions. |
| `app/installer/app/views/installer.vue`, `app/system/modules/site/app/views/{edit,index,settings}.js`, `app/system/modules/user/app/views/{admin/role-index,admin/user-edit,profile,registration,reset-confirm}.js`, `app/system/modules/widget/app/views/edit.js`, `packages/pagekit/blog/app/views/{admin/comment-index,admin/post-edit,reply.vue}` (13 files) | `import { ValidationObserver, VInput } from '…validation.vue'` → `import VInput, { ValidationObserver } from '…validation.vue'` — the same one-line import-specifier fix at every call site that used the now-removed named form. `app/system/modules/site/app/components/input-link.vue` already imported `VInput` as the default export and needed no change. |

Tests: none (test-writer: skip — ticket-wide; this step's only source changes are the `VInput` export-shape fix described above, which has no PHP surface). Gates: Verifier PASS; Tester — PHPUnit PASS, PHPStan PASS; inventory-parity diff against the Step 1/4 baseline empty; after-audit committed and free of the three webpack-transitive advisories that were ever present in the before-audit (see Risks & Rollout Notes); final E2E (3 `@ci` Playwright specs, chromium-desktop) FAILed once — `installation.spec.js` timed out on `input#form-sitename` — traced to the `VInput` wiring bug (see Key Decisions); refactorer retry (`validation.vue` export shape + 13 import-site updates) → Verifier PASS, PHPUnit PASS, PHPStan PASS, then final E2E PASS on all 3 specs. This Checklist Step completes every `## EXECUTION STATE` box.

---

## 🧠 Key Decisions (Rationale)

- **Inventory sort forces `LC_ALL=C` (Checklist Step 1).** The ticket's decision-2 inventory command ends in a bare `sort`; the committed file instead runs `LC_ALL=C sort`, because the default `en_US.UTF-8` collation orders the recorded paths differently than the C locale would, which would otherwise produce a large diff against Step 9's regenerated inventory that contains no actual path-set difference. Recorded in the committed file's own header comment — Step 9's regeneration command must use the same `LC_ALL=C sort` or the parity check will show a false-positive diff.
- **Vite pinned at latest 7.x, not 8 (Checklist Step 3).** `@vitejs/plugin-vue2` is archived (Vue 2 reached EOL) with a peer range capped at Vite 7; its two Vite-8-support PRs were never merged (repo archived, tracking issue locked) — the task prompt's claim that Vite-8 support had already merged was verified wrong ahead of this step. `vite@^7.3.6` + `@vitejs/plugin-vue2@^2.3.4` is the newest released pair; Vite 7 is still the previous-major security-backport line. Revisit is deferred to Step 3.3.3 (Vue 3 core), when the plugin itself is swapped for `@vitejs/plugin-vue`.
- **`vue-nestable` needed an explicit resolve alias to its UMD build (Checklist Step 3 discovery).** The package's own export map advertises an unbundled `module` build that neither ships nor declares its `vue-runtime-helpers` peer; `build-js.mjs` aliases the import straight to `vue-nestable/dist/index.umd.min.js`, the only build the package ships that is actually self-contained.
- **`version.js`'s CommonJS export needed conversion, outside the ticket's 4 named import sites (Checklist Step 3 discovery).** Decision 3's verification covered `.html`/`.json` imports and SFC `<style>` blocks, not plain-`.js` module syntax; this file's bare `exports.compare = function …` doesn't resolve under Vite's native ESM handling (no CJS-interop plugin in this pipeline), so it became `export default { compare }` in the same pass that also dropped its already-dead `this.php_js` reset (pre-marked `// END REDUNDANT`).
- **Library/CLI split replaces a fragile entry-point guard, applied to both build scripts (Checklist Step 4).** `watch.mjs` needs the JS-bundle and LESS builders as plain library calls (`watchBundles()`, `watchStyles()`); the first refactorer pass kept everything in `build-js.mjs` and gated its own `main()` behind an `import.meta.filename === process.argv[1]` "am I the entry point" check so the same file could double as an importable module. The Verifier FAILed it: that check silently evaluates false — no error, nothing runs — on a Node release without `import.meta.filename` or whenever the running script's path differs from `argv[1]` (a symlinked invocation, for instance). The retry doesn't harden the guard, it removes the need for one: `bundles.mjs`/`styles.mjs` are pure libraries with zero top-level execution, and `build-js.mjs`/`build-css.mjs` are thin CLI wrappers that always run unconditionally — a CLI wrapper is by construction never imported, so there is nothing left to guard against.
- **`pnpm-workspace.yaml`'s `allowBuilds` approves `esbuild` only, not `core-js` (Checklist Step 5).** `pnpm install` surfaced two build-script requests, not just the ticket's named `esbuild` case; `core-js`'s postinstall only prints a funding banner with no build output the tree needs, so it is explicitly denied (`false`) rather than left off the map or blanket-allowed — one reviewed exception granted, one reviewed and declined, per the ticket's decision 5 instruction to approve only what is needed.
- **ESLint pinned at 10, not the task prompt's named 9 (Checklist Step 6).** ESLint 9 hits EOL 2026-08-06 — days after this step landed — so pinning it would have shipped a dead major immediately; `eslint@^10.8.0` + `eslint-plugin-vue@^10.10.0` (peers ESLint 10; ships the `flat/vue2-recommended` preset this config uses) + `vue-eslint-parser@^10.4.1` is the newest compatible set. `eslint-config-airbnb-base` has no flat-config support, so the port does not chase its rule set rule-by-rule — only the non-formatting custom rules and script-tag globals carry over from `.eslintrc` (see What Changed).
- **`pnpm/action-setup` chosen over corepack activation for CI (Checklist Step 7).** The ticket left either mechanism open, provided the pnpm version resolves from `packageManager`; `pnpm/action-setup@0ebf47130e4866e96fce0953f49152a61190b271` (v6.0.9, no version input — reads `packageManager` itself) was picked to match the repo's existing convention of pinning every action to a commit SHA (`actions/checkout`, `actions/setup-node` in the same jobs), rather than introducing a differently-shaped `corepack enable` step.
- **`VInput`'s named export stopped carrying its compiled template once Step 3 swapped webpack for Vite — invisible until this step's E2E run (Checklist Step 9 discovery).** `installation.spec.js` timed out waiting for the sitename input to render. `validation.vue` exported the same object two ways — `export default VInput` and a named `export { ValidationObserver, VInput }` — and every call site importing the named form (`import { ValidationObserver, VInput } from …`) ended up with a component that had no working template once `@vitejs/plugin-vue2` (Step 3) replaced webpack's `vue-loader`; the unchanged source had rendered correctly under the old pipeline. No gate between Step 3 and Step 9 renders a `<v-input>` in a real browser — Step 2's E2E smoke ran *before* the Vite swap, and Steps 3–8's own gates check build/bundle output, not rendered DOM — so the regression rode along undetected until this step's Playwright run. Fix: `validation.vue` keeps `VInput` as the default export only (`ValidationObserver` stays named); the 13 call sites that destructured `VInput` by name switch to `import VInput, { ValidationObserver } from …` — `input-link.vue`, the one call site already using the default form, needed no change.

---

## ⚠️ Breaking Changes (Extensions)

_TBD_

---

## ⚠️ Risks & Rollout Notes

- **Step 9's audit-parity gate can only confirm 3 of its 5 named advisories (Checklist Step 1 discovery — resolved in Checklist Step 9).** The ticket's Step 9 gate expects the after-audit to show picomatch, braces, micromatch, serialize-javascript and elliptic all gone. The committed before-audit (`step-2-4-audit-yarn-before.txt`) shows only braces, micromatch and serialize-javascript actually present at `yarn audit --level moderate`; picomatch and elliptic never appear in the baseline output. Step 9 should verify removal of the three advisories that are actually present and not treat a missing picomatch/elliptic entry in the after-audit as a discrepancy. Resolved as expected: `step-2-4-audit-pnpm-after.txt` shows braces, micromatch and serialize-javascript gone and confirms picomatch/elliptic never appeared in either audit — 67 advisories before, 8 after, with all 8 traced to still-in-scope runtime deps (`tinymce`, `vue`), none to removed tooling — see What Changed.
- **Webpack baseline inventory re-recorded on the Vue 2.7 layout, not left as Step 1 committed it (Checklist Step 2).** The 2.6.12 → 2.7.16 bump changes what the webpack pipeline copies into `app/assets/vue/` (~200 new paths — `src/`, `packages/`, `types/`, `compiler-sfc/`, `dist/vue.runtime.mjs`; nothing removed, nothing changed outside that directory). `step-2-4-build-inventory-webpack.txt` is updated in place rather than left for Step 9 to reconcile, so Step 9's pnpm/Vite parity diff runs against this post-bump file, not the original pre-bump one — the file's own header records the exact breakdown against that original recording.
- **That same file needed a second correction — 195 stale Vue 2.6.12 paths removed (Checklist Step 4 discovery).** The Step 2 re-recording above turned out to still be wrong, in a different way: it was taken from a working tree where `app/assets/vue/` had been built once on 2.6.12 (Step 1's baseline commands) and then rebuilt on 2.7.16 without the directory ever being cleared first, so 195 files that exist only in the 2.6.12 distribution survived underneath the 2.7.16 one and were recorded as if the new pipeline has to reproduce them too. It cannot — they aren't shipped by `vue@2.7.16`, and no asset copy (gulp's original task or its `copy-assets.mjs` replacement, neither of which clears a destination before writing) can recreate a file its source package doesn't ship. Corrected in this step's commit rather than left for Step 9's parity diff to surface as an unexplained failure; the file's own header now documents the exact count and cause.
- **`.git-blame-ignore-revs` is committed without its hash yet (Checklist Step 6 — resolved in Checklist Step 7).** The file records the format-once policy and its own usage comment but can't reference the format-commit SHA before the Orchestrator commits this step's diff. Ticket decision 12 already plans for Step 7 to append the hash via `git log`, so Step 7's diff should show the file gaining a line, not being authored fresh — not an oversight if seen that way. Resolved as planned: Step 7 appends `e44e0f91573bd5f75a58ad76c9ce8398148ee696` (the Step 6 format commit) — see What Changed.
- **Zero-reference grep gate surfaced two categories beyond the ticket's named allow-list, plus a Checklist-Step-4 leftover (Checklist Step 8 discovery).** The sweep's only non-allow-listed hits were: (a) `migration-docs/documentation/**` and `migration-docs/dependencies/**` — the same historical-writeup class as the named `migration-docs/TODO/**`/`migration-docs/audits/**`, just two directories the ticket text didn't happen to name; and (b) `packages/packages.lock` + `packages/composer/installed.json`, Pagekit's generated registry snapshots of the `pagekit/theme-one` package's `composer.json` — not hand-edited source, so left as-is. Both snapshots (and, until this step, `theme-one/composer.json` itself) still named `theme-one/gulpfile.js` in an `archive.exclude` list — a dangling reference to a file Checklist Step 4 had already deleted. This step drops the stale names from the live `theme-one/composer.json`; the two generated snapshots still carry them (regenerating Pagekit's package registry is outside this ticket). Verifier treated all of the above as PASS, not a missed scrub — Step 9 (or any later zero-reference sweep) should extend the allow-list to these two path classes rather than treat them as a FAIL.

---

## 🔐 Security & Data Impact

_TBD / None_

---

## 🛡️ No-Mercy Compliance

- **Rule 4 (Delete over wrap) — Checklist Step 4:** `gulpfile.js` and the three package-level toolchain files (`theme-one/gulpfile.js`, `theme-one/package.json`, `blog/package.json`) deleted outright, no transitional wrapper; theme-one's LESS root folds directly into `styles.mjs`'s 3-root list rather than keeping a second, parallel compile path alive. The `gulp`/`gulp-*`/`merge-stream`/`npm-run-all` dependency chain is removed from `package.json` in the same commit, not left installed-but-unused.
- **Rules 2 & 4 (No adapters / Delete over wrap) — Checklist Step 6:** `.eslintrc`, `.eslintignore`, and the ESLint 7 + `eslint-config-airbnb-base` + `babel-eslint` + `eslint-plugin-import` chain are deleted outright in the same commit that adds the flat `eslint.config.js` — no rule-by-rule airbnb parity shim, no dual-config transition period. The two Vue rules the codebase can't clear (`vue/multi-word-component-names`, `vue/no-reserved-component-names`) are disabled once, centrally, with an inline justification comment in the config, rather than sprayed as per-file `// eslint-disable` overrides (ticket decision 12).
- **Rule 4 (Delete over wrap) — Checklist Step 7:** `frontend.yml`'s advisory, diff-scoped ESLint/Prettier machinery (`continue-on-error`, the changed-files step, the PR base-ref fetch, checkout's `fetch-depth: 0`) is deleted outright in the same commit that makes `pnpm lint` / `pnpm exec prettier --check .` blocking full-tree — no interim commit where the old advisory gate and the new blocking gate run side by side.

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
