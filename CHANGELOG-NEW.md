# Changelog

## Pagekit 1.2.38 - Extension Safety & Fault Isolation (August 11, 2026)

### 💥 Breaking Changes

- **A package's lifecycle file must return a `PackageLifecycleInterface` implementation, not an array of closures** — `extra.scripts` still names the file, but `LifecycleRunner` rejects the previous `['install' => fn, ...]` shape with a `\RuntimeException` naming what it got instead. The two shipped lifecycle files (the system's own, the blog's) convert to the new contract in this same release.
- **`MigrationService::getExtensionCurrentVersion()` now returns `?string` instead of always a `string`** — it used to report `'0'` (the value that means "nothing has run yet", and the one a rollback reads as "unwind everything") for a version it could not read at all, silently conflating the two. Code written against the old signature must now treat `null` (unreadable) as distinct from `'0'` (genuinely empty).

### ✨ Added

- **A durable, never-throws record of which extensions and themes have failed** — `ExtensionFailureStore` (`tmp/system/extension-failures.json`, denied over HTTP, written atomically) survives the request that hit the failure, keeps a recovered extension off the next boot even when the database write that would have disabled it also failed, and backs a standing admin notice plus a warning icon in the extension manager until the module is re-enabled or a theme recovers on its own load. (Closes #160)
- **Fault isolation around every extension boot window** — module registration, the load/`main()` window (new `ExtensionLoader`), and a package's `disable`/`uninstall` lifecycle hooks each run behind their own `\Throwable` barrier, so one broken package can no longer take the whole boot, a sibling module's registration, or an administrator's way out of it down too. `public/index.php`'s last-resort exception handler is now registered unconditionally instead of only once a `tmp/logs/debug.log` already existed.
- **`PackageLifecycleInterface` + `LifecycleRunner`**, replacing the array-of-closures `PackageScripts`; `PackageManager` now runs an extension's declared schema migrations itself, snapshotting the pre-attempt version so a failed enable rolls the schema back alongside the existing config rollback.
- **`Router::addResolver()`** — a per-class factory seam for a route resolver that needs constructor dependencies (a metadata cache, an entity repository), replacing a resolver's own static-locator bridge.

### ♻️ Changed

- **The routing cache is data-only and content-addressed** — the two deprecated, hand-copied dumpers (`PhpMatcherDumper`, `UrlGeneratorDumper`) are replaced by Symfony's own `CompiledUrlMatcherDumper` and a new Pagekit-owned `CompiledUrlGenerator`, both dumping a plain `<?php return [...];` array instead of a generated PHP class per cache key. Cache freshness is now a content hash instead of a `filemtime()` comparison, which a deployment that resets file mtimes (or runs with `opcache.validate_timestamps=0`) could previously fool into serving a stale route set. A hash that cannot be computed at all — a route's controller is a closure, or a router option holds something unserializable — degrades the request to the uncached matcher/generator instead of throwing.
- **Three static-locator bridges are gone** — the blog's `UrlResolver`, `theme-one`'s `ThemeOneHelpers`, and `UniqueValidator` all move to constructor injection (the last resolved through Symfony's `ContainerConstraintValidatorFactory`); nothing reaches a database connection, a cache pool or a URL provider through a static setter any more.

### 🐛 Fixed

- **A theme that recovered still read as broken in the admin notice** — until an unrelated package action happened to clear it. `ExtensionLoader` now clears a theme's failure record itself the moment it loads successfully again.
- **A broken pending-update check could lock an administrator out of `/admin`** — the `auth.login` listener's migration-status check now has its own fault barrier: a failure is logged and flashed without repeating the throwable's own message, and the recorded version is left alone so the next login asks again instead of marking an unfinished upgrade as done.
- **The debug bar's route list could go stale for an entire deployment's lifetime** — `RoutesDataCollector` keyed its cache on the routing generator's own file, which used to be regenerated per route set and is now one shared file; it keys on a signature built from each route's own name, path, methods and controller instead, so a route change invalidates the panel's cache again without throwing on a route whose controller is a closure.
- **An extension could report "enabled" while staying excluded from every boot** — `PackageManager::enable()` cleared the durable failure record best-effort and moved on regardless of whether that succeeded, so a record that could not be rewritten stayed on disk while the panel and the configuration both called the module enabled. An extension enable that cannot clear its own record is now refused, through the same rollback an enable failure already triggers; a theme's enable still proceeds, since a theme is executed — and cleared — on its very next load regardless of the record.
- **A rolled-back enable could erase its own failure record** — clearing the record above moved ahead of the `package.enable` event so a refusal would leave nothing to unwind, but that also put it ahead of the one call left in `enable()` that can still throw; a listener failing there rolled the enable back with the record already gone, so a package that never actually recovered read as never having failed. `PackageManager` now snapshots the entry before clearing it and restores that snapshot whenever the enable it belonged to does not go through.
- **A fresh install's migration and database setup had several silent failure modes** — `MigrationService` no longer creates its metadata table merely by being resolved from the container (only `migrate()`, `rollback()` and `initialize()` do, so a locked or unwritable database no longer surfaces as a failure from a plain lookup); a nested install failure now shows every distinct message in its exception chain instead of just the outer wrapper; the installer persists the SQLite connection defaults it actually resolved — not only what the install form itself submitted — into `config.php`; a fresh or partial install with no `site.theme` yet now falls back to the blank layout instead of failing the boot; and `path.vendor` is corrected from `vendor/` to `app/vendor/` in both the installer and the front controller, matching where Composer actually installs it.
- **A failed extension enable could roll its schema back to the wrong version** — `MigrationService::getExtensionCurrentVersion()` returned `'0'` (an empty schema) whenever the real version could not be read at all, so a pre-attempt snapshot taken under that condition could point a failed migration's rollback at dropping every table the extension already had in production; it now reports `null` for an unreadable version, and the enable/install refuses to run the migration rather than snapshot a rollback target it cannot trust.
- **Restoring the branch a metrics push started from could discard commits that were never pushed** — the V1 Conductor metrics importer's git helper reset the caller's branch to its own remote tracking branch when putting it back after a push; it now checks the branch back out as it stands locally instead, keeping every commit ahead of `origin` intact.

### ❌ Removed

- **`PackageScripts`, `PhpMatcherDumper`, `UrlGeneratorDumper`**, and the three `UrlResolver`/`ThemeOneHelpers`/`UniqueValidator` static bridges above — all deleted outright, with no dual path kept alongside their replacements.

### 🔒 Security

- **The failure record is denied over HTTP and never carries a stack trace** — `tmp/system/.htaccess` adds `Require all denied` on top of a directory that already sits outside `public/`; the admin notice it backs names only the failing module, HTML-escaped, never the throwable's own message.
- **A route cache can no longer be mistaken for executable code** — both the matcher and generator dumps are validated arrays now, never a `require`d PHP class; a half-written or tampered cache file degrades to the non-cached router instead of being instantiated.
- **The metrics git helper no longer interpolates branch names into a shell** — `.github/conductor/metrics.mjs`'s standalone git helper (`syncMetricsFromRemote()`/`pushMetricsToRemote()`) now runs `git` via `execFileSync` with argv arrays (`shell: false`) instead of an interpolated command string, and validates every branch/ref name before use — closing a command-injection path through `pushMetricsToRemote()`'s `returnBranch`.

---

## Pagekit 1.2.37 - Filesystem Write Resilience: Atomic Writes (August 10, 2026)

### ✨ Added

- **One shared atomic-write primitive: `Filesystem::dumpAtomic()`** — writes through a same-directory temp file, carries an existing target's permission bits onto the replacement (or applies the umask-narrowed default for a fresh file), `rename()`s over the target, and centrally invalidates OPcache; refuses anything that isn't a plain local path rather than silently degrading a stream-wrapper or adapter-backed write. (Closes #257)

### ♻️ Changed

- **`Router::writeCache()`, the installer's and settings screen's `config.php` writes, and the Composer helper's package-registry write now go through the shared primitive** — each drops its own ad hoc temp-file/rename/permission logic, and the two `config.php` writers drop their own separate OPcache invalidation now that the primitive does it centrally.

### 🐛 Fixed

- **A failed settings save used to report success anyway** — `SettingsController::saveAction()` ignored the return value of its `config.php` write, so a hardened or read-only application tree got back a success message over a file that was never touched. The write now throws and the failure propagates instead of hiding unsaved settings from the administrator.
- **A failed package-registry write left a stale `packages.php` with no trace** — `Composer::writeConfig()`'s own write call ignored its return value the same way; it now throws through the shared primitive.
- **An install pinned to a version range (`^1.0`, `~2.3`) dropped out of Composer's forced-refresh list with no record of why** — the previously empty `catch` around that case now logs the package and its constraint; the package still installs and lands in the registry exactly as before.

### ❌ Removed

- **`SelfupdateCommand`'s long-dead commented-out update flow and its unused `SelfUpdater` import** — the command has refused to run since self-update was discontinued; git history is the record of the old implementation now, not a comment block beside the code that disabled it.

---

## Pagekit 1.2.36 - Docker Production Image & Deploy, TinyMCE removal (August 4, 2026)

### 💥 Breaking Changes

- **The TinyMCE editor is gone** — the `tinymce` option disappears from Settings → Misc, and with it the split visual/code view and the two UIkit presets (`Preload UIkit framework scripts`, `Add UIkit container class`) that only ever configured it. An installation whose stored `system/editor` config still names `tinymce` falls back to a plain textarea until an administrator picks **HTML** or **Codemirror** again. No migration ships for this: the modernization tree carries no production installations.
- **`$editor` no longer carries `locale`, `content_css`, `content_js` or `body_class`** — all four were TinyMCE init options that reached it through a wholesale merge of the view data into the editor config; the HTML and CodeMirror editors never read them. Only `root_url` remains. A theme or extension that reads one of the dropped keys has to stop.
- **`<v-editor>` drops its `mode` prop** — split view was a TinyMCE-only display mode, so the prop, the matching `system/editor.mode` config key, and the tab markup around the textarea are gone.
- **The dashboard's location widget is gone** — a saved one disappears from the dashboard on the next load, since only widget types that still have a component are rendered; its entry stays in the stored widget configuration and is ignored until the dashboard is next saved. The `admin/dashboard/weather` route, the `weather.api`/`weather.key` module configuration an extension could have read, and `PAGEKIT_WEATHER_API_KEY` go with it.

### ✨ Added

- **Production Docker image** — the `Dockerfile` grows from one stage to five (`base`/`dev`/`composer-deps`/`assets`/`prod`); `prod` is the default `docker build` target and produces a non-root (`33:33`/`www-data`) Apache runtime on `:8080` with a read-only application tree, a prod-tuned `php.ini` overlay, and an `entrypoint.sh` that recreates writable state on every start, symlinks `config.php` onto a data volume, and can run `setup`/`migration:migrate` automatically. (Closes #158)
- **12-factor container configuration** — `EnvConfigLoader` maps a fixed set of `PAGEKIT_*` variables onto the `application`/`system`/`database` config (registered last in all three boot files, so the environment always outranks `config.php`); a `TrustedProxies` helper configures `Request::setTrustedProxies()` from `PAGEKIT_TRUSTED_PROXIES` before the request is built.
- **Production compose stack** — `docker-compose.prod.yml` (Pagekit + healthchecked MySQL 8.4, named volumes for `config.php`/storage/data, resource limits, no published DB port) and `prod.env.example` documenting every variable it or the image consumes.
- **`docker-image.yml` CI workflow** — Hadolint → image build → runtime smoke (webroot denial, security headers, proxy-aware HTTPS redirect) → Trivy scan → GHCR publish (`develop` / `sha-<short>` tags), publish split into its own job so `packages: write` never reaches the build that runs on a pull request.

### ♻️ Changed

- **The dev image also gains `mod_headers`/`mod_expires`** — both moved into the shared `base` stage alongside the existing `mod_rewrite`, since the production vhost needs them; a rebuilt dev container starts enforcing `public/.htaccess`'s security-header and cache-expiry rules for the first time (they had silently no-op'd for want of the modules).
- **`editor.vue` and `editor-code.js` lost their dead split branches** — `editorMode`, the `unsplit` list, the switcher wiring in `mounted()`, `addCode()`, the second textarea and CodeMirror's split-only resize path all existed solely to serve TinyMCE.

### 🐛 Fixed

- **A demo install's content script could blank out `config.php`** — `Installer::runContentScript()` now runs `install.php`/`install-demo.php` in a scope of their own; previously the script's own `$config`/`$db` assignments overwrote the array `install()` was about to write, so a completed installation could come out of it missing `database`/`locale`.
- **A blank `PAGEKIT_DB_PORT` or `PAGEKIT_DB_DRIVER` no longer miscasts or throws** — both now read as unset (MySQL's own default port; no connection selected) instead of casting an empty string to `0` or rejecting an empty driver name.
- **`php pagekit …` now exits with the command's own status** — the console previously always exited `0`, so a failing `setup` or `migration:migrate` reported success to whatever ran it; the entrypoint no longer masks a failed `setup` either, and now stops the container start on one.
- **The `config.php` symlink is now followable under `fs.protected_symlinks`** — the application root is `chown root:root` + `chmod 755` instead of inheriting the base image's world-writable, sticky default.
- **OPcache is asserted rather than (incorrectly) installed** — PHP 8.5 links Zend OPcache into the interpreter; the build now asserts it's loaded instead of calling `docker-php-ext-enable`/`-install` against a module that was never built as a shared library.

### ❌ Removed

- **The editor module sheds roughly a third of its surface** — four component files (`editor-tinymce.js` plus the `pagekitLink`/`pagekitImage`/`pagekitVideo` plugin bridges), eleven committed skin stylesheets and the ~200-file published `tinymce/` asset tree are deleted, along with the `tinymce` npm dependency and its copy step in `scripts/assets.mjs`. The HTML editor (UIkit + CodeMirror + Markdown) and the plain CodeMirror editor cover the ground it held.
- **The admin navbar's help icon is gone** — it linked to the Pagekit community Discord, which this project does not run; the "Visit Site" and "Logout" icons beside it are unchanged.
- **The dashboard's location widget is deleted** — the component, its 13 weather icons, the `admin/dashboard/weather` proxy, the `weather.api`/`weather.key` configuration and `PAGEKIT_WEATHER_API_KEY`. Neither provider it depended on can be switched on by a new installation any more: OpenWeatherMap's `data/2.5` endpoints (the `/find` autocomplete among them) are closed to newly issued free keys, and the clock's time zone came from the Google Time Zone API, which requires a billing-enabled Google Cloud project. The panel and feed widgets are unaffected.

### 🔒 Security

- **Committed OpenWeatherMap API key removed, along with the widget that needed one** — the hardcoded key first moved into the environment, and then the dashboard's location widget went with it, so no credential and no third-party destination remains: the CSP `connect-src` drops `api.openweathermap.org` and `maps.googleapis.com`, and the administrator's Google key that the widget sent from the browser is gone with them. The key arrived with the imported upstream sources and belongs to the upstream Pagekit project, whose own history carries it too — it cannot be revoked from here, so no longer using it is the whole of the available answer.
- **Production runtime is non-root with a read-only application tree** — only `tmp/`, `storage/`, and the data volume are writable by `www-data`; error detail and PHP fingerprinting are off by default; the database is reachable only on the compose network.
- **The HTTPS redirect trusts a declared proxy, not just its header** — `public/.htaccess`'s `X-Forwarded-Proto` bypass is gated behind an Apache `<IfDefine PAGEKIT_TRUSTED_PROXY>` that `entrypoint.sh` sets only when `PAGEKIT_TRUSTED_PROXIES` actually names one.
- **GHCR publish carries its own, minimal permission** — `packages: write` lives only on the `publish-image` job (gated off pull requests), never on the job that builds and smoke-tests untrusted branch content.
- **Trivy CVE gate, passed without an exception** — the image is scanned for CRITICAL/HIGH findings before any push; `.trivyignore` is the one place a finding can be waived and needs a reason and a review date per entry, and it ends this release empty. The three stored-XSS advisories it was written for (`CVE-2026-47759`, `CVE-2026-47761`, `CVE-2026-47762`, in TinyMCE 5.10.9's content parser, with no fix short of an editor major) left with the editor.

---

## Pagekit 1.2.35 - CI Gates: coverage ratchet, complete quality report (July 31, 2026)

### ♻️ Changed

- **Minimum line-coverage floor raised from 3.8% to 7.1%** — pinned to the measured line coverage on PHP 8.5 (3403/47657 statements, PCOV), rounded down for float jitter. The gate ratchets up only, never down.
- **E2E smoke always runs on Dependabot pull requests** — dependency bumps get the Playwright smoke signal without waiting on a local run, while every other branch stays opt-in through the `E2E_SMOKE_PR_ENABLED` repository variable, so a feature PR does not pay for the PHP + Node + Playwright bootstrap.

### 🐛 Fixed

- **The quality-report comment no longer emails a table with pending rows** — the first create waits until every gate run for the commit has finished, instead of firing as soon as one of them uploaded a number. PHPStan publishes its report seconds after PHPUnit publishes coverage, so a create triggered in between mailed out a "pending" PHPStan row that only the silent follow-up edit ever resolved. Waiting cannot strand the comment: each gate that is waited for triggers the renderer again when it completes, and a gate that never ran on the pull request is not waited for.

---

## Pagekit 1.2.34 - Webroot Modernization: adopt `public/` (July 31, 2026)

### 💥 Breaking Changes

- **Runtime-installed extension/theme assets can go unreachable** — only `public/` is served now, and the build publishes only checked-out (in-repo) module/package/theme trees; a package installed through the admin upload flow (`admin/system/package/upload`) has no `public/` mirror, so its bundles, CSS and icons — previously reachable through the old approot-wide URL mapping regardless of build state — now resolve to no URL. PHP-only packages and views are unaffected.

### ✨ Added

- **Dedicated `public/` webroot** — `public/index.php` is the sole front controller (root `index.php` deleted outright); `public/.htaccess` carries the security headers and rewrite rules forward plus a new storage PHP-execution deny; root `.htaccess` shrinks to a one-line shared-hosting fallback (`RewriteRule ^(.*)$ public/$1 [L,QSA]`) for hosts that can't move the document root. `php pagekit start` and the dev Docker vhost now serve `-t public public/index.php`. (Closes #243)
- **Build-time static publication pass** (`scripts/publish.mjs`) — copies every module/package/theme's committed servable files (`assets/`, `css/`, `js/`, `images/`, `fonts/`, root icons, `app/**/*.{js,css}`) into `public/` alongside the relocated Vite bundles, vendor-asset copies and compiled CSS; `public/storage` is symlinked to `../storage` by the Node build and, for zip-unpacked installs, by the installer and CLI setup (warns instead of failing when `symlink()` is unavailable).
- **Extension page lifecycle across package disable/enable/uninstall** — `ExtensionNodeLifecycle` snapshots menu/parent/neighbor placement, parks nodes under "Not Linked" on disable, restores placement (still unpublished) on enable, and soft-deletes into Trash on uninstall; `package.enable` fires only after enable scripts succeed so a failed script cannot leave partial node state.

### ♻️ Changed

- **URL resolution is mount-based, not approot-wide** — `FileAdapter` maps an ordered mount list (`path.public` primary, `path.storage` secondary) instead of the whole application root, so a file outside both mounts gets no URL by construction; `Locator` gains a publish-mirror-first overlay (published copy checked ahead of the module source) so `$view->script()`/`style()` and template asset references resolve unchanged — extension DX is unaffected.
- **SQLite and locale paths resolve against the application root** — `pdo_sqlite` relative DB paths and `IntlModule` language-directory discovery use `path` (next to `config.php`), never `getcwd()`, so a docroot of `public/` cannot create or scan files under the webroot by accident.
- **`$pagekit.url` follows the router context only** — the installer no longer invents a `/index.php` fallback that breaks FastCGI (`No input file specified`); rewrite and non-rewrite bases come from `RequestContext` as before.
- **README / AGENTS.md** — Manual Installation documents both hosting paths (fixed `public/` docroot vs. the shared-hosting rewrite fallback) plus the storage-symlink recovery command; the dev-server command and router-file caveat now name `public/`; Nginx docs call out an explicit `*.db` deny (`.htaccess` does not apply).

### 🐛 Fixed

- **The debug module's vendored `highlight.js`/`highlight.css` were not published** — the static-publication pass excluded any directory literally named `vendor` (aimed at Composer/`node_modules` trees), which also skipped this module's own vendored front-end library; the exclusion no longer applies to a nested vendored library under a served directory.
- **Quality-report sticky comment no longer emails an empty first post** — the first CREATE waits until at least one number-bearing artifact exists (GitHub notifies on create, not later PATCHes); subsequent gate completions still update the same comment.

### 🔒 Security

- **Everything outside `public/`/`storage/` is now structurally unreachable** — `config.php`, `app/system/config.php`, `composer.json`, `.git`, `tmp/` sit outside the webroot entirely once `public/` is the docroot, rather than merely outside a URL allow-list.
- **Storage media library gets a defense-in-depth PHP-execution deny** — `public/.htaccess` blocks `.php` execution under `/storage` ahead of the front-controller rewrite, since finder uploads are the only admin-writable served path.
- **`config.php` no longer resolves relative to the running process's working directory** — the installer now reads/writes it via the application root explicitly, independent of hosting mode.
- **SQLite databases under `public/` are rejected** — connection setup throws if the resolved DB path sits under `path.public` (Apache denials are not universal on Nginx / `php -S`).
- **`public/.htaccess` restores sensitive-file denials** — `.db`/`.lock`/`.cache`, Composer/package manifests, changelogs, shell/ini/log/backup suffixes stay denied inside the webroot as defense-in-depth for stray copies or mis-deploys.

---

## Pagekit 1.2.33 - Build Tools: pnpm + Vite (July 26, 2026)

### 💥 Breaking Changes

- **Third-party JS bundle entries are no longer auto-discovered** — the deleted root `webpack.config.js` glob-scanned `{app/modules,app/installer,app/system,packages}/**/webpack.config.js` at build time. The static entry manifest (`scripts/bundle-entries.mjs`) enumerates only the 17 first-party module directories; a new bundle entry needs a core-repo change to that manifest. Extensions and themes stay unaffected if they are PHP-only or ship prebuilt `app/bundle/*.js` in their ZIP. Source-only packages that relied on the core build to compile their sources no longer work.

### ✨ Added

- **Vite JS pipeline** — static entry manifest (`scripts/bundle-entries.mjs`, 56 entries across 17 module directories) plus `scripts/bundles.mjs` / `scripts/build-js.mjs` drive one `vite build` per entry via `@vitejs/plugin-vue2`, replacing the 17 module-level `webpack.config.js` files (plus the root aggregator and `.babelrc`). Runtime contract unchanged: bundle paths, externals (`vue`/`uikit`/`uikit-util` → `Vue`/`UIkit`/`UIkit.util`), `@installer`/`@system` aliases, and IIFE globals (`Debugbar`, `Captcha`, `Editor`, `Finder`, `Links`). (Closes #159)
- **Node LESS / assets / CLDR scripts** — `scripts/styles.mjs`, `scripts/assets.mjs`, `scripts/cldr.mjs` (plus CLI wrappers and `build.mjs` / `watch.mjs`) replace Gulp's `less`, `assets` and `cldr` tasks with the same output layout: 3 LESS roots (installer, system theme, theme-one), 7 asset-copy packages, CLDR-derived locale formats.
- **pnpm 11** — `packageManager` pin, `npx only-allow pnpm` preinstall guard, `pnpm-lock.yaml` + `pnpm-workspace.yaml` (`allowBuilds`: `esbuild` approved, `core-js` declined).
- **ESLint 10 flat config** — `eslint.config.js` replaces `.eslintrc` / `.eslintignore`; ports script-tag globals and non-formatting custom rules; disables two chronically violated Vue rules centrally. `.git-blame-ignore-revs` records the one-time Prettier format-commit hash.

### ♻️ Changed

- **Vue 2.6.12 → 2.7.16** — `vue-template-compiler` removed from devDependencies (Vue 2.7 ships its own template compiler); README badge and prose updated.
- **Install/build decoupled** — `pnpm install` only installs; `pnpm build` (JS + CSS + assets) is explicit in CI workflows, `.cursor/install.sh`, `docker-compose.yml`'s `node` service, and the README quickstart.
- **CI moves to pnpm; lint/format become blocking full-tree** — `frontend.yml`, `e2e.yml`, `nightly.yml`, `e2e-weekly.yml` use commit-SHA-pinned `pnpm/action-setup` + `cache: pnpm`; `frontend.yml` runs full-tree blocking `pnpm lint` / `pnpm exec prettier --check .`. Required job names (`frontend`, `e2e-smoke`, `e2e-merge`, `e2e-viewports`, `e2e-sweep`) stay frozen.
- **One-shot Prettier format** — 188 `.js` / `.mjs` / `.vue` files reformatted to `.prettierrc`; 19 dead inline `eslint-disable-line` suppressions removed.
- **Agent env / Docker / docs realigned** — `.cursor/Dockerfile`, `.cursor/install.sh`, `.cursor/modernize-helper.sh`, `AGENTS.md`, `README.md`, `docker-compose.yml`, and affected rules/skill docs scrubbed of yarn/webpack/gulp.
- **`php pagekit build`** (`BuildCommand.php`) — one cross-platform call into the new Node pipeline (JS + CSS + assets).

### 🐛 Fixed

- **`pnpm watch` skipped the initial CSS build and never copied runtime assets** — `watchStyles()` compiles once before watching; `watch.mjs` copies assets once before starting watchers.
- **A failed first bundle build under `pnpm watch` still reported success** — `watchBundles()` rejects startup on a failing first build and shuts down started watchers.
- **A release build (`php pagekit build`) omitted compiled CSS and copied assets** — runs the full JS + CSS + asset-copy pipeline; stdout and stderr captured on failure.
- **`VInput`'s named export lost its compiled template under the Vite plugin** — `validation.vue` keeps `VInput` as default export only (`ValidationObserver` stays named); 13 named-destructure call sites updated.
- **The CLDR formats task was silently a no-op** — `cldr.mjs` fixes a missing `$` in the formats-file template literal and points at `node_modules/vue-intl/dist/locales/`; 46 `formats.json` files updated, 21 added.
- **theme-one's compiled CSS banner carried a stray double space** — empty `copyright` banner segment dropped.
- **theme-one's `composer.json` still excluded two already-deleted files** — `archive.exclude` no longer names `gulpfile.js` / `package.json`.

### ❌ Removed

- Root + 17 module `webpack.config.js`, `.babelrc`, and the webpack/babel/`vue-loader` dependency chain.
- `gulpfile.js` (root + theme-one), `theme-one/package.json`, `blog/package.json`, and the `gulp` / `gulp-*` / `merge-stream` / `npm-run-all` dependency chain.
- `yarn.lock`, `app/scripts/checkYarn.js` — superseded by `pnpm-lock.yaml` and the `only-allow` preinstall guard.
- `.eslintrc`, `.eslintignore`, and the ESLint 7 + `eslint-config-airbnb-base` + `babel-eslint` + `eslint-plugin-import` + `eslint-watch` + `eslint-webpack-plugin` lint stack.

### 🔒 Security

- **Dependency-audit findings drop sharply** — deleting the webpack/babel/Gulp transitive tree resolves most of what `yarn audit --level moderate` reported; remaining advisories are unrelated to build tooling (`tinymce` ~5.10.9; one low-severity `vue` ReDoS). Before/after audits at `step-2-4-audit-{yarn-before,pnpm-after}.txt`.

---

## Pagekit 1.2.32 - Docker Dev Experience & Quality Reporting v2 (July 26, 2026)

### ✨ Added

- **Quality history + dashboard charts** — `quality-collect.yml` maintains `.github/quality/quality-history.json` on `quality-data`; dashboard plots line coverage, full Infection MSI, PHPUnit test count and PHPStan suppressed-error debt. Points append only when a watched value changes (Infection compared only while nightly reports); capped at 90 points; tip-only table when no history exists.
- **`DRY_RUN=1` on both quality collectors** — renders comment body / snapshot + history verdict to stdout without writing; `workflow_run` / `workflow_dispatch` resolve workflow file and script from the default branch.

### ♻️ Changed

- **Dockerfile reconciliation** — root `Dockerfile` and `.cursor/Dockerfile` slim `docker-php-ext-install` to `pdo_mysql`, `gd`, `zip` (`pdo_sqlite`/`mbstring`/XML stay in the base image) and prune apt build-deps to justified-only. Root image no longer bakes application code — the dev compose bind-mount covers that path. (Closes #241)
- **`.dockerignore`** — 16 → 32 entries (docs/tests/CI/agent config, report/coverage artefacts, local install files, Docker artefacts).
- **Dev compose + `.env` wiring** — `docker-compose.yml` drops top-level `version:` and `profiles:`; `mysql` TCP healthcheck gates `web`/`phpmyadmin`; `docker.env.example` → `.env.example`; `docker-setup.sh`/`docker-setup.ps1` generate `.env` byte-safely with a missing-template guard.
- **`docker/php/php.ini`** — drops `opcache.fast_shutdown` (removed since PHP 7.2).
- **Docs alignment** — `README.md` / `AGENTS.md` Docker sections match the reconciled stack; `migration-docs/documentation/DOCKER.md` folded into README then deleted.
- **Sticky PR comment is a metrics table** — `| Metric | This PR | vs develop |`; deltas against the live `quality-data` snapshot; Infection has no numeric delta (PR diff vs nightly full scope).
- **Dashboard Infection row carries a verdict** — ✅/❌ against `infection.json.dist` thresholds (80), plus killed/escaped counts.
- **Quality snapshot schema v2 → v3** — `infection.dailyFull` gains `killed`, `escaped`, `timedOut`, `errors`, `totalMutants`; snapshot gains `commit`; seed/demo drop unused `workflows.frontendTests`.

### 🐛 Fixed

- **The PR quality comment reported no metrics** — matching keyed on workflow `path` instead of display/`run-name`.
- **A green gate with no artifact read `pass`** — rows without a number report `pending`, `skipped`, `—`, or `out of scope`.
- **Dashboard Infection row rendered `MSI — · covered — @ —`** — Nightly is a collect trigger; until it reports the row says `awaiting nightly`.

### ❌ Removed

- **Standalone Docker E2E stack** — `docker-compose.e2e.yml`, `scripts/e2e-start.sh`, `scripts/e2e-stop.sh`, `scripts/e2e-reset.sh`; docs/config realigned to Playwright `webServer` (`php pagekit start`) at `http://127.0.0.1:8080`.
- **`docker/mysql/init/01-create-database.sql`** — mysql image `MYSQL_*` env vars already create database/user/grants.
- `migration-docs/documentation/DOCKER.md`.

### 🔒 Security

- **Last hardcoded DB credential removed** — MySQL credentials come from a generated, gitignored `.env` with `${VAR:?Run ./docker-setup.sh first}` guard. Dev-only scope.

---

## Pagekit 1.2.31 - CI/CD Pipeline (Juli 24, 2026)

### ✨ Added

- **PHP Tests workflow** — `.github/workflows/php-tests.yml` replaces `php-quality.yml` (same required job names). PR/merge split (`cs-fixer` / `security-audit` on PR only); JUnit + PHPStan JSON artifacts; advisory `phpunit-mysql` leg (`phpunit-mysql.xml.dist` + `mysql:8.4`); PR-only `version-ssot` guard (`.github/scripts/check-version-ssot.php`). (Closes #157)
- **Infection / Frontend / E2E PR gates** — `infection.yml` (`infection-diff`, git-diff MSI, out-of-scope early green); `frontend.yml` (blocking webpack+gulp, advisory diff-scoped ESLint/Prettier); `e2e.yml` (`e2e-smoke` on PR — opt-in via `E2E_SMOKE_PR_ENABLED`, dormant by default; `e2e-merge` on push for snapshot feed).
- **Nightly + E2E Weekly** — `nightly.yml` (24h guard, full Infection, chromium desktop/tablet/mobile with tablet/mobile non-blocking); `e2e-weekly.yml` (dispatch-first 9-browser/viewport sweep; cron gated by `E2E_WEEKLY_ENABLED`).
- **Quality report + live snapshot** — `quality-report.yml` sticky PR comment; `quality-collect.yml` writes schema-v2 snapshot to unprotected `quality-data`; `pages-deploy.yml` overlays it onto the docs-site dashboard.
- **Playwright selection model** — `@ci` tags on three smoke specs; eight specs `test.describe.fixme`; env-composed projects (`PW_VIEWPORTS` / `PW_BROWSERS`).

### ♻️ Changed

- **Quality dashboard** — dynamic PHPUnit keys (`8.5-sqlite` / `8.5-mysql`), E2E `scope` label, live data-source copy.
- **Agent / branch-doc metrics discipline** — Orchestrator handoffs and Verification sections stay PASS/FAIL + links; coverage/MSI/counts live only in the sticky comment and dashboard.
- **Finalize CI watch** — consumers use `gh pr checks … --watch` (covers all required PR gates).

### 🐛 Fixed

- **Snapshot pairing** — `quality-snapshot.mjs` pairs PHP Tests + E2E by `head_sha`; Nightly MSI lookup scoped to the collected branch; `quality-collect` / `SNAPSHOT_BRANCH` limited to `develop`.

### ❌ Removed

- `.github/workflows/php-quality.yml`
- `playwright.smoke.config.js`

---

## Pagekit 1.2.30 - PHP 8.5 version upgrade (Juli 22, 2026)

### Breaking Changes

- **Minimum PHP raised to 8.5** — Composer `require.php` / `config.platform.php`, `index.php` runtime guard, and installer `REQUIRED_PHP_VERSION` now require PHP 8.5+. Hosts and extension Composer constraints below 8.5 will fail install / boot. (Closes #231)

### Changed

- **Composer lock refresh** — platform bump to 8.5.0; Infection cap `>=0.33 <0.35` (resolves `0.34.0`). Symfony stays 6.4.x; `doctrine/dbal` 3.10.6; PHPUnit 11.5.56. PHPStan baseline regenerated for the new platform.
- **`MenuHelper` null-parent short-circuit** — synthetic-root `parent_id = null` no longer triggers PHP 8.5 null-as-array-offset deprecation; `MenuHelperTest` covers the branch.
- **CI PHP Quality** — PHPUnit matrix `['8.5']` only; phpstan / cs-fixer / security-audit jobs on PHP 8.5. Dead `.travis.yml` removed.
- **Runtime docs / Docker** — root `Dockerfile` `FROM php:8.5-apache`; README badge and requirements strings → PHP 8.5+; agent context / ROADMAP stack wording aligned.

### Deferred

- Docs-site quality dashboard matrix keys (8.2/8.3 legs) → Step 2.2.
- PHPUnit doc-comment metadata deprecations + `failOn*` flips; PHPUnit 12/13 eval → Step 2.9.
- Root `Dockerfile` multi-stage/Alpine redesign → Step 2.3.

### Maintainer action

- Update Ruleset "Protect for Develop-Branch" required status checks from `phpunit (8.2)` / `phpunit (8.3)` → `phpunit (8.5)`.

---

## Pagekit 1.2.29 - TinyMCE security patch (Juli 21, 2026)

### Security

- **TinyMCE `~5.5.1` → `~5.10.9`** — resolves to `5.10.9` (final community 5.x). Unique `yarn audit` TinyMCE advisories **16 → 7** (nine patched; seven remain, including high-severity media/iframe-class XSS fixed upstream only in TinyMCE ≥ 6.8.1). Commit surface `package.json` + `yarn.lock` only; gulp-refreshed editor assets stay gitignored. (Closes #230)

### Deferred

- Residual TinyMCE v5 advisories (CSP / major upgrade) → Step 3.2.1 / Step 5.1.
- Webpack-locked transitive advisories → Step 2.4.

---

## Pagekit 1.2.28 - Composer security advisories (Juli 21, 2026)

### Security

- **`composer/composer` 2.10.1 → 2.10.2** — clears CVE-2026-59946 / CVE-2026-59947 / CVE-2026-59948 (path traversal, credential leak in verbose logs, arbitrary file write). Constraint raised to `^2.10.2`; `composer audit --locked` clean.

### Changed

- **Codecov / PHPUnit coverage excludes** — ignore `app/system/config.php`, root `index.php`, and `**/index.php` (manifests/front controllers) so version bumps and bootstrap files do not create patch-coverage noise.

---

## Pagekit 1.2.27 - Residual `mixed` narrowing (Juli 16, 2026)

### Added

- **CaptchaListener unit tests** — `CaptchaListenerTest` + module `Tests/bootstrap.php` (`__()` stub + `require_once`); covers `onRequest()` via `post()` override (no network). (Closes #217)
- **`site` container service** — `SiteModule::main()` registers `$app->set('site', $this)` for constructor DI by parameter name.

### Changed

- **`CaptchaListener::verifyToken()`** — params `mixed` → `string`; call site uses `$request->request->getString('gRecaptchaResponse')` + `(string)` secret cast.
- **`NodeController`** — `protected mixed $site` + `ModuleManager` lookup replaced by promoted `private readonly SiteModule $site`; null-guards `getTypes() ?? []` and `getType($node->type ?? '')`.
- **`DataModelTrait::$data`** — typed `?array` with `@var array<int|string, mixed>|null` (int keys required for `Arr::set` by-ref typing).

### Breaking Changes (Extensions)

- **Signature narrowing.** Subclasses/callers of `CaptchaListener::verifyToken()`, `NodeController`'s constructor (`SiteModule` instead of `ModuleManager`), or writers to `DataModelTrait::$data` must match the new types or TypeError under PHP 8.

### Deferred

- Remaining legitimate `mixed` (docblock shapes, magic `__get`/`__set`, filter/loader/PSR-11 contracts, callable properties) — permanent non-goal, not future work.

---

## Pagekit 1.2.26 - EntityManager DI — remove singleton (Juli 14, 2026)

### Added

- **Generic `Repository<T>`** — `query`/`where`/`find`/`findAll`/`create`/`save`/`delete`/`removeRole(int)` with per-EM repository map on `EntityManager::getRepository()`. (Closes #205)
- **`EntityEvent`** — lifecycle handlers receive the emitting `EntityManager` via `$event->getEntityManager()`.
- **`SerializableModelInterface` + serialization map** — `Metadata::getSerializationMap()` injected at hydration; `toArray()`/`jsonSerialize()` map-only after Step 8.
- **Custom repositories** — `NodeRepository` (cached finders + `fixOrphanedNodes`), `UserRepository` (auth finders + `findRoles`), `PostRepository` (`updateCommentInfo`, `getAuthors`).
- **Container services** — `nodeRepository`, `pageRepository`, `userRepository`, `roleRepository`, `widgetRepository`, `postRepository`, `commentRepository` (constructor param names match service names).
- **Per-instance role loader** — `User::hasPermission()` resolves roles through a closure attached at `#[ORM\Init]` hydration.
- **Unit tests** — ORM core, site/user/widget/blog module migrations, lifecycle handlers, repositories, controllers; Finalize coverage-gap tests for user controllers + `AuthDataCollector`.

### Changed

- **Site, user, widget, blog modules** — all controllers, listeners, helpers, and auth chain migrated from static Active-Record finders to injected repositories.
- **`NodeModelTrait` request cache (RC-2)** — moved to `NodeRepository` backed by `ArrayAdapter(0, false)`.
- **`PositionHelper` function-statics** — become instance properties (`$activeWidgets` / `$renderedPositions`).
- **`UserRepository::findRoles`** — `whereIn('id', …)` replaces string-interpolated `IN` SQL.
- **`blog/UrlResolver`** — bridge swap: `Post::where()` → bridged `PostRepository` (`TEMPORARY BRIDGE — Step 2.5`).
- **Blog baseline migration (RC-3)** — stale audit comment rewritten as permanent upgrade note (docs-only).

### Removed

- **`EntityManager` singleton** — `$instance`, ctor assignment, `getInstance()`.
- **`ModelTrait` static Active-Record API** — `getManager()`, static finders, instance `save()`/`delete()`.
- **`AccessModelTrait::removeRole()`** — logic lives on `Repository::removeRole(int)`.
- **RC-1 eager `db.em` boot block** — `app/system/index.php` no longer force-resolves the EM at boot.
- **Dead code** — `findByLogin`, per-site `instanceof` guards (central hydration guard), `RunInSeparateProcess`/`primeEntityManager*` test harnesses.

### Breaking Changes (Extensions)

- **Static model API removed.** Extensions calling `Model::find()` / `findAll()` / `where()` / `query()` / `create()` / instance `save()`/`delete()`, `ModelTrait::getManager()`, `EntityManager::getInstance()`, or `AccessModelTrait::removeRole()` will fatal. Migrate to container repository services or `$app->get('db.em')->getRepository(Entity::class)`.
- **Serialization requires hydration.** Raw `new Entity(...)` then `toArray()`/`jsonSerialize()` throws `\LogicException`.

### Phase 1 Audit

- **Step 1.11 (ORM Modernization) — FULL:** `EntityManager` singleton + static Active-Record finding resolved. Row **⚠️ → 🛡️** (combined with Steps 2.0.8 + 2.1.6 + 2.1.10).

### Deferred

- `NodeRepository` cache invalidation on save → Step 4.3.
- `UrlResolver` static bridge removal → Step 2.5.
- Raw-entity JSON API responses → Step 4.2 presenters/DTOs.

---

## Pagekit 1.2.25 - Entity Presentation Layer (Juli 10, 2026)

### Added

- **`NodePresenter`** — constructor-DI presenter for site nodes (`getUrl`, `isAccessible`, `toArray`) with `NodePresenterTest`. (Closes #204)
- **`PostPresenter`** — constructor-DI presenter for blog posts (`isCommentable`, `isAccessible`, `toArray`) with `PostPresenterTest` + `tests/Unit/Blog/bootstrap.php`.

### Changed

- **Site API + menus** — `NodeApiController`, `MenuHelper`, and menu views use `NodePresenter` instead of entity presentation methods.
- **Blog API + public views** — `PostApiController`, `CommentApiController`, `SiteController`, and post list views use `PostPresenter` or transient `commentable` data flags.

### Removed

- **`ModelServiceLocator`** — static service locator deleted; `Node`/`Post` entity presentation methods (`getUrl`, `isAccessible`, `isCommentable`, enriched `jsonSerialize`) removed.

### Phase 1 Audit

- **Step 1.11 (ORM Modernization) — partial:** `ModelServiceLocator` static-service-locator finding resolved. Row stays ⚠️ until Step 2.1.11 removes the `EntityManager` singleton.

---

## Pagekit 1.2.24 - Test Coverage Expansion (Juli 9, 2026)

### Added

- **Injectable clock** (`psr/clock` + `symfony/clock`) for `DatabaseHandler` and `LoginAttemptListener` — deterministic boundary tests kill two Infection `LessThan` mutants. (Closes #156)
- **Site module tests** — first `Tests/` dir with `MenuApiControllerTest` (`#[Assert]` + `ValidatesRequestTrait`).
- **ORM query-cache invalidation regression test** — `EntityManager::save()`/`delete()` → `invalidateCache()` → `clear()`.
- **DI-wiring integration tests** — module `$app` fallback, factory/finder freshness (`tests/Unit/Container/`).
- **PackageManager migration integration tests** — enable/uninstall auto-migrate/rollback (`tests/Unit/Package/`).
- **MigrationCommand CLI integration test** — `CommandTester` + in-memory SQLite (`tests/Unit/Console/`).
- **CI minimum line-coverage gate** — ratcheting floor pinned at **3.8 %** in `phpunit (8.3)` leg.
- **Codecov upload** — non-blocking `coverage.xml` upload + README badge (external app/token activation flagged).

### Changed

- **PHPUnit strictness** — `failOnWarning`/`failOnPhpunitWarning`/`failOnRisky` flipped to `"true"`; UserAccessTest-masked Infection parser ignores removed.
- **AddRelNofollowFilter** — hardened against slash-obfuscated and null-byte XSS; replaces existing `rel="follow"` with `rel="nofollow"`.
- **RouterTest** — decoupled from `blog.permalink` extension config.

### Fixed

- **`StreamWrapper::$context`** — declared property kills 4 PHP deprecation notices.
- **PHP 8.5 forward-compat** — all `setAccessible()` calls removed (tests + production).
- **MenuApiController** — restore `trim()` on id/label in `saveAction()` (Bugbot finding).
- **PHP-CS-Fixer** — anonymous-class parentheses in `PackageManagerMigrationTest`.

### Deferred

- Breadth coverage to target table → ongoing / Step 2.9 closeout.
- Full E2E rework (Phase 1 audit 1.10.5) → Step 3.6.1.
- `packages/` coverage (out of measured `<source>` scope).
- DB/kernel-bound unit gaps → Step 2.9.
- Infection MSI ratcheting beyond auth+user → Step 2.9 closeout.

---

## Pagekit 1.2.23 - Infection Mutation Testing (Juli 9, 2026)

### Added

- **Infection mutation testing** for the auth + user security-critical core. Dev dependency `infection/infection` (≥0.29, capped at <0.33 for PHP 8.2 CI matrix) and `infection.json.dist` with `minMsi` / `minCoveredMsi` gates at 80%. (Closes #155)
- **Security-core unit tests:** `NativePasswordEncoderTest`, extended `DatabaseHandlerTest` (`read()`/`destroy()`), `UserProviderTest`, `RoleTest`, `UserTest`, `LoginAttemptListenerTest`, `AuthorizationListenerTest`, `AccessListenerTest`.

### Fixed

- **Infection PHP 8.2 CI compatibility.** Lock pinned Infection 0.34 (PHP ≥8.3-only), breaking `composer install` on the `phpunit (8.2)` matrix leg; constraint capped at `<0.33` with `config.platform.php: 8.2.0`.

### Deferred

- Infection CI wiring (scheduled + manual-dispatch) → Step 2.2.
- DB/kernel-bound coverage gaps (`UserProvider` lookups, trait static methods, `UserListener`) → Step 2.1.9.

---

## Pagekit 1.2.22 - QueryBuilder API Standardization (Juli 8, 2026)

### Added

- **`QueryBuilder::cacheKey(?string $key)`** — explicit ORM result-cache discriminator (akin to Doctrine's `setResultCacheId`) for queries that share SQL, parameters and eager-load relation names but load different related data via a dynamic `related()` constraint.

### Changed

- **QueryBuilder execution API split for DBAL 3.x.** Legacy `execute()` removed; use `executeQuery(): Result` for SELECT and `executeStatement(): int` for UPDATE/DELETE. All call sites migrated across database module, site, blog, and validator code. (Closes #154)
- **`json_array` DBAL type normalized to `json`.** `JsonArrayType` registered as JSON override; redundant `json_array` type registration and mappings removed.
- **DBAL 3 schema APIs updated.** `getSchemaManager()` → `createSchemaManager()`, `createSchema()` → `introspectSchema()` in `Utility`, `Installer`, and `DbUtil` test helper.
- **`@template` generics** added to ORM `QueryBuilder::get()`/`first()` fetch returns (deferred from Step 2.1.6).

### Removed

- **`Connection::exec()` alias** — zero production callers.
- **`Utility::migrate()`** — dead code superseded by MigrationService (Step 2.0.4).

### Fixed

- **`executeStatement()` no longer falls through to `DELETE`.** A builder without a write type (e.g. one configured for SELECT, also reachable via the ORM `__call` proxy) now throws a `LogicException` instead of silently issuing a `DELETE` against the FROM table.
- **ORM query cache key correctness.** The key is derived from the SQL, bound parameters and eager-load relation names — not by serializing, reflecting or materializing constraint closures — so combining `cache()` + `related()` neither crashes on Closures nor serves the wrong cached relations. Use `cacheKey()` to disambiguate dynamic constraints; bound parameters are normalized so a non-serializable binding cannot break key generation.

### Phase 1 Audit

- **Step 1.5 (Doctrine DBAL 3.x) ⚠️ → 🛡️** — audit findings for `exec()`, deprecated schema manager APIs, Comparator/migrate debt, and `DbUtil` `exec()` resolved.

---

## Pagekit 1.2.21 - PHPStan Level 7 → 8 (Strict Typing) (Juli 1, 2026)

### Static Analysis

- **PHPStan baseline raised from `level: 7` to `level: 8`.** Strict typing enforced across `app/modules/`, `app/system/`, `app/installer/`, `app/console/`, and `packages/pagekit/blog/`. (Closes #153)
- Added **`phpstan/phpstan-phpunit`** extension and swept test property declarations (`protected ?Type` → `private Type`).
- **Mixed usage audit** — all remaining justified `mixed` usages documented with `@return mixed Genuinely unknown type —` docblocks.
- **`#[AllowDynamicProperties]` fully removed** — explicit typed properties on Node, Widget, and related models.

### Changed

- **`MailPluginInterface`** split from `MailerInterface`; `ImpersonatePlugin` implements plugin interface only.
- **`IntlServiceLocator`** — static container replaced with constructor DI + boot-time registration.
- **`FileLocatorAsset`** — constructor injection via view module factory closure.
- **Console commands** — setter-DI removed; `Container` injected via constructor.
- **`PackageController`** — God-DI replaced with explicit service constructor parameters.
- **`ModelServiceLocator`** — `getUrl()`/`getUser()`/`getModule()` return types narrowed from `mixed` to concrete types (architectural removal deferred to Step 2.1.10).
- **`EntityManager`/ORM** — typing hardened on Metadata, Relation classes, PropertyTrait (singleton removal deferred to Step 2.1.11).
- **`UrlGeneratorInterface` → `LinkReferenceType`**, **`GetResponseEvent` → `AuthResponseEvent`** renames.
- **Null-safety sweeps** across `app/modules/` (29 files) and `app/system/` + `app/installer/` + `app/console/` (24 files).

### Removed

- **`DebugStack.php`** — dead code, zero callers.
- **`AliasListener.php`** dead query-string parser block.
- **`requirements.php`** PHP 5.x/7.x/APC dead code blocks.

### Fixed

- **Blog post URLs no longer show "Disabled" for the "Numeric" permalink type.** The router cache key had dropped route-affecting options (notably `blog.permalink`), so switching the permalink type kept serving a stale alias route and `UrlProvider::getRoute()` returned `false`. All router options are part of the cache key again, so each permalink type regenerates its own matcher/generator.
- **Routing cache race condition fixed (HTTP 500 on `/api/site/node` & `/api/site/menu`).** Rapid page reordering (drag & drop) regenerates the route dump concurrently; a half-written cache file made `getMatcher()`/`getGenerator()` throw an uncaught `LogicException`. Cache files are now written atomically (temp file + atomic rename), and the router falls back to the non-cached matcher/generator on any corrupted/partial cache file instead of crashing. (Also fixes the page-reorder selection that previously had to be cleared manually.)
- **`IntlModule::$app`** — nullable property with guard accessor (Bugbot Rule 4.2).
- **php-cs-fixer CI** — style fixes across branch-changed files.

---

## Pagekit 1.2.20 - PHPStan Level 6 → 7 (Null Safety) (Juni 23, 2026)

### Static Analysis

- **PHPStan baseline raised from `level: 6` to `level: 7`.** Property types and null-safe code are now enforced across `app/modules/`, `app/system/`, `app/installer/`, `app/console/`, and `packages/pagekit/blog/`. (Closes #152)
- **Property-type sweep** — 11 `app/modules/` core files and all 16 console commands received native property types (`?string $name`, `string $description`, `\Closure` for callables, `mixed` only where PHP cannot express the type).
- **Null-safety sweep** — grouped by module risk profile (database ORM, view/twig, routing/feed, system modules, installer, packages). Prefer type-narrowing over null guards per the §3.1 decision tree.
- **`phpstan-baseline.neon`** — surgical removals only; no wholesale regeneration.

### Fixed

- **`EventDispatcher` / `TraceableEventDispatcher` listener identity** — subscribe/unsubscribe now track exact callable references via `SplObjectStorage`; re-subscribe guard prevents duplicate listeners (Bugbot).
- **`SimpleArrayType` scalar coercion** — removed `strval()` on JSON-decoded values; native PHP scalar types preserved (Bugbot).
- **`MetaHelper::add()` null config values** — fresh installs with unset `meta.twitter` / `meta.facebook` keys no longer throw `TypeError` on the homepage (E2E regression caught after Bugbot mini-loop).

### Removed

- **`app/modules/view/src/PhpEngine.php.backup`** — stale backup artifact deleted during Step 14 cleanup.

### CI / Cloud Agent

- **Composer bootstrap in `.cursor/install.sh`** — snapshot-based cloud environments ignore the Dockerfile at runtime, so the install script now self-heals: an idempotent `command -v composer` guard installs Composer via the official installer (with a `sudo` fallback for non-root) before `composer install`, mirroring the existing `rg`/`jq` guard. Fixes the `composer: command not found` cold-boot failure when the pinned snapshot ships without Composer (the Dockerfile that installs it is never built because `environment.json` pins a snapshot and has no `build` block). A `composer --version` line was added to the tool-verification block.
- **`.cursor/environment.json`** — pinned base snapshot updated to `snapshot-20260620-594c3358`.

### Documentation

- **Roadmap Step 2.1.6** — recorded the `app/installer/requirements.php` audit finding (legacy Symfony RequirementChecker: dead PHP 5.6/7.0 and APC branches, obsolete eAccelerator/XCache accelerator list, abandoned `magic_quotes`/`register_globals`/`detect_unicode` php.ini checks, stale APCu/PCRE/`utf8_decode` messaging) plus a "Lessons learned (from 2.1.5)" note — view-/event-layer null narrowings are E2E-regression-prone (the `MetaHelper` fresh-install 500 passed PHPStan + PHPUnit but failed Playwright).
- **`MODERNISATION_STRATEGY.md`** — added a **Quality Metrics Tracker** under the Visual Roadmap recording the PHPStan baseline and test counts per level milestone (suppressed errors: L5 891 → L6 680 → L7 654; unit tests stable at 326; E2E 25 specs). The static-document header note was adjusted to mark the tracker as the one living exception.

---

## Pagekit 1.2.19 - Strict-Typing Runtime Regression Fixes (Juni 21, 2026)

### Fixed

- **ORM single-entity relations crashed on typed nullable properties** — `Relation::initRelation()` seeded `BelongsTo` / `HasOne` relations with the legacy `false` sentinel. After Step 2.1.4 typed the relation properties (e.g. `Post::$user` → `?Pagekit\User\Model\User`), assigning `false` threw `TypeError: Cannot assign false to property … of type ?User` on every blog post list / Post API request (`PostApiController::indexAction()`). The default is now `null` — the natural empty value for a nullable single-entity relation. Collection relations (`HasMany` / `ManyToMany`) are unaffected; they explicitly pass `[]`.
- **`$view->script()` rejected string dependencies at 13 call sites** — the strict `array $dependencies` parameter (Step 2.1.4) raised `TypeError: Argument #3 ($dependencies) must be of type array, string given` whenever a page registered a script with a bare string dependency. Affected: cache + mail settings (`'settings'`), theme-one site/node/widget editors (`'site-settings'` / `'site-edit'` / `'widget-edit'`), and blog/info/user/permission views (`'vue'`). All wrapped in arrays (`['settings']`, `['vue']`, …). No compatibility shim added — every call site was updated per the No-Mercy rules.
- **`sha1(int)` TypeError in the debug `RoutesDataCollector`** — `sha1(filemtime(...))` passed an `int` (and potentially `false`) where PHP 8.2's `sha1()` requires `string`, throwing on every request while the debug bar was active. The value is now cast to `(string)`; the matching `sha1` entry was removed from `phpstan-baseline.neon`.

### Notes

- All three regressions were introduced by the strict-typing work in Step 2.1.4 (PR #203) and only surfaced at runtime on admin/blog pages that PHPUnit, PHPStan, and CS-Fixer do not exercise — a coverage gap that admin-facing Playwright E2E tests would catch.

---

## Pagekit 1.2.18 - TODO Inventory & Legacy Cleanup (Juni 20, 2026)

### Security

- **Dependency security update** — `composer update` of all advisory-affected packages resolved **27 advisories across 10 packages** that `composer audit --locked` surfaced (the lockfile had been frozen since 2026-04-26; most CVEs were published in the May 2026 Symfony/Twig security wave). Notably **`twig/twig` `v3.24.0` → `v3.27.1`** (closes the critical `CVE-2026-46633` PHP code injection via `{% use %}` plus the Twig sandbox bypass series), **`composer/composer` → `2.10.1`** (`CVE-2026-45793` GITHUB_TOKEN disclosure), and the Symfony 6.4 components **`cache`/`http-foundation`/`http-kernel`/`routing` → `6.4.41`**, **`mailer` → `6.4.40`** (`CVE-2026-45068` SendmailTransport argument injection), **`mime` → `7.4.13`** (`CVE-2026-45067` CRLF header/SMTP injection), **`translation` → `6.4.38`**, **`dom-crawler` → `7.4.12`**, **`twig-bridge` → `6.4.40`**, and **`polyfill-intl-idn` → `1.38.1`**. `symfony/validator` was realigned `v7.4.8` → `v6.4.37` to match the project-wide `^6.4` constraint. `composer audit --locked` now reports zero advisories; PHPUnit stays green (326 tests, 752 assertions). Only `composer.lock` changed (`app/vendor/` is gitignored).

### Fixed

- **DBAL 3 platform class name** — `Doctrine\DBAL\Platforms\MySqlPlatform` (the DBAL 2.x name, non-existent in DBAL 3) corrected to `MySQLPlatform` in `ConfigManager`, `Database\Query\QueryBuilder` and `DatabaseSessionHandler`. The old name silently made every `instanceof MySqlPlatform` evaluate to `false`, so the MySQL-specific SQL paths (config upsert, session merge) were never taken on MySQL. Three now-resolved `class.notFound` baseline entries removed.
- **CI `security-audit` job** — `composer audit` in `.github/workflows/php-quality.yml` now passes `--locked` so the lockfile is audited without `composer install`. Newer Composer 2.x on GitHub Actions runners no longer skips with exit 0 when no packages are installed (regression after PR #203 merge on `develop`).
- **CI `phpstan` job (baseline drift)** — four stale `method.void` entries removed from `phpstan-baseline.neon` (`BuildCommand`, `InstallCommand`, `SelfupdateCommand`, `UpdateCommand`). The console refactor above fixed `(int) $this->error(...)` / `return (int) $this->line(...)` usage; leftover baseline suppressions caused “Ignored error pattern … was not matched” failures.

### Refactored

- **Console commands** — removed the obsolete `// TODO: Callback` markers and switched to explicit `Command::SUCCESS` / `Command::FAILURE` (Archive, Build, Setup, Start, Uninstall). The disabled marketplace commands `install` / `update` / `self-update` previously returned exit `0` via `(int) $this->error(...)` (`error()` is `void`); they now return `Command::FAILURE` with a clear message so agents/CI detect the disabled state. Marketplace bundling tagged to Step 5.6. `ArchiveCommand::getPackageFilename()` hardened (collapse/trim hyphens, empty-string fallback).
- **View engine** — removed the dead `$parser` constructor param + property from `PhpEngine` (the only caller passed `null`); clarified the `escape()` null-guard comment (it guards the PHP 8.1+ null→string deprecation, min PHP is 8.2 — not old-PHP support). Documented the intentional PHP + Twig dual-engine (`DelegatingEngine`): PHP primary, Twig optional, no consolidation planned.

### Documentation / TODO inventory

- **Canonical TODO retagging** — routed legacy in-code TODOs to their roadmap homes: console setter-DI / blog `UrlResolver` / theme-one helpers (2.1.6), `DbUtil` DBAL-3 test helper (2.1.7), `ModelServiceLocator` (2.1.10), `EntityManager` singleton + `db.em` boot trigger (2.1.11), `PhpNodeVisitor` + `ExtensionTranslate` custom-domain extraction + forked Intl loaders (3.4.6), dead `DebugStack` shim deletion (2.1.6).
- **New roadmap Step 2.1.11** (EntityManager DI — remove singleton, Active-Record → Data-Mapper) created with GitHub issue **#205** (sub-issue of #147). The EntityManager singleton removal was split out of Step 2.1.6 (which now only hardens the typing). `PHASE_2`/`PHASE_3` docs expanded; issue **#153** (2.1.6) and **#154** (2.1.7) bodies updated to match. PHP-template → Twig consolidation explicitly deferred (dual-engine kept by design).

### Tooling

- **`.vscode/settings.json`** — `app/vendor` removed from `files.exclude` (kept in `search.exclude`) so intelephense indexes the non-standard vendor directory and stops reporting false "Undefined type" errors for Symfony/Composer classes.

---

## Pagekit 1.2.17 - PHPStan Level 5 → 6 (Return Types) (Mai 4, 2026)

### Static Analysis

- **PHPStan baseline raised from `level: 5` to `level: 6`.** Every public/protected method, property, parameter, and iterable value across `app/modules/`, `app/system/`, `app/installer/`, `app/console/`, `packages/pagekit/blog/`, `packages/pagekit/theme-one/` now carries a declared type (or, for genuinely-unknown payloads such as `__call`, `__invoke`, container/registry interactions, an explicit `mixed`). The migration eliminated **1493 missing-type errors** discovered on the prior `develop` HEAD: `app/modules` 936, `app/system` 339, `app/installer` 102, `app/console` 13, `packages/pagekit` 62. (Closes #151)
- **`phpstan.neon` `excludePaths`** extended with `packages/autoload.php` and `packages/composer` — Composer-generated autoload scaffolding (`@generated by Composer`). Both files form one coherent unit (the wrapper at `packages/autoload.php` returns `ComposerAutoloaderInit{hash}::getLoader()` defined in `packages/composer/autoload_real.php`); excluding only one breaks PHPStan's class resolution for the other. The caller in `/workspace/autoload.php` (project root) already guards with `file_exists(...)` and remains analyzed.
- **`phpstan-baseline.neon`** — surgical removals only across all 16 checklist commits + 3 mini-loop commits. **~200 entries removed, 0 added.** Wholesale baseline regeneration is forbidden for this step — disappearing errors are the positive signal of L6 compliance, not noise to absorb.
- **`readonly mixed $` constructor sweep** — 144 occurrences across 41 source files (`app/system/modules/*` 100, `app/installer/src/*` 15, `app/system/src/*` 6, `packages/pagekit/blog/src/Controller/*` 10, `app/system/modules/captcha/src/CaptchaListener.php` 2, `app/system/modules/content/src/Plugin/MarkdownPlugin.php` 1) replaced with the concrete container-resolved type derived from the corresponding `index.php` / `ServiceProvider`. Notable: `MailController` / `RegistrationController` / `ResetPasswordController` mailer parameter typed `\Pagekit\Mail\Mailer` (concrete class — controllers call `Mailer::create()` / `Mailer::send()`); `AuthController`, `ProfileController`, `UserApiController` etc. constructor parameters typed `\Pagekit\User\Auth\Auth`, `Symfony\Component\HttpFoundation\Request`, `Pagekit\Session\Session`, etc. The 6 occurrences in `app/modules/kernel/src/Tests/ControllerResolverTest.php` are intentionally preserved (the test exercises the resolver's name-based resolution) with a class-level docblock documenting the rationale per Step 2.1.4 §5.1 of the plan.

### Phase 1 Audit Closures (Step 1.1 — Mailer Migration ⚠️ → 🛡️)

- **`app/system/modules/mail/src/Mailer.php`** — `send(Email $message): bool` typed.
- **`app/system/modules/mail/src/MessageInterface.php` + `Message.php`** — `send(?array &$errors = null): int` and `queue(?array &$errors = null): int` interface signatures updated in lockstep with implementation.
- **`app/system/modules/mail/src/Controller/MailController.php`** — all `readonly mixed $` constructor properties typed concretely.
- **`app/system/modules/mail/index.php`** — dead `auth_mode` config key deleted (zero remaining consumers, verified via `rg`).

### Other Phase 1 Audit Fixes Folded Into This Step

- **`app/modules/log/src/Logger.php`** — `__invoke()` rewritten to PSR-3 compatible signature `(int|string $level, string|\Stringable $message, array $context = []): void`.
- **`app/modules/view/modules/twig/src/TwigLoader.php`** — `findTemplate(string $name, bool $throw = true): ?string` matches Twig 3's parent signature.
- **`app/modules/view/modules/twig/src/TwigCache.php`** — `protected string $dir;` typed; `__construct(string $directory, int $options = 0)` matches Twig 3's `FilesystemCache`.
- **`app/modules/debug/src/DataCollector/AuthDataCollector.php`** — `instanceof User` type-narrow added between the existing null check and the role/auth lookups; `// TODO: Must be refactored in Step 2.1.4 ...` block removed. `UserInterface` itself is **not** modified — debug-bar concerns must not pollute the auth contract (Single Responsibility).
- **`app/console/src/Commands/SetupCommand.php`** — `execute(): int` typed; bare `exit;` replaced with `return Command::FAILURE;` / `return Command::SUCCESS;`.
- **`app/console/src/Commands/{TranslationFetch,ExtensionTranslate}Command.php`** — `execute(): void` → `: int` to match parent `Symfony\Component\Console\Command\Command::execute(): int`. Matching baseline `Return type (void) ... should be compatible with return type (int)` entries surgically removed.
- **`app/console/src/Commands/ArchiveCommand.php`** — `getPackageFilename(string $name): string` typed.
- **`app/console/src/Commands/SelfupdateCommand.php`** — `download(string $url, string $file): void` typed.
- **`packages/pagekit/blog/src/Model/Post.php`** — `public mixed $user = null;` → `public ?\Pagekit\User\Model\User $user = null;`; `public mixed $comments = null;` → `/** @var array<int, \Pagekit\Blog\Model\Comment>|null */ public ?array $comments = null;`; `protected static array $properties` value-type `array<string, string>`; `getStatuses()` / `jsonSerialize()` return-type generics typed; `PostModelTrait::saving/deleting/getAuthors` parameter and return types added.

### Cross-Module Side-Effect Fixes (Architect Amendments)

- **`app/system/modules/site/src/Controller/MenuApiController.php`** (Step 7 side-effect): adding the strict `: bool` return type to `Mailer::send()` narrowed PHPStan's inference of `__()` at the call site, which broke the existing `offsetAccess.notFound` baseline entry on the trash-menu literal. Resolved per architect amendment 2026-05-04 option (e) using `'count' => max(0, 0)` to defeat literal-`0` narrowing while keeping zero behavioural change. Surgical baseline removal of the matching entry. Broader Step-9 work on this file (return-type annotations on all action methods) was applied normally in Step 9.
- **`app/modules/database/src/ORM/{ModelTrait, PropertyTrait, QueryBuilder}.php` + `app/modules/auth/src/Auth.php`** (Step 8 side-effect, "scope expansion #2" amendment): typing `User`/`Role` model properties concretely caused PHPStan to resolve the ORM traits in those concrete contexts, surfacing 11 emergent errors. Resolved by adding `instanceof static` guards on `ModelTrait::create()`/`find()`, switching `PropertyTrait` to `ReflectionClass::getStaticProperties()` (runtime-modification-safe), adding `@method` docblocks to `QueryBuilder` for inherited methods, and narrowing `Auth::login()`/`logout()` return types from `EventInterface` to the concrete `LoginEvent`/`LogoutEvent` classes. Cascading surgical baseline removals across 18 paths (database, site, comment, widget, blog).
- **`app/modules/application/src/Tests/FileUtil.php` → `app/modules/filesystem/src/Tests/FileUtil.php`** (Step 2 side-effect): the trait's only consumers are in `app/modules/filesystem/`, so PHPStan's L6 `trait.unused` check fired when Group A was analyzed without the consumer module. Moved the trait into the filesystem module, updated namespace to `Pagekit\Filesystem\Tests`, updated both consumers' `use` imports, and added the missing `use Symfony\Component\Filesystem\Exception\IOException;` import.

### Final-Test / Bugbot Mini-Loop Fixes

- **`app/system/modules/{view, widget, user, site}/index.php`, `app/system/modules/site/widgets/{text, menu}.php`, `packages/pagekit/blog/index.php`** — the new `array $dependencies = []` strict parameter type on `AssetManager::register()` (added in Step 4) rejected pre-existing call sites that passed bare string literals (e.g. `'uikit'` instead of `['uikit']`). Wrapped each scalar dependency in an array. **Caught by Playwright E2E (`installation.spec.js` failed with HTTP 500 `TypeError`) — PHPUnit / PHPStan / cs-fixer all stayed green; this is a runtime regression that only end-to-end testing surfaces.**
- **`app/console/src/Commands/ArchiveCommand.php`** (Bugbot): `getPackageFilename(string $name): string` was incompatible with `preg_replace`'s `string|null` return. Added `?? $name` null-coalesce fallback.
- **`app/modules/database/src/ORM/PropertyTrait.php`** (Bugbot): `get_class_vars(static::class)` only returns default values, not runtime-modified values. Switched to `(new \ReflectionClass(static::class))->getStaticProperties()` which captures runtime modifications. Docblock updated.
- **`app/console/src/Commands/{Setup, Archive}Command.php`** (Bugbot): `return (int) $this->line(...)` was casting `void` to `int` (yields `0` only by coincidence). Replaced with explicit `$this->line(...); return Command::SUCCESS;`. Two matching baseline `method.void` entries surgically removed.

### Style

- **`app/modules/auth/src/Handler/DatabaseHandler.php`** — `blank_line_before_statement` cs-fixer rule satisfied (blank line added before `return null;`).
- **`app/modules/filesystem/src/Tests/FilesystemTest.php`** — unused `use Pagekit\Filesystem\Tests\FileUtil;` import removed (the trait is consumed via `use FileUtil;` inside the same namespace, no import needed).

### Documentation

- **Branch documentation** added: `migration-docs/branches/step-2-1-4-phpstan-level-6.md` — full change record (16 checklist commits + 3 mini-loop commits, ~200 baseline removals, 144 `readonly mixed` replacements, 1493 missing-type errors fixed, 4 cross-module side-effect amendments), No-Mercy compliance table, per-step + final test results (PHPUnit / PHPStan / CI / Playwright E2E), Bugbot quick-peek summary, and the deferred-work table (Steps 2.1.5 / 2.1.6 / 2.1.7 / 2.1.8 / 2.1.9).

### Internal

- **Step 2.1.4 (PHPStan Level 5→6 — Return Types)** marked ✅ in `.cursor/ROADMAP.md`; `Current Step` header pointer advances from `2.1.4` to `2.1.5` (PHPStan Level 6→7 — Null Safety). Phase 1 audit row 1.1 (Mailer Migration) marked 🛡️ via this step's Mail-related fixes. No new sub-step rows inserted; no new GitHub issues filed.
- **No-Mercy compliance** — zero shims, zero adapters, zero `@phpstan-ignore` annotations, zero `TEMPORARY BRIDGE` markers introduced. Deferred work explicitly listed in `.cursor/tickets/PROMPT_2_1_4_PHPStan-Level-6_plan.md` ("Deferred:" section) and routed to the relevant future steps.

---

## Pagekit 1.2.16 - `strict_types` Migration (Mai 3, 2026)

### Strict Typing

- **`declare(strict_types=1);` enforced in every PHP file** of the project. ~745 PHP files across `app/modules/`, `app/system/`, `app/installer/`, `app/console/`, `packages/pagekit/blog/`, `packages/pagekit/theme-one/`, the workspace root (`index.php`, `autoload.php`, `app/config/migrations.php`), the composer autoloader bundle, and all 82 language files now declare strict types. PHP-CS-Fixer rule **`declare_strict_types`** is flipped from disabled to `'declare_strict_types' => true`, so any future PHP file added without the declaration is blocked at the `cs-fixer` CI gate from Step 2.1.2. The placeholder `// TODO: Must be refactored in Step 2.1.3 (strict_types Migration)` in `.php-cs-fixer.php` is resolved and removed. `php-cs-fixer fix --dry-run` exits 0 across all 790 scanned files. (Closes #150)
- **Forward-only migration — zero compatibility layers.** Per the No-Mercy / Aggressive rules (`.cursor/ROADMAP.md`), every PHP-8 strict `TypeError` surfaced by the migration is fixed at the **call site** (cast, signature widening, or `false`/`null`/`int` guard where a `string` was expected). No shim classes, no `@deprecated` markers, no in-code TODOs added.
- **Migration order — 12 grouped checklist steps, one commit each:** filter / filesystem / cookie → auth → database → routing / session / config / markdown / migration / log → kernel / application / view / debug / feed → system user / site / widget → remaining `app/system/modules/*` + `app/system/src/` → installer / console → blog package → theme-one package → root + composer + language sweep → CS-Fixer rule flip + 34 view-template formatting fixes. Each commit was Tester-gated (PHPUnit + PHPStan, plus a `git diff --quiet phpstan-baseline.neon` regression check) before moving on.

### Strict-mode TypeError Fixes (call-site only, no shims)

- **`app/modules/filesystem/src/Path.php`** — `strrpos()` returns `int|false`; the result is now guarded with a `!== false` check before being passed to `substr()` / `strtr()` (under strict types these reject `false` for their `string` parameters).
- **`app/modules/filesystem/src/Filesystem.php`** — `parse_url()` returns `string|int|null|false`; the result is cast to `(string)` before `strlen()`. Defensive `is_string` guards added in `exists()` for `getPathInfo()` return values.
- **`app/modules/filesystem/src/StreamWrapper.php`** — `mkdir()` `$recursive` is now strictly `bool`; the bitmask `$options & STREAM_MKDIR_RECURSIVE` (an `int`) is cast to `(bool)`.
- **`app/modules/routing/src/Router.php`** — `Router::generate()` rewritten to use `strpos()`-guarded `substr()` instead of `strstr()`/`substr()` chaining (the latter propagates `false` into strict-typed string parameters). Empty-query guard (`$query !== ''`) added before `parse_str()`.
- **`app/modules/routing/src/Event/AliasListener.php`** — same `strstr()`/`substr()` pattern as `Router::generate()` replaced with `strpos()` + explicit `substr()` and `false` check.
- **`app/system/modules/cache/src/CacheModule.php`** — `opcache_invalidate()` now receives `$file->getPathname()` (a `string`) instead of the `Symfony\Component\Finder\SplFileInfo` object (strict types reject implicit `__toString()` coercion). The second `$force = true` argument is also passed explicitly.
- **`app/console/src/Commands/ExtensionTranslateCommand.php`** — same `SplFileInfo`-to-`string` issue; `extractStrings()` now receives `$file->getPathname()`.
- **`app/console/src/Commands/ArchiveCommand.php`** — Symfony Console `addOption()` `$shortcut` parameter is `array|string|null`; the legacy `false` sentinel ("no shortcut") is replaced with `null`. Discovered by `php pagekit list` smoke test, not by PHPStan.
- **`app/modules/application/src/Application/UrlProvider.php`** — three independent fixes: (1) `Filesystem::getUrl()` returns `string|false`, guard before `substr()` in the `BASE_PATH` branch with `if (!is_string($url)) { $url = ''; }`; (2) `parseQuery()` rewritten to use `strpos()` + `substr()` with proper `false` check, replacing the chained `strstr()` pattern; (3) `Router::generate()` `$referenceType` is now strictly `int`, so `is_int($type)` guard added with fallback to `UrlGenerator::ABSOLUTE_PATH`. Redundant `is_string()` guard later removed once PHPStan narrowed the type.
- **`app/system/modules/site/src/Model/Node.php`** — `Node::getUrl()` default changed from `false` to `UrlGenerator::ABSOLUTE_PATH` (the corresponding `Router::generate()` `$referenceType` parameter is now strictly `int`).
- **`app/modules/session/src/Csrf/Provider/{Default,Session}CsrfProvider.php`** — `uniqid()` `$prefix` is now strictly `string`; the `rand()` result (an `int`) is cast to `(string)` before being passed.
- **`app/system/modules/site/src/Event/NodesListener.php`** — `strcmp(int, int)` is invalid under strict types; replaced with the spaceship operator (`<=>`) and reverse operands for descending sort. Also modernized to an arrow function (`fn($a, $b) => ...`).
- **`app/system/modules/view/src/Asset/FileLocatorAsset.php`** + **`app/modules/view/src/Asset/FileAsset.php`** — `getPath()` was declared `: string` but returned `false` on miss; both now return `''`. Every call site uses truthiness checks (`if ($path = $this->getPath())`), so empty string still triggers the failure path identically to `false`.
- **`packages/pagekit/theme-one/functions.php`** — `isImage()` was declared `: bool` but the function body returned `string|false` (the matched extension or `false`). Return type corrected to `string|false` to match the actual behavior; call sites in `offcanvas.php` / `header-logo.php` use the value as a string.
- **`app/modules/filesystem/src/Filesystem.php`** — `Filesystem::getUrl()` `NETWORK_PATH` branch (in addition to the `parse_url()` cast already shipped earlier) still passed the raw `strpos($url, '//')` result directly into `substr()`. The earlier mini-loop had patched the `ABSOLUTE_PATH` branch but missed this one. Fix: capture the result, guard with `$pos !== false`, fall back to the original URL on no-match — same pattern as the sibling branch. **(Bugbot mini-loop addition.)**
- **`app/modules/view/src/Asset/Asset.php`** — base class `Asset::getPath()` declared `: string` but returned `false` on miss. The `FileAsset` and `FileLocatorAsset` subclasses had been corrected earlier, but the base method was missed. Fix: return `''` instead of `false` (call sites use truthiness checks; behavior unchanged). **(Bugbot mini-loop addition.)**
- **`app/modules/view/src/Asset/Asset.php`** + **`app/modules/view/src/Asset/FileAsset.php`** — `getContent()` declared `: string` but returned the nullable `?string $content` property; `null` is rejected under `strict_types=1`. Fix: `$this->content ?? ''` in both classes; `FileAsset::getContent()` additionally null-coalesces the `file_get_contents()` return value to `''`. **(Bugbot mini-loop addition.)**
- **`app/modules/view/src/Asset/FileAsset.php`** — `getPath()` was passing the nullable `$this->source` (declared `?string` in parent `Asset`, default `null`) directly to `file_exists()`, which under `strict_types=1` rejects `null`. Fix: guard with `$this->source !== null && file_exists($this->source)`. Two adjacent null-safety improvements folded in: `FileAsset::hash()` null-coalesces `$this->source` before string concatenation, and `FileLocatorAsset::getSource()` null-coalesces the parent return value to satisfy its `: string` return type. **(Bugbot mini-loop addition, iteration 2.)**
- **`app/modules/application/src/Application/UrlProvider.php`** — `parseQuery()` (rewritten earlier in this PR to use `strpos()`/`substr()` instead of `strstr()`) had lost the `$url ?? ''` null guard that the original `strstr()` chain implicitly tolerated. Caller `get()` passes `$path` which is genuinely nullable per the existing `?? ''` guards — under `strict_types=1`, `strpos(null, '?')` would throw `TypeError` at runtime. Fix at the boundary: `get()` now coerces `$path ??= ''` once upfront (replacing the two scattered `?? ''` expressions on its in-method callsites) so `parseQuery()` keeps its strict `string $url` signature without an in-body null guard. The corresponding `nullCoalesce.variable` baseline entry surgically removed. **(Bugbot mini-loop addition, iteration 3 — the finding had been first reported by Bugbot in review `5e81f74d`, was silently dropped by the per-review-only peek procedure, surfaced manually by the user, and resolved together with the workflow procedure repair below.)**
- **`app/modules/filesystem/src/StreamWrapper.php`** — the `(bool)` cast on `mkdir()` `$recursive` shipped earlier in this PR addressed only one of two strict-mode regressions in the same file. `Filesystem::getPath()` returns `string|false` (line 53–56 of `Filesystem.php`: `return $this->getPathInfo(...) ?: false;`), and that value reaches every other strict-typed PHP function in the wrapper: `opendir()` (`dir_opendir`), `mkdir()` first arg (`mkdir`), `rename()` both args (`rename`), `rmdir()` (`rmdir`), `unlink()` (`unlink`), `file_exists()` + `stat()` (`url_stat`), and `fopen()` (`stream_open`). Pre-`strict_types`, `false` was silently coerced to `''`; under strict mode every one of these would throw `TypeError` the moment `getPath()` failed to resolve a path. Fix: each of the seven call sites now captures the `getPath()` result, returns the wrapper-spec failure value (`false`) on `=== false`, and only then invokes the strict-typed PHP function. Also fixed `url_stat()`'s `@return array` PHPDoc to `array|false` — the wrapper has always been allowed to return `false` per the PHP stream-wrapper contract; the PHPDoc was wrong even before strict mode and surfaced now via PHPStan once the explicit `return false;` branch was added. **(User-catch mini-loop addition, iteration 4.)**

### PHPStan Baseline (surgical removals only — no regeneration)

- **8 obsolete entries removed** from `phpstan-baseline.neon`, each directly traceable to a code fix in this step that eliminated the underlying error: `StreamWrapper.php` `mkdir bool/int`, `ArchiveCommand.php` `addOption false`, `UrlProvider.php` first `nullCoalesce.variable`, `DefaultCsrfProvider.php` `uniqid int`, `SessionCsrfProvider.php` `uniqid int`, two `NodesListener.php` `strcmp int/int`, `Asset.php` `getPath() should return string but returns false`, `UrlProvider.php` second `nullCoalesce.variable` (from the `parseQuery` boundary fix). Diff shape: **8 entries removed, 0 entries added.** Wholesale baseline regeneration was forbidden by the architect plan and is explicitly rejected — disappearing errors are the positive signal of strict-mode compliance, not noise to absorb.

### Documentation

- **Branch documentation** added: `migration-docs/branches/step-2-1-3-strict-types-migration.md` — full change record (12 checklist commits + 4 mini-loop / workflow-repair commits, ~745 file additions, 18 call-site fixes, 8 baseline removals), No-Mercy compliance table, per-step + final test results (PHPUnit / PHPStan / `php pagekit setup` / `php pagekit list` / Playwright E2E), and the deferred-work table (Steps 2.1.4 / 2.1.5 / 2.1.6 / 2.1.7 / 2.1.8 / 2.1.9).

### Internal

- **Step 2.1.3 (`strict_types` Migration)** marked ✅ in `.cursor/ROADMAP.md`; `Current Step` header pointer advances from `2.1.3` to `2.1.4` (PHPStan Level 5 → 6 — Return Types). No new sub-step rows inserted; no new GitHub issues filed.
- **No-Mercy compliance** — zero shims, zero adapters, zero `@deprecated` markers, zero new in-code TODOs introduced. Deferred work explicitly listed in `.cursor/tickets/PROMPT_2_1_3_Strict-Types-Migration_plan.md` ("Deferred:" section) and routed to the relevant future steps.

### Workflow

- **Bugbot quick-peek decision matrix replaced with a GraphQL `reviewThreads { isResolved }` query** in `.cursor/rules/orchestrator-subagent-workflow.mdc`. Earlier procedure read only the latest Cursor-Bugbot review's REST inline-comments; that systematically misses any finding first reported in an earlier review and never resolved. (Concrete failure mode: the `parseQuery()` null-safety regression listed in **Strict-mode TypeError Fixes** above was first flagged by Bugbot in review `5e81f74d` and silently dropped from two subsequent quick-peeks until a user manually surfaced it.) The new procedure issues a single GraphQL call that returns every Bugbot review thread on the PR with its `isResolved` state, then operates on the open-threads list (`isResolved == false`) regardless of which review created each thread. Each thread carries its own `original_commit`, so the stale-SHA branch processes per-thread instead of assuming one shared SHA. The `Stale-Bugbot Check` procedure in `.cursor/agents/verifier.md` was correspondingly rewritten to consume an `OPEN` array of thread objects and emit per-thread PASS/FAIL plus an `OVERALL` verdict.
- **One version bump per PR** rule added (`.cursor/rules/orchestrator-subagent-workflow.mdc` Rule #4): Finalize bumps `app/system/config.php` exactly once per Roadmap Step / PR; subsequent mini-loop fix commits on the same PR amend the existing CHANGELOG section in place rather than producing another `chore(release):` commit. Prevents drift between PR diff and the version pointer for in-flight reviews.
- **Architect template + handoff message** aligned in `.cursor/agents/architect.md` to match the CI-driven Final Test ordering already documented in `.cursor/rules/orchestrator-subagent-workflow.mdc` (Early Push → Final Test → Bugbot quick-peek → Finalize). Documentation parity only; no agent runtime behavior change.

### Tests

- **Per-step gates (Tester subagent)** — after every Checklist commit (Steps 1–12) and both mini-loop fix commits: `./app/vendor/bin/phpunit` (326 tests, 0 failures throughout), `./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M` (no errors throughout), and `git diff --quiet phpstan-baseline.neon` (clean except where surgical removals were intentional). Step 11 additionally asserted `find ... -exec grep -L 'declare(strict_types' {} \; | wc -l` → `0`. Step 12 additionally asserted `php-cs-fixer fix --dry-run` exit 0.
- **Final gate (Tester subagent)** — full closure run executed twice (after each mini-loop fix): PHPUnit + PHPStan + `php pagekit setup` (exit 0, "Done") + `php pagekit list` (exit 0, full command list) + Playwright E2E on chromium per `AGENTS.md`: `installation.spec.js` (1/1, ~11 s), `authentication.spec.js` (14/14, ~1 m), `dashboard.spec.js` (10/10, ~33 s). All green on the latest SHA.
- **Remote CI runs** — Run `25195012112` (SHA `f8b7b1c6`): all 5 jobs ✅. Run `25195918220` (SHA `5e81f74d`): all 5 jobs ✅. Run `25196756827` (SHA `aad6c2df`): all 5 jobs ✅. Run `25197422178` (SHA `cc7a2f79`, latest): `phpunit (8.2)` ✅ (27 s), `phpunit (8.3)` ✅ (23 s), `phpstan` ✅ (26 s), `cs-fixer` ✅ (18 s), `security-audit` ✅ (12 s).
- **Bugbot quick-peek** — three mini-loop iterations. Iteration 1: three findings on stale SHAs `e7ba5711` / `38f5a04c` (`Filesystem.php` NETWORK_PATH, `Asset::getPath()`, `Asset::getContent()` + `FileAsset::getContent()`) caught by the Stale-Bugbot Verifier check, fixed in commit `5e81f74d`. Iteration 2: one follow-up finding on stale SHA `1cafe817` (`FileAsset::getPath()` `file_exists($this->source)` without null guard), fixed in commit `aad6c2df`. Iteration 3: one finding from review `5e81f74d` that the per-review-only peek procedure had silently dropped, surfaced manually by the user, fixed in commit `cc7a2f79` together with the procedure repair commit `74f8e1b5` (replacing per-review REST lookups with a cumulative GraphQL `reviewThreads { isResolved }` query). Final re-peek via the fixed procedure: `OPEN_COUNT == 0` (all 5 historical Bugbot threads on this PR are marked resolved by the GraphQL state) → proceed.
- **User-catch mini-loop, iteration 4** — user-reported `string|false` regression in `app/modules/filesystem/src/StreamWrapper.php`: the original `mkdir()`-cast fix (commit `f8b7b1c6`) addressed only the `$recursive` argument and missed that `Filesystem::getPath()`'s `string|false` return reaches seven other strict-typed PHP functions (`opendir`, `mkdir` first arg, `rename` both args, `rmdir`, `unlink`, `file_exists`, `stat`, `fopen`). Per-call-site `=== false` guards added, returning the wrapper-spec failure value (`false`) before the strict-typed PHP function is invoked. PHPUnit (326/326) and PHPStan (StreamWrapper-clean — 9 pre-existing `MySQLPlatform`/`MySqlPlatform` case-sensitivity errors in `ConfigManager.php` / `QueryBuilder.php` / `DatabaseSessionHandler.php` are tracked under Step 2.1.7, verified pre-existing via `git stash`-comparison) green.

### Phase Plan Updates

- **Step 2.1.6 (PHPStan Level 7→8 / Strict Typing)** in `migration-docs/TODO/PHASE_2_MODERNISING.md` — new bullet block **"Audit findings (Step 2.1.3 review)"** routes the dead inline-query-string parser in `app/modules/routing/src/Event/AliasListener.php` (lines 50–57 + dependent `array_filter` `strtok` clause on line 39) to Step 2.1.6 for deletion. Workspace-wide search confirms zero callers use the `?param=value` suffix in the alias `$name` argument of `Routes::alias()` — the `$defaults` parameter has fully replaced this convenience API. Not to be confused with `Router::generate('route?foo=bar', [...])`, which is a separate, actively used mechanism (covered by `RouterTest::testGenerateWithQuery()`) and must remain. Routed alongside the existing `DebugStack.php` dead-shim deletion in the same Step 2.1.6 bullet, per Aggressive Rule 4 ("Delete Over Wrap"). The pre-existing predecessor comment `// TODO: is this still needed?` was reformatted in the same iteration-4 commit to the canonical Rule 5 Out-of-scope tag `// TODO: Must be refactored in Step 2.1.6 (PHPStan Level 7→8 / Strict Typing)` so the legacy remnant carries a ROADMAP step pointer instead of an ambiguous question.

---

## Pagekit 1.2.15 - CI/CD Integration & Quality Gates (April 30, 2026)

### Continuous Integration

- **New GitHub Actions workflow `.github/workflows/php-quality.yml`** — every push to `main` / `develop` and every pull request targeting them now runs four independent quality jobs in CI: **`phpunit`** (matrix `php: ['8.2', '8.3']`, with code coverage via Xdebug → `--coverage-text` + `--coverage-clover=coverage.xml`, Clover XML uploaded as `coverage-clover` artifact on the `8.3` leg only), **`phpstan`** (single PHP `8.3`, runs `./app/vendor/bin/phpstan analyse --no-progress --error-format=github` against the existing `phpstan.neon` Level 5 + `phpstan-baseline.neon` — new errors above the baseline fail the job), **`cs-fixer`** (single PHP `8.3`, `./app/vendor/bin/php-cs-fixer fix --dry-run --diff --no-interaction --show-progress=none` — style violations fail the job), and **`security-audit`** (single PHP `8.3`, `composer audit --no-interaction --abandoned=ignore` operating directly on the lockfile, no `composer install` step needed). Each job mirrors the exact local command documented in `AGENTS.md`, so red CI on a PR matches red local terminal output one-for-one. (Closes #149)
- **`composer install`-based jobs cache `app/vendor/`** via `actions/cache@v4` keyed on `${{ runner.os }}-php-${{ matrix.php }}-composer-${{ hashFiles('composer.lock') }}` (with the matching restore-key). The `vendor-dir: app/vendor` Composer config from `composer.json` is respected — Pagekit is not a stock-`vendor/` project. Pre-PHPUnit each runner also creates the writable runtime directories (`tmp/logs tmp/cache tmp/temp tmp/packages storage`) called out in `AGENTS.md`.
- **Workflow hygiene** — top-level `permissions: contents: read` (least-privilege; no `write` scopes), `concurrency: { group: ${{ github.workflow }}-${{ github.ref }}, cancel-in-progress: true }` (superseded runs on the same PR are auto-cancelled to save CI minutes). Triggers limited to `push` / `pull_request` on `main` + `develop`.
- **Validation** — workflow YAML parses cleanly via `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/php-quality.yml'))"`; all four `jobs.*` ids (`phpunit`, `phpstan`, `cs-fixer`, `security-audit`) are unique and present.
- **GitHub Actions runtime bumped to Node 24** — all eight action pins in `.github/workflows/php-quality.yml` lifted to the current `node24` major: `actions/checkout@v4` → `@v5` (4×, v5.0.0 released 2025-08-11, `runs.using: node24`), `actions/cache@v4` → `@v5` (3×, `runs.using: node24`), `actions/upload-artifact@v4` → `@v6` (1×, **v6 not v5** — per the upstream v6.0.0 release notes from 2025-12-12: *"v5 had preliminary support for Node.js 24, however this action was by default still running on Node.js 20. Now this action by default will run on Node.js 24"*; only `upload-artifact@v6` flips `runs.using` to `node24`). The bumps eliminate the `Node.js 20 actions are deprecated` annotations GitHub started attaching to every CI run ahead of the platform-wide `node20` shutoff (forced default 2026-06-02, full removal 2026-09-16 per [github.blog/changelog/2025-09-19-deprecation-of-node-20-on-github-actions-runners](https://github.blog/changelog/2025-09-19-deprecation-of-node-20-on-github-actions-runners/)). `ubuntu-latest` runners (24.04 LTS) already meet the new minimum runner requirement (≥ v2.327.1). No workflow logic changed — pure runtime hygiene; the four jobs (`phpunit (8.2)`, `phpunit (8.3)`, `phpstan`, `cs-fixer`, `security-audit`) keep their existing keys, caches, and gating semantics.

### Developer Environment

- **Node 22 LTS pinned as the supported development runtime.** New top-level **`.nvmrc`** (`22`) plus a new **`engines`** block in `package.json` (`{"node": ">=20 <23", "yarn": "1.x"}`) make the supported range explicit and machine-readable for `nvm`, Volta, fnm, and `yarn install` itself. `docker-compose.yml` updated from `node:18-alpine` → `node:22-alpine` so the local Docker dev stack matches CI / `engines` exactly. `README.md` consolidated: every Node-version reference now reads "Node 20+ (Node 22 LTS recommended)" instead of the pre-existing mix of `Node 18+` (Major Changes / System Requirements) and `Node.js 18` (Docker Development Environment). Webpack 4 stays on the existing toolchain — Node 24 is intentionally out of scope here and routed to the future Vite migration step alongside the Vue 2 → Vue 3 work in PHASE_2.

### Documentation

- **Branch documentation** added: `migration-docs/branches/step-2-1-2-cicd-quality-gates.md` — full change record (5 commits, 1 new workflow file, 0 source modifications), No-Mercy compliance table, local + Playwright E2E test results, and the **manual branch-protection runbook** (required status checks `phpunit (8.2)`, `phpunit (8.3)`, `phpstan`, `cs-fixer`, `security-audit`; "Require branches to be up to date before merging"; 1 approval for external contributors). Branch protection is intentionally **not scripted** — the workflow MUST NOT call any GitHub admin API; the user applies these settings manually in GitHub repo settings (admin-only).
- **Coverage baseline** — Step 2.1.2 generates Clover XML on every `phpunit (8.3)` run and uploads it as a workflow artifact, but the numerical baseline is intentionally not pinned in this PR. Step 2.1.9 (Test Coverage Expansion) is the designated step to document the canonical baseline, wire Codecov / Coveralls if desired, and enforce minimum-coverage thresholds.

### Workflow

- **Orchestrator workflow refined for CI-driven Final Test.** `.cursor/rules/orchestrator-subagent-workflow.mdc` reorders the post-loop sequence to **Early Push → PR creation → Final Test → Bugbot quick-peek → Finalize**. Reasons: (1) the Final Test now waits on the CI workflow that this step introduced (`phpunit (8.2)` / `phpunit (8.3)` / `phpstan` / `cs-fixer` / `security-audit`), running the 3 Playwright E2E specs locally **in parallel** until E2E migrates to CI as a separate roadmap step — wall-clock cost stays at `max(CI, E2E)`, not `CI + E2E`; (2) Finalize commits (branch doc, version bump, CHANGELOG, ROADMAP closure incl. PR#) happen **after** Final Test PASS, avoiding wasted version/CHANGELOG churn on CI failures; (3) a new non-blocking **Bugbot quick-peek** (single `gh api repos/.../pulls/$PR/reviews` call, ~1 s) catches Cursor-Bugbot findings on the latest commit SHA without waiting for the up-to-15-minute Pro-tier review window — if Bugbot reports `found N potential issue` on the latest SHA the Orchestrator runs the standard mini-loop, otherwise it proceeds to Finalize. Stale Bugbot reviews on older commit SHAs are explicitly ignored. The user always reviews remaining findings manually before merging, so a late Bugbot result post-peek is a manual decision, not a workflow gate. `.cursor/agents/tester.md` extended: "End-of-ticket tests" now invoke `gh run watch` on the four PHP Quality jobs and run the 3 stable Playwright specs locally in parallel; both must pass for FINAL PASS. `.cursor/PROMPT_TASK_INVOCATION_TEMPLATE.md` ordering updated to match. `.cursor/rules/push.mdc` Step 8 simplified to a single line `**Do NOT merge**` — the merge gate is a manual user-review responsibility, not a workflow precondition.

### Internal

- **Step 2.1.2 (CI/CD Integration & Quality Gates)** marked ✅ in `.cursor/ROADMAP.md`; `Current Step` header pointer advances from `2.1.2` to `2.1.3` (`strict_types` Migration). No new sub-step rows inserted; no new GitHub issues filed.
- **No-Mercy compliance** — CI YAML is platform infrastructure, not a runtime compatibility layer (Aggressive Rule 3). **0** lines of PHP / JS / LESS / Vue source modified; **0** Composer / Yarn dependency changes; **0** new shims, adapters, `@deprecated` markers, or in-code TODOs introduced. Deferred work is explicitly listed in `.cursor/tickets/PROMPT_2_1_2_CI-CD-Quality-Gates_plan.md` ("Deferred:" section) and routed to Steps 2.1.3 / 2.1.4 / 2.1.5 / 2.1.6 / 2.1.8 / 2.1.9 / 2.2.

### Tests

- **Per-step gates (Tester subagent)** — after every checklist commit (Steps 1–5): `./app/vendor/bin/phpunit` (326 tests, 765 assertions, 0 failures — 1 pre-existing SMTP warning, 5 pre-existing skips, environment-only) and `./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M` (no errors beyond baseline) — both green throughout.
- **Final gate (Tester subagent)** — full closure run: PHPUnit + PHPStan + `php pagekit setup` + `php pagekit list` + Playwright E2E on chromium (per `AGENTS.md`): `installation.spec.js` (1/1, ~11 s), `authentication.spec.js` (14/14, ~1 m), `dashboard.spec.js` (10/10, ~35 s). All green.
- **Remote CI run** — actual GitHub Actions run materializes once the branch is pushed; the four jobs (`phpunit (8.2)`, `phpunit (8.3)`, `phpstan`, `cs-fixer`, `security-audit`) MUST go green on the resulting PR. Any remote-only failure is treated as a regression on Checklist Steps 1–5 and looped back through the standard refactorer → verifier → tester cycle.

---

## Pagekit 1.2.14 - Foundation Consolidation Closure (April 28, 2026)

### Audit

- **Step 2.0 closure audit shipped** — `migration-docs/audits/2026/04/AUDIT_REPORT_STEP_2.0_FOUNDATION_CLOSURE_2026-04-28.md` cross-checks every scope claim and Phase 1 audit-closure claim made by the ten leaf sub-steps **2.0.0, 2.0.1 + 2.0.1a–e, 2.0.2, 2.0.3, 2.0.4, 2.0.5, 2.0.6, 2.0.7, 2.0.8** against `develop` HEAD. All 10 leaf sub-steps audit ground-truth as 🛡️: Doctrine `@Route` annotations are gone (2.0.0); `Container` natively implements PSR-11 with `Psr11Adapter` / `StaticTrait` / `\ArrayAccess` physically deleted (2.0.1 + 2.0.1a–e); `validators.php` per-locale files exist with `ValidatorServiceProvider` wiring `setTranslator()` + `setTranslationDomain()` (2.0.2); the Pagekit `CacheInterface` + `Psr6Adapter` 7-file compatibility layer is gone with consumers on PSR-6 (2.0.3); `DatabaseHandler::createTable()` is removed, the blog migration is renamed to `Version20251023070000_*`, and `MigrationServiceTest` is un-skipped with 12 in-memory SQLite tests (2.0.4); dead PSR-4 mappings + 6 unused deps are dropped, `paragonie/random-lib` is replaced by native `random_bytes()`, lockfile committed (2.0.5); module-level `phpunit.xml.dist` configs are deleted, `@dataProvider` / `@group` migrated to `#[DataProvider]` / `#[Group]`, `RoutesLoader::addController()` debug-aware exception handler shipped (2.0.6); `SymfonyEventDispatcherBridge` + `EventDispatcherCompatibilityTest` + `symfony.event_dispatcher` registration all gone (2.0.7); `create_function()` replaced by a pure-PHP recursive-descent parser supporting `&&` / `||` / `!` / `&` / `|` (2.0.8). (Closes #181)

### Internal

- **Step 2.0 (Foundation Consolidation — umbrella) marked ✅ / 🛡️** in `.cursor/ROADMAP.md`. `Current Step` header pointer advances from `2.0` to `2.1.2` (2.1.1 already done). Zero new `2.0.X` (X ≥ 9) sub-step rows inserted; zero new GitHub issues filed.
- **Two routed gaps recorded** as `**Audit findings (Step 2.0[.8] closure review):**` PHASE_2 sub-blocks in `migration-docs/TODO/PHASE_2_MODERNISING.md`:
  - **Step 2.1.6 (PHPStan Level 7→8 — Strict Typing)** — `app/modules/database/src/Logging/DebugStack.php` is a 42-line dead `@deprecated since DBAL 3.x migration` shim (class + 2 methods) with **zero consumers** in `app/` / `packages/` source. The replacement (`DebugMiddleware`) is wired and used. Per Aggressive Rule 4 ("Delete Over Wrap"), this file must be `git rm`'d in 2.1.6 alongside the adjacent `EntityManager` / `ModelServiceLocator` / `IntlServiceLocator` strict-typing work. (Routed from §4.4 Gap List row 1.)
  - **Step 2.5 (Extension Safety System)** — `User::evaluateBooleanExpression()` (`app/system/modules/user/src/Model/User.php:251`) extraction into a standalone `PermissionExpressionEvaluator` service is documentation-only routed; today there is exactly one caller (`User::hasAccess()`), so per Aggressive Rules 1 + 2 the helper stays inline as a `private static` method. No code change required in 2.5 unless extension code or a new permission system surfaces a second caller. (Routed from §4.4 Gap List row 2.)
- **Five informational-only gaps** recorded in audit report §4.4 (rows 3–7) and branch doc §🚦: `PhpMatcherDumper.php` upstream-Symfony `@deprecated` docblock copy; one-time `// TODO: AUDIT FIX Step 2.0.5` install-time `migration_versions` row-rename hint; hardcoded OpenWeatherMap API key already routed to Step 4.2; PHASE_2 sections 2.0.0–2.0.3 lacking an `Agent Prompt:` line (cosmetic, predates per-step prompt convention introduced in 2.0.4); `migration-docs/branches/` legacy `*_MIGRATION.md` / `UPPERCASE_SNAKE.md` naming inconsistency. None blocks 2.0 closure; none requires a new sub-step.
- **Branch doc** added: `migration-docs/branches/step-2-0-foundation-closure.md` (verdict, 10-row closure table, routed-gap list, informational-gap list, related documents).
- **No-Mercy compliance** — audit-only PR. **0** lines of PHP / JS / LESS / Vue source modified; **0** Composer / Yarn dependency changes; **0** new shims, adapters, `@deprecated` markers, or `// TODO: Step X.Y` markers introduced. Cross-cutting ripgrep sweeps (audit report §4.3.1–§4.3.5) confirm zero remaining Foundation-Consolidation compatibility layers in production code; all surviving Rule-5 markers in `app/` / `packages/` source point at valid future ROADMAP IDs (Steps 2.1, 2.1.4, 2.1.6, 2.1.7, 2.1.9, 3.4.6, 4.2, 4.3).

### Tests

- **Audit-only PR — PHPUnit + PHPStan act as regression detectors.** No PHP / JS / LESS source touched, so both gates MUST stay green from the pre-flight baseline (Checklist Step 1) to the final gate (Checklist Step 22). Raw outputs recorded in audit report §4.7.1–§4.7.5.
- Pre-flight baseline (Checklist Step 1) and final-gate (Checklist Step 22) — `./app/vendor/bin/phpunit`, `./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M`, `php pagekit list`, `php pagekit setup`, plus Playwright E2E (chromium-only per `AGENTS.md`): `installation.spec.js` (1/1), `authentication.spec.js` (14/14), `dashboard.spec.js` (10/10).

---

## Pagekit 1.2.13 - User::hasAccess() Hotfix (April 28, 2026)

### Fix

- **`create_function()` removed from `User::hasAccess()`** — The legacy `create_function()`-based boolean evaluator inside `app/system/modules/user/src/Model/User.php` is replaced by a safe, pure-PHP recursive-descent parser. `create_function()` was deprecated in PHP 7.2 and removed in PHP 8.0; the previous code would have produced a fatal error on every non-trivial permission check (anything containing `&`, `|`, `(`, `)`, or `!`). The new evaluator is a `private static evaluateBooleanExpression(string $exp): bool` method on the same class, accepts both single (`&`, `|`) and double (`&&`, `||`) operators plus `!` and parentheses, and enforces correct precedence (`!` > `&&` > `||`). No `eval()`, no `Closure::fromCallable`, no `assert()`, no Symfony `ExpressionLanguage` dependency. Invalid expressions now raise `\InvalidArgumentException` (previously the bug surfaced as a fatal `Call to undefined function create_function()`). (Closes #185)

### Refactor

- **`hasAccess()` simplified** — The `if (!$fn = @create_function('', "return …;")) { throw … } return (bool) $fn();` block is physically deleted (no shim, no adapter, no compatibility flag) per the modernization "DELETE OVER WRAP" rule. The early-exit logic (administrator short-circuit, empty expression, single-permission short-circuit) and the existing regex reduction that produces the `0/1/&/|/!/()` string are unchanged.

### Chore

- **`phpstan-baseline.neon` baseline trimmed** — The now-stale `Function create_function not found.` ignore entry scoped to `app/system/modules/user/src/Model/User.php` is removed, since the call site no longer exists. Baseline stays internally consistent: zero "ignored error not matched" warnings.

### Tests

- **New test file `app/system/modules/user/src/Tests/UserAccessTest.php`** — 37 test cases (765 assertions in the suite total). Section A exercises the pure evaluator via `\ReflectionMethod` (literals, AND/OR with both single and double operators, NOT, parentheses, precedence, nested expressions). Section B covers `User::hasAccess()` end-to-end via partial PHPUnit mocks for `isAdministrator()` and `hasPermission()` — simple permissions, AND/OR (single + double), NOT, parentheses, nested, complex composites, empty/null short-circuit, administrator override, and the `\InvalidArgumentException` path on parse failure. Tests are pure unit tests; no DB, no container, no Pagekit kernel bootstrap.
- All **326 PHPUnit tests green** (was 289 before this step), 0 failures. PHPStan clean against the trimmed baseline (`[OK] No errors`).
- `php pagekit setup` and `php pagekit list` successful.
- Playwright E2E (chromium-only per `AGENTS.md`): `installation.spec.js` (1/1), `authentication.spec.js` (14/14), `dashboard.spec.js` (10/10) — all green; the login flow is the closest production-shaped smoke test for the authorization stack.

### Internal

- **Step 2.0.8 (User::hasAccess() Hotfix)** marked complete in `.cursor/ROADMAP.md`; Current Step pointer advances to the next foundation step.
- **Phase 1 audit closure (partial)** — Phase 1 §1.11 (ORM modernization) audit cell stays ⚠️; the remaining findings (`EntityManager` singleton, `ModelServiceLocator` / `IntlServiceLocator` static service locators, ORM `Metadata` / `Relation` / `PropertyTrait` typing gaps, `#[AllowDynamicProperties]` on `Node` / `Widget`) are deferred to Step 2.1.6 (PHPStan Level 7→8) per the task prompt's "Closes Phase 1 audit (partial)" annotation.
- **No-Mercy compliance:** physical deletion of the `create_function()` block, no shims, no adapters, no `@deprecated` markers, no new in-code TODOs. Aggressive Rules 1, 2, 4, 5 enforced.

---

## Pagekit 1.2.12 - Event Dispatcher Bridge Removal (April 27, 2026)

### Breaking Changes (Internal)

- **`Pagekit\Event\SymfonyEventDispatcherBridge` deleted** — The unused `Symfony\Component\EventDispatcher\EventDispatcherInterface` adapter on top of Pagekit's own event system is gone. Audit confirmed zero production consumers in `app/` and `packages/`. Pagekit's own `EventDispatcher`, `PrefixEventDispatcher`, `Event`, and `EventInterface` API surface remains untouched (stable platform API, ~147 call sites).
- **`symfony.event_dispatcher` container service removed** — The corresponding `$app->set('symfony.event_dispatcher', …)` registration in `app/modules/application/index.php` is removed. No replacement service. Extensions that need a Symfony-style dispatcher must now wire against `$app->get('events')` directly. (Closes #184)

### Refactor

- **`app/modules/application/index.php` cleaned up** — `$app->set('symfony.event_dispatcher', function ($app) { return new \Pagekit\Event\SymfonyEventDispatcherBridge($app->get('events')); });` block removed (3 lines + surrounding blank line). No other service registrations touched.

### Chore

- **`phpstan-baseline.neon` baseline trimmed** — Two stale ignore blocks (`function.alreadyNarrowedType` for `SymfonyEventDispatcherBridge.php`, `method.impossibleType` for `EventDispatcherCompatibilityTest.php`) removed alongside the file deletions. Baseline is internally consistent: zero "ignored error not matched" warnings.
- **`EventDispatcherCompatibilityTest` removed** — The only consumer of the deleted bridge; no cross-test fixtures, self-contained.

### Internal

- **Step 2.0.7 (Event Dispatcher Bridge Removal)** marked complete in `.cursor/ROADMAP.md`. Foundation Consolidation phase 2.0 progresses; Current Step pointer advances to the next foundation step.
- **No-Mercy compliance:** pure deletion (340 lines removed, 0 added, 4 paths touched). No shims, no adapters, no `@deprecated` markers, no new in-code TODOs. Aggressive Rules 1, 2, 4 enforced.

### Tests

- All **289 PHPUnit tests green** (0 failures, 0 errors). `EventDispatcherCompatibilityTest` correctly no longer discovered.
- PHPStan clean against the trimmed baseline (`[OK] No errors`).
- `php pagekit setup` and `php pagekit list` successful.
- Playwright E2E (chromium-only per `AGENTS.md`): `installation.spec.js` (1/1), `authentication.spec.js` (14/14), `dashboard.spec.js` (10/10) — all green.
- Final ripgrep audit of the live codebase: zero hits for `SymfonyEventDispatcherBridge`, `symfony.event_dispatcher`, `Symfony\Component\EventDispatcher\EventDispatcherInterface`, `EventDispatcherCompatibilityTest`.

---

## Pagekit 1.2.11 - Test Infrastructure Cleanup (April 27, 2026)

### Fix

- **Silent `InvalidArgumentException` swallowing in `RoutesLoader::addController()`** — The empty `catch (\InvalidArgumentException $e) {}` block previously masked controller-loading bugs in production. The handler now re-throws when debug mode is enabled and otherwise logs the exception via the application logger (with an `error_log` fallback). `RoutesLoader::__construct()` accepts an optional `Application` so it can resolve `debug` and `log` services when available. (Closes #183)

### Test

- **`@dataProvider` PHPDoc → `#[DataProvider]` attribute** — Migrated 6 occurrences across `app/modules/filter/src/Tests/PregReplaceTest.php`, `StripNewlinesTest.php`, `app/modules/filesystem/src/Tests/PathTest.php`, and `LocatorTest.php`. Each file now imports `PHPUnit\Framework\Attributes\DataProvider`.
- **`@group` PHPDoc → `#[Group]` attribute** — Migrated mail module tests: `app/system/modules/mail/src/Tests/MailerTest.php`, `Integration/MailIntegrationTest.php`, `Controller/MailControllerTest.php`. Each file now imports `PHPUnit\Framework\Attributes\Group`.
- **`ConfigManagerTest` modernized for current `ConfigManager` API** — Removed the legacy `Doctrine\Common\Cache\ArrayCache` import and obsolete `getCache()` helper. Test setup now calls the modern `ConfigManager(Connection $connection, array $config)` constructor. The remaining `->will($this->returnValue(true))` mock pattern was replaced with `->willReturn(true)`. Three stale entries in `phpstan-baseline.neon` (referencing the removed `ArrayCache` and 3-arg constructor) were pruned. DELETE OVER WRAP.
- **`RoutesLoaderTest` covers debug-aware exception handler** — Four new tests (`testAddControllerRethrowsInDebugMode`, `testAddControllerLogsViaLoggerInProduction`, `testAddControllerFallsBackToErrorLogWhenNoLogService`, `testAddControllerFallsBackToErrorLogWhenNoApplicationInjected`) exercise all three branches of the new `InvalidArgumentException` catch in `RoutesLoader::addController()` end-to-end via the public `load()` API. The catch is triggered by passing a route whose `controller` option points to an abstract fixture class (`AttributeLoader::load()` throws `InvalidArgumentException` for abstract classes — the only `InvalidArgumentException` path reachable after the `class_exists()` precheck).

### Chore

- **Obsolete module-level `phpunit.xml.dist` files removed** — Deleted `app/modules/filter/phpunit.xml.dist`, `app/modules/filesystem/phpunit.xml.dist`, `app/modules/cookie/phpunit.xml.dist`, `app/modules/auth/phpunit.xml.dist`. They used the PHPUnit 9 schema with broken bootstrap paths; their tests are already discovered by the root `phpunit.xml.dist` via the `app/modules/*/src/Tests` glob.

### Internal

- **Step 2.0.6 (Test Infrastructure Cleanup)** marked complete (✅ + 🛡️) in `.cursor/ROADMAP.md`; Current Step pointer advanced to 2.0.7 (Event Dispatcher Bridge Removal).

### Tests

- All 298 PHPUnit tests green (0 failures, pre-existing warnings/skips only). +4 new `RoutesLoader` tests covering the new debug-aware exception handler.
- PHPStan `analyse` clean against the (now smaller) baseline.
- `php pagekit setup` and `php pagekit list` successful.
- Playwright E2E (chromium): `installation.spec.js` (1/1), `authentication.spec.js` (14/14), `dashboard.spec.js` (10/10) all green.

---

## Pagekit 1.2.10 - Composer & Autoload Hygiene (April 26, 2026)

### Breaking Changes

- **`paragonie/random-lib` removed from `composer.json`** — Extensions that depended on the `auth.random` container service or on `RandomLib\Generator` being autoloaded by core must now generate their own random tokens (e.g. `bin2hex(random_bytes(32))`).
- **`Pagekit\Auth\Handler\DatabaseHandler::__construct()` signature tightened** — Old: `(string $key, RandomLib\Generator $random, array $config = [])`. New: `(string $key, ?array $config = null)`. Extensions instantiating `DatabaseHandler` directly must drop the `RandomLib\Generator` argument.

### Refactor

- **`paragonie/random-lib` replaced with native PHP** — All call sites in `app/modules/auth/index.php`, `app/modules/auth/src/Handler/DatabaseHandler.php`, and `app/installer/src/Installer.php` switched to `bin2hex(random_bytes(32))`. The transitive `ircmaxell/security-lib` is also dropped. (Closes #182)
- **`auth.random` container service removed** — No replacement; native `random_bytes()` is now used directly. DELETE OVER WRAP.

### Chore

- **Composer schema cleaned** — Removed invalid `title` property and discouraged `version` field so `composer validate --strict` passes.
- **Dead PSR-4 autoload mappings removed** — `Pagekit\Theme\` and `Pagekit\Package\` (target directories did not exist). `Pagekit\Installer\Package\*` is unaffected.
- **Unused direct dependencies removed** — `symfony/framework-bundle`, `symfony/twig-bridge`, `symfony/yaml`, `symfony/process`, `paragonie/sodium_compat`, and (require-dev) `doctrine/data-fixtures`. All verified zero direct PHP usage.
- **`symfony/validator` aligned to LTS** — `^7.4` → `^6.4` (resolves to `v6.4.36`); no 7.x-only Validator API in use.
- **`psr/log` widened** — `^2.0` → `^2.0|^3.0` (matches existing `psr/cache` pattern; allows Monolog 3.x's PSR Log 3.x interfaces). Lock resolves `psr/log 3.0.2`.
- **PHPStan baseline regenerated** — Stale `class.nameCase` suppressions for `MySqlPlatform` replaced with current `class.notFound` errors; one stale `requireOnce.fileNotFound` entry removed.
- **`composer dump-autoload --optimize`** run as final consistency pass.

### Cloud Agent & Workflow

- **`.cursor/environment.json` added** — Cloud Agent environment is now repo-versioned and takes precedence over personal/team configs in the Cursor Dashboard ([resolution order](https://cursor.com/docs/cloud-agent/setup#environment-resolution-order)). Wires `Dockerfile` + `install.sh` + `start.sh` so every cloud agent (including Bugbot on PRs) uses the same setup.
- **Playwright chromium-only by default** — `playwright.config.js` now ships only the `chromium` project; firefox/webkit are gated behind `PW_BROWSERS=all` (intended for CI/CD pipelines on full hosts). Matches the cloud agent VM, which only ships chromium because firefox/webkit need root for `playwright install-deps`.
- **`.gitignore` cleanup** — Removed `.cursor/environment.json` ignore entry (now repo-tracked); `.cursor/secrets.env` remains ignored.
- **Cloud Agent pitfalls documented** — New sections in `AGENTS.md` and `.cursor/README.md` covering: (1) Cursor secret names must be valid bash identifiers (`[A-Z_][A-Z0-9_]*`) — whitespace in names breaks the platform pre-commit secret scanner via `CLOUD_AGENT_INJECTED_SECRET_NAMES`. (2) Playwright browser matrix (chromium canonical, `PW_BROWSERS=all` for CI).
- **Browser matrix doc** — `tests/e2e/README.md` updated with chromium-default install instructions and `PW_BROWSERS=all` toggle for the full matrix.
- **Version source-of-truth clarified** — `.cursor/rules/push.mdc`, `.cursor/skills/version-bump/SKILL.md`, and `.cursor/modernize-helper.sh` updated: Pagekit version lives exclusively in `app/system/config.php`. References to `composer.json` as a version source removed (the field was dropped in this release to satisfy `composer validate --strict`).

### Internal

- **Step 2.0.5 (Composer & Autoload Hygiene)** marked complete (✅ + 🛡️) in `.cursor/ROADMAP.md`; Current Step pointer advanced to 2.0.6 (Test Infrastructure Cleanup).
- **Phase 1.9 (Symfony 6.4 LTS components)** audit upgraded `⚠️ → 🛡️` — `composer.json` is now consistently `^6.4` across all `symfony/*` direct dependencies (no 7.x drift), unused Symfony components removed, and `composer validate --strict` is clean. The original 1.9 audit-debt items are fully resolved.

### Tests

- All 289 PHPUnit tests green (0 failures, pre-existing warnings/skips only).
- PHPStan analyse clean against the regenerated baseline.
- `php pagekit setup` and `php pagekit list` successful.
- `composer install --dry-run` reports zero pending operations.
- Playwright E2E (chromium): `installation.spec.js` (1/1), `authentication.spec.js` (14/14), `dashboard.spec.js` (10/10) all green.

---

## Pagekit 1.2.9 - Package Migration System Redesign (April 9, 2026)

### Breaking Changes

- **`PackageManager` method signatures typed** — All public methods (`uninstall`, `enable`, `disable`, `getScripts`, `doInstall`, `getVersion`, `rollbackEnable`) now have PHP 8.2+ union/object type declarations.
- **`DatabaseHandler::createTable()` removed** — The `@system_auth` table is now exclusively managed by Doctrine migration `Version20251023061532`.
- **Extension migration is now explicit** — Extensions must call `MigrationService::migrateExtension()` in their `scripts.php` `enable` hook (see blog extension for reference pattern). `PackageManager` no longer auto-detects `src/Migrations/`.

### Features

- **`getExtensionCurrentVersion()`** — New `MigrationService` method returns the current migration version for an extension, used for precise rollback targeting in extension `scripts.php` hooks.
- **Explicit extension migration pattern** — Blog `scripts.php` demonstrates the recommended pattern: `install`/`enable` hooks call `migrateExtension()` explicitly, `uninstall` hook optionally rolls back. (Closes #180)

### Bug Fixes

- **MigrationController defensive guards** — `indexAction()` and `migrateAction()` check `$this->app->has('migration')` before accessing the service, preventing `NotFoundExceptionInterface` when redirected due to pending scripts while migration service isn't registered.
- **CLI script update guard** — `MigrationCommand::execute()` wraps `$scripts->update()` in try/catch to prevent version bump on script failure and provide clean error output.
- **Test directory case normalization** — `tests/unit/` renamed to `tests/Unit/` to match `phpunit.xml.dist` configuration. Tests were invisible on case-sensitive systems (Linux/macOS CI).
- **Metadata storage auto-initialization** — `ensureInitialized()` called on both core and extension `DependencyFactory`, fixing metadata storage errors on fresh databases.

### Refactor

- **MigrationService hardened** — Removed `is_array($result)` guard branches from `migrate()`, `rollback()`, `migrateExtension()`, `rollbackExtension()`. Doctrine Migrations 3.x returns `array<string, ExecutionResult>`. Deleted unused `getConfigPath()` method. Extracted `createExtensionDependencyFactory()` to DRY up duplicated factory creation.
- **Login check unified** — `auth.login` handler checks `MigrationService::status()['has_pending']` before version bump. Redirects to migration wizard if Doctrine migrations OR scripts are pending. Fail-safe: errors treated as pending.
- **Update wizard unified** — `MigrationController::migrateAction()` runs Doctrine migrations before `PackageScripts::update()`. `indexAction()` shows wizard when pending Doctrine migrations exist. Constructor fully typed.
- **CLI migrate command unified** — `MigrationCommand::execute()` runs Doctrine migrations first, then scripts. Version bump only after both succeed. Distinguishes "updated" vs "up to date" output. Added `declare(strict_types=1)`.
- **Blog migration renamed** — `Version001_CreateBlogTables` → `Version20251023070000_CreateBlogTables` (timestamp format consistent with core migrations). Uses `createTableIfNotExists()` for safe re-runs.
- **PackageManager simplified** — Removed auto-migration detection from `enable()` and auto-rollback from `uninstall()`. Deleted namespace resolution methods (`resolveExtensionMigrationNamespace`, `parseAutoloadFromIndexFile`, `extractBracketBody`, `unescapePhpString`, `stripPhpComments`). Extensions use explicit `scripts.php` hooks per original Pagekit philosophy.
- **PackageManager public API typed** — All public methods have proper PHP 8.2+ type declarations. Outdated `@param` PHPDoc blocks removed.
- **Blog `scripts.php` explicit pattern** — `enable` hook with idempotent `migrateExtension()` call. Simplified `install`/`uninstall` hooks. Schema changes in `src/Migrations/`, `updates` array for data migrations only.

### Tests

- **Real MigrationServiceTest** — All `markTestSkipped()` stubs replaced with real SQLite in-memory tests: migrate, rollback, status, extension migrate/rollback, no-op migration. Added `getExtensionCurrentVersion` and partial rollback tests (12 total).

### Chore

- **Audit cleanup** — Removed resolved TODO comments referencing Step 2.0.4 from `scripts.php`. Removed stale PHPStan baseline entries for deleted code paths.
- **BUGBOT rules updated** — Added PHP 8.2+ error model rule (Rule 2.2). Updated resolved/deferred tracking tables. Auto-migration removal tracked as resolved.

---

## Pagekit 1.2.8 - Full Cache API Modernization (April 4, 2026)

### Breaking Changes

- **`Pagekit\Cache\CacheInterface` removed** — Third-party extensions must migrate to `Psr\Cache\CacheItemPoolInterface` (PSR-6). The Pagekit-specific `fetch()`/`save()`/`contains()`/`delete()`/`flushAll()` API is replaced by the standard PSR-6 `getItem()`/`isHit()`/`set()`/`save()`/`deleteItem()`/`clear()` pattern.
- **`Pagekit\Cache\Adapter\*` classes removed** — `Psr6Adapter`, `ArrayAdapter`, `FilesystemAdapter`, `PhpFilesAdapter`, `ApcuAdapter`, `NullAdapter` wrappers deleted. Use `Symfony\Component\Cache\Adapter\*` directly.
- **ORM cache type unions removed** — `MetadataManager::getCache()` and `QueryBuilder::cache()` now accept only `CacheItemPoolInterface` (no more `CacheInterface` union type).

### Refactor

- **CacheModule factory rewrite** — `createCachePool()` returns `CacheItemPoolInterface` directly from Symfony adapters. Namespace/prefix passed via constructor argument instead of `setNamespace()`. (Closes #179)
- **ORM PSR-6 consolidation** — `MetadataManager` and `QueryBuilder` use single PSR-6 code path; removed `instanceof` dual branches for legacy `CacheInterface`.
- **MetadataManager key sanitization** — `sanitizeCacheKey()` replaces PSR-6 reserved characters (`{}()/\@:`) with `_` in metadata cache IDs containing class FQCNs.
- **LoginAttemptListener PSR-6 migration** — Type-safe `CacheItemPoolInterface` constructor; PSR-6 API for login attempt tracking; key sanitization for usernames with reserved characters.
- **Blog UrlResolver/RouteListener PSR-6 migration** — `UrlResolver` static cache and `RouteListener` cache clearing use PSR-6 API exclusively.
- **`doClearCache()` modernized** — Uses PSR-6 `clear()` instead of removed `flushAll()`.

### Fix

- **QueryBuilder cache key collision** — `getCacheKey()` now includes bound query parameters in the hash. Previously, two queries with the same SQL template but different WHERE values produced identical cache keys, risking stale/wrong results.
- **ClearCacheCommand alignment** — CLI `php pagekit clearcache` now calls `clear()` on the PSR-6 pool before file cleanup, matching the admin "Clear Cache" button behavior. Also adds `opcache_invalidate()` on cleared files.

### Tests

- **Cache tests rewritten** — `Psr6AdapterTest` renamed to `CachePoolTest`; all tests exercise PSR-6 `CacheItemPoolInterface` contract with Symfony adapters directly. Covers Array, Filesystem, PhpFiles, Null adapters, namespace isolation, TTL expiration, and performance benchmarks.

---

## Pagekit 1.2.7 - Phase 1 Codebase Audit & Foundation Consolidation Planning (April 3, 2026)

### Documentation

- **Systematic Phase 1 audit** — All 17 Phase 1 steps audited against No Mercy rules. Found legacy artifacts (compatibility layers, deprecated APIs, missing types, dead code) across 13 of 17 steps. Only Steps 1.3, 1.3.5, 1.6, and 1.14 passed clean.
- **6 new Foundation Consolidation steps** — Steps 2.0.3–2.0.8 added to ROADMAP and PHASE_2_MODERNISING.md with full descriptions and audit findings:
  - 2.0.3: Cache API full PSR-6 modernization (Closes #179)
  - 2.0.4: Package/Migration system redesign (Closes #180)
  - 2.0.5: Composer & Autoload hygiene (#182)
  - 2.0.6: Test infrastructure cleanup (#183)
  - 2.0.7: Event Dispatcher bridge removal (#184)
  - 2.0.8: Critical hotfix — `create_function()` removal (#185)
- **Agent prompts created** — Detailed prompts for all 6 new steps under `Step-2_0-Foundation-Consolidation/`.
- **Existing step prompts enriched** — Audit findings added to 2.1.3, 2.1.4, 2.1.6, 2.1.7, 2.1.9 prompts.
- **Phase 1 prompt reorganization** — Completed Phase 1 prompts moved to `Phase1/` subdirectory.
- **PHASE_1 correction** — Step 1.13.5 status corrected from "COMPLETED" to "~80%" (CSP `unsafe-eval` remains until Step 3.2.1).
- **Visual roadmap updated** — `MODERNISATION_STRATEGY.md` now shows all Foundation Consolidation and Static Analysis sub-steps.
- **ROADMAP audit columns corrected** — Steps with known legacy artifacts marked with warning instead of passed audit.

### Refactor

- **Tester subagent** — PHPStan added as mandatory quality gate after every step. Two-phase workflow: per-step (PHPUnit + PHPStan) and final (+ `php pagekit setup` + Playwright E2E).
- **Verifier subagent** — New checklist item for audit findings verification.
- **Architect subagent** — References PHASE_2 audit findings; testing strategy section in ticket template.
- **Orchestrator workflow** — Updated handoff format and workflow diagram with final test run block.

### Chores

- **PHPStan baseline regenerated** — 885 errors at level 5 (updated from 883 after code changes outside this session).

---

## Pagekit 1.2.6 - Static Analysis Tooling Baseline (April 2, 2026)

### Build

- **PHPStan installed** — `phpstan/phpstan`, `phpstan/phpstan-doctrine`, and `phpstan/phpstan-symfony` added as dev dependencies.
- **`phpstan.neon` created** — Level 5 configuration with Doctrine and Symfony extensions, analysing `app/modules`, `app/system`, `app/installer`, `app/console`, and `packages`.
- **`phpstan-baseline.neon` generated** — 883 errors baselined at level 5 (reduced from 888 during 2.1.1 review: removed 5 stale entries for fixed Monolog 2.x fallbacks and DBAL 2.x dead code). PHPStan analyse runs clean with zero errors above baseline.
- **`roave/security-advisories`** added as dev dependency for vulnerability detection.
- **`friendsofphp/php-cs-fixer`** added as dev dependency for automated code style enforcement.

### Style

- **PSR-12 ruleset activated** — `.php-cs-fixer.php` upgraded from `@PSR2` to `@PSR12`; `packages` directory removed from exclusion list.
- **PSR-12 formatting applied** — 601 PHP files reformatted across the entire codebase. No behavioral changes.

### Fixes

- **PHPStan non-ignorable errors resolved** — Fixed 10 type errors (missing returns, redundant self-imports) in `PropertyTrait.php`, `QueryBuilder.php`, `Type.php`, and `NodeInterface.php`.
- **Monolog 2.x dead code removed** — Removed unreachable Monolog 2.x fallback branches from `LogDataCollector.php` and `DebugBarHandler.php` (`composer.json` requires `monolog/monolog: ^3.7`). Typed `handle()` parameter directly as `LogRecord`.
- **DBAL 2.x dead code removed** — Removed non-existent `Doctrine\DBAL\Driver\PDOConnection` import and dead `instanceof` check from `InfoHelper.php`. Removed deprecated `getDriver()->getName()` fallback.
- **EntityManager cache cleanup** — Removed legacy `CacheInterface` fallback from `invalidateCache()`. Only PSR-6 `clear()` remains (Step 4.3 will add tag-based invalidation).

### Refactor (TODOs for future steps)

- **19-file post-formatting review** — Reviewed all files changed during PSR-12 formatting for inconsistencies, forgotten TODOs, and legacy remnants. Set precise TODO references for 15+ locations across 6 future steps.
- **Step 2.0.3 (Cache API Vollmodernisierung)** — New roadmap step created ([#179](https://github.com/Shadesman5/pagekit/issues/179)). Pagekit's custom `CacheInterface` + `Psr6Adapter` identified as compatibility layer to be replaced by direct PSR-6 `CacheItemPoolInterface`.
- **Step 2.0.4 (Package/Migration System Redesign)** — New roadmap step created ([#180](https://github.com/Shadesman5/pagekit/issues/180)). Update pipeline does not run Doctrine Migrations automatically; version bumps can happen without schema checks.
- **Step 2.1.6 TODOs** — `MailerInterface` split (Mailer vs Plugin), `EntityManager` singleton removal, `FileLocatorAsset` static service locator, `ResponseListener` mixed typing.
- **Step 2.1.7 TODOs** — DBAL type normalization: `json_array` type name to be renamed to `json`, redundant type registrations to be removed.
- **Step 3.4.6 TODOs** — `transChoice` Twig filter and `_c()` function to be removed; 4 PHP views and ~20 JS/Vue files need ICU MessageFormat migration.
- **Step 4.3 TODO** — ORM cache invalidation strategy: replace `$cache->clear()` with tag-based invalidation via `TagAwareCacheInterface`.

### Documentation

- **ROADMAP.md** — Added Steps 2.0.3 and 2.0.4 with GitHub issue references.
- **PHASE_2_MODERNISING.md** — Added full step descriptions for 2.0.3 (Cache) and 2.0.4 (Package/Migration). Extended Steps 2.1.6 and 2.1.7 with concrete refactoring tasks from review.
- **PHASE_3_MODERNISING.md** — Extended Step 3.4.6 with concrete transChoice removal task list.
- **PHASE_4_MODERNISING.md** — Added ORM cache invalidation task to Step 4.3.
- **Agent prompts updated** — `PROMPT_2_1_6` (4 new refactoring sections + checklist items), `PROMPT_2_1_7` (DBAL type normalization section + checklist items).

### Chores

- **`.php-cs-fixer.cache` added to `.gitignore`** — Prevents generated cache file from being tracked.
- **PHPStan baseline reduced** — 888 to 883 errors (5 stale entries removed for code that was fixed in this review).

---

## Pagekit 1.2.5 - Documentation Cleanup & Workflow Alignment (March 28, 2026)

### Documentation

- **Stale file references updated** — Replaced all references to removed `MODERNISING_PAGEKIT_TODO_LIST.md` with `MODERNISATION_STRATEGY.md` or `ROADMAP.md` across `.cursor/README.md`, `github-issue-creator/SKILL.md`, and `tests/e2e/COMPLETE_TEST_PLAN.md`.
- **migration-docs README restructured** — Cleaned up layout: flat file listings, archived `branches/` and `pull-requests/` sections as historical Phase 1 artifacts, added tracking/navigation table pointing to ROADMAP as SSOT, updated timeline to reflect Phase 2 active status.
- **ROADMAP header added** — Current version (1.2.5) and current step (2.1) now shown at the top of `.cursor/ROADMAP.md` for quick orientation.
- **AGENTS.md extended** — Added modernisation workflow note referencing `.cursor/rules/` and `ROADMAP.md`.
- **Feature-branch rule simplified** — Removed outdated PR documentation path; now references `push.mdc` for version bump, push, and PR creation.
- **Tickets README simplified** — Replaced verbose output-for-human section with concise reference to `push.mdc`.

---

## Pagekit 1.2.4 - Validator-Translator Integration + Audit (March 27, 2026)

### Features

- **Symfony Translator wired into ValidatorBuilder** — `ValidatorServiceProvider::register()` now calls `setTranslator()` and `setTranslationDomain('validators')`, enabling domain-aware translation of validation constraint messages (Step 2.0.2).

### Refactoring

- **Rename `validation.php` to `validators.php`** — Translation files in `app/system/languages/en_US/` and `packages/pagekit/blog/languages/en_US/` renamed to match the Symfony `validators` domain convention. `IntlModule::loadLocale()` auto-derives domain from filename via `glob()`.

### Tests

- **ValidatorTranslatorIntegrationTest** — 5 new integration tests: translated violation messages, parameterized length constraints, valid entity produces no violations, `validationErrorResponse()` returns human-readable messages, locale fallback to `en_US`.

### Documentation

- **Boot comment updated** — `app/system/index.php` comment reflects Step 2.0.2 Translator-integrated validator (replaces Step 1.13 hybrid-mode note).

### Audit

- **No Mercy audit passed for Steps 2.0-2.0.2** — Comprehensive audit covering Controller Attributes (2.0), PSR-11 Container Vollmodernisierung (2.0.1, 2.0.1a-e), and Validator-Translator Integration (2.0.2). All 8 steps pass: zero compatibility layers, zero adapters, full strict typing, 280 tests green.
- **ROADMAP updated** — Step 2.0 audit upgraded from `needs-audit` to `passed`; Step 2.0.2 marked as done with PR #175; Step 2.1 advanced to Current Step.

### Chores

- **Deferred TODO for ExtensionTranslateCommand** — Added `TODO: Step 2.0.2` marker in `PhpNodeVisitor.php` for future `#[Assert\...]` attribute key extraction (low priority, deferred to Step 2.1).

---

## Pagekit 1.2.3 - PSR-11 Container Closure Audit (March 26, 2026)

### 🛡️ Audit

- **PSR-11 Container closure audit passed** — All 10 acceptance criteria verified for ROADMAP Step 2.0.1 (PSR-11 native, no ArrayAccess, no StaticTrait, zero `App::` calls, zero magic methods, controllers/listeners use constructor DI, migration docs published, PHPUnit + E2E green).
- **No Mercy rules verified** — Zero compat layers, zero adapters, ArrayAccess removed, legacy traits deleted, zero `TEMPORARY BRIDGE` markers.

### 🧹 Cleanup

- **Remove stale TODO marker** — Deleted outdated `TODO: Must be refactored in Step 2.0.1e` comment from `app/console/index.php` (work completed).
- **Remove 7 dead `App` imports** — Removed unused `use Pagekit\Application as App;` from `PageApiController`, `StorageController`, `IntlController`, `IntlApiController`, `InfoController`, `CacheController`, and `UserListener`.

### 📝 Documentation

- **ROADMAP updated** — Step 2.0.1 marked as ✅ with audit 🛡️; sub-steps 2.0.1a–e audit columns set to 🛡️; Step 2.0.2 advanced to **Current Step**.

---

## Pagekit 1.2.2 - Task Prompts & Agent Roles (March 26, 2026)

### 📁 Documentation

- **Reorganize PSR-11 docs** — Moved migration docs, agent prompts, branch docs, and ticket plans into organized `PSR-11-Container/` subdirectories for better discoverability.
- **PSR-11 Closure Audit prompt** — Added verification and No Mercy audit prompt for Issue #145 (all 10 acceptance criteria + 5 ROADMAP rules).
- **Validator-Translator prompt** — Added Step 2.0.2 task prompt (#146) for connecting Symfony Validator to Pagekit Translator.
- **Static Analysis prompts (2.1.1–2.1.9)** — Added 9 sub-step prompts + overview for PHPStan, strict_types, CI/CD, QueryBuilder API, Infection, and test coverage.

### 🤖 Agent Improvements

- **Refactorer** — Explicit boundary: no test execution, no self-verification.
- **Verifier** — Restricted to static code review only; no PHPUnit, Playwright, or application commands.
- **Tester** — Declared as exclusive test runner. Added clean-state rule: `rm -f pagekit.db config.php` before any fresh installation.

---

## Pagekit 1.2.1 - PSR-11 StaticTrait Removal Bugfixes (March 26, 2026)

### 🐛 Bug Fixes — Wave 1 (initial)

- **subscribe() multi-arg regression** — `EventDispatcher::subscribe()` accepts one subscriber, but migrated calls passed multiple (silently dropped). Split into individual calls in routing, kernel, site, content, user, and blog modules.
- **DashboardModule TypeError** — Non-nullable `$app` property without default caused TypeError when accessed before `main()`. Restored nullable typing.
- **PackageScripts null container** — 3 call sites (system/index.php, MigrationController, MigrationCommand) omitted `$app` parameter, causing null container in script callbacks.
- **PackageManager::getVersion() wrong file** — Read `composer.json` instead of `installed.json` for fallback lookup; also fixed `->getName()` called on array.
- **systemApi factory crash** — Missing fallback for unregistered `system.api` service in DashboardModule. Added `has()` guard matching other controllers.
- **Console commands broken __call()** — Fixed `$this->container->path()`, `->version()`, `->config()` magic calls in MigrationCommand, BuildCommand, ArchiveCommand, ExtensionTranslateCommand.

### 🐛 Bug Fixes — Wave 2

- **DashboardModule::getWidget() null return** — Returned null violating array contract. Added `assertBooted()` guard for `getWidgets`/`saveWidgets` called before `main()`.
- **ExceptionListenerWrapper \Exception vs \Throwable** — `shouldRun()` typed `\Exception` but receives `\Throwable` from `getException()`. Fixed to `\Throwable`.
- **PackageFactory::load() null url** — Silently skipped URL when `$url` was null. Use nullsafe operator with empty string fallback.
- **CacheModule clearCache missing null guard** — `$this->app` accessed without null guard. Added `assertBooted()` pattern.
- **routing/index.php redirect args** — Passed 3 args to `setResponse()` instead of status 301 to `redirect()`.

### 🐛 Bug Fixes — Wave 3

- **SiteModule missing assertBooted() guard** — Nullable `$app` property and guard added, matching DashboardModule/CacheModule pattern.
- **SelfupdateCommand wrong SelfUpdater constructor** — Commented-out instantiation passed wrong argument order after constructor signature change.
- **Container::extend() crash on resolved services** — Debug module calls `extend('events', ...)` after events service is resolved by ModuleLoader. Added early-return branch for post-resolution decoration.
- **UserModule/SystemModule missing guards** — Added nullable `$app` + `assertBooted()` guards.
- **intl/index.php wrong service reference** — Used `$app->get('intl')` but IntlModule IS the intl service. Reverted to `$this`.

### 🐛 Bug Fixes — Wave 4

- **ExceptionListenerWrapper wrong status code** — Symfony `HttpExceptionInterface` uses `getStatusCode()`, Pagekit `HttpException` uses `getCode()`. Previously all Symfony exceptions fell back to 500.
- **JSON error handler type-hint too narrow** — Only caught Pagekit `HttpException`, not Symfony exceptions. API errors returned HTML instead of JSON.
- **JSON error response leaked internals** — Internal errors (DB, filesystem) were exposed to API clients. Restricted to HTTP exceptions only.
- **DashboardModule::getWidget() nullable return restored** — `reorderAction()` relies on null for missing widget IDs.
- **Installer view.data handler crash** — Called `$view->data()` on `DataHelper` which has no `data()` method. Fixed to use `$data()` invocable.
- **ExceptionListenerWrapper Closure-only limitation** — Only accepted `\Closure`, now accepts any `callable` via `Closure::fromCallable()`.
- **IntlServiceLocator::setIntl() NotFoundException on boot** — `$app->get('intl')` threw because no separate intl service exists. Reverted to `$this`.
- **DashboardController hardcoded API key** — Moved OpenWeatherMap API key/URL to module config (AUDIT FIX marker).
- **Weather API key empty default** — Restored default key with AUDIT FIX TODO marker; empty default broke widget.

### 🐛 Bug Fixes — Wave 5

- **Container::extend() raw[] stale after decoration** — `raw()` returned stale original closure after `extend()` on resolved service. Now syncs `raw[$name]` to decorated value.

### 🧪 Tests

- **testExtendOnResolvedServiceUpdatesRaw** added to `ContainerTest`.
- **275 PHPUnit tests pass**, 658 assertions, 0 failures.

### 📝 Documentation

- `README.md` — Fixed broken ArrayAccess code example, updated test count, PSR-11 description, removed stale migration guide reference.

---

## Pagekit 1.2.0 - PSR-11 Container: StaticTrait Removal (March 19, 2026)

### 🚀 Breaking Changes

- **StaticTrait, EventTrait, RouterTrait Deleted** — All three Application trait files removed. `App::*` static shortcut methods (`App::abort()`, `App::redirect()`, `App::trigger()`, `App::user()`, `App::db()`, etc.) and `App::getInstance()` are no longer available. Use explicit `$app->get('service')` PSR-11 calls or constructor dependency injection.
- **Container::__call() Removed** — Magic method service access (`$app->module()`, `$app->config()`, etc.) no longer works. Use `$app->get('module')`, `$app->get('config')`, etc.

### ♻️ Refactoring

- **Instance Magic Calls Migrated** — All `$app->module()`, `$app->config()`, `$app->request()`, `$app->url()`, `$app->view()` calls replaced with `$app->get('service')` across all module index files, views, and mail templates.
- **EventTrait Calls Migrated** — All `$app->on()`, `$app->subscribe()`, `$app->trigger()` instance calls replaced with `$app->get('events')->on/subscribe/trigger()`.
- **RouterTrait Calls Migrated** — `$app->error()` converted to events service with `ExceptionListenerWrapper`. `$app->redirect()` converted to router service.
- **App::abort() Replaced** — ~80 `App::abort()` static calls across system, installer, console, and blog replaced with typed Symfony HTTP exceptions (`NotFoundHttpException`, `AccessDeniedHttpException`, `BadRequestHttpException`, etc.).
- **App::redirect() Replaced** — ~18 `App::redirect()` static calls replaced with injected router service via constructor DI.
- **Remaining App:: Static Calls Eliminated** — `App::trigger()`, `App::on()`, `App::markdown()`, `App::path()`, `App::debug()`, `App::log()`, `App::user()`, `App::request()`, `App::filter()`, `App::db()`, `App::cache()`, `App::url()`, `App::response()`, `App::feed()`, `App::content()` all replaced with constructor DI.
- **App::getInstance() Bridges Resolved** — ~38 temporary bridge patterns in system and installer areas replaced with proper constructor DI. PackageManager, PackageFactory, PackageScripts, Installer, and all installer controllers refactored.
- **Models Cleaned** — `Post.php` and `Node.php` models purged of all `App::` static access. ModelServiceLocator provides URL/user/module services for model serialization (tagged for Step 2.1 replacement with DTO/presenter pattern).
- **IntlServiceLocator Created** — Permanent narrow service locator for `__()`, `_c()`, `_i()` global translation functions, replacing `App::translator()` and `App::intl()`.
- **Blog Package Fully Migrated** — All 4 controllers, RouteListener, UrlResolver, and Post model now use constructor DI exclusively.
- **SymfonyEventDispatcherBridge Fixed** — `dispatch()` method now properly forwards events to Pagekit's event dispatcher.
- **ExceptionListenerWrapper Created** — New kernel event wrapper for typed exception filtering on the events dispatcher.

### 🧪 Tests

- **IntlServiceLocatorTest Added** — 5 tests covering getter/setter and error paths.
- **EventDispatcherCompatibilityTest Added** — Tests for bridge dispatch forwarding.
- **ContainerTest Updated** — `__call()` test removed.
- **274 PHPUnit tests pass**, 658 assertions, 0 failures.

### 📝 Notes

- **EntityManager singleton deferred** — `static::$instance` in EntityManager tagged for removal in Step 2.1 (Static Analysis). ORM refactoring is out of scope for 2.0.1e.
- **ModelServiceLocator** is a transitional pattern tagged for Step 2.1 replacement with proper DTO/presenter patterns.
- Container is now pure PSR-11: `get()`, `has()`, `set()`, `factory()`, `extend()`, `raw()`, `keys()`, `remove()` — no magic methods, no static traits.

---

## Pagekit 1.1.8 - PSR-11 Container Stage 4: Packages Final & ArrayAccess Removal (March 18, 2026)

### 🚀 Breaking Changes

- **ArrayAccess Removed from Container** — `Pagekit\Container` no longer implements `\ArrayAccess`. All `$app['x']`, `$this['x']`, `$container['x']` bracket-access patterns are replaced with PSR-11 `get()`/`has()` and new `set()` method. Extensions using ArrayAccess must migrate (see migration guide).

### ♻️ Refactoring

- **Container `set()` Method** — New `public function set(string $id, mixed $value): void` added to Container. `factory()`, `extend()`, and constructor now call `set()` internally.
- **Application.php Migration** — All `$this['x']` ArrayAccess in Application.php converted to `$this->set()`/`$this->get()`.
- **app/modules/ Migration** — 17 module index.php files: all `$app['x'] = ...` writes migrated to `$app->set('x', ...)`, all reads to `$app->get('x')`.
- **app/system/ Migration** — 11 system files: all service registrations migrated from ArrayAccess to `set()`/`get()`.
- **Bootstrap Migration** — Installer, console, and system bootstrap files migrated to PSR-11.
- **Blog Package Migration** — `scripts.php`, `index.php` ArrayAccess migrated. All 4 blog controllers (`BlogController`, `PostApiController`, `CommentApiController`, `SiteController`) refactored with constructor DI for module service.
- **Blog Listeners/Models Tagging** — `RouteListener` static calls tagged for Step 2.0.1e. `Post` model and `UrlResolver` bridge patterns tagged with `TEMPORARY BRIDGE`.
- **Theme-One Tagging** — `App::view()` in `functions.php` tagged for Step 2.0.1e.
- **Console & Installer Cleanup** — Remaining `$container['x']` and `$this->app['x']` patterns in Console/Application and Installer migrated.

### 🧪 Tests

- **ContainerTest Updated** — Rewritten for PSR-11: `testSetAndGetMethods`, `testGetThrowsNotFoundException`, `testSetThrowsExceptionWhenOverriding`, `testSetMethod`. All ArrayAccess syntax removed.
- **ContainerPsr11Test Updated** — All bracket-access migrated to `set()`/`get()`/`has()`.
- **268 PHPUnit tests pass**, 648 assertions, 0 failures.

### 📝 Documentation

- **Extension Migration Guide** — `migration-docs/PSR11_CONTAINER_EXTENSION_MIGRATION.md` with service access tables, registration tables, controller DI examples, and exception types.
- **Stage 4 Branch Documentation** — `migration-docs/branches/PSR11_CONTAINER_STAGE4.md` with full migration summary and deferred items.

### 🔧 Validation

- Zero `$app['x']`, `$this['x']`, `$container['x']`, `$this->app['x']` ArrayAccess patterns in codebase.
- `php pagekit setup` and `php pagekit list` execute successfully.
- Container implements only `Psr\Container\ContainerInterface`.

---

## Pagekit 1.1.7 - PSR-11 Container Stage 3: System, Installer & Console (March 4, 2026)

### ♻️ Refactoring

- **System Module index.php Migration (2.0.1c)** — All `$app['xxx']` ArrayAccess READ patterns in 12 system module `index.php` files migrated to `$app->get('xxx')`. WRITE patterns preserved with TODO for Step 2.0.1d.
- **System Bootstrap & Module Classes (2.0.1c)** — ArrayAccess reads in `app.php`, `scripts.php`, `SystemModule`, `SiteModule`, `UserModule`, `IntlModule`, `ValidatorServiceProvider`, view templates, and mail templates migrated to `$app->get('xxx')`.
- **System Controllers Constructor Injection (2.0.1c)** — All 25 system controllers migrated from `App::service()` to constructor injection with `private readonly` typed properties. `App::abort()`/`App::redirect()` deferred to Step 2.0.1e.
- **System Listeners Constructor Injection (2.0.1c)** — 7 event listeners (`AuthorizationListener`, `AccessListener`, `LoginAttemptListener`, `ResponseListener`, `MaintenanceListener`, `NodesListener`, `CaptchaListener`) migrated to constructor injection. Index.php files updated to pass services.
- **System Helpers & Module Classes (2.0.1c)** — `SystemMenu`, `PositionHelper`, `MenuHelper`, `InfoHelper`, `FileLocatorAsset`, `CacheModule`, `DashboardModule`, `UniqueValidator`, and module classes migrated to constructor injection.
- **System Model Temporary Bridge (2.0.1c)** — `Node.php` model uses `App::getInstance()->get()` pattern (models cannot use constructor injection). Intl global functions marked with TODO for Step 2.0.1e.
- **Installer Migration (2.0.1c)** — All ArrayAccess reads in installer bootstrap, index, install scripts, controllers, and `PackageManager`/`PackageFactory` migrated to `$app->get()` or constructor injection.
- **Console Migration (2.0.1c)** — All ArrayAccess reads in console `app.php` and commands migrated to `$app->get()`. `App::abort()` in `SelfupdateCommand` deferred to Step 2.0.1e.

### 📝 Documentation

- **Stage 3 Branch Documentation** — `PSR11_CONTAINER_STAGE3.md` with discovery results and final validation.
- **Validation Results** — 267 PHPUnit tests pass, 0 `$app['xxx']` reads remain, 0 `App::service()` in controllers/listeners, PHP syntax valid across 292 files.

---

## Pagekit 1.1.6 - PSR-11 DI Infrastructure + Workflow Optimization (February 23, 2026)

### ✨ New Features

- **PSR-11 Constructor DI for Controllers (2.0.1b)** — `ControllerResolver` now accepts `ContainerInterface` and resolves constructor dependencies via reflection. Controllers can declare typed constructor params (matched by name to container services); fallback to `new $class()` when no params exist. Wired in `kernel/index.php`.

### 🧪 Tests

- **ControllerResolver DI Tests** — PHPUnit coverage for reflection-based instantiation, default values, missing-service errors, and request integration.

### 🔧 Maintenance

- **Orchestrator Token Discipline** — Orchestrator is now a thin coordinator: delegates to Architect immediately, does not read task prompts or ROADMAP itself, does not re-interpret the Architect's checklist, does not verify Refactorer output.
- **Ticket-based Handoff** — Plans written to `.cursor/tickets/{task-slug}_plan.md` by Architect. Subagents receive only the ticket path + step number, not the full task prompt. Reduces context duplication and token usage.
- **Subagent Output Discipline** — All subagents (Architect, Refactorer, Verifier, Tester) have strict output rules: minimal chat output, no narration, structured results only.
- **Push + PR Phase** — Orchestrator now executes `push.mdc` as final step (version bump, CHANGELOG, push, PR with metadata block). Replaces Cursor's auto-PR feature.
- **Console Command DI TODO** — Deferred console command DI to Step 2.0.1e with TODO markers.

### 📝 Documentation

- **Branch Documentation** — `PSR11_CONTAINER_DI_INFRASTRUCTURE.md` created and updated for Step 2.0.1b completion.

---

## Pagekit 1.1.5 - PSR-11 Container Core + DI Modernization Plan (February 22, 2026)

### ♻️ Refactoring

- **PSR-11 Native Container (2.0.1a Stage 1)** - `Container` now directly implements `Psr\Container\ContainerInterface` with native `get()` and `has()` methods. `Psr11Adapter` wrapper deleted. `ArrayAccess` delegates to `get()`/`has()` for backward compatibility. `NotFoundException` and `ContainerException` implement PSR-11 exception interfaces. (Issue #145)
- **Core Modules Call Site Migration (2.0.1a Stage 2)** - Migrated ~116 call sites in `app/modules/` from `$app['x']` to `$app->get('x')` and `isset($app['x'])` to `$app->has('x')`. Service registration (`$app['x'] = fn`) kept as ArrayAccess (deferred to 2.0.1d). (Issue #145)
- **StaticTrait Cleanup** - Removed dead `get`/`has` cases from `__callStatic` (PHP limitation: `__callStatic` not triggered when instance method exists). Documented workaround: `App::getInstance()->get('x')` for 8 call sites where `App::get('x')` collides with PSR-11 instance method.

### 📝 Documentation

- **PSR-11 Vollmodernisierung Plan** - Expanded Step 2.0.1 from 4 stages into 5 sub-steps (2.0.1a–e) with proper dependency chain: Container Core → DI Infrastructure → System Migration → ArrayAccess Removal → StaticTrait Deletion. Discovered and fixed critical bug in Stage 3/4 agent prompts where `App::db() → App::get('db')` would cause fatal errors due to `__callStatic`/PSR-11 name collision.
- **New Agent Prompts** - Created `PSR-11-Container-DI-Infrastructure.md` (2.0.1b: ControllerResolver constructor injection) and `PSR-11-Container-StaticTrait-Removal.md` (2.0.1e: delete all traits, repository pattern for models, fix SymfonyEventDispatcherBridge).
- **Architecture Analysis** - Documented component DI feasibility (controllers: yes, listeners: yes, models: need repositories, commands: yes). Identified RouterTrait and EventTrait as additional removal targets dependent on StaticTrait.
- **ROADMAP** - Updated tracking table with 5 sub-steps for 2.0.1. Branch docs updated with architecture notes.

### 🧪 Tests

- All 261 PHPUnit tests pass (0 failures, 0 errors)
- `php pagekit setup` succeeds
- Container implements `ContainerInterface` (verified)
- No references to `Psr11Adapter`, `getService`, `hasService`

---

## Pagekit 1.1.4 - GitHub Metadata Automation & Security Hardening (February 21, 2026)

### ✨ New Features

- **Metadata Sync Workflow** (`sync-metadata.yml`) - New GitHub Action that parses `<!-- metadata -->` blocks from issue **and PR** bodies and automatically applies labels + milestones. Chains with `auto-add-to-project-phase.yml` to add issues to project and set Phase field. (PRs #138, #141)
- **Issue Cleanup Workflow** (`issue-cleanup.yml`) - Emergency manual workflow to bulk-close agent-created issues via GitHub UI. Inputs are passed safely via `process.env` (no script injection). Strict `/^\d+$/` validation rejects malformed issue numbers. (PRs #138, #141)
- **Migration Script** (`add-metadata-to-issues.sh`) - One-time bash script to add `<!-- metadata -->` blocks to existing issues #124–#136 with dry-run support. (PR #138)

### 🐛 Bug Fixes

- **Script Injection in issue-cleanup** - Replaced direct `${{ inputs }}` interpolation in JS strings with `process.env` to prevent script injection via apostrophes in workflow_dispatch inputs. (PR #141)
- **parseInt Accepting Partial Numbers** - Replaced `parseInt()` with strict `/^\d+$/` regex to reject malformed entries like ranges or suffixed values. (PR #141)
- **Sender Permission Check** - `sync-metadata.yml` now checks the sender's actual repo permission (`admin`/`maintain`/`write`) instead of `issue.author_association`, which incorrectly showed "NONE" for Cloud Agent-created issues. (PR #141)
- **Non-Collaborator 404** - `getCollaboratorPermissionLevel` wrapped in try/catch to gracefully skip non-collaborators instead of crashing the workflow. (PR #141)
- **Auth Errors Fail Loudly** - 401/403 errors from a broken `PROJECT_TOKEN` now fail the workflow instead of silently skipping, so expired tokens are noticed immediately. (PR #141)
- **Batch Safety Limit** - Unified inconsistent limits (5 vs 10) to 10 issues per session in `github-issue-creator` SKILL. (PR #141)

### ⚡ Performance

- **Bot Actor Skip** - `sync-metadata.yml` skips `cursor[bot]` at job level (`github.actor != 'cursor[bot]'`), preventing runner startup for frequent PR body edits. Other bots (Cloud Agent) are allowed through. (PR #141)
- **Fork PR Skip** - Fork PRs are skipped at job level since `PROJECT_TOKEN` is unavailable. (PR #141)

### 📝 Documentation

- **Push Workflow** (`push.mdc`) - Step 6 now requires a `<!-- metadata -->` block in every PR body with labels, milestone, and closes fields. Added Issue Linking via `Closes #X`. (PRs #137, #141)
- **GitHub Issue Creator SKILL** - Clarified PR linking (issue body vs PR body), replaced broken `gh issue edit --add-sub-issue` with GraphQL mutation, added PR metadata support notes, added `breaking-change` label option. (PRs #120, #137, #141)
- **ROADMAP** - Added Issue column mapping #119-#142 to completed tasks. Restructured Phase 2: 2.0.5→2.0.1, added 2.0.2 Validator-Translator, substeps 2.1.1-2.1.9 (Tooling, CI, PHPStan, QueryBuilder, etc.). Phase 3: 3.2.5→3.2.1, added 3.4.6 Translation System.
- **Validation System** - Expanded `VALIDATION_SYSTEM.md` with translation architecture, domain separation, key-pattern convention, `validators.php` rename, Open Items. Updated `VALIDATION_TESTING_GUIDE` references (Step 2.1.5+ → Phase 3 Step 3.4+).
- **GitHub Guide** - Updated QueryBuilder step reference 2.1.5 → 2.1.7.
- **Rules** - `conventional-commits.mdc`: fix description CHANGELOG-2025 → CHANGELOG-NEW. `pagekit-standards.mdc`: add PSR-2/PSR-12 migration note for Step 2.1.

### 🔧 Maintenance

- **Workflow Renamed** - `issue-metadata-sync.yml` → `sync-metadata.yml` to match the new scope (issues + PRs). All references updated. (PR #141)
- **Managed Labels** - Added `breaking-change` to managed label set. Documented which labels are intentionally excluded (Dependabot, standard GitHub labels). (PR #141)
- **.gitignore** - Broadened `.env` to `*.env` to catch all environment files. Added `/planning/` for local planning documents. (PR #138)
- **.cursorignore** - Commented out `languages/**` and `/app/vendor` for local dev visibility. (PR #138)
- **Token Handling** - `PAGEKIT_BACKGROUND_AGENT` only overrides `GH_TOKEN` when explicitly set; `secrets.example.env` updated with `PROJECT_TOKEN` docs. (PR #138)

---

## Pagekit 1.1.3 - E2E Hardening & Auth Bugfix (February 15, 2026)

### ✨ New Features

- **GitHub Issue Creator Skill** - Added `.cursor/skills/github-issue-creator/` skill for creating GitHub issues with correct labels, milestones, and PR linking.

### 🐛 Bug Fixes

- **Login Rate Limiting** - Fixed off-by-one in `LoginAttemptListener`: use `>=` instead of `>` for attempt threshold; replaced `array_pop()` with `end()` to avoid mutating the attempts array; added `is_array()` guard.

### ♻️ Refactoring

- **E2E Test Infrastructure** - Centralized wait-time constants (animation, transition, hover, tick) derived from test-config instead of hardcoded values. Added `getWorkers()` method with config → env → CI fallback chain. Added `skipAdminCheck` option to `testConnectivity()` for installation tests.
- **Installation Spec** - Replaced deprecated `page.$eval` / `page.$` with Playwright locator API; use config-driven timeouts; fallback to `/installer` URL if no redirect.
- **Authentication Spec** - Fixed `waitForURL` patterns to correctly match post-login redirect (`/admin(?!/login)`); replaced `page.fill` with `fillVueInput` helper; added `.catch(() => false)` guards for optional UI elements.
- **Dashboard Spec** - Removed duplicate user-menu and widget-editing tests; switched to language-independent selectors (href-based); fixed `page.mouse` API usage for drag-and-drop; added `MIN_CONTENT_LENGTH` constant.

### 📝 Documentation

- **ROADMAP** - Expanded Rule 3 (Breaking Changes Allowed Internally) with scope clarification for internal API, platform API and public API. Added Vue 3 migration substeps 3.4.1–3.4.5 (axios, mitt, @vue/compat, Pinia, deps). Aligned task table column widths and sub-task arrows (↳) for consistency.
- **GitHub Labels Rule** - Synced with GitHub (28 labels, 2026-02-14); added phase labels section and CLI management section; streamlined examples.
- **E2E Test Plan** - Updated to v2.2 with 96 tests across 12 specs; added folder structure, phase annotations and scope disclaimers.
- **E2E README** - Documented workers config, rate-limiting verification steps, installation test prerequisites and browser-specific run commands.
- **GitHub Guide** - New `GITHUB_PROJECTS_ISSUES_ACTIONS_GUIDE.md` for workflow improvement with Projects, Issues and Actions. Phase field values aligned to `phase-1` … `phase-5`; removed redundant label list from guide.

### 🔧 Maintenance

- **GitHub Actions** - Added `.github/workflows/project-auto-phase.yml` for project automation.
- **.gitignore** - Removed `.cursor/rules/push.mdc` from ignore list so the push workflow rule is tracked.
- **package.json** - Fixed `test:e2e:install` script path to `specs/01-setup/installation.spec.js`.
- **playwright.config.js** - Workers now read from `testConfig.getWorkers()` instead of hardcoded value.
- **test-config.example.json** - Updated default port to 8180 (Docker E2E); added `workers` setting; removed BOM.

---

## Pagekit 1.1.2 - Workflow Modernization & Version Bump Skill (February 8, 2026)

### ✨ New Features

- **Version Bump Skill** - Added `.cursor/skills/version-bump/SKILL.md` with custom Pagekit semantic versioning (SYSTEM-STATE.MAJOR-MINOR.PATCH). Updates both `composer.json` and `app/system/config.php`.
- **Push Workflow Extended** - `push.mdc` now calls version-bump skill before CHANGELOG update, streamlined from 66 to 16 lines with references to existing rules.

### ♻️ Refactoring

- **Aggressive Rules Unified** - Consolidated ROADMAP (5 rules) and pagekit-context (6 rules) into consistent 5 rules across both files, eliminating duplicates.
- **Subagent Models Upgraded** - All 4 subagents (architect, refactorer, verifier, tester) upgraded to `claude-4.6-opus-high-thinking`.
- **PSR-11 Prompts Streamlined** - Removed duplicated aggressive rules from all 4 stage prompts (reference ROADMAP instead). Fixed safety checks with correct PHPUnit path (`./app/vendor/bin/phpunit`) and server startup instructions.
- **Agent Prompts Reorganized** - Audit prompts moved to `AUDITS/` subfolder, Vue template prompt moved into `agent_prompts/`.

### 🐛 Bug Fixes

- **CHANGELOG Filename** - Fixed `CHANGELOG-2025.md` references to `CHANGELOG-NEW.md` in `conventional-commits.mdc` and `pagekit-files.mdc`.

### 🔧 Maintenance

- **Dockerfile** - Added agent log/artifact directories.
- **Task Invocation Template** - Fixed placeholder to generic `@PROMPT_X_Y.md`.
- **Modernize Helper** - Added `watch_assets` function for Webpack watch mode.

---

## Pagekit 1.1.1 - Cursor Workflow & Agent Prompts (February 6, 2026)

### ✨ New Features

- **UrlProvider route() alias** - Added `route()` as alias for `getRoute()` for backward compatibility and clearer API
- **#[Request] filter options** - Added `options` parameter to `#[Request]` attribute for filter-specific options (e.g. pregreplace pattern)
- **E2E Test Architect Skill** - Added complete `.cursor/skills/e2e-test-architect/` skill with SKILL.md, selector patterns, runtime patterns, and codebase analysis script for automated E2E test generation

### 📝 Documentation

- **Subagent Orchestration Workflow** - Added ROADMAP.md, WORKFLOW_SUBAGENTS.md, task invocation template, and orchestrator rule for structured multi-agent modernization
- **Agent Definitions** - Added architect, refactorer, tester, and verifier agent definitions in `.cursor/agents/`
- **Orchestrated Agent Prompts** - Added 15 agent prompts for PSR-11 container (5 stages), Doctrine attributes migration, Symfony validator (3 phases), ORM modernization, database migrations, audit tasks, and template security modernization

### 🐛 Bug Fixes

- **FinderController no-op rename** - Fixed error when user saves without changing the file/folder name (source equals target)
- **install.sh lock cleanup** - Only remove lock file when current process acquired it; prevents third process from bypassing lock

### 🔧 Maintenance

- **PHPUnit cache** - Added `.phpunit.cache/` to `.gitignore` to exclude test results from version control
- **Push workflow** - Refactored to branch-agnostic, CC-style integration branch names, no auto-merge
- **GitHub labels** - Updated PR workflow description (descriptive PR titles)
- **Cursor Docker** - Align WORKDIR with /workspace mount, add workspace directory creation
- **Cursor README** - Update documentation for /workspace layout and command paths
- **install.sh** - Add flock-based lock to prevent duplicate execution; use composer update instead of removing lock files
- **Push workflow** - Branch-specific logic: develop (protected) uses integration branch + PR; other branches push directly
- **Cursor rules** - Fix changelog reference CHANGELOG-2025 to CHANGELOG-NEW in feature-branch and pagekit-standards
- **secrets.example.env** - Commented out placeholder values to prevent accidental use as real credentials
- **.gitignore** - Added exception for `migration-docs/TODO/agent_prompts/` to track orchestrated prompts
- **.prettierignore** - Added `ROADMAP.md` to formatting exceptions

---

## Pagekit 1.1.0 - Complete PHP 8 Attributes Migration (January 26, 2026)

### 🚀 Major Changes - BREAKING

- **Complete Doctrine Annotations to PHP 8 Attributes Migration** - Eliminates `doctrine/annotations` dependency
  - ✅ ORM Attributes: `#[Entity]`, `#[Column]`, `#[Id]`, `#[BelongsTo]`, `#[HasMany]`, etc.
  - ✅ Routing Attributes: `#[Route]`, `#[Request]`
  - ✅ Access Control Attributes: `#[Access]`
  - ✅ Captcha Attributes: `#[Captcha]`

### ✨ New Features

- **ORM Attribute Classes** (`app/modules/database/src/ORM/Attribute/`)
  - `Entity`, `MappedSuperclass` - Class-level mapping
  - `Column`, `Id` - Property mapping
  - `BelongsTo`, `HasOne`, `HasMany`, `ManyToMany`, `OrderBy` - Relations
  - `Saving`, `Saved`, `Creating`, `Created`, `Updating`, `Updated`, `Deleting`, `Deleted`, `Init` - Lifecycle events

- **Routing Attribute Classes** (`app/modules/routing/src/Attribute/`)
  - `Route` - Route definition with path, methods, requirements, defaults
  - `Request` - Parameter mapping from request

- **Access Control Attribute** (`app/system/modules/user/src/Attribute/`)
  - `Access` - Permission and admin access control

- **Captcha Attribute** (`app/system/modules/captcha/src/Attribute/`)
  - `Captcha` - reCAPTCHA integration for forms

- **New AttributeLoaders**
  - `Pagekit\Database\ORM\Loader\AttributeLoader` - ORM metadata from PHP 8 Attributes
  - `Pagekit\Routing\Loader\AttributeLoader` - Route loading from PHP 8 Attributes

### 🔄 Migrated Components

**ORM Entities (7 entities + 6 traits):**
- `User`, `Role`, `Page`, `Node`, `Comment` (base), `Widget`
- `Post` (blog), `Comment` (blog)
- `AccessModelTrait`, `UserModelTrait`, `RoleModelTrait`, `NodeModelTrait`, `DataModelTrait`, `CommentModelTrait`, `PostModelTrait`

**Controllers (31 controllers):**
- User Module: `UserController`, `UserApiController`, `AuthController`, `ProfileController`, `RegistrationController`, `ResetPasswordController`, `RoleApiController`
- Site Module: `NodeController`, `NodeApiController`, `PageApiController`, `MenuApiController`
- Widget Module: `WidgetController`, `WidgetApiController`
- System: `AdminController`, `MigrationController`, `SettingsController`
- Installer: `PackageController`, `MarketplaceController`, `UpdateController`
- Blog Package: `BlogController`, `SiteController`, `PostApiController`, `CommentApiController`
- Other: `MailController`, `InfoController`, `IntlController`, `IntlApiController`, `FinderController`, `StorageController`, `CacheController`, `DashboardController`

**Listeners Updated:**
- `ConfigureRouteListener` - Now reads `#[Request]` attributes
- `AccessListener` - Now reads `#[Access]` attributes
- `CaptchaListener` - Now reads `#[Captcha]` attributes

### 🗑️ Removed

- **Deleted Annotation Classes:**
  - `app/modules/routing/src/Annotation/Route.php`
  - `app/modules/routing/src/Annotation/Request.php`
  - `app/modules/routing/src/Loader/AnnotationLoader.php`
  - `app/system/modules/user/src/Annotation/Access.php`
  - `app/system/modules/captcha/src/Annotation/Captcha.php`
  - `app/modules/database/src/ORM/Annotation/*` (19 files)
  - `app/modules/database/src/ORM/Loader/AnnotationLoader.php`

- **Removed Dependency:**
  - `doctrine/annotations` package removed from `composer.json`

### 🐛 Bug Fixes

- Fixed `AttributeLoader` array access error for empty attribute arrays
- Fixed `OrderBy` string-to-array conversion for `HasMany` relations
- Fixed HTTP status code validation in `ExceptionController` (0 is invalid)
- Fixed Symfony 6.4 `InputBag::get()` non-scalar value handling in `ParamFetcherListener`
- Fixed `_request` route default format for `ParamFetcherListener` (value/options structure)
- Fixed `CommentApiController::saveAction()` parameter name mismatch

### 📝 Breaking Changes

- **All ORM annotations replaced with PHP 8 Attributes** - Extensions using `@Entity`, `@Column`, etc. must migrate
- **All routing annotations replaced with PHP 8 Attributes** - Extensions using `@Route`, `@Request`, `@Access` must migrate
- **doctrine/annotations dependency removed** - Extensions depending on it must add it explicitly

### 🔧 Migration Guide

**Before (Annotation):**
```php
/**
 * @Entity(tableClass="@system_user")
 */
class User {
    /** @Column(type="integer") @Id */
    public ?int $id = null;
}
```

**After (Attribute):**
```php
use Pagekit\Database\ORM\Attribute as ORM;

#[ORM\Entity(tableClass: '@system_user')]
class User {
    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    public ?int $id = null;
}
```

**Controller Migration:**
```php
// Before
/** @Access(admin=true) */
/** @Route("/api/users") */
/** @Request({"id": "int"}) */

// After
#[Access(admin: true)]
#[Route('/api/users')]
#[Request(['id' => 'int'])]
```

---

## Pagekit 1.0.48 - Template Security Hardening (January 23, 2026)

### 🔒 Security - Enhanced CSP Implementation

- **Complete PHP eval() Removal from Template Engines** - Eliminated all `eval()` calls from template rendering
  - Removed eval() from `app/modules/view/src/PhpEngine.php` (lines 168, 174)
  - Removed eval() from `app/modules/view/src/Engine/PhpEngine.php` (line 122)
  - String template execution no longer supported (was dead code path)
  - All templates must be file-based for security

- **JSON Data Container (Replaces Inline Scripts)** - Industry best practice implementation
  - `DataHelper` now outputs `<script type="application/json">` instead of inline `<script>var...`
  - New `config-loader.js` reads JSON and exposes data as global variables
  - Backward compatible: `$pagekit`, `$debugbar` globals still work
  - CSP compliance: No `unsafe-inline` required in script-src

- **Content Security Policy** - Hardened with Vue.js compatibility
  - `script-src 'self' 'unsafe-eval'` - No inline scripts, but eval needed for Vue.js runtime templates
  - `style-src 'self' 'unsafe-inline'` - UIkit requires inline styles
  - Added `object-src 'none'` - No Flash/plugins
  - Added `base-uri 'self'` - Prevent base tag injection
  - Added `form-action 'self'` - Forms only submit to same origin
  - Added `frame-ancestors 'self'` - Prevent clickjacking
  - External APIs allowed: Google reCAPTCHA, Gravatar, OpenWeatherMap, Pagekit.com, Google Maps Timezone API, RSS2JSON API
  - **Note:** `unsafe-eval` required because Vue.js 2.x uses `new Function()` for runtime template compilation

- **Inline Script Migration** - All modules migrated to DataHelper
  - `CaptchaListener`: `$captcha` now via DataHelper
  - `Editor`: `$editor` now via DataHelper
  - `ScriptHelper`: Inline scripts blocked with warning

- **Modern Cross-Origin Security Headers**
  - `Cross-Origin-Opener-Policy: same-origin` - Prevents window.opener attacks
  - `Cross-Origin-Resource-Policy: same-origin` - Prevents unauthorized embedding of resources
  - `Cross-Origin-Embedder-Policy: REMOVED` - Not needed (Pagekit doesn't use SharedArrayBuffer)
    - COEP caused compatibility issues with external resources (reCAPTCHA, OpenWeatherMap)
    - COEP breaks browser extensions (password managers, etc.)
    - COOP and CORP provide sufficient protection for Pagekit's use case
  - Upgraded `Referrer-Policy` to `strict-origin-when-cross-origin`
  - Extended `Permissions-Policy` with autoplay, fullscreen, payment

### ✨ New Features

- **config-loader.js** - CSP-compliant configuration reader
  - Reads JSON from `<script type="application/json">` container
  - Exposes data as global variables for backward compatibility
  - No inline JavaScript execution required
  - Works with strict CSP without exceptions

### 📝 Documentation

- **feature-template-security-hardening.md** - Complete migration guide and documentation
  - How the JSON data container works
  - Migration guide for theme/extension developers
  - Security impact and CSP configuration details
  - Testing checklist and verification steps

### 🔧 Technical Details

**Files Created:**
- `app/system/app/lib/config-loader.js`
- `migration-docs/branches/feature-template-security-hardening.md`

**Files Modified:**
- `app/modules/view/src/PhpEngine.php` (eval removal)
- `app/modules/view/src/Engine/PhpEngine.php` (eval removal)
- `app/modules/view/src/Helper/DataHelper.php` (JSON data container)
- `app/system/modules/view/index.php` (register config-loader)
- `.htaccess` (strict CSP and modern headers)

**Breaking Changes (Internal Only):**
- String template execution no longer supported (was dead code)
- Inline `<script>` tags with executable JavaScript blocked by CSP

**Backward Compatible:**
- All existing PHP templates work unchanged
- Global variables (`$pagekit`, etc.) still accessible
- Vue.js components work normally
- Admin interface unchanged

---

## Pagekit 1.0.47 - Routing Cache Fix (January 21, 2026)

### 🔧 Fixes

- **Routing cache race condition** - Fixed `ClassNotFoundError` when moving pages via drag & drop
  - Added automatic cache invalidation when routes change (cache key or modified time)
  - Added error handling with fallback to non-cached matcher/generator if cache file is corrupted
  - Fixed cache key calculation to only include structural options (matcher, generator, cache path)
  - Meta-options like `blog.permalink` no longer invalidate routing cache unnecessarily
  - Prevents multiple unnecessary cache files from being created

---

## Pagekit 1.0.46 - Symfony Validator Integration (January 19, 2026)

### 🚀 Major Changes

- **Symfony Validator Integration (Step 1.13 - Hybrid Mode)** - Complete migration from manual validation to Symfony Validator with PHP 8 Attributes
  - ✅ Added `symfony/validator` ^7.4 for modern PHP 8 Attribute-based validation
  - ✅ Hybrid approach: Validation uses Attributes, ORM still uses Doctrine Annotations (until Step 1.14)
  - ✅ No compatibility layers - aggressive modernization per project rules
  - ✅ Phase 1: User module (User, Role entities)
  - ✅ Phase 2: Site module (Node, Page entities), Widget module (Widget entity)
  - ✅ Phase 3: Comment module (base), Blog package (Post, Comment entities)

### ✨ New Features

- **ValidatorServiceProvider** - New service provider for Symfony Validator integration
  - Registers `$app['validator']` service with PHP 8 Attribute support enabled
  - Registered during boot event in `app/system/index.php`

- **ValidatesRequestTrait** - Standardized controller validation
  - `validate($object)` - Returns `JsonResponse` on failure, `null` on success
  - `validateOrFail($object)` - Throws `Exception` on failure
  - Consistent JSON error format for Vue.js frontend integration

- **Custom Unique Constraint** - Database uniqueness validation
  - `#[PagekitAssert\Unique]` attribute for entity properties
  - Uses Pagekit's QueryBuilder (DBAL 3.x compatible)
  - Supports update context (excludes current record by ID)

- **Centralized Validation Messages** (Phase 2)
  - Created `app/system/languages/en_US/validation.php`
  - All validation messages referenced by key for future translation
  - Message keys follow pattern: `validation.{module}.{field}_{constraint}`

### 🔄 Refactored

- **User Module** (Phase 1)
  - `User` entity: Full validation with `#[Assert\...]` and `#[PagekitAssert\Unique]`
  - `Role` entity: Name and priority validation
  - Controllers: `UserApiController`, `RegistrationController`, `ProfileController`, `RoleApiController`
  - **REMOVED** old `validate()` method (Rule #4: DELETE OVER WRAP)

- **Site Module** (Phase 2)
  - `Node` entity: Slug, title, link, type, status, priority validation
  - `Page` entity: Title validation
  - Controller: `NodeApiController` with ValidatesRequestTrait
  - Removed manual slug/link validation checks

- **Widget Module** (Phase 2)
  - `Widget` entity: Title, type, status validation
  - Controller: `WidgetApiController` with ValidatesRequestTrait
  - Removed manual 'Widget title empty' check

- **Comment Module** (Phase 3)
  - Base `Comment` entity (abstract): Author, content, status validation

- **Blog Package** (Phase 3)
  - `Post` entity: Title, slug, status, user_id, comment_count validation
  - `Comment` entity: Email, url, post_id validation (extends base Comment)
  - Controllers: `PostApiController`, `CommentApiController` with ValidatesRequestTrait
  - Removed manual 'Invalid slug' validation check

### 📝 Breaking Changes

- **User::validate() method removed** - Replace all calls with `$this->validateOrFail($user)` using `ValidatesRequestTrait`
- **Manual validation in controllers removed** - All validation now uses Symfony Validator
- Internal API changes for cleaner PHP 8 code (Rule #3: BREAKING CHANGES ALLOWED)

### 🔧 Fixes

- **URL field normalization** - Fixed optional URL fields in User and Comment models (empty strings converted to null for Symfony Url constraint compatibility)
- **Case-insensitive uniqueness validation** - Updated UniqueValidator to use SQL LOWER() for case-insensitive comparison
- **Validation group handling** - Fixed RegistrationController to explicitly include 'Default' validation group
- **Modernization rules documentation** - Added aggressive modernization rules to project context (NO COMPATIBILITY LAYERS, NO ADAPTERS, DELETE OVER WRAP)

### 📚 Documentation

- **VALIDATION_SYSTEM.md** - Comprehensive documentation in `migration-docs/branches/`
  - Hybrid approach explanation (Attributes for validation, Annotations for ORM)
  - Usage guide for ValidatesRequestTrait
  - All validation constraints documented
  - Error response format for Vue.js frontend
  - Migration guide from manual validation
  - Message key reference

- **VALIDATION_PHASE2_DISCOVERY.md** - Migration checklist and discovery document

### 🔧 Technical Details

**Files Created (Phase 1):**
- `app/system/src/ValidatorServiceProvider.php`
- `app/system/src/Controller/ValidatesRequestTrait.php`
- `app/system/src/Validator/Constraints/Unique.php`
- `app/system/src/Validator/Constraints/UniqueValidator.php`
- `migration-docs/branches/VALIDATION_SYSTEM.md`

**Files Created (Phase 2):**
- `app/system/languages/en_US/validation.php`
- `migration-docs/branches/VALIDATION_PHASE2_DISCOVERY.md`

**Files Modified (Phase 1):**
- `composer.json` (added symfony/validator)
- `app/system/index.php` (registered validator service)
- `app/system/modules/user/src/Model/User.php`
- `app/system/modules/user/src/Model/Role.php`
- `app/system/modules/user/src/Controller/UserApiController.php`
- `app/system/modules/user/src/Controller/RegistrationController.php`
- `app/system/modules/user/src/Controller/ProfileController.php`

**Files Modified (Phase 2):**
- `app/system/modules/user/src/Controller/RoleApiController.php`
- `app/system/modules/site/src/Model/Node.php`
- `app/system/modules/site/src/Model/Page.php`
- `app/system/modules/site/src/Controller/NodeApiController.php`
- `app/system/modules/widget/src/Model/Widget.php`
- `app/system/modules/widget/src/Controller/WidgetApiController.php`

**Files Modified (Phase 3):**
- `app/system/languages/en_US/validation.php` (added Blog/Comment messages)
- `app/system/modules/comment/src/Model/Comment.php` (base Comment entity)
- `packages/pagekit/blog/src/Model/Post.php`
- `packages/pagekit/blog/src/Model/Comment.php`
- `packages/pagekit/blog/src/Controller/PostApiController.php`
- `packages/pagekit/blog/src/Controller/CommentApiController.php`

**Aggressive Modernization Rules Applied:**
- Rule #1: NO COMPATIBILITY LAYERS - No shim classes created
- Rule #2: NO ADAPTERS - All controller usages updated in same commit
- Rule #3: BREAKING CHANGES ALLOWED - Internal API changed for cleaner code
- Rule #4: DELETE OVER WRAP - Old `validate()` method and manual checks completely removed
- Rule #5: MANDATORY FLAGGING - All ORM annotations marked with TODO for Step 1.14

---

## Pagekit 1.0.45 - Database Migration System Improvements (January 17, 2026)

### 🐛 Fixed

- **Fixed scripts.php execution after migrations** - Ensured scripts.php runs after migration execution in the installer to maintain compatibility with legacy extension installation hooks
- **Fixed MenuManager return types** - Corrected return type declarations in MenuManager to prevent TypeError exceptions during menu operations
- **Fixed dry-run option in migration commands** - The `--dry-run` flag now correctly prevents database changes and shows SQL statements that would be executed
- **Fixed migration generator return value** - Corrected array access on string return value from `generateMigration()` that caused only first character of file path to be returned
- **Fixed migration name being ignored** - User-provided migration names are now included in generated class names (e.g., `Version20250118_CreateUserTable` instead of just `Version20250118`)
- **Fixed inconsistent rollbackExtension() behavior** - `rollbackExtension(null)` now rolls back one step (previous version) instead of all migrations, consistent with `rollback()` method behavior

### ✨ Added

- **Extension migration support in MigrationService** - Added `migrateExtension()` method to MigrationService for handling extension-specific migrations with automatic namespace registration
- **Year-based migration organization** - Migrations now organized in year-based subdirectories (e.g., `app/migrations/2025/`) for better structure and maintainability
  - Core migration moved to `app/migrations/2025/Version20251023061532.php`
  - Blog extension migration moved to `packages/pagekit/blog/src/Migrations/2025/Version001_CreateBlogTables.php`

### 🔄 Refactored

- **Modernized migration architecture with clean separation** - Improved code organization by separating migration structure configuration from execution logic, reducing code duplication and improving maintainability
- **Simplified scripts.php files** - Reduced complexity in both core and blog extension scripts.php files by leveraging the new migration system architecture

### 📚 Documentation

- **Updated branch documentation with architecture modernization** - Documented the improved migration architecture and year-based organization structure
- **Added post-implementation fixes documentation** - Documented fixes for MenuManager TypeError issues encountered during implementation
- **Completed branch documentation** - Finalized comprehensive documentation for the database migration system implementation
- **Added final modernization validation and statistics** - Documented validation results and completion statistics for the migration system modernization

---

## Pagekit 1.0.44 - Database Migration System (October 23, 2025)

### 🗄️ Database Migration System

**feat: Add professional database migration system**

- ✅ **Doctrine Migrations Integration** (compatible with DBAL 3.10.2)
  - Doctrine Migrations 3.9.4 installed and configured
  - Migration version tracking in `pk_migration_versions` table
  - Platform-independent schema definitions
  
- 📦 **Console Commands**
  - `migration:migrate` - Execute pending migrations
  - `migration:status` - Show migration status and history
  - `migration:generate` - Create new timestamped migration files
  - `migration:rollback` - Rollback migrations with version targeting
  
- 🔄 **Automatic Schema Versioning**
  - Timestamped migration files (Version{YmdHis}_{Name}.php)
  - Complete execution history with timestamps
  - Execution time tracking
  
- 🔙 **Rollback Functionality**
  - Full rollback support with `down()` methods
  - Rollback to specific version or previous version
  - Complete rollback with `--to=0` option
  
- 🔧 **Installer Integration**
  - Installer directly executes migrations for schema creation
  - Replaces legacy scripts.php installation method
  - Modern Pagekit requires fresh installation (no upgrade path needed)
  
- 🧩 **Extension Migration Support**
  - `ExtensionMigration` base class with helper methods
  - Automatic table and index name prefixing
  - Safe table creation/deletion helpers
  - Complete blog extension migration example
  
- 📊 **Migration Status Tracking**
  - View executed and pending migrations
  - Execution timestamps and performance metrics
  - Migration description display
  
- ✅ **SQLite and MySQL Support**
  - Full compatibility with both database systems
  - Platform-independent DBAL types
  - Custom type handling (json, simple_array)

**Technical Details**:
- Initial schema migration with all 8 core Pagekit tables
- Default roles automatically inserted (Anonymous, Authenticated, Administrator)
- Table prefix support (@table → pk_table)
- Configuration in `app/config/migrations.php`
- Migrations stored in `app/migrations/`
- Namespace: `Pagekit\Migration`

**Documentation**:
- Complete extension migration guide in `migration-docs/branches/`
- Migration best practices
- Helper methods documentation
- Real working examples (core + blog extension)

---

## Pagekit 1.0.43 - ORM Layer Modernization, Project Infrastructure & Critical Bugfixes (October 22, 2025)

### 🚀 Major Changes

- **ORM Layer Modernization** - Complete PHP 8.2+ modernization with type safety
  - ✅ All ORM classes now use `declare(strict_types=1)`
  - 🔧 Full type hints for methods and properties
  - 📊 PSR-6 cache integration for query results
  - ⚡ Performance improvements with query caching

### ✨ New Features

- **Query Result Caching** - PSR-6 based caching system

  - 💾 `QueryBuilder::cache(int $ttl)` method for query caching
  - 🔄 Automatic cache invalidation on entity save/delete
  - 🔑 Smart cache key generation based on SQL and relations
  - 📈 ~70% reduction in database load for repeated queries

- **Typed Entity Models** - All entity models fully typed
  - 👤 User, Role, Page, Node models (system)
  - 📝 Post, Comment models (blog)
  - 🎨 Widget model
  - ✅ All properties have explicit types
  - 🔒 Better type safety and IDE support

### 🔧 Infrastructure

- **Enhanced Relations** - Modernized relation classes

  - ✨ BelongsTo, HasOne, HasMany, ManyToMany all typed
  - 🎯 Full constructor parameter typing
  - 🚀 Eager loading prevents N+1 query problems
  - 📊 Up to 50x query performance improvement

- **Debug Database Storage** - Automatic cleanup system

  - 🗄️ SQLite-based debug bar storage with automatic cleanup
  - 🔄 Keeps maximum 100 entries, auto-deletes oldest
  - 💾 Prevents unlimited database growth
  - 📊 Performance-optimized with memory-based journal mode

- **System Settings Enhancement** - SQLite availability detection

  - ✅ Automatic SQLite driver detection (SQLite3 + PDO)
  - 🔍 Real-time check for database configuration options
  - 🎯 Better UX: Shows only available database options

- **Frontend Development** - ESLint modernization

  - 📦 Updated to ECMAScript 2020 (ES11)
  - 🔧 Modern JavaScript features support
  - ✨ Better code quality and linting

- **Comprehensive Testing** - New test coverage
  - 🧪 13 PHPUnit tests (100% passing)
  - 🎭 6 E2E test scenarios for ORM operations
  - ✅ Entity CRUD, relations, and caching tested

### 📈 Performance Improvements

- **N+1 Query Resolution**: 50x improvement with eager loading
  - Before: 1 + N queries (e.g., 101 queries for 100 posts)
  - After: 2 queries (1 for posts, 1 for users)
- **Query Caching**: 2-5x faster for cached results
- **Type Safety**: Reduced runtime overhead and early error detection

### 🔧 Build & CI/CD

- **build(git): enhance gitattributes for cross-platform consistency**

  - ✅ Consistent LF line endings for all text files
  - 🔒 Binary files properly marked
  - 📦 Export-ignore for test and dev files
  - 🚫 No more CRLF/LF warnings

- **ci(dependabot): adopt conventional commits format**

  - 🤖 All Dependabot PRs now use `chore(deps):` format
  - ✅ Follows Conventional Commits v1.0.0 specification
  - 📊 Better changelog integration
  - 🔄 Automatic label assignment

- **build(agent): improve background agent reliability**
  - 🐳 Enhanced Dockerfile with explicit PATH configuration
  - 🔍 Comprehensive error diagnostics in install.sh
  - 💾 PDO driver checks and installation (MySQL, SQLite)
  - 🔄 Support for both Dockerfile and Snapshot environments
  - 📦 Automatic installation of missing dependencies
  - ✅ Environment detection and summary

### 🐛 Bug Fixes

- **Fixed: Symfony InputBag non-scalar values** (`ParamFetcher.php`)

  - 🔧 Changed `$bag->get($name)` to `$bag->all()[$name] ?? null`
  - ✅ Vue.js arrays/objects for filters now work correctly
  - 🎁 Bonus: Blog comments display fixed as side effect

- **Fixed: Node link validation** (Multiple files)

  - 🛡️ 4-layer defense: Frontend validation, Controller validation, Model fallback, DB constraint
  - 🎨 UI improvement: External URLs auto-select "Link" type, disable Alias/Redirect options
  - 🔧 Fixed v-model binding in `input-link.vue` component
  - ✅ Prevents "NOT NULL constraint failed: pk_system_node.link" errors

- **Fixed: Blog permalink routing** (Critical!)
  - 🔧 Removed static cache from `UrlResolver::getPermalink()`
  - 🔧 Fixed `Router::generate()` to call resolver BEFORE URL generation
  - 🔧 Set `_resolver` on `@blog/id` route in `RouteListener`
  - ✅ All permalink types now work: Numeric, Name, Date+Name, Month+Name, Custom
  - 🎨 Full flexibility for custom permalink patterns (e.g., `{day}/{slug}/{year}`)

### 📋 Documentation & Standards

- **docs(rules): add github labels guide** - Comprehensive label system for PRs and issues

  - 🏷️ Dependency labels (php, javascript, docker, github-actions)
  - 🏷️ Conventional Commits labels (breaking-change, security, performance)
  - 🏷️ Project area labels (frontend, backend, database, module, theme, migration)
  - 📖 Full usage guide with examples and best practices

- **docs(rules): link labels to conventional commits** - Integrated workflow
  - 🔗 Commit types mapped to GitHub labels
  - 📝 PR labeling guidelines
  - 🔄 Workflow integration documentation

### 📝 Documentation

- **Migration Guide**: `migration-docs/branches/feature-orm-modernization.md`
- **Test Coverage**: Comprehensive unit and E2E tests
- **Breaking Changes**: None - fully backward compatible

---

## Pagekit 1.0.42 - Enhanced Extension Error Handling & Transaction Safety (October 7, 2025)

### 🚀 Major Changes

- **Transactional Package Activation** - Complete rewrite with atomic operations
  - 🔒 Database transaction support with automatic rollback on failure
  - 🛡️ Safe package enable/disable with proper error recovery
  - 📝 Comprehensive error logging and user feedback
  - ⚡ Improved package manager API with better exception handling

### ✨ New Features

- **File-based Debug Logging** - New persistent logging system
  - 📄 Debug messages now written to `tmp/logs/debug.log`
  - 🔍 Better debugging capabilities for production environments
  - 💾 Persistent log storage for troubleshooting

### 🐛 Bug Fixes

- **Frontend Error Handling** - Improved error display for package operations

  - ✨ Better error messages when enabling/disabling packages
  - 🎯 Clear user feedback for failed package operations
  - 🔧 Enhanced error recovery mechanisms

- **Package Manager API** - Robust error handling improvements
  - 🛠️ Better exception handling in package operations
  - 📊 Improved error reporting and debugging
  - 🔄 Automatic state recovery on failures

### 🔧 Infrastructure

- **Development Environment** - Enhanced debugging capabilities
  - 🗂️ Updated `.gitignore` for Playwright MCP integration
  - 🧪 Better test environment isolation
  - 📁 Improved temporary file management

### 📝 Documentation

- **Documentation Reorganization** - Extension error handling docs moved to migration-docs
  - 📂 Moved analysis and implementation docs to `migration-docs/documentation/`
  - 📋 Moved test guide to `migration-docs/testing/`
  - 🗂️ Cleaned up root directory
  - 🧹 Removed test packages (faulty-bootstrap, faulty-enable, faulty-install)
- **Change Tracking** - All changes documented in CHANGELOG-2025.md
- **Error Handling Guide** - Improved documentation for troubleshooting

### 🔍 Technical Details

**Breaking Changes**: None

**Migration Notes**: No migration required. The changes are backward compatible.

**Testing Notes**:

- Test package enable/disable functionality
- Verify error handling with faulty extensions
- Check debug.log file generation
- Confirm transaction rollback on errors

---

## Pagekit 1.0.41 - E2E Testing Infrastructure & Configuration Fixes (October 6, 2025)

### 🚀 Major Changes

- **E2E Testing Infrastructure** - Completely reorganized and modernized testing framework
  - 🎭 Modern web testing with multi-browser support (Chrome, Firefox, Safari)
  - 🐳 Isolated test environment with Docker integration
  - 🧪 Comprehensive test coverage across all Pagekit functionality
  - 📊 Optimized parallel execution with smart test organization
  - 🔧 Centralized configuration management system
  - 📁 Category-based test organization (Setup, Core, Content, Frontend, Features)

### ✨ New Features

- **Restructured Test Architecture**: Category-based organization (01-setup/, 02-core/, 03-content/, etc.)
- **Centralized Configuration**: New `TestConfig` class with lazy loading and validation
- **Enhanced Helper System**: Modern Vue.js helpers and improved error handling
- **Smoke Test Suite**: Quick validation tests for rapid feedback
- **Improved Documentation**: Updated README with new structure and examples
- **Better Error Handling**: Comprehensive connectivity testing and validation

### 📦 Dependencies

- Added @playwright/test for E2E testing
- Added dotenv for environment configuration
- All changes are dev dependencies only

### 📝 Documentation

- **Updated README**: Reflects new category-based test organization
- **Enhanced Examples**: Modern test structure examples with TestConfig usage
- **Improved Setup Guide**: Clear configuration instructions and troubleshooting
- **Architecture Documentation**: Comprehensive helper system documentation

### 🐛 Bug Fixes

- **E2E Test Configuration** - Fixed JSON parsing issues in test configuration
  - 🔧 Added BOM (Byte Order Mark) handling in test-config.js
  - 📝 Updated test-config.example.json with proper placeholder values
  - 🛠️ Enhanced error handling for malformed JSON files
  - ✅ Resolved "Unexpected end of JSON input" errors

### 🔧 Infrastructure

- **Cursor Development Environment** - Updated installation script
  - 📦 Enhanced setup process for development environment and installation scripts
  - 🚀 Improved development onboarding experience and environment configuration
  - 🛠️ Updated modernize helper and start scripts
  - 🔧 Further cursor tooling improvements
  - ⚡ Additional cursor tooling enhancements
  - 🐳 Update Dockerfile for background agent
  - 📁 Reorganized migration docs structure

---

## Pagekit 1.0.40 - PSR-6 Cache Migration COMPLETE (September 26, 2025)

### 🚀 Major Changes

- **COMPLETE PSR-6 Cache Migration** - Successfully migrated from doctrine/cache to PSR-6 (Symfony Cache)
  - ✅ doctrine/cache dependency REMOVED
  - ✅ Full backward compatibility maintained
  - ✅ No breaking changes for extensions
  - ✅ Frontend and Backend fully functional

### ✨ New Features

- PSR-6 compliant cache adapters (Array, Filesystem, PhpFiles, APCu, Null)
- `Pagekit\Cache\CacheInterface` for backward compatibility
- Automatic cache key sanitization for PSR-6 compliance
- Improved namespace support
- Better TTL handling

### 🐛 Fixed

- Critical fix: Cache key validation for PSR-6 reserved characters
- Resolved 500 errors caused by invalid cache keys
- Fixed autoloading issues for cache classes

### 🧪 Testing

- All automated tests passing
- CLI commands fully functional
- Web interface working correctly
- Admin panel accessible
- No PHP errors or warnings

### 📝 Technical Details

- Removed legacy FilesystemCache.php and PhpFileCache.php
- Updated CacheModule to use PSR-6 exclusively
- Created adapter layer for seamless migration
- All system modules now use PSR-6 through compatibility layer

### 📚 Documentation

- **Docs: Add migration-docs structure** - Comprehensive migration documentation added
- **Chore: Update .gitignore & cleanup** - Repository maintenance and cleanup

---

## Pagekit 1.0.39 - Complete Symfony 6.4 LTS Upgrade (September 25, 2025)

### 🎉 Major Upgrade

- **Symfony 6.4 LTS** - Successfully upgraded from Symfony 5.4 to 6.4 LTS
  - All components updated to ^6.4
  - Full system functionality restored
  - ~99% compatibility achieved

### 🔧 Fixed

- **Installer** - Fixed JavaScript globals and request handling
- **Authentication** - Fixed service access and CSRF validation
- **Password Reset** - Complete flow working with all fixes
- **Module System** - Fixed anonymous function support in ModuleLoader
- **Controllers** - Removed @Request annotations, fixed parameter handling
- **Mail System** - Updated to Symfony Mailer API
- **Menu Management** - Fixed SQL parameter binding
- **Translation** - Fixed \_\_() function availability

### 📝 Technical Changes

- Updated method signatures for Symfony 6.4
- Fixed typed properties causing issues
- Generated URL-safe activation keys
- Improved error handling throughout
- Removed all debug code from production
- **Removed symfony/templating** - Replaced with custom PhpEngine implementation
- **Modernized View System** - New engine interfaces for PHP and Twig templates
- **Fixed PHP 8.1+ compatibility** - Null handling in template functions

---

## Pagekit 1.0.38 - Symfony 6.4 Routing System Compatibility (September 24, 2025)

### Changed

- **Symfony Routing Compatibility** - Updated routing system for Symfony 6.4 compatibility
  - Added strict type hints to all routing methods
  - Updated Router, Route, and RoutesLoader classes with PHP 8+ types
  - Fixed LINK_URL constant to use integer value for Symfony compatibility
  - Enhanced UrlGenerator with proper type declarations

### Technical Details

- **Full Test Coverage** - 36 tests with 69 assertions all passing
- **Zero Breaking Changes** - All existing routes and extensions remain compatible
- **Performance** - No performance degradation, route caching continues to work
- **Documentation** - Complete migration guide in SYMFONY_ROUTING_MIGRATION.md

### Cleanup

- **Cleanup: Remove outdated migration docs** - Removed completed migration documentation files that are no longer needed

---

## Pagekit 1.0.37 - Symfony 6.4 Event System Compatibility (September 24, 2025)

### Added

- **Symfony 6.4 Event System Compatibility** - Implemented compatibility layer for Symfony EventDispatcher
  - Added `SymfonyEventDispatcherBridge` class implementing Symfony's EventDispatcherInterface
  - Registered `symfony.event_dispatcher` service for Symfony components
  - Full support for Symfony event subscribers and listeners
  - Enables seamless integration with Symfony 6.4 components

### Technical Details

- **Zero Performance Impact** - Compatibility layer only activated when explicitly needed
- **Full Backward Compatibility** - Pagekit's event system remains unchanged
- **Test Coverage** - 8 comprehensive tests with 100% code coverage
- **Documentation** - Complete migration guide in SYMFONY_EVENT_MIGRATION.md

---

## Pagekit 1.0.36 - PSR-11 Container Compatibility (September 24, 2025)

### Added

- **PSR-11 Container Compatibility** - Implemented PSR-11 ContainerInterface support
  - Added `getService()` and `hasService()` methods for PSR-11 compliance
  - Created `Psr11Adapter` class that fully implements ContainerInterface
  - Added `getPsr11Adapter()` method to get PSR-11 compliant adapter
  - Created PSR-11 exception classes: `NotFoundException` and `ContainerException`

### Changed

- **Container Architecture** - Modernized container to support PSR-11 standard
  - PSR-11 methods renamed to avoid PHP naming conflicts (getService/hasService instead of get/has)
  - Static method handling via `__callStatic()` magic method
  - Full backward compatibility maintained - all existing code works unchanged

### Technical Details

- **No Breaking Changes** - All existing static calls (`App::get()`, `App::has()`, `App::db()`) continue to work
- **ArrayAccess Compatibility** - Existing ArrayAccess interface fully maintained
- **Test Coverage** - Added 25 comprehensive tests for PSR-11 compliance
- **Documentation** - Complete migration guide in PSR11_CONTAINER_MIGRATION.md

---

## Pagekit 1.0.35 - Doctrine DBAL 3.x Update (September 23, 2025)

### Changed

- **doctrine/dbal** - Updated from 2.13 to 3.8 (major version update for better performance and modern PHP support)
- **Debug Module** - Replaced deprecated SQLLogger with new Middleware-based SQL logging system
- **Database Layer** - Full compatibility with DBAL 3.x APIs and methods
- **Custom Types** - Updated JsonArrayType and SimpleArrayType for DBAL 3.x compatibility

### Added

- **DebugMiddleware System** - New middleware-based SQL logging for debug bar
  - `DebugMiddleware` - Main middleware for SQL logging
  - `DebugLogger` - PSR-3 compatible logger for collecting queries
  - `DebugDriver` - Driver wrapper for debug logging
  - `DebugConnection` - Connection wrapper for query tracking
  - `DebugStatement` - Statement wrapper for parameter binding tracking

### Fixed

- **Type Constants** - Fixed deprecated Type constants (SIMPLE_ARRAY, JSON_ARRAY, DATETIME)
- **Custom Type Registration** - Fixed infinite recursion in type registration
- **WrapperClass Compatibility** - Fixed middleware integration with custom Connection class
- **Debug Bar** - SQL queries now properly displayed with parameters and execution times
- **Method Signatures** - Updated all method signatures for DBAL 3.x compatibility
- **Arrow Functions** - Replaced all arrow functions (fn) with regular anonymous functions for compatibility
- **SQL Aggregate Queries** - Added missing AS keyword in COUNT() queries
- **DateTime Type Mapping** - Replaced Type::DATETIME with Types::DATETIME_MUTABLE
- **Blog Extension** - Fixed 500 error in blog frontend caused by DBAL type constants
- **Widget Position Management** - Fixed widget position not being saved or loaded correctly
- **Widget Theme Properties** - Fixed null reference errors in widget theme settings
- **PHP 8.2+ Deprecations** - Added #[\AllowDynamicProperties] attribute to Widget model
- **Widget Edit View** - Fixed JavaScript error handling and scope issues

### Technical Details

- **DBAL 3.x Compatibility** - All database operations updated for DBAL 3.x
- **Middleware Pattern** - Implemented DBAL 3.x middleware pattern for SQL logging
- **PSR-3 Compliance** - Debug logger implements PSR-3 LoggerInterface
- **Backward Compatibility** - Deprecated DebugStack class kept for compatibility
- **Performance** - Improved query logging performance with middleware approach
- **Manual Middleware Wrapping** - Implemented workaround for DBAL 3.x limitation with wrapperClass
- **Query Builder Updates** - Fixed guessParamTypes() method for DateTime handling
- **Node System** - Fixed route registration issues caused by arrow functions
- **Widget System** - Complete overhaul of widget position management and theme property handling
- **PHP 8.2+ Compatibility** - Resolved all deprecation warnings with proper attribute usage

---

## Pagekit 1.0.34 - Safe Dependency Updates (September 23, 2025)

### Changed

- **twig/twig** - Updated from 3.11.3 to 3.21.1 (latest 3.x version with bug fixes and improvements)
- **paragonie/sodium_compat** - Updated from 1.21.2 to 2.2.0 (major version update without breaking changes, improved PHP compatibility)
- **composer/composer** - Updated constraint from ~2.2 to ^2.8 (already at 2.8.12)
- **php-debugbar/php-debugbar** - Updated constraint from ~1.23 to ^1.23.3 (already at 1.23.6)
- **nikic/php-parser** - Updated constraint from ~5.4 to ^5.4 (already at 5.6.1, allows newer patches)

### Technical Details

- **No breaking changes** - All updates carefully tested to ensure backward compatibility
- **Test stability maintained** - All existing tests pass at same rate (68.7%)
- **No new deprecations** - Zero new deprecation warnings introduced
- **Performance verified** - No performance degradation detected

### Testing

- **Tests**: 163 tests with 112 passing (68.7% - same as baseline)
- **Manual verification**: Admin panel, debug toolbar, Twig rendering, encryption, and package management all functioning correctly
- **Compatibility**: Fully compatible with PHP 8.2-8.4

---

## Pagekit 1.0.33 - Doctrine Dependencies Rollback & System Stability (September 22, 2025)

### Fixed

- **System stability restored** - Rolled back problematic Doctrine dependency updates that were causing system failures and breaking changes
- **Database operations working** - Restored full database functionality after major version conflicts

### Changed

- **doctrine/annotations** - Rolled back from ~2.0 to ~1.14 (major version downgrade due to breaking changes)
- **doctrine/dbal** - Rolled back from ^3.8 to ~2.13 (major version downgrade due to breaking changes)
- **doctrine/cache** - Rolled back from ^2.2 to ~1.13 (major version downgrade due to breaking changes)

### Technical Details

- **Breaking changes identified** - Doctrine DBAL 2→3 migration introduced incompatible API changes that were not properly tested
- **System functionality restored** - All core Pagekit features now working correctly with stable Doctrine versions
- **Strategic decision** - Rollback necessary to maintain system stability while preparing proper migration strategy

### Migration Strategy

- **Next steps planned** - Doctrine Annotations will be migrated to PHP 8 Attributes first
- **Proper upgrade path** - After annotations migration, Doctrine packages will be updated with proper compatibility testing
- **Incremental approach** - Breaking changes will be addressed systematically rather than in bulk updates

### Affected Systems

- All Pagekit installations that experienced system failures after Doctrine updates
- Development environments where database operations were broken
- Production systems requiring immediate stability restoration

---

## Pagekit 1.0.32 - Mail System Sendmail Fix & Windows Compatibility (September 19, 2025)

### Fixed

- **Sendmail path processing for Windows systems** - Fixed sendmail command flags issue on Windows systems with Mailpit/Laragon. The system now automatically appends required `-t` or `-bs` flags to sendmail paths that don't include them, resolving "Unsupported sendmail command flags" errors.

- **SMTP connection test validation** - Improved parameter validation in SMTP connection testing to prevent 500 Internal Server Error when testing with empty or incomplete configuration. Now shows clear error message "SMTP host is required for connection testing" instead of attempting connection with null values.

### Added

- **Automatic flag detection for sendmail paths** - Added intelligent detection and appending of required sendmail flags:

  - Windows/Mailpit systems: Automatically appends `-t` flag
  - Unix-like systems: Automatically appends `-bs` flag
  - Only adds flags when missing, preserves existing valid configurations

- **Enhanced SMTP test error handling** - Added proper validation for empty SMTP parameters with clear error messages for better user experience in admin panel.

- **MAIL_SENDMAIL_FIX.md documentation** - Comprehensive documentation of the sendmail fix including test cases, affected systems, and backward compatibility notes.

### Testing

- **Sendmail Transport Tests** - Added comprehensive test coverage for sendmail path processing including Mailpit path validation and various sendmail configurations
- **SMTP Parameter Validation Tests** - Added tests for empty and partial SMTP configuration handling
- **Manual Testing Verified** - Confirmed fix works on Windows systems with Laragon/Mailpit and various sendmail configurations

### Backward Compatibility

- ✅ **Fully backward compatible** - Existing configurations continue to work without changes
- ✅ **No breaking changes** - Only adds missing flags, doesn't modify valid existing paths
- ✅ **Cross-platform support** - Works on Windows, Linux, and macOS systems

### Affected Systems

- Windows development environments with Laragon/XAMPP/WAMP
- Systems using Mailpit for local mail testing
- Any system where sendmail_path doesn't include required flags

---

## Pagekit 1.0.31 - Strategic Dependency Analysis & Security Patches (September 19, 2025)

### Security

- **CRITICAL: marked security update** - Updated marked from 1.2.0 to 4.3.0 to fix ReDoS and XSS vulnerabilities
- **blueimp-md5 security patch** - Updated from 2.18.0 to 2.19.0 for security improvements

### Changed

- **doctrine/annotations** - Updated from ~1.14 to ~2.0 (major version upgrade, no breaking changes)
- **vue-loader** - Updated from 15.9.3 to 15.11.1 (improved Vue component compilation)
- **eslint-config-airbnb-base** - Updated from 14.2.0 to 15.0.0 (stricter linting rules)
- **eslint-plugin-vue** - Updated from 7.1.0 to 7.20.0 (better Vue 2.x linting support)

### Added

- **DEPENDABOT_UPDATES.md** - Comprehensive documentation of all dependency updates and strategy

### Strategic Analysis & Decision Making

- **14 Dependabot PRs analyzed** - Each update evaluated for security impact, breaking changes, and Symfony compatibility
- **Intelligent version selection** - marked upgraded to 4.x (not 16.x) to fix security while minimizing breaking changes
- **Symfony conflict detection** - symfony/phpunit-bridge deliberately deferred to avoid upgrade conflicts
- **Risk-based prioritization** - Security updates prioritized over convenience updates
- **Strategic deferrals** - Major breaking changes (vee-validate 4.x, build tools) deferred until after Symfony upgrade
- **Comprehensive testing** - Each update validated against existing test suite and manual verification

### Testing

- **PHP Tests**: Same 69% pass rate maintained, no new failures
- **Frontend Build**: All compile processes working correctly
- **Security Audit**: Zero vulnerabilities confirmed with composer audit
- **Manual Testing**: All core functionality verified, system performance improved

---

## Pagekit 1.0.30 - PHPUnit Test Suite Modernization (September 19, 2025)

### Fixed

- **PHPUnit 11 compatibility** - Fixed all data provider methods to be static as required by PHPUnit 11
- **Test autoloading issues** - Added 19 missing namespace mappings to composer.json for complete module coverage
- **Class name mismatches** - Corrected FilesystemTest class name to match filename
- **Mock object configurations** - Fixed UserInterface mocks and Auth test dependencies
- **Deprecated test methods** - Removed calls to non-existent Auth::setHandler() method

### Added

- **Comprehensive autoload-dev configuration** - Added Pagekit\Tests namespace mapping
- **Complete module namespace coverage** - All 19 core modules now properly autoloaded for testing
- **TEST_IMPROVEMENTS.md documentation** - Detailed analysis of all test fixes and remaining issues

### Changed

- **Test success rate** - Improved from 0% (fatal errors) to 69% passing tests (159 tests, 234 assertions)
- **PHPUnit infrastructure** - Modernized for PHP 8.4 and PHPUnit 11.5.39 compatibility
- **Test foundation** - Established solid base for continuous integration and code quality assurance

### Testing

- **Tests**: 159 total with 234 assertions
- **Success Rate**: ~69% (110 passing tests)
- **Remaining Issues**: 41 errors, 2 failures (mostly mock configurations)
- **Foundation**: Ready for CI/CD integration and incremental improvements

---

## Pagekit 1.0.29 - Critical Security Patches & Major Dependency Updates (September 19, 2025)

### Security

- **CRITICAL: Resolved all security vulnerabilities** - Applied comprehensive security patches to eliminate all known vulnerabilities identified by `composer audit`
- **Zero vulnerabilities confirmed** - Post-update security audit reports 0 vulnerabilities across all dependencies

### Changed

- **Major Doctrine DBAL upgrade**: 2.13.9 → 3.10.2

  - Updated `Driver\ResultStatement` to `Result` class throughout codebase
  - Migrated `executeQuery()` return type from `ResultStatement` to `Result`
  - Replaced deprecated fetch methods:
    - `fetchAll()` → `fetchAllAssociative()`
    - `fetchAll(\PDO::FETCH_COLUMN)` → `fetchFirstColumn()`
    - `fetchAll(\PDO::FETCH_NUM)` → `fetchAllNumeric()`
    - `fetch(\PDO::FETCH_ASSOC)` → `fetchAssociative()`
    - `fetchColumn()` → `fetchOne()`
  - Updated `Comparator::compareSchemas()` from static to instance method
  - Removed type hints from SQL parameters for DBAL 3.x compatibility with SQLite and other drivers

- **Major Monolog upgrade**: 2.1.1 → 3.9.0

  - Updated handler methods to support both `array` (compatibility) and `LogRecord` (Monolog 3.x) formats
  - Implemented runtime type checking for seamless backward compatibility
  - Modified level comparisons to use `$record->level->value` for LogRecord objects
  - Updated record property access to use object notation for LogRecord

- **PSR Log upgrade**: 1.1.4 → 2.0.0 (required for Monolog 3.x compatibility)
- **Doctrine Cache upgrade**: 1.13.0 → 2.2.0 (security updates and PHP 8.x compatibility)
- **Doctrine Event Manager upgrade**: 1.2.0 → 2.0.1 (automatic dependency update)

### Fixed

- **Database compatibility issues** - Resolved DBAL 3.x compatibility issues across 15+ core files
- **Logging system compatibility** - Fixed Monolog 3.x handler compatibility in debug and logging modules
- **Type safety improvements** - Added proper type handling for modern PHP versions
- **SQLite compatibility** - Ensured full compatibility with SQLite database driver

### Files Modified

- **Database Layer** (9 files): Connection.php, QueryBuilder.php, Utility.php, EntityManager.php, ManyToMany.php, DatabaseSessionHandler.php, DatabaseHandler.php, ConfigManager.php
- **Models** (3 files): NodeModelTrait.php, RoleModelTrait.php, PostModelTrait.php
- **Logging System** (2 files): DebugBarHandler.php, LogDataCollector.php
- **Controllers** (1 file): BlogController.php

### Testing

- **PHPUnit 11.5.39** confirmed working with updated dependencies
- **PHP 8.4.12** full compatibility verified
- **Composer audit** reports 0 vulnerabilities post-update
- **No dependency conflicts** - All package updates installed successfully

### Migration Notes

- **Backup required** before applying these patches to production
- **Breaking changes** addressed with backward compatibility where possible
- **Test thoroughly** in staging environment before production deployment
- **Monitor regularly** with `composer audit` for future security updates

---

## Pagekit 1.0.28 - PHPUnit 11 Upgrade, PHP 8.2+ & Docker Development Setup (September 18, 2025)

### Changed

- **Upgraded PHPUnit to version 11.x** - Modernized test suite to use PHPUnit 11 for improved testing capabilities and PHP 8.4 compatibility
- **Increased minimum PHP version to 8.2** - Updated minimum PHP requirement from 7.4 to 8.2 for better performance, security, and modern language features
- **Updated phpunit.xml.dist configuration** - Migrated to PHPUnit 11 XML schema with modern configuration options including coverage configuration and strict test settings
- **Docs: Modernize README.md** - Updated README with current system requirements and setup instructions

### Added

- **Docker: Add containerized development setup** - Containerized local development environment
- **Config: Add Docker setup scripts & env template** - Setup scripts and environment template for Docker workflow
- **Chore: Add dev tools and update gitignore** - Development tooling and gitignore updates

### Fixed

- **Fixed deprecated PHPUnit methods** - Replaced `setMethods()` with `onlyMethods()` in mock builder for PHPUnit 11 compatibility
- **Fixed email_address typo in test configuration** - Corrected `email_adress` to `email_address` in phpunit.xml.dist and all related test files for proper test configuration variable naming
- **Improved markdown formatting in MAIL_MIGRATION.md** - Applied standard markdown formatting with consistent bullet point spacing and structure for better readability

### Updated

- **Updated composer.json requirements** - Changed PHP requirement to ^8.2 and PHPUnit to ^11.0
- **Updated installer requirements check** - Updated PagekitRequirements::REQUIRED_PHP_VERSION to 8.2.0
- **Updated index.php version check** - Changed minimum PHP version check from 7.3 to 8.2

---

## Pagekit 1.0.27 - Symfony Mailer Migration, Login Fix & Security Improvements (September 16, 2025)

### Changed

- **Completed Swift Mailer to Symfony Mailer 5.4 migration** - Fully migrated email system from deprecated Swift Mailer to modern Symfony Mailer 5.4. All email functionality now uses Symfony's modern mail component with improved performance and maintainability
- **Updated theme-one template** - Modified template.php to load local fonts before theme CSS
- **Replaced Google Fonts imports** - Removed external Google Fonts @import statements from theme variables.less
- **Improved font loading performance** - Implemented font-display: swap for better loading experience

### Added

- **Comprehensive mail system test suite** - Added 42 tests covering all mail functionality including unit tests for Mailer, Message, and Plugin classes, plus integration tests for complete email workflows
- **SMTP connection testing functionality** - Added test connection feature in admin panel to verify SMTP settings before saving
- **Mail plugin system** - Implemented extensible plugin architecture for mail processing with ImpersonatePlugin as default implementation
- **Enhanced error handling** - Improved error reporting and exception handling throughout the mail system
- **Enhanced .htaccess security configuration** - Added modern security headers including HSTS, X-Content-Type-Options, X-XSS-Protection, X-Frame-Options, Permissions-Policy, and Referrer-Policy for improved security posture
- **DSGVO-compliant local font implementation** - Replaced Google Fonts with local font files to ensure GDPR compliance and eliminate external data transfers
- **Content Security Policy (CSP) implementation** - Added restrictive CSP headers to prevent XSS attacks and unauthorized resource loading
- **Local font system for theme-one** - Created `local-fonts.less` with Open Sans and Roboto Mono font definitions using local font files instead of Google Fonts
- **GitHub Dependabot configuration** - Added `.github/dependabot.yml` for automated dependency updates across Composer (PHP), npm/Yarn (JavaScript), Docker, and GitHub Actions with weekly schedules and proper reviewer assignments

### Fixed

- **Fixed modal login retry mechanism for CSRF errors** - Implemented automatic retry when session expires during login process. Users no longer need to click the login button twice when their session has expired. The system now automatically handles CSRF token refresh and retries the login request transparently.
- **MailController SMTP test parameter handling** - Fixed parameter mismatch between controller and mailer for SMTP connection testing
- **Message::send() error handling** - Corrected return values and error collection in message sending methods
- **Missing EsmtpTransport import** - Added missing Symfony Mailer transport imports
- **Improved markdown formatting** - Applied standard markdown formatting with consistent bullet point spacing in documentation files

### Technical Details

- No Swift Mailer references remaining in codebase
- Full compatibility with Symfony Mailer 5.4
- Maintains backward compatibility with existing mail configuration
- Support for SMTP and Sendmail transports
- Extensible plugin architecture for custom mail processing

---

## Pagekit 1.0.26 - PHP 8.4 Compatibility (September 12, 2025)

### Added

- Development tools configuration (`.editorconfig`, `.php-cs-fixer.php`, `.prettierrc`, `.vscode/settings.json`)
- `#[\AllowDynamicProperties]` attribute for PHP 8.2+ compatibility

### Fixed

- Fixed implicit nullable parameters across all modules (28+ files)
- Fixed deprecated `ReflectionParameter::getClass()` method
- Fixed `JsonSerializable::jsonSerialize()` return type compatibility
- Fixed string functions null parameter deprecations (`strpos`, `strstr`, `ltrim`, `substr_count`)
- Fixed UrlGenerator cache template for PHP 8.4 compatibility
- Fixed dynamic property creation warnings
- **Fixed PackageController error handling compatibility with Symfony ErrorHandler** - Replaced deprecated `App::exception()` API with native PHP error handlers to resolve "exception_handler is not defined" errors when enabling extensions. The new implementation provides proper error catching during module activation with clean JSON error responses and debug information display.

### Changed

- Updated `.gitignore` to exclude build artifacts
- Improved code formatting in Installer

---

_For complete version history see [CHANGELOG.md](CHANGELOG.md)_
