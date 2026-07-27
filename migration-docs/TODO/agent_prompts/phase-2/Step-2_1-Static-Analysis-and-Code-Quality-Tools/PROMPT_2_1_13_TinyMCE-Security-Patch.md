# Step 2.1.13: TinyMCE Security Patch (~5.10.9)

<!-- conductor-mode: full -->

**ROADMAP:** 2.1.13. GitHub Issue: #230. Reference: `@ROADMAP.md`, `PHASE_2_MODERNISING.md` §2.1.13, `migration-docs/audits/2026/04/DEPENDENCY-AUDIT-2026-04.md` §4.1.

---

## CONTEXT

- **Depends on:** Current `develop` (2.1.12 merged).
- **Risk:** Low — same-major TinyMCE 5.x bump; plugin API unchanged per audit.
- **Current:** `package.json` pins `"tinymce": "~5.5.1"` (EOL since 2023-04; 13 known XSS/mXSS CVEs).
- **Target:** `"tinymce": "~5.10.9"` — fixes **12 of 13** CVEs; remaining iframe XSS needs TinyMCE ≥6.8.1 (out of scope; CSP / later editor track).

**Goal:** Minimal same-major security bump of TinyMCE in the admin editor — not a full editor modernization.

---

## 0. SAFETY CHECKS (CRITICAL)

**Before starting:**

1. Branch up-to-date with `develop`.
2. Capture baseline audit (for before/after evidence):
   ```bash
   yarn audit --json > /tmp/yarn-audit-before.json || true
   node -e "console.log(require('./node_modules/tinymce/package.json').version)"
   ```

**After the bump:**

```bash
yarn install
yarn compile-js --mode=production
# gulp copies node_modules/tinymce → app/system/modules/editor/app/assets/tinymce/ (via postinstall / gulp assets)
./app/vendor/bin/phpunit
```

**IF PHPUnit fails for unrelated reasons → investigate; this step should not touch PHP.**

---

## 1. DISCOVERY

```bash
rg -n '"tinymce"' package.json yarn.lock
ls app/system/modules/editor/app/assets/tinymce/ | head
rg -n "tox-tinymce|tinymce\.min\.js|skin_url" app/system/modules/editor/
```

Confirm:

- Runtime loads `${root}/app/assets/tinymce/tinymce.min.js` via `editor-tinymce.js`.
- Custom skin: `skin_url` → `app/assets/tinymce_skin` (project skin, not necessarily from npm) — leave alone unless 5.10.9 breaks toolbar CSS (then fix minimally).

---

## 2. CHANGE

1. In `package.json`: `"tinymce": "~5.5.1"` → `"tinymce": "~5.10.9"`.
2. `yarn install` (updates lockfile; postinstall runs compile + gulp asset copy).
3. Verify installed version is **5.10.x**:
   ```bash
   node -e "console.log(require('./node_modules/tinymce/package.json').version)"
   # and that assets were refreshed:
   rg -l . app/system/modules/editor/app/assets/tinymce/tinymce.min.js | head -1
   ```
4. Re-run `yarn audit` (or scoped) and note tinymce advisory count drop in the branch doc / PR.

**Do not** change editor Vue plugins (`link.js` / `image.js` / `video.js`) unless the bump breaks them — API-compatible per audit.

---

## 3. OUT OF SCOPE

- TinyMCE 6+ / 7+ (licensing, config, Vue track — later).
- Remaining iframe XSS CVE (needs ≥6.8.1) — defer; CSP / later editor work.
- Webpack 5 / pnpm / Vite (Step 2.4).
- Broader `yarn audit` cleanup of webpack-locked transitive CVEs.

---

## 4. TESTING

**Automated:**

- `yarn compile-js --mode=production` succeeds.
- `./app/vendor/bin/phpunit` green (no PHP changes expected).
- Optional: Playwright smoke specs (installation / authentication / dashboard) if admin boot path is cheap.

**Manual / E2E focus (Final Test):**

- Admin → open content editor (TinyMCE).
- Toolbar visible; create/edit a page or blog post; save; reload and confirm content.
- Image / link / video plugins still insert.

---

## SUCCESS CRITERIA

- `package.json` + lockfile on `tinymce` `~5.10.9` (resolved 5.10.x)
- Editor assets under `app/system/modules/editor/app/assets/tinymce/` refreshed from that version
- Admin editor smoke OK; PHPUnit green
- Branch doc + ROADMAP row updated on Finalize; no TinyMCE 6+ scope creep
