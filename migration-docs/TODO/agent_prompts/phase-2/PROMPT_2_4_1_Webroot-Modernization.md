# Step 2.4.1: Webroot Modernization — Adopt `public/`

<!-- conductor-mode: full -->

**ROADMAP:** 2.4.1. GitHub Issue: #243. Reference: `@ROADMAP.md`, `PHASE_2_MODERNISING.md` §2.4.1.

---

## CONTEXT

- **Land after:** 2.4 (Vite/pnpm asset build — this step targets the final build-output paths directly).
- **Risk:** Medium — touches the front controller and every static-asset path; application logic itself does not change.
- **Goal:** Move the web-servable surface to a dedicated `public/` directory so that `app/`, `config.php`, `tmp/`, `vendor/`, and the non-public parts of `storage/` become structurally unreachable over HTTP.
- **Why:** Pattern-matching file-denial rules in `.htaccess` are a maintenance burden and fail silently if a rule is missed; a physical `public/` split removes that class of bug entirely — anything not inside `public/` cannot be served, regardless of server configuration.

### Current state (verified 2026-07-26 — confirm in Discovery, then build; do not rediscover blindly)

- **Front controller:** root `index.php` resolves `$path = __DIR__` and builds every config path (`path.packages`, `path.storage`, `path.temp`, `path.cache`, `path.logs`, `path.vendor`, `path.artifact`, `config.file`) from that single variable, then delegates to `app/$env/app.php` (`$env` is `system`, `installer`, or `console`). Under the PHP built-in dev server it also short-circuits static-file requests directly.
- **`.htaccess`** (repository root, ~205 lines): file-extension/name denials (`<FilesMatch>` for `.lock`, `.cache`, `.db`, `composer.json`, etc.), security headers (HSTS, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy, a Content-Security-Policy with a documented `unsafe-eval` need for the Vue runtime compiler, Cross-Origin-Opener/Resource-Policy), the front-controller rewrite (`RewriteRule ^ index.php [L]` when the request does not match a real file/directory), www/HTTPS redirects, gzip/deflate compression, and cache-control `Expires` rules.
- **Build outputs today:** JS/CSS bundles land at `<module>/app/bundle/<name>.js` scattered across `app/modules/**`, `app/system/modules/theme/**`, and `packages/**`; vendor asset copies (UIkit, Vue, TinyMCE, …) land in `app/assets/` and `app/system/modules/editor/app/assets/`; theme LESS compiles to `css/` next to its source. All of these are servable today because Apache serves any file that physically exists before falling back to the front-controller rewrite.
- **`storage/`:** holds user uploads (media library). No symlink or public/private split exists today — the whole tree is reachable wherever Apache can find a matching file.
- **Installer:** `app/installer/` runs the first-boot / upgrade flow; its path resolution currently assumes the front controller and the application tree share one root.

---

## PRINCIPLES (hold across every checklist step)

- **Structural over pattern-matching** — the goal is that sensitive paths cannot physically be requested, not that they are correctly denied by a rule that could be forgotten or misconfigured.
- **One front controller** — `public/index.php` delegates to the existing boot chain unchanged; no parallel bootstrap logic.
- **No half-migrated asset categories** — every category currently served directly (JS/CSS bundles, vendor asset copies, theme CSS, uploads) gets an explicit, working destination inside or reachable from `public/`. None are left on the old path.
- **Hosting-agnostic** — both a document-root change and a root-level rewrite fallback must work, so the change does not narrow which hosting environments can run the application.
- **Rule 3** — tree stays green after every checklist step; PHPUnit, PHPStan, and Playwright E2E must pass throughout.

---

## 0. SAFETY CHECKS

Before starting:

1. Branch up-to-date with `develop`.
2. Confirm **2.4 has landed** (or this branch includes the final Vite/pnpm build-output paths) — this step's asset moves target those paths directly. If 2.4 is not merged, STOP and sequence correctly.
3. Baseline green:

```bash
php -v && node -v
composer install --no-interaction --prefer-dist --no-progress
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
pnpm install --frozen-lockfile && pnpm build
```

**IF ANY FAILS → STOP AND FIX** (or report the pre-existing failure) before touching the webroot.

---

## 1. DISCOVERY

```bash
rg -n 'path\.|__DIR__|realpath' index.php
rg -n 'DocumentRoot|RewriteRule|FilesMatch|Content-Security-Policy|Header' .htaccess
rg -n 'app/bundle|app/assets' --glob '*.config.js' --glob '*.js' -g '!node_modules' -g '!**/bundle/**'
rg -n '__DIR__|path\.storage|path\.vendor' app/installer/ --glob '*.php'
ls app/assets/ storage/ 2>/dev/null
```

- Enumerate every static-asset category the Step 2.4 build produces and its exact output path.
- Confirm how `config.php` and every `path.*` value are resolved relative to the front controller, so `public/index.php` can adjust the single reference point correctly.
- Decide the exact `storage/` public/private split (which sub-paths are user-facing media vs. internal state) before choosing what the symlink covers.
- Confirm which `.htaccess` directives are Apache-specific vs. pure HTTP semantics (headers) that any future webserver would need identically.

---

## 2. WORK

Architect: decompose into ordered, individually-green checklist steps. Suggested order: 2.1 (front controller + path resolution) → 2.2 (asset output paths) → 2.3 (`.htaccess` split + uploads) → 2.4 (installer/self-updater audit) → 2.5 (docs).

### 2.1 Front controller + path resolution

- Create `public/index.php`: resolve the application root one level up, keep the rest of the boot logic (`$env` detection, `path.*` config, `require` into `app/$env/app.php`) unchanged.
- Remove the old root `index.php` once `public/index.php` is confirmed working end-to-end.

### 2.2 Asset output paths

- Point the build configuration's output targets at `public/` (mirroring the existing per-module bundle names and theme CSS paths, just relocated).
- Verify every PHP view/template asset reference resolves correctly against the new location.

### 2.3 `.htaccess` split + uploads

- Move the front-controller rewrite and security headers into `public/.htaccess`.
- Add a minimal root `.htaccess`: `RewriteRule ^(.*)$ public/$1 [L,QSA]`, so hosts that cannot repoint their document root still work.
- Remove `<FilesMatch>` deny rules for files that no longer exist inside `public/`; keep any that still apply.
- Implement the `storage/` public-subset symlink; verify uploads still render and nothing else in `storage/` is reachable.

### 2.4 Installer & self-updater audit

- Update any path assumption in `app/installer/` tied to the previous single-root layout.
- Verify a fresh install and an upgrade path both complete successfully against the new layout.

### 2.5 Docs

- `README.md` "Manual Installation": add a shared-hosting subsection covering both the document-root change and the fallback `.htaccess`.

---

## MANUAL WORK (record in the branch doc — agents do NOT perform these)

1. **Fresh install + upgrade on a real webserver**: confirm both paths boot correctly end-to-end (no Docker daemon in the agent VM).
2. **HTTP denial proof**: confirm `app/`, `config.php`, `tmp/` return 404/connection-refused, not file contents, over HTTP.
3. **Shared-hosting fallback verification**: confirm the root-`.htaccess` rewrite actually works on a host where the document root cannot be changed (or via a local Apache config that simulates it).

---

## 3. OUT OF SCOPE

- **Webserver/runtime engine** (Apache vs. nginx + PHP-FPM vs. FrankenPHP) — this step keeps Apache; the engine choice is a separate concern.
- **Docker production image work** — building and hardening the container image itself.
- **CSP delivery mechanism** (moving CSP from `.htaccess` to a PHP-level response listener) — a separate, focused change.

---

## 4. TESTING

- **Per checklist step (Conductor Tester):** PHPUnit + PHPStan PASS/FAIL. The front-controller and installer changes touch production PHP — cover with tests where meaningful; mark pure asset-path/docs steps `test-writer: skip` in `## TESTING STRATEGY`.
- **Playwright E2E**: admin login + a page render, to confirm assets resolve correctly from the new location.

---

## SUCCESS CRITERIA

- `public/index.php` is the sole front controller; the application boots and behaves identically to before.
- Every static-asset category (JS/CSS bundles, vendor asset copies, theme CSS) resolves correctly from `public/`.
- `app/`, `config.php`, `tmp/`, and non-public `storage/` paths are not reachable over HTTP.
- The uploads symlink correctly exposes only the intended public subset of `storage/`.
- A shared-hosting document-root change and the root-`.htaccess` fallback both work.
- README documents the shared-hosting installation path.
- PHPUnit + PHPStan + Playwright E2E all green.

---

## NOTES FOR THE ARCHITECT

- Sequence the front controller and path-resolution change first — every other checklist step depends on it working correctly.
- Keep the change mechanical: no application logic changes beyond path resolution.
- One ticket / one PR; Conventional Commits; version bump once at Finalize — never inside checklist steps.
