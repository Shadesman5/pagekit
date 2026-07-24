# Step 2.4: Build Tools Modernization (pnpm + Vite)

<!-- conductor-mode: full -->

**ROADMAP:** 2.4. GitHub Issue: #159. Reference: `@ROADMAP.md`, `PHASE_2_MODERNISING.md` §2.4, `migration-docs/branches/branch-doc-skeleton.md`.

---

## CONTEXT

- **Land after:** 2.3 (Docker Dev Experience). Must land before 2.5 — the production image (Step 2.5) builds on the Vite asset pipeline. Prerequisite for all of Phase 3.
- **Risk:** Medium–High — the frontend build is replaced wholesale; every module bundle, the LESS output, the asset copies, four CI workflows, and the agent environment change together.
- **Goal:** Replace **Yarn 1 + Webpack 4 + Gulp** with **pnpm + Vite** — one modern frontend pipeline instead of three legacy ones. Includes the **minimal Vue 2.6.12 → 2.7.16 bump** (user-approved pull-forward of the Step 3.2 version bump, 2026-07-24) because the Vue-2.6-only Vite plugin is EOL; the bridge work itself (Composition API trials, deprecation analysis) stays in Step 3.2.
- **Why:** Webpack 4 and its loader chain are unmaintained and carry the bulk of the JS audit findings in locked transitives; Yarn 1 is frozen; Gulp only exists for LESS/asset-copy/CLDR tasks a script or Vite can do. Phase 3 (Vue 3, TypeScript) needs a Vite-native toolchain.

### Current state (verified 2026-07-24 — confirm in Discovery, then build; do not rediscover blindly)

- **Build model:** the root `webpack.config.js` is a meta-config that globs **17 per-module configs** (`app/modules/**`, `app/installer/**`, `app/system/**`, `packages/**` — blog + theme-one) and merges defaults: babel-loader on `.js`, aliases `@installer` / `@system`, `externals: { vue: 'Vue', uikit: 'UIkit', 'uikit-util': 'UIkit.util' }`, auto-injected `VueLoaderPlugin`. Sub-configs add per-case loaders (`vue-loader`, `json-loader`, `html-loader`, `vue-style-loader`/`css-loader`).
- **Runtime contract (must not change):** PHP views load assets as **classic script tags with globals** — `window.Vue` / `window.UIkit` come from `app/assets/` dists, each webpack entry emits a standalone classic script at `<module>/app/bundle/<name>.js` that references those globals. All build outputs are **gitignored** (`**/app/bundle/**/*.js`, `/app/assets/*`, editor assets except the committed `tinymce_skin`) and regenerated at install time. **Output paths are the contract**; switching the script-tag/global model to ES modules is NOT part of this step.
- **Install-time build coupling:** `package.json` has an `install` lifecycle script (`yarn compile-js --mode=production && gulp`) — every `yarn install` produces bundles + CSS + asset copies. CI E2E workflows rely on this (comments in `e2e.yml` / `nightly.yml` / `e2e-weekly.yml` say so explicitly). `preinstall` runs `app/scripts/checkYarn.js`, which hard-exits unless Yarn is used. `engines`: `node >=20 <23`, `yarn 1.x`. `resolutions` (Yarn-only field) pins `chokidar` / `source-map-resolve`. GitHub-fork deps: `Codemirror`, `JSONStorage`, `vue-intl` (uatrend forks).
- **Gulp tasks** (`gulpfile.js`):
  - `compile` — LESS → CSS for exactly two roots (`app/installer/`, `app/system/modules/theme/`), compressed, `relativeUrls`, output renamed `less/` → `css/`, prefixed with a banner from root `composer.json` `title`/`version` — **both fields no longer exist there** (version SSoT moved to `app/system/config.php` in Step 2.0.5), so the banner currently renders `undefined`.
  - `assets` — copies node_modules dists into the app: `uikit`, `vue`, `flatpickr`, `lodash` (dist folder) → `app/assets/`; `tinymce`, `marked`, `codemirror` (npm package name `Codemirror`, case-sensitive) → `app/system/modules/editor/app/assets/`.
  - `cldr` — regenerates committed intl JSON under `app/system/languages/**` from `cldr-core` / `cldr-localenames-modern`; **carries a real bug**: the last write uses a template string missing its `$` (`` `{cldr.languages}${src}/formats.json` ``), silently writing to a literal `{cldr.languages}...` path.
  - `lint` / `watch` — redundant with `yarn lint` / `watch-less`.
- **Lint/format stack:** ESLint **7** with a legacy `.eslintrc` (airbnb-base + `plugin:vue/recommended`, parser `vue-eslint-parser` + `babel-eslint`, extensive custom rules, globals for the script-tag world: `_`, `Vue`, `UIkit`, `$pagekit`, …), plus `eslint-watch` (`lint-watch` script), `gulp-eslint`, `eslint-webpack-plugin` (verify consumers). Prettier **3.9.6** + `.prettierrc` is **advisory only** (diff-scoped, `continue-on-error` in `frontend.yml`); the tree carries ~13k pre-existing violations. Step 2.2 forwarded the **final formatting policy decision to this step**.
- **Babel:** `.babelrc` (preset-env + transform-runtime), no browserslist config anywhere — the Babel chain disappears with webpack (esbuild target instead).
- **CI (4 workflows use Yarn):** `frontend.yml` (blocking production build gate + advisory diff-scoped ESLint/Prettier; **required-check job name `frontend` must survive** — renames orphan required checks, Step 2.2 lesson), `e2e.yml` (2 jobs), `nightly.yml`, `e2e-weekly.yml` — all `cache: yarn` + `yarn install --frozen-lockfile` + install-hook build.
- **Agent/Docker/docs surface:** `.cursor/Dockerfile` (`npm install -g yarn@1.22`), `.cursor/install.sh` (`yarn install --frozen-lockfile`), `.cursor/modernize-helper.sh` (`yarn watch-all`), `.cursor/README.md`, `AGENTS.md` (services table + the "yarn install triggers a full production build" caveat), `README.md` (~20 yarn/webpack/gulp reference lines), `docker-compose.yml` `node` service (`yarn install && yarn watch-all`), rule texts `.cursor/rules/pagekit-files.mdc` + `pagekit-standards.mdc` ("Always run yarn compile-js after changes").
- **Vue × Vite plugin matrix (2026):** `vite-plugin-vue2` (Vue ≤ 2.6) is EOL since 2022 with Vite peer ≤ 4; the official `@vitejs/plugin-vue2` requires Vue ≥ 2.7 and got Vite-8 support merged in Apr 2026 — **verify the released peer range in Discovery** and pin Vite accordingly. Current `vue-loader ~15.11.1` already supports Vue 2.7, so the Vue bump can land before the pipeline swap.
- **Audit claim to verify:** dropping Webpack 4 removes its vulnerable locked transitives (picomatch, braces, micromatch, serialize-javascript, elliptic — the bulk of the JS audit findings). Prove it with a before/after audit, not by assertion.
- **Dependency right-sizing:** `chokidar` sits in production `dependencies` (watch tooling); `glob` / `minimist` / `lodash` are consumed by the webpack meta-config; `npm-run-all` only by `watch-all`. After the swap, every dependency without a consumer is deleted (No Mercy).

---

## PRINCIPLES (hold across every checklist step)

- **One pipeline, no parallel tooling** — webpack, gulp, babel, and their loader/plugin chains are **deleted** in this step, not kept as fallback (delete over wrap). No dual build paths.
- **Output paths are the runtime contract** — every bundle keeps its exact `<module>/app/bundle/<name>.js` path, LESS output keeps its `css/` paths, asset copies keep their `app/assets/` layout. PHP views are not touched.
- **Classic scripts + globals stay** — per-entry self-contained classic-script bundles with `vue`/`uikit`/`uikit-util` mapped to the `Vue`/`UIkit`/`UIkit.util` globals. The ES-module/script-tag redesign belongs to Phase 3.
- **Vue 2.7 bump is minimal** — version bump + plugin swap + compat verification only. No Composition API adoption, no `<script setup>`, no deprecation-warning cleanup (all Step 3.2).
- **Required-check continuity** — CI job names that are required checks (`frontend`, the E2E smoke job) keep their names; only steps inside them change.
- **The tree stays green after every checklist step** (Rule 3) — sequence so each commit builds and passes; do not leave the repo mid-swap without a working build.
- **Tooling wraps, never bloats runtime** — build tooling changes only; no PHP runtime code changes, no new runtime dependencies.

---

## 0. SAFETY CHECKS

Before starting:

1. Branch up-to-date with `develop`.
2. Baseline green **including the current frontend build**:

```bash
php -v && node -v
composer install --no-interaction --prefer-dist --no-progress
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
yarn install --frozen-lockfile
yarn compile-js --mode=production && yarn gulp
```

3. **Record the baseline output inventory** (the migration's ground truth):

```bash
git ls-files --others --ignored --exclude-standard -- '**/app/bundle/*' 'app/assets/*' 'app/installer/**/css/*' 'app/system/modules/theme/**/css/*' | sort > /tmp/build-inventory-webpack.txt
yarn audit --level moderate > /tmp/audit-before.txt || true
```

**IF ANY FAILS → STOP AND FIX** (or report the pre-existing failure) before touching the toolchain.

---

## 1. DISCOVERY

```bash
rg -n 'entry|output|use:' --glob '**/webpack.config.js' -g '!node_modules'
rg -n "from '.*\.html'|require\(.*\.html|from '.*\.json'" app/ packages/ --glob '*.{js,vue}' --glob '!**/bundle/**' --glob '!**/assets/**'
rg -n 'lodash|vue-resource|vue-event-manager|vue-intl' app/system/app/vue.js
rg -n 'yarn|webpack|gulp' .github/workflows/ .cursor/ AGENTS.md README.md docker-compose.yml
rg -n 'install|preinstall|watch' package.json
```

- Verify the **released** `@vitejs/plugin-vue2` peer range (Vite majors) and pin the newest supported Vite; record the pin rationale in the ticket.
- Verify `eslint-plugin-vue` flat-config support for **Vue 2** (`flat/vue2-recommended` — v9 has it; check whether v10 dropped Vue 2) and pin accordingly.
- Enumerate every `.html` / `.css` / `.json` import site that relied on webpack loaders (`html-loader`, `vue-style-loader`/`css-loader`, `json-loader`) — JSON is Vite-native; `.html` imports need `?raw` (update the import sites — Rule 2, no shim loader).
- Confirm which devDependencies have consumers after the swap (candidates for deletion: webpack, webpack-cli, all loaders, babel chain, gulp chain, eslint-watch, eslint-webpack-plugin, vue-hot-reload-api, vue-template-compiler, glob, minimist, npm-run-all, chokidar-in-prod).
- Check `pnpm` lifecycle-script policy needs: Vite's `esbuild` requires its install script — prepare `pnpm.onlyBuiltDependencies`.

---

## 2. WORK

Architect: decompose into ordered, individually-green checklist steps. Suggested order (each step leaves a working build): 2.1 (Vue 2.7 under webpack) → 2.2 (Vite JS pipeline) → 2.3 (LESS/assets/CLDR, delete gulp) → 2.4 (pnpm swap, delete yarn) → 2.5 (ESLint 9 + Prettier decision) → 2.6 (CI) → 2.7 (agent env + Docker + docs) → 2.8 (audit + inventory verification).

### 2.1 Vue 2.6.12 → 2.7.16 (minimal bump, still under webpack/yarn)

- Bump `vue` to `~2.7.16`; remove `vue-template-compiler` (2.7 ships its own compiler; `vue-loader` 15.11 auto-detects it).
- Verify the plugin stack under 2.7: `vue-resource`, `vue-event-manager`, `vue-intl` fork, `vee-validate` 3, `vue-nestable` (per the Phase-3 dependency analysis all are 2.7-compatible — confirm at runtime, admin smoke).
- The copied global dist (`app/assets/vue/`) becomes 2.7 via the assets task — admin UI + E2E smoke must pass on 2.7 **before** the pipeline swap, so regressions are attributable.
- **Scope guard:** no Composition API usage, no code changes beyond what the bump forces. Bridge analysis stays Step 3.2.

### 2.2 Vite pipeline — JS bundles

- Replace the meta-config + 17 sub-configs with a Vite-based build that preserves the contract: **per-entry classic-script bundles** (IIFE-style, self-contained), `vue`/`uikit`/`uikit-util` external → globals, aliases `@installer`/`@system`, identical `app/bundle/<name>.js` output paths.
- Mechanism is the Architect's choice (Rollup IIFE constraint: multi-entry code-splitting does not work with classic formats — options: per-entry lib-mode builds driven by one orchestrator config/script, Vite environments/builder API, or equivalent). One config source of truth; per-module boilerplate minimized.
- `.vue` SFCs via `@vitejs/plugin-vue2`; JSON imports native; `.html` imports converted to `?raw` at the import sites; CSS imports in SFC/entry handled by Vite's own pipeline (verify runtime style injection parity for the system `vue` bundle).
- esbuild target replaces Babel (no browserslist existed; pick a target matching the supported-browser reality of UIkit 3.5/Vue 2.7 and record it). Delete `.babelrc` + the Babel chain with webpack.
- `watch-js` → `vite build --watch` equivalent; **no Vite dev server / HMR-PHP integration** (out of scope, Phase 3).

### 2.3 LESS, asset copies, CLDR — delete gulp

- **LESS → CSS**: same two roots (`app/installer/`, `app/system/modules/theme/`), compressed, same `css/` output paths. Mechanism free (Vite CSS entries or a small script using the existing `less` package). **Fix the banner source**: title/version from `app/system/config.php` (the version SSoT) or drop the version — no more `undefined` banners.
- **Asset copies**: port `gulp assets` to a small script (or Vite plugin) with the identical target layout, including the `Codemirror` package-name case and the committed `tinymce_skin` exception.
- **CLDR**: port `gulp cldr` to a standalone node script (`pnpm cldr`), **fixing the literal `{cldr.languages}` path bug**.
- Wire JS build + LESS + assets into one `build` script (that is what CI and the install coupling call); delete `gulpfile.js` + all `gulp-*` deps.

### 2.4 pnpm swap — delete Yarn

- `packageManager` field (pinned pnpm) + corepack as the documented activation path; update `engines` (drop `yarn`, align node with the Vite pin).
- Replace `app/scripts/checkYarn.js` with a pnpm guard (`preinstall: npx only-allow pnpm` or equivalent); delete the old script.
- `resolutions` → `pnpm.overrides` (re-evaluate whether the chokidar/source-map-resolve pins are still needed at all); add `pnpm.onlyBuiltDependencies` for esbuild (and anything else Discovery found).
- Replace `yarn.lock` with a committed `pnpm-lock.yaml`; verify the GitHub-fork deps resolve under pnpm.
- **Decide (Architect): install-time auto-build.** Either keep parity (root lifecycle script so `pnpm install` still produces assets — CI comments and AGENTS.md rely on that today) or decouple to an explicit `pnpm build` and add the build step everywhere the hook was relied on (4 workflows, `.cursor/install.sh`, docs). One decision, applied consistently, documented in the ticket.
- Right-size dependency placement (chokidar out of prod deps or deleted; drop `npm-run-all`/`eslint-watch` if their scripts go).

### 2.5 ESLint 9 flat config + final Prettier decision

- Migrate `.eslintrc` (legacy JSONC) to `eslint.config.js` (flat). Replace `airbnb-base` (no official flat support) with a maintained base (`@eslint/js` recommended + ported local rules is enough — do not chase airbnb parity rule-by-rule); replace `babel-eslint` with espree (no Babel syntax left) under `vue-eslint-parser`; `eslint-plugin-vue` flat vue2 preset per Discovery pin.
- Port the existing custom rules + script-tag globals; keep `pnpm lint` behavior (same file scope via flat `ignores`).
- Drop `eslint-watch` (`lint-watch`), `gulp-eslint`, `eslint-webpack-plugin` unless a consumer survives.
- **Decide (Architect): final formatting policy** (forwarded from Step 2.2): **(a)** format the tree once with Prettier (dedicated commit + `.git-blame-ignore-revs` entry) and flip the CI Prettier check to blocking full-tree, or **(b)** drop Prettier entirely (delete `.prettierrc` + dep + CI step; ESLint stays the single style authority). Record the decision + rationale in the ticket and branch doc; the ~13k-violation backlog must not survive as a permanent advisory limbo.

### 2.6 CI workflows (4 files, job names frozen)

- `frontend.yml`: pnpm setup (corepack or `pnpm/action-setup`, `cache: pnpm`), `pnpm install --frozen-lockfile`, `pnpm build` as the blocking gate; ESLint/Prettier steps per the 2.5 decision (advisory diff-scoped ESLint stays unless the policy decision changes it). **Job name `frontend` unchanged.**
- `e2e.yml` (both jobs), `nightly.yml`, `e2e-weekly.yml`: swap the Node/Yarn setup + install steps to pnpm; ensure assets are built per the 2.4 install-coupling decision. Job names unchanged.
- Pin any new third-party action by commit SHA (repo convention).

### 2.7 Agent environment, Docker, docs, rules

- `.cursor/Dockerfile`: replace the global yarn install with corepack/pnpm; keep the rest untouched.
- `.cursor/install.sh` + `.cursor/modernize-helper.sh` + `.cursor/README.md`: pnpm commands.
- `AGENTS.md`: services table (`pnpm lint`, `pnpm build`, watch commands), the install-hook caveat per the 2.4 decision, ESLint status line (the ~13k note changes per the 2.5 decision).
- `README.md`: all build/tooling sections (requirements, quickstart, command reference) — pnpm + Vite reality, no yarn/webpack/gulp remnants.
- `docker-compose.yml` `node` service: pnpm equivalent of `yarn install && yarn watch-all` (image + corepack).
- Rule texts: update the `yarn compile-js` references in `.cursor/rules/pagekit-files.mdc` + `.cursor/rules/pagekit-standards.mdc` descriptions to the pnpm command.

### 2.8 Verification — inventory + audit

- Rebuild from clean (`git clean -dfx` on ignored build outputs is acceptable — never touch tracked files) and produce the same inventory listing as the baseline: **the file-path set must be identical** to `/tmp/build-inventory-webpack.txt` (contents differ, paths do not). Investigate every missing/extra path.
- `pnpm audit > /tmp/audit-after.txt`; diff against the baseline audit and record the dropped webpack-transitive advisories in the ticket (verification of the §2.4 claim — numbers live in CI/artefacts, not prose metrics tables).

---

## MANUAL WORK (record in the branch doc — agents do NOT perform these)

1. **Required-check sanity** after the PR: confirm the `frontend` and E2E smoke checks still report under their old names; if anything was renamed despite the freeze, realign the Ruleset (admin rights).
2. **Local dev machines**: one-time `corepack enable` (or pnpm install per docs), remove `node_modules/`, run `pnpm install`; delete stale local yarn artifacts.
3. If the formatting decision was **(a) format-once**: configure local git with `git config blame.ignoreRevsFile .git-blame-ignore-revs` (per-clone setting).

---

## 3. OUT OF SCOPE

- **Vue 3, Composition API adoption, `<script setup>`, deprecation-warning cleanup** → Steps 3.2 / 3.3. This step ships 2.7 as a drop-in runtime only.
- **Dependency swaps** (`vue-resource` → axios, `vue-event-manager` → mitt, vue-intl rewrite, lodash removal) → Steps 3.3.1 / 3.3.2 / 3.3.5.
- **UIkit update** (3.5 → 3.21) → Step 3.1. **TinyMCE 6+** → stays ~5.10.9. **TypeScript** → Step 3.4.
- **ES-module / script-tag loading redesign, Vite dev server + PHP integration (HMR)** → Phase 3 (with Vue 3).
- **Webpack 5, Yarn Berry** — dead evaluation branches (superseded by the pnpm + Vite decision; Issue #159 has been modernized accordingly).
- **Docker image/compose work beyond the `node` service command** → Step 2.3 / 2.5.
- **Coverage floors, Infection, PHP tooling** — untouched (Step 2.2 owns CI quality gates; PHP side does not change).

---

## 4. TESTING

- **Per checklist step (Conductor Tester):** PHPUnit + PHPStan PASS/FAIL — must stay green untouched (no PHP changes in this ticket). For build-affecting steps additionally: the build command of that stage completes and the inventory check (§2.8) holds for the affected outputs.
- **Frontend verification:** `pnpm install && pnpm build` from clean; output inventory identical to the webpack baseline; admin login + a Vue-heavy admin page (dashboard widgets, user index) render without console errors on Vue 2.7 — via the E2E smoke specs (`--grep @ci`, `chromium-desktop`).
- **CI is the real gate:** all four reworked workflows green on the PR; `frontend` job blocking on the Vite build.
- **Lint:** `pnpm lint` runs under ESLint 9 flat config with the ported rule set (exit status per the formatting-policy decision).

---

## SUCCESS CRITERIA

- Webpack 4, webpack-cli, all webpack loaders, the Babel chain, Gulp + all `gulp-*` packages, `vue-template-compiler`, `vue-hot-reload-api`, `eslint-watch`, `yarn.lock`, and `checkYarn.js` are **deleted**; no dual build path remains.
- `pnpm install && pnpm build` produces the **identical output-path inventory** as the webpack baseline (bundles, css, asset copies); PHP views work unchanged; E2E smoke green on Vue 2.7.16.
- Vue 2.7.16 ships with `@vitejs/plugin-vue2` on the newest Vite its released peer range supports (pin + rationale recorded); no Composition-API code landed.
- ESLint 9 flat config active with the ported rules/globals; airbnb-base and babel-eslint gone; `pnpm lint` works.
- The **formatting policy is decided and implemented** (tree formatted + blocking check, or Prettier fully removed) — no permanent advisory limbo; decision recorded in ticket + branch doc.
- All four CI workflows run on pnpm with **unchanged job names**; frontend build gate blocking; actions pinned by SHA.
- Agent env (`.cursor/Dockerfile`, `install.sh`, `modernize-helper.sh`, `.cursor/README.md`), `AGENTS.md`, `README.md`, `docker-compose.yml` node service, and the two rule texts carry pnpm/Vite reality — zero yarn/webpack/gulp references left outside historical docs (`migration-docs/branches/`, `CHANGELOG-NEW.md` history stay untouched).
- Before/after dependency audit recorded; the webpack-transitive advisories (picomatch, braces, micromatch, serialize-javascript, elliptic) are gone from the after-audit.
- CLDR script ported with the literal-path bug fixed; LESS banner sources real title/version (or drops the version) — no `undefined` banners.
- PHPUnit + PHPStan green; **no PHP runtime code changed**.
- Manual Work list complete in the branch doc.

---

## NOTES FOR THE ARCHITECT

- **Sequencing is the main risk control.** The suggested order (Vue 2.7 under webpack first, Vite second, pnpm third) keeps every failure attributable: a 2.7 regression shows up under the known-good webpack build; a Vite regression shows up under the known-good yarn install. Re-order only with a written reason in the ticket.
- pnpm's strict `node_modules` can break legacy tooling that relied on hoisting — that is why the pnpm swap comes **after** webpack/gulp are gone. If a survivor still breaks under strict linking, fix the missing declaration (add the dep) — do not enable `shamefully-hoist` (that is a compatibility layer).
- The per-entry classic-bundle mechanism is your call, but keep it **one** mechanism for all 17 module configs — no per-module special cases beyond entry lists and the odd extra alias.
- Where the plugin/Vite pin question (§ Discovery) resolves to an older-than-latest Vite major: pin it, record why, and note the revisit in Step 3.3 (Vue 3 swaps to `@vitejs/plugin-vue` and current Vite anyway). Do not add a forward TODO in code for this — it is toolchain config, tracked via ROADMAP.
- Issue #159 was modernized to match this prompt (2026-07-24). `PHASE_2_MODERNISING.md` §2.4 and `PHASE_3_MODERNISING.md` §3.2 carry the Vue-2.7 pull-forward note. Where anything disagrees, §2.4 + this prompt win.
- One ticket / one PR; Conventional Commits; the version bump happens once at Finalize — never inside checklist steps.
