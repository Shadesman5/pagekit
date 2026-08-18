# Pagekit Bugbot Review Rules

If a later section conflicts with §0, §0 wins.

---

## 0. Scope

Review the PR diff. A follow-up review: only files changed since the last Bugbot review, plus the call chain needed to check a fix.

Do not run PHPUnit, PHPStan, Playwright, pnpm, or Composer. Do not commit or patch.

### Review

- `app/`, `packages/`, `public/` (except built CSS/LESS), `docker/`, `Dockerfile`
- `**/*.{vue,js}` under `app/` and `packages/`
- `.github/conductor/*.mjs`, `.github/conductor/*.test.mjs`, `.github/workflows/conductor.yml`, `.github/workflows/import-*.yml`
- `.cursor/rules/orchestrator-v2-*.mdc`, `.cursor/rules/orchestrator-subagent-workflow.mdc`, `.cursor/WORKFLOW_SUBAGENTS.md`, `.cursor/agents/`, `.cursor/skills/`

### Do not open

- `tests/`, `**/Tests/**`, `**/*Test.php`, `**/*.spec.js`, `phpunit*.xml*`, `infection.json.dist`
- `migration-docs/`, `docs-site/`, `README.md`, `CHANGELOG.md`, `CHANGELOG-NEW.md`, `AGENTS.md`
- `.github/conductor/metrics/`, `.github/quality/quality-snapshot.json`
- `*.css`, `*.less`, `node_modules/`, `app/vendor/`, `tmp/`

Coverage: §6, PR file list only.

`.cursor/ROADMAP.md`: open only when this PR has no prior `<!-- BUGBOT_REVIEW -->`. Otherwise use §1.6 tables.

---

## 1. ROADMAP Compliance

### 1.1 Mandatory TODO Tags

Legacy remnants in the PR without a tag → **blocking Bug**.

| Pattern | Required tag |
|---------|----------------|
| `App::getInstance()` | `// TODO: TEMPORARY BRIDGE - To be removed in Step X.Y` |
| `App::abort()`, `App::redirect()` | `// TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)` |
| `App::on()`, `App::subscribe()`, `App::trigger()` | `// TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)` |
| `$app['x'] = ...` (ArrayAccess WRITE) | `// TODO: Must be refactored in Step 2.0.1d (Packages + ArrayAccess Removal)` |
| Other leftover that must change later | `// TODO: Must be refactored in Step X.Y (Name)` |

A tag pointing at a ROADMAP step already ✅ → **blocking Bug**.

### 1.2 No Compatibility Layers

New Shim/Compat/Legacy/Adapter class that only preserves old calling patterns → **blocking Bug** "Compatibility layer violates ROADMAP Rule 1".

### 1.3 No Wrapper Adapters

Signature change with a wrapper for old call sites (`legacyFoo()` → `foo()`) → **blocking Bug**. Update call sites.

### 1.4 Delete Over Wrap

Commented-out old code → **non-blocking Bug** "Commented-out code — use git history instead (ROADMAP Rule 4)".

### 1.5 Scope Enforcement

Changes that belong to a different ROADMAP step than the PR targets → **non-blocking Bug** "Out-of-scope change for Step X.Y".

### 1.6 Deferred patterns

Match findings against these tables. Known deferred → do not flag. Unsure → one informational comment: "Tracked in Step X.Y (Issue #N) — not in scope for this PR."

**Do not flag**

| Pattern | Step | Issue |
|---------|------|-------|
| `Connection::exec()` alias, `json_array` type, deprecated `getSchemaManager()` | 2.1.7 | #154 |
| Missing test coverage for refactors; `MigrationCommand` integration test | 2.1.9 | #156 |
| `ModelServiceLocator` | 2.1.10 | #204 |
| `EntityManager` static singleton + `find(mixed $identifier)` | 2.1.11 | #205 |

**Do not re-flag**

| Pattern | PR |
|---------|-----|
| `.gitignore` dropped `/yarn.lock` + `/composer.lock` | #187 |
| `.cursor/install.sh` uses `composer install` | #187 |
| `MigrationCommand` two-phase (migrations + scripts) | #189 |
| `MigrationController` `has('migration')` guard | #189 |
| `catch (\Throwable)` in `MigrationCommand::execute()` | #189 |
| Auto-migration/rollback removed from PackageManager | #189 |
| Commented-out `rollbackExtension()` in blog `scripts.php` (API example) | #189 |

Update these tables when ROADMAP deferred items change. Do not open ROADMAP.md to discover new ones on a follow-up review.

---

## 2. PHP

### 2.1 Strict types

Changed `app/**/*.php` missing `declare(strict_types=1);` as the second line → **non-blocking Bug** "Missing strict_types declaration".

### 2.2 Errors

PHP 8.2+: `\Throwable` is the root. There is no catchable non-`\Throwable` error. Do not flag `catch (\Throwable)` as incomplete.

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
`dd()` allowed if `symfony/var-dumper` is in `composer.json`.

### 3.3 Secrets

`/(password|secret|api_key|token|credential)\s*[:=]\s*['"][^'"]{8,}['"]/i` → **blocking Bug**.
Exempt: `.env.example`, `*.test.*`, `*Test.php`.

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
`v-html` or `$notify(...)` with unescaped server text → **blocking Bug**.

### 5.3 CSRF

POST/PUT/DELETE action without `csrf: true` (or equivalent) → **non-blocking Bug**.

---

## 6. Coverage

Do not open test files. Do not run tests.

PR file list touches `app/system/src/`, `app/installer/src/`, or `app/modules/*/src/` and has no `**/Tests/**` or `tests/` path → **non-blocking Bug** "No tests for backend changes".

Skip for mechanical refactors (`$app['x']` → `$app->get('x')`, constructor injection, types only). Issue #156.

---

## 7. Frontend

Changed `**/*.{js,vue}` with `<script setup>`, `defineProps`, `defineEmits`, `ref(`, `reactive(`, Composition-API `computed(` → **non-blocking Bug** "Vue 3 syntax".

New `mixins: [...]` → **non-blocking Bug**.

---

## 8. Commits

PR commit messages: `<type>[optional scope]: <description>`
(`feat` `fix` `docs` `style` `refactor` `perf` `test` `build` `ci` `chore` `revert`).

Mismatch → **non-blocking Bug**.
