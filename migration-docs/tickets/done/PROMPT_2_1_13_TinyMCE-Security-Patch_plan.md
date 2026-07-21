# Ticket: TinyMCE Security Patch (~5.10.9) — Step 2.1.13

## ARCHITECT OUTPUT

- **Current Step (ROADMAP):** 2.1.13
- **Scope:**
  - `package.json` — `"tinymce": "~5.5.1"` → `"tinymce": "~5.10.9"` (line 40; the only dependency change)
  - `yarn.lock` — regenerated `tinymce@~…` entry only (currently resolves 5.5.1; target 5.10.x — 5.10.9 is the final community 5.x release)
  - Local build artifacts refreshed by `yarn install`, **not committed** (gitignored via `.gitignore` `/app/system/modules/editor/app/assets/*` + `**/app/bundle/**/*.js`): `node_modules/tinymce/`, `app/system/modules/editor/app/assets/tinymce/`, webpack bundles
  - **Expected commit diff = `package.json` + `yarn.lock` only.** Repo mechanics: yarn 1 (`preinstall` runs `app/scripts/checkYarn.js` — must use yarn, not npm); the `install` lifecycle script runs `yarn compile-js --mode=production && gulp`; gulp default task (`assets` + `compile`) copies `node_modules/tinymce/**` → `app/system/modules/editor/app/assets/tinymce/`
  - Runtime path (verify, don't change): `editor-tinymce.js` loads `${$editor.root_url}/app/assets/tinymce/tinymce.min.js`; `skin_url` points to the **committed** custom skin `app/system/modules/editor/app/assets/tinymce_skin/` (gitignore exception); default admin editor is TinyMCE (`editor/index.php` config `'editor' => 'html'`)
  - **No PHP changes.** No changes to `editor-tinymce.js` / `link.js` / `image.js` / `video.js` (custom plugins use stable 5.x APIs: `PluginManager.add`, `editor.ui.registry.addToggleButton` — API-compatible per `DEPENDENCY-AUDIT-2026-04.md` §4.1). Contingency only: minimal CSS fix inside `tinymce_skin/` if 5.10.9 breaks toolbar rendering (see Checklist 1f)
- **Deferred:**
  - Step 3.2.1 (Template Pre-compilation / CSP) — CSP `frame-src`/`object-src` mitigation for the one TinyMCE advisory without a v5 fix (iframe XSS; patched upstream only in TinyMCE ≥ 6.8.1). `PHASE_3_MODERNISING.md` §3.2.1 amended.
  - Step 2.4 (Build Tools) — webpack-4-locked transitive advisories (picomatch, braces, micromatch, serialize-javascript, elliptic) disappear with the pnpm+Vite switch; broader JS audit cleanup belongs there. `PHASE_2_MODERNISING.md` §2.4 amended.
  - Non-goals (no ROADMAP routing): TinyMCE 6+/7+ major upgrade (licensing, config keys, Vue track — editor decision point at Step 5.1 Modern Block Editor); UIkit bump (Step 3.1); Webpack 5 itself (Step 2.4).
- **Bridges:** None — pure dependency version bump; no code changes, no compatibility layers, no Rule 5 tags (no production code site changes hands).
- **Checklist:**
  1. **Bump TinyMCE `~5.5.1` → `~5.10.9` with security evidence capture (package.json + yarn.lock; rebuild + asset refresh).** Ordered:
     (a) Baseline evidence **before any edit**: `yarn audit --json > /tmp/yarn-audit-before.json || true` (non-zero exit is expected while advisories exist) and `node -e "console.log(require('./node_modules/tinymce/package.json').version)"` (expect `5.5.1`). If the registry audit endpoint is unreachable, continue — the lockfile diff plus `migration-docs/audits/2026/04/DEPENDENCY-AUDIT-2026-04.md` §4.1 (13 tinymce advisories at 5.5.1) serve as baseline.
     (b) In `package.json`, change only `"tinymce": "~5.5.1"` → `"tinymce": "~5.10.9"`.
     (c) Run `yarn install` (regenerates the lockfile; the `install` script rebuilds production bundles and gulp copies the refreshed `node_modules/tinymce/**` into `app/system/modules/editor/app/assets/tinymce/`).
     (d) Confirm on disk: `node -e "console.log(require('./node_modules/tinymce/package.json').version)"` → `5.10.9` (or later 5.10.x); `md5sum node_modules/tinymce/tinymce.min.js app/system/modules/editor/app/assets/tinymce/tinymce.min.js` → identical hashes; `git diff yarn.lock` touches only the tinymce entry (GitHub-pinned forks `Codemirror`/`JSONStorage`/`vue-intl` untouched); `git status` shows only `package.json` + `yarn.lock` (assets/bundles are gitignored).
     (e) After-evidence: `yarn audit --json > /tmp/yarn-audit-after.json || true`; leave both `/tmp/yarn-audit-*.json` files in place for the Tester.
     (f) Do **not** touch `editor-tinymce.js`, `link.js`, `image.js`, `video.js`, `tinymce_skin/`, or any other dependency. Contingency, only on a Verifier/Tester/E2E FAIL showing broken toolbar rendering under 5.10.9: minimal CSS fix inside the committed `app/system/modules/editor/app/assets/tinymce_skin/` — nothing else.

## EXECUTION STATE

<!-- Machine-readable progress index for the Orchestrator/Conductor. Mirrors the Checklist 1:1
     (same numbers + short titles). Size hint per step: S = small/atomic, M = medium,
     L = large or loop-risk. A step orchestrator flips its box to [x] in the SAME commit as that step's code + tests (after full step PASS incl. test-writer when applicable) -->

- [x] Step 1 (M) — TinyMCE `~5.5.1` → `~5.10.9` bump (package.json + yarn.lock) + rebuild + evidence

## TESTING STRATEGY

- **Per step (production gate):** Refactorer → Verifier → Tester (PHPUnit + PHPStan) — production code must be green before any new tests are written
- **Per step (coverage — inline light):** test-writer → Verifier (test files only) → Tester (PHPUnit + PHPStan) — **skip** when the step changes no production PHP under `app/` or `packages/` (docs/config/ROADMAP-only steps); mark those steps `test-writer: skip` here
- **Per step notes:**
  - **Step 1:** `test-writer: skip` — JS dependency bump only, no production PHP changes. Tester runs, in order:
    1. `yarn compile-js --mode=production` — build gate, must succeed.
    2. `./app/vendor/bin/phpunit` — green (this step touches no PHP; a failure is environmental or pre-existing — investigate before attributing it to the bump).
    3. `./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M` — green against baseline.
    4. Version/asset evidence: `node -e "console.log(require('./node_modules/tinymce/package.json').version)"` → 5.10.x; `md5sum node_modules/tinymce/tinymce.min.js app/system/modules/editor/app/assets/tinymce/tinymce.min.js` → identical.
    5. Audit evidence: `yarn audit --json > /tmp/yarn-audit-after.json || true`, then compare tinymce advisory counts (e.g. `grep -c '"module_name":"tinymce"' /tmp/yarn-audit-before.json /tmp/yarn-audit-after.json`); expected drop 13 → ≤ 1 (residual = iframe XSS, fixed upstream only in ≥ 6.8.1). `yarn audit`'s non-zero exit code is expected (webpack-locked transitives remain) and is NOT a FAIL; include both counts in the PASS line so the doc-writer can record them in the branch doc / PR.
- **E2E (Execute — last checklist step only):** when ticking Step 1 completes every `## EXECUTION STATE` box, the Orchestrator delegates `"final E2E run"` to Tester (3 Playwright specs, local PASS/FAIL). **Not** gated on PR/CI — see `.cursor/agents/tester.md` § End-of-ticket E2E. Additionally run `npx playwright test tests/e2e/specs/03-content/pages.spec.js` (admin page create — the TinyMCE editor surface). Editor smoke focus at Final Test: admin → Pages → Add Page → TinyMCE toolbar (`.tox-tinymce`) renders with the custom skin; type content; link/image/video plugin buttons open their pickers; save; reload retains content. Where GUI-driven verification is available at Final Test, capture it; otherwise the pages spec + disk evidence stand.
- **Finalize:** Orchestrator opens PR → waits on PHP Quality CI (`gh run watch`) → Bugbot → version/CHANGELOG/ROADMAP. GitHub Issue: #230 (`Closes #230` in PR metadata). Branch doc notes the before/after tinymce advisory counts.
