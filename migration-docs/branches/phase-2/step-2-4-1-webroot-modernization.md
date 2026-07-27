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

### PHP URL/path resolution → public-aware (Checklist Step 3)

| File | Change |
|---|---|
| `app/modules/filesystem/src/Path.php` | New `Path::directory()` helper normalizes a path to forward slashes plus a single trailing slash, so every prefix comparison introduced by this step is segment-boundary-safe (`<root>/public/…` matches; `<root>/publicfoo` does not). |
| `app/modules/filesystem/src/Adapter/FileAdapter.php` | Single `$path`/`$url` root replaced by an ordered `$mounts` list (primary = constructor's `$path`/`$url`, plus an optional `$mounts` map); `getPathInfo()` now matches a file's directory against each mount in turn and only sets `url` on a hit — a file under no mount gets no URL, whatever code calls `getUrl()`/`getStatic()` on it. |
| `app/modules/filesystem/src/Locator.php` | Constructor takes the new `path.public` webroot; `add()` registers a path's published mirror (`published()`, when one exists under `path.public`) ahead of the path itself, so multi-path prefixes hit the webroot copy first and fall back to the module source only when nothing was published (views, translations); `get()`'s bare-root fallback tries `path.public` before the application root, so unprefixed refs (`app/assets/…`) resolve to the published copy. |
| `app/modules/filesystem/index.php` | `locator` service now receives `path.public`; the `request` listener builds `FileAdapter` with `path.public` as the primary mount plus a `path.storage` mount (URL = storage's path relative to the app root, so a relocated `system/finder.storage` still gets a working mount), replacing the old single-root construction. |
| `index.php` | Root config gains `'path.public' => $path.'/public'` — the value `filesystem/index.php`'s wiring above reads, and what Checklist Step 4's `public/index.php` will carry forward. |
| `app/system/modules/editor/index.php` | `root_url` resolves through the locator (`getStatic('system/editor:')`) instead of `getStatic(__DIR__)`, so it addresses the module's published mirror; the module's own source directory has no URL under the new mounts. |
| `app/installer/index.php` | `PackageFactory` construction gains the application root (`$app->get('path')`), needed to translate a package's source path into its published-webroot path. |
| `app/installer/src/Package/PackageFactory.php` | Package `url` is now built from the package path relative to the application root (`served()`) rather than the raw source path, so it addresses the webroot copy; a package with no published copy resolves to a source-only path outside every mount, so `url` stays empty instead of pointing at an inaccessible file. |

Tests: `test-writer` runs — new `FileAdapterTest` (mount-matching, boundary-safety, unmounted/missing-file cases) and `PackageFactoryTest` (published vs. unpublished package URL, no-`UrlProvider` construction); `LocatorTest` gains the public-overlay/dual-path resolution cases; `PathTest` gains the segment-boundary-safety case; `FileUtil` gains a `writeFile()` fixture helper shared by the new tests. Gates: Verifier — production PASS; tests FAIL once (`PathTest`'s boundary-safety claim was asserted without proving it) then PASS after retry. Tester — PHPUnit + PHPStan PASS (production); FAIL twice on the `PathTest` addition (non-empty-string typing) then PASS after retries.

### Front controller flip → `public/` becomes the docroot (Checklist Step 4)

| File | Change |
|---|---|
| `public/index.php` (new) | Sole front controller. Byte-equivalent to the deleted root `index.php` except: `$path = dirname(__DIR__)` (was `__DIR__`); the config map gains `'path.public' => __DIR__`; the cli-server static-file short-circuit and the debug-log path resolve from the new `$path`/`__DIR__` (decision 7). |
| `index.php` (deleted) | Root front controller removed outright — no parallel or proxy copy kept at the old location. |
| `pagekit` | Bin's `require_once` repointed from `__DIR__.'/index.php'` to `__DIR__.'/public/index.php'`. |
| `app/console/src/Commands/StartCommand.php` | Dev server now execs `php -S $server -t public public/index.php` (was `index.php` from the approot); the printed "Document root is …" line reports `getcwd().'/public'`. |
| `phpunit.xml.dist`, `phpunit-mysql.xml.dist` | Coverage `<source><exclude>` entry for the front controller tracks the move: `index.php` → `public/index.php`. |
| `codecov.yml` | Redundant standalone `index.php` ignore line removed — the pre-existing `**/index.php` glob already covers the relocated file. |

Tests: none (test-writer: skip — front controller + exec-string command has no testable seam; behavior is covered by the curl smoke + E2E per the ticket's testing strategy). Gates: Verifier — production PASS. Tester — PHPUnit PASS, PHPStan PASS.

### `.htaccess` split + Docker dev vhost (Checklist Step 5)

| File | Change |
|---|---|
| `public/.htaccess` (new) | Carries the security headers (HSTS, X-Content-Type-Options, X-XSS-Protection, X-Frame-Options, Referrer-Policy, Permissions-Policy, CSP, COOP/CORP), `X-Powered-By` unset, cookie-edit, www/HTTPS redirects, front-controller rewrite, `SetEnv HTTP_MOD_REWRITE` + header fallback, `RedirectMatch` admin fallback, mime/SVG, deflate and expires rules verbatim from the old root `.htaccess`; adds the storage PHP-execution deny (`RewriteRule ^storage/.*\.php$ - [F,NC]`, decision 6) ahead of the front-controller rewrite; keeps only `Options -Indexes` plus a self-referencing `.htaccess` deny — the old `<FilesMatch>` denials for `.lock/.cache/.db/composer.json/package.json/pnpm-*/CHANGELOG/README/pagekit` and the `.htaccess|.htpasswd|.ini|.log|.sh|.inc|.bak`/backup-file classes are not carried over. |
| `.htaccess` | Cut from the full rule set (205 lines) to the shared-hosting fallback only: `RewriteEngine On` + unconditional `RewriteRule ^(.*)$ public/$1 [L,QSA]` — every request, including for files that used to live at the root (`config.php`, `app/`), is forwarded into `public/` and 404s there once `public/.htaccess` takes over. |
| `Dockerfile` | Dev vhost `DocumentRoot`/`<Directory>` repointed from `/var/www/html` to `/var/www/html/public`; the `<Directory>` block gains `+FollowSymLinks`, needed for Apache to traverse the `public/storage` symlink (decision 6). |

Tests: none (test-writer: skip — Apache config only). Gates: Verifier PASS. Tester — PHPUnit PASS, PHPStan PASS.

### Installer & self-updater webroot audit (Checklist Step 6)

| File | Change |
|---|---|
| `app/installer/src/StorageLink.php` (new) | Puts the `public/storage → ../storage` link in place for installs unpacked from an archive — a checkout gets that link from the Node build (Checklist Step 2), an unzipped install has none. `link()` derives the link's location and relative target from `path`/`path.public`/`path.storage`, returning `null` when storage does not sit under the application root (nothing to link into — covers an admin-configured `system/finder.storage` pointing elsewhere); `exists()` counts a link pointing nowhere as present, since it already occupies the name; `ensure()` creates the link unless one is already there and returns `false` — never throws — when `symlink()` is disabled or the target directory can't be made; `getProblem()` renders the by-hand `ln -s` command (or a webserver-alias note when storage lies outside the root) for whichever case `ensure()` left unresolved. |
| `app/installer/src/Installer.php` | `$configFile` now resolves to `$app->get('path').'/config.php'` instead of the bare `'config.php'` literal — the old relative path depended on the PHP process's working directory, which the docroot flip (Checklist Step 4) made unsafe once a real webserver's docroot is `public/`; the web install flow calls a new `linkStorage()` after a successful install, logging `StorageLink::getProblem()` as a warning instead of failing the install when the link cannot be created. |
| `app/console/src/Commands/SetupCommand.php` | After a successful CLI setup, checks the same `StorageLink` and prints `getProblem()` via `$this->comment()` when the link is still missing — `Installer::linkStorage()`'s warning goes to the log, which a CLI install would otherwise never surface. |
| `app/installer/src/SelfUpdater.php` | New `$keepFile = ['.htaccess', 'public/.htaccess']` replaces the single `unset($fileList[array_search('.htaccess', ...)])` call, so an update preserves both `.htaccess` files from Checklist Step 5 instead of only the root one; `$cleanFolder` (still `['app']`) gets a `// TODO: Must be refactored in Step 2.9` marker — it does not yet reach `public/`, so an update still can't recreate a dropped `public/storage` link or prune stale published assets there. |
| `app/console/src/Commands/ArchiveCommand.php` | `// TODO: Must be refactored in Step 2.8` marks the `PharArchiver` call — it archives only the package's source directory, so a package's built bundles and published assets under `public/` do not make it into the archive; audit-only, no functional change. |

Tests: `test-writer` runs — new `StorageLinkTest` (link creation; survives a moved installation via the relative target; idempotent re-`ensure()`; an existing directory or a link already pointing nowhere is left in place rather than replaced; storage nested inside vs. outside the application root, including a sibling directory that merely shares the root's name as a prefix; trailing-slash-insensitive paths; the by-hand `ln -s` command a blocked target yields) and `InstallerStorageLinkTest` (`Installer::linkStorage()` through an anonymous subclass exposing the protected method — a successful link logs nothing, an unlinkable storage path logs the warning and leaves `public/` empty). Gates: Verifier — production PASS; tests PASS. Tester — PHPUnit + PHPStan PASS (production and after tests).

### Docs sweep — README + AGENTS.md (Checklist Step 7)

| File | Change |
|---|---|
| `README.md` | Manual Installation renarrated for the `public/` docroot: step 3 (`pnpm build`) gains a note that the build fills `public/` and a fresh checkout has nothing to serve until it runs; step 4 ("Set up web server") replaces the old generic Apache/Nginx/permissions bullets with `DocumentRoot`/`root` + `try_files` snippets, a shared-hosting subsection (root `.htaccess`'s unconditional rewrite as the fallback when a fixed docroot can't be moved), and a media-library-link subsection (manual `ln -s ../storage public/storage`, plus the rule for relinking a custom `system/finder.storage` path); new step 5 ("Install Pagekit") documents the web installer, the CLI `setup` command, and `php pagekit start` as the built-in-server dev command wrapping `-t public public/index.php`. Development section's `pnpm build`/`build:assets` one-liners reworded to name `public/` and the storage link. |
| `AGENTS.md` | Services table: PHP dev server command → `php -S localhost:8080 -t public public/index.php`; frontend-build note calls out output landing in `public/`. Router-file caveat rewritten to cover the docroot as a whole (front controller, `.htaccess` and the storage symlink live in `public/`; everything else there is gitignored build output; sources/`config.php`/`tmp/` stay outside it) rather than just the router-file requirement. Writable-dirs caveat gains the storage-symlink note (recreate with `ln -s ../storage public/storage` if uploads 404). `php pagekit start` caveat's wrapped command updated to `-t public public/index.php`, correcting the documented bind address to the command's actual `127.0.0.1:8080` default (was stated as `0.0.0.0:8080`). |

`tests/e2e/README.md` needed no change — it already names `php pagekit start`, not a root-`index.php` invocation.

Tests: none (test-writer: skip — docs only). Gates: Verifier PASS. Tester — PHPUnit PASS, PHPStan PASS.

---

## 🧠 Key Decisions (Rationale)

- **Locator's publish-mirror-first resolution lives in `add()`/`get()`, not at each call site (Checklist Step 3).** Any path registered via `add()` that sits under the application root gets its published-webroot mirror (`published()`) prepended ahead of the source path automatically; the module-resource loader in `filesystem/index.php` needed no change and still calls `add($prefix, $modulePath)` with the single module path it always has. The bare-root overlay is realized the same way, as `get()`'s public-then-source fallback order, rather than a separate `add('', path.public)` registration.

---

## 💥 Breaking Changes (Extensions)

_TBD / None_

---

## ⚠️ Risks & Rollout Notes

- **Vue baseline inventory over-counts vs. the fresh `public/` copy (Checklist Step 2 — flagged for Step 8 parity).** The Step 1 baseline captured `app/assets/vue/` (418 entries) before this step moved the copy destination to `public/app/assets/vue/`; the old, git-ignored destination had accumulated leftover files from earlier builds that `scripts/assets.mjs` never pruned (overwrites/adds in place, never deletes). The fresh `public/app/assets/vue/` copy (223 entries) matches the installed `vue@2.7.16` package exactly, including its `.ts` compiler sources. Step 8's parity check must treat the ~195-entry shrinkage as pre-existing baseline staleness, not a publication regression.
- **`app/modules/debug/assets/vendor/highlight/` is not published (Checklist Step 2 — flagged for Step 8 parity).** Decision 3d names `app/modules/debug/assets/**` as a tree that must publish in full, but `scripts/publish.mjs`'s `PRIVATE_DIRS` exclusion treats any directory literally named `vendor` as a never-served PHP/Composer source tree, so this module's own vendored front-end library (a highlight.js copy, currently unreferenced by any `$view->script()`/`style()` call site) is skipped too. Needs a rule adjustment before Step 8's parity check can close cleanly.
- **The app is not manually browsable end-to-end yet (Checklist Step 3 — planned, resolves at Step 4).** URLs now resolve through `path.public`/the storage mount, but the front controller (`index.php`) still runs from the application root and `public/` is not yet the docroot, so a live request cannot reach any of the newly-mounted paths. PHPUnit and PHPStan stay green throughout since neither browses; manual/E2E verification resumes once Checklist Step 4 flips the docroot. **Closed at Checklist Step 4** — `public/index.php` is now the sole front controller and `StartCommand` serves via `-t public public/index.php`; the docroot flip this note was waiting on has landed.

---

## 🔐 Security & Data Impact

- **File-to-URL resolution is now allow-listed by mount, not approot-wide (Checklist Step 3).** `FileAdapter` previously mapped every path under the application root to a URL; it now only does so for a path under an explicit mount (`path.public`, `path.storage`) — `config.php`, `app/system/config.php`, `composer.json`, and everything else outside both mounts get no `url` regardless of what calls `getUrl()`/`getStatic()` on them, and mount matching is segment-boundary-safe (a sibling directory merely sharing a mount's name as a prefix does not match). Covered by `FileAdapterTest`.
- **Mount allow-listing now gates live HTTP requests, not just code-level `getUrl()`/`getStatic()` calls (Checklist Step 4).** Before this step the front controller still ran from the application root, so no live request could reach the app at all; with `public/` as the docroot, everything outside `path.public`/`path.storage` — `config.php`, `app/system/config.php`, `composer.json`, `.git`, `tmp/` — sits outside the webroot entirely rather than merely outside a URL allow-list.
- **Storage media library gets a defense-in-depth PHP-execution deny (Checklist Step 5).** `public/.htaccess` adds `RewriteRule ^storage/.*\.php$ - [F,NC]` ahead of the front-controller rewrite — `storage/` is the only admin-writable served path (finder uploads are already extension-allowlisted), so a bypass of that allowlist still cannot get a `.php` file executed there.
- **`config.php` no longer resolves relative to the running process's working directory (Checklist Step 6).** `Installer::$configFile` was the bare literal `'config.php'`; with `public/` as the docroot (Checklist Step 4), a webserver that runs PHP from the script's own directory would read and write the credentials file at `public/config.php` — inside the webroot — instead of the application root. It now resolves as `$app->get('path').'/config.php'`, independent of hosting mode.

---

## 🛡️ No-Mercy Compliance

- **Rule 4 (Delete over wrap) — Checklist Step 2.** `packages/pagekit/theme-one/css/theme.css`'s tracked compiled copy is untracked outright rather than kept as a committed fallback beside the new `public/`-only build output (decision 3c); the uikit-relative LESS variables (`@uikit-path`/`@image-path` and their `@internal-*-image` consumers) are deleted rather than kept as a dead alias once the imports resolve straight from `node_modules` (decision 4).
- **Rule 4 (Delete over wrap) — Checklist Step 3.** `FileAdapter`'s single-root `$path`/`$url` mapping and `Locator`'s single-path-per-prefix registration are replaced in place by the mounts list / publish-mirror-first resolution — there is no legacy single-mount code path kept alongside the new one; a one-mount adapter is simply the one-element case of the same mechanism.
- **Rule 4 (Delete over wrap) — Checklist Step 4.** Root `index.php` is deleted outright, not kept as a compatibility stub/redirect to `public/index.php`; the `pagekit` bin and `StartCommand`'s exec string are repointed in place rather than supporting both entry points.
- **Rule 4 (Delete over wrap) — Checklist Step 5.** Root `.htaccess`'s `<FilesMatch>` denials for file classes that no longer exist inside the webroot (`.lock/.cache/.db/composer.json/package.json/pnpm-*/CHANGELOG/README/pagekit`, the `.htaccess|.htpasswd|.ini|.log|.sh|.inc|.bak` class, backup-file suffixes) are dropped outright rather than carried into `public/.htaccess` as dead defensive rules.
- **Rule 5 (Mandatory flagging) — Checklist Step 6.** `SelfUpdater`'s `$cleanFolder` and `ArchiveCommand`'s package archiving are left as-is functionally but tagged `// TODO: Must be refactored in Step 2.9 (Automated Update System)` / `// TODO: Must be refactored in Step 2.8 (Extension Packaging & Prebuilt Assets)` — forward debt for the `public/` gap this step's audit found but does not close.

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
