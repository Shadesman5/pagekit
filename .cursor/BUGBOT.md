# Pagekit Bugbot Review Rules

If a later section conflicts with §0, §0 wins.

Canonical DNA: [pagekit.mdc](rules/pagekit.mdc). Cursor `*.mdc` rules are not auto-loaded — follow that file via the link. This file maps DNA onto flags.

---

## 0. Scope

Same flag rules for both runs. The input differs:

- **Local** (`/review-bugbot`, XL step, including fix-loop re-runs): always the **full** branch diff vs merge-base. There is no PR yet. Do not shrink to files changed since the last local review.
- **Remote** (`bugbot run` on the PR): PR diff. Follow-up: only files changed since the last Bugbot review, plus the call chain needed to check a fix.

Do not run PHPUnit, PHPStan, Playwright, pnpm, or Composer. Do not commit, patch, or spawn Autofix.

### Review

- `app/`, `packages/`, `public/` (except built CSS/LESS), `docker/`, `Dockerfile`
- `docker-compose.yml`, `docker-compose.prod.yml`, `prod.env.example`, `composer.json`
- `**/*.{vue,js}` under `app/` and `packages/`
- `.github/conductor/*.mjs`, `.github/conductor/*.test.mjs`, `.github/workflows/conductor.yml`, `.github/workflows/import-*.yml`
- `.cursor/rules/orchestrator-v2-*.mdc`, `.cursor/rules/orchestrator-subagent-workflow.mdc`, `.cursor/WORKFLOW_SUBAGENTS.md`, `.cursor/agents/`, `.cursor/skills/`

Conductor `*.test.mjs` **are** in scope. PHPUnit and Playwright test bodies are not.

### Do not open

- `tests/`, `**/Tests/**`, `**/*Test.php`, `**/*.spec.js`, `phpunit*.xml*`, `infection.json.dist`
- `migration-docs/`, `docs-site/`, `README.md`, `CHANGELOG.md`, `CHANGELOG-NEW.md`, `AGENTS.md`
- `.github/conductor/metrics/`, `.github/quality/quality-snapshot.json`
- `*.css`, `*.less`, `node_modules/`, `app/vendor/`, `tmp/`

Coverage: §6, changed-file list of this diff only.

`.cursor/ROADMAP.md`: open on the first review of this branch (local XL) or when the PR has no prior `<!-- BUGBOT_REVIEW -->`. Otherwise use §1.6.

---

## 1. ROADMAP Compliance

### 1.1 Forbidden remnants and TODO tags

These patterns are forbidden. Reintroduction → **blocking Bug**. A TODO tag does not exempt them.

| Pattern |
|---------|
| `App::getInstance()` |
| `App::abort()`, `App::redirect()` |
| `App::on()`, `App::subscribe()`, `App::trigger()` |
| `$app['x'] = ...` (ArrayAccess WRITE) |

Other leftover that must change later → `// TODO: Must be refactored in Step X.Y (Name)` or `// TODO: TEMPORARY BRIDGE - To be removed in Step X.Y` pointing at a **⏳** ROADMAP step. Missing tag → **blocking Bug**. Tag pointing at a ✅ step → **blocking Bug**.

No completed-step, ticket, or ROADMAP history in code comments.

### 1.2 No Compatibility Layers

New Shim/Compat/Legacy/Adapter class that only preserves old calling patterns → **blocking Bug** "Compatibility layer violates ROADMAP Rule 1".

### 1.3 No Wrapper Adapters

Signature change with a wrapper for old call sites (`legacyFoo()` → `foo()`) → **blocking Bug**. Update call sites.

### 1.4 Delete Over Wrap

Commented-out old code → **non-blocking Bug** "Commented-out code — use git history instead (ROADMAP Rule 4)".

### 1.5 Scope Enforcement

Changes that belong to a different ROADMAP step than the ticket/PR targets → **non-blocking Bug** "Out-of-scope change for Step X.Y".

### 1.6 Deferred patterns

**Do not flag** ⏳ leftovers that look like bugs. Never keep a ✅ row — after a step ships, reintroduction is in scope. Unsure (first review, ROADMAP ⏳) → one informational comment: "Tracked in Step X.Y (Issue #N) — not in scope for this change."

No ⏳ leftover rows yet. Add a row when a later step tracks a leftover. Do not open ROADMAP.md on a follow-up review to discover new ones.

**Do not re-flag** (intentional design still in the tree)

| Pattern |
|---------|
| `.cursor/install.sh` uses `composer install` (not `update`) |
| `MigrationCommand` two-phase: Doctrine `migrate()` then `LifecycleRunner` scripts |
| `has('migration')` guard in `MigrationCommand` / `MigrationController` |

---

## 2. PHP

### 2.1 Strict types

Changed `app/**/*.php` missing `declare(strict_types=1);` as the second line → **non-blocking Bug** "Missing strict_types declaration".

### 2.2 Errors

PHP 8.5+: `\Throwable` is the root. There is no catchable non-`\Throwable` error. Do not flag `catch (\Throwable)` as incomplete.

Do flag: swallowed errors; `\Throwable` when a specific type is required; continuing with corrupt state.

### 2.3 Typed properties

New untyped property (`protected $foo`) → **non-blocking Bug**. `mixed` is a type. Do not flag justified `mixed` (PSR-11 `get()`, `__get`/`__set`/`__call`, generic containers).

### 2.4 Return types

New public/protected method without a return type → **non-blocking Bug** "Missing return type".

### 2.5 Constructor promotion

Constructor body `$this->foo = $foo` that could be promoted → **non-blocking Bug**.

---

## 3. Forbidden patterns

### 3.1 WordPress

`/\b(add_action|do_shortcode|WP_Query|get_post_meta|wp_posts|wp_options|the_content|the_title)\b/` → **blocking Bug**.

### 3.2 Laravel

`/\b(dd\(|collect\(|Str::|Arr::|Route::|\bFacade\b|Illuminate\\)/` → **blocking Bug**.
`dump()` from `symfony/var-dumper` is allowed. `dd()` is not.

### 3.3 Secrets

`/(password|secret|api_key|token|credential)\s*[:=]\s*['"][^'"]{8,}['"]/i` → **blocking Bug**.
Exempt: `.env.example`, `prod.env.example`, `*.test.*`, `*Test.php`.

### 3.4 eval/exec

`/\b(eval|exec|system|passthru|shell_exec|proc_open)\s*\(/` in PHP → **blocking Bug**.

---

## 4. Container & DI

### 4.1 Factory capture

`$app->factory(...)` stored on a constructor property → **blocking Bug**. Resolve via `$container->get()` or `Finder::create()`.

### 4.2 Uninitialized typed properties

Module `protected App $app` assigned only in `main()` → **blocking Bug**. Use `protected ?App $app = null`.

### 4.3 ArrayAccess reads

New `$app['x']` reads → **blocking Bug**. Use `$app->get('x')`.

### 4.4 `__invoke` vs `get`

Do not flag `App::url($x)` → `$this->url->get($x)` when `__invoke()` delegates to `get()`.

Known: `UrlProvider`, `ConfigManager`.

### 4.5 Install scripts

`install.php` / `install-demo.php` run after `Installer::install()` → `runMigrations()`. Tables exist. Do not flag missing tables without a call-chain check.

---

## 5. Security

### 5.1 SQL injection

SQL concatenated with request input → **blocking Bug**. Parameterized queries.

### 5.2 XSS

Unescaped `<?= $var ?>` in `**/views/` or `**/widgets/` → **non-blocking Bug**.

`v-html` or `$notify(...)` with unescaped request or API text → **blocking Bug**.
Do not flag `v-html` of trusted local content (`marked()` of package metadata, installer command output) unless request input flows into it.

### 5.3 CSRF

POST/PUT/DELETE action without `csrf: true` (or equivalent) → **non-blocking Bug**.

---

## 6. Coverage

Do not open PHPUnit or Playwright test files. Do not run tests.

Changed-file list touches `app/system/src/`, `app/installer/src/`, or `app/modules/*/src/` and has no `**/Tests/**` or `tests/` path → **non-blocking Bug** "No tests for backend changes".

Skip for mechanical refactors (`$app['x']` → `$app->get('x')`, constructor injection, types only).

---

## 7. Frontend

Vue 2.7 Options API is current. New `<script setup>`, `defineProps`, `defineEmits`, or Composition-API `ref(` / `reactive(` / `computed(` → **non-blocking Bug**, unless that file already uses a Vue 2.7 Composition-API bridge.

Do not flag Options-API `computed:` or `$refs`.

New `mixins: [...]` → **non-blocking Bug**.

---

## 8. Commits

Branch/PR commit messages: `<type>[optional scope]: <description>`
(`feat` `fix` `docs` `style` `refactor` `perf` `test` `build` `ci` `chore` `revert`).

Mismatch → **non-blocking Bug**.
