# Pagekit Bugbot Review Rules

> **Canonical rules:** `.cursor/ROADMAP.md` (THE 5 AGGRESSIVE RULES).
> Bugbot MUST read and enforce ROADMAP.md on every review. If a rule here
> conflicts with ROADMAP.md, ROADMAP.md wins.
>
> **Scope:** These rules apply to **both** the remote PR Bugbot **and** the
> local `/review-bugbot` run (the modernization workflow runs the local review
> once per PR — see `orchestrator-subagent-workflow.mdc` § Local Bugbot Review).

---

## 1. ROADMAP Compliance

### 1.1 Mandatory TODO Tags (ROADMAP Rule 5)

Every legacy remnant introduced or touched in a PR MUST have a TODO comment
referencing a ROADMAP step. Flag as **blocking Bug** if any of the following
patterns appear without a proper tag:

| Pattern | Required Tag Format |
|---------|---------------------|
| `App::getInstance()` | `// TODO: TEMPORARY BRIDGE - To be removed in Step X.Y` |
| `App::abort()`, `App::redirect()` | `// TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)` |
| `App::on()`, `App::subscribe()`, `App::trigger()` | `// TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)` |
| `$app['x'] = ...` (ArrayAccess WRITE) | `// TODO: Must be refactored in Step 2.0.1d (Packages + ArrayAccess Removal)` |
| Any other legacy workaround | `// TODO: BACKWARD COMPATIBILITY - Must be refactored later` |

If a tagged TODO references a ROADMAP step that is already marked ✅, flag it:
the bridge should have been removed.

### 1.2 No Compatibility Layers (ROADMAP Rule 1)

If the PR introduces a "Shim", "Compat", "Legacy", or "Adapter" class that
exists solely to support old calling patterns alongside new ones, flag as
**blocking Bug** titled "Compatibility layer violates ROADMAP Rule 1".

### 1.3 No Wrapper Adapters (ROADMAP Rule 2)

If a method signature changes but old call sites are preserved via a wrapper
method (e.g., `legacyFoo()` calling `foo()`), flag as **blocking Bug**.
All call sites must be updated directly.

### 1.4 Delete Over Wrap (ROADMAP Rule 4)

If old code is commented out instead of deleted, flag as **non-blocking Bug**
titled "Commented-out code — use git history instead (ROADMAP Rule 4)".

### 1.5 Scope Enforcement

Check which ROADMAP step the PR branch targets (branch name or PR description).
If changes modify files or patterns that belong to a different step, flag as
**non-blocking Bug** titled "Out-of-scope change for Step X.Y".
Reference the correct step where this change belongs.

### 1.6 Deferred Pattern Awareness (CRITICAL)

Before flagging any finding, cross-reference against the **ROADMAP tracking table**
(`.cursor/ROADMAP.md`). If the finding falls under a later ROADMAP step that is
already tracked with a GitHub Issue, **do NOT flag it as a Bug**. Instead:

- Skip silently if the pattern is a known deferred item.
- If unsure, add a single **informational** comment (not a Bug) referencing the
  step and issue: "Tracked in Step X.Y (Issue #N) — not in scope for this PR."

**Known deferred patterns** (do NOT flag during Steps 2.0.x):

| Pattern | Tracked In | Issue |
|---------|-----------|-------|
| `mixed` typed constructor parameters | Steps 2.1.4–2.1.6 (PHPStan) | #151, #152, #153 |
| Missing `declare(strict_types=1)` | Step 2.1.3 (strict_types Migration) | #150 |
| Missing test coverage for refactors | Step 2.1.9 (Test Coverage Expansion) | #156 |
| `MigrationCommand` integration test (Doctrine + scripts flow) | Step 2.1.9 (Test Coverage Expansion) | #156 |
| `App::abort()`, `App::redirect()` | Step 2.0.1e (StaticTrait Removal) | #166 |
| `App::getInstance()` temporary bridges | Step 2.0.1e (StaticTrait Removal) | #166 |
| `$app['x'] = ...` ArrayAccess WRITE | Step 2.0.1d (ArrayAccess Removal) | #165 |
| `Pagekit\Cache\CacheInterface` / `Psr6Adapter` | Step 2.0.3 (Cache API Modernization) | #179 |
| ~~`PackageScripts` without Doctrine Migrations~~ | ~~Step 2.0.4~~ (RESOLVED in PR #189) | #180 |
| `composer.lock` not versioned, dead PSR-4 mappings | Step 2.0.5 (Composer & Autoload Hygiene) | #182 |
| Old `phpunit.xml.dist` in modules, `@dataProvider` annotations | Step 2.0.6 (Test Infrastructure Cleanup) | #183 |
| `SymfonyEventDispatcherBridge` / `symfony.event_dispatcher` | Step 2.0.7 (Event Bridge Removal) | #184 |
| `create_function()` in `User::hasAccess()` | Step 2.0.8 (Hotfix: create_function) | #185 |
| `Connection::exec()` alias, `json_array` type, deprecated `getSchemaManager()` | Step 2.1.7 (QueryBuilder/DBAL) | #154 |
| `IntlServiceLocator`, `#[AllowDynamicProperties]` | Step 2.1.6 (PHPStan Level 8) | #153 |
| `ModelServiceLocator` (static locator → DTO/presenter; type-narrow in 2.1.6, removal in 2.1.10) | Step 2.1.10 (Entity Presentation Layer) | #204 |

**Already resolved** (do NOT re-flag — these shipped intentionally):

| Pattern | Resolved In | Notes |
|---------|-------------|-------|
| `.gitignore`: removed `/yarn.lock` + `/composer.lock` | PR #187 (Step 2.0.3) | Out-of-scope for 2.0.3 but shipped early; documented in Issue #182 comment |
| `.cursor/install.sh` rewrite (`composer update` → `install`) | PR #187 (Step 2.0.3) | Same as above; reduces Step 2.0.5 remaining scope |
| `MigrationCommand` two-phase model (migrations + scripts) | PR #189 (Step 2.0.4) | By design: migrations are idempotent (tracking table), scripts are version-gated. Retry on next run works correctly. |
| `MigrationController` `has('migration')` guard | PR #189 (Step 2.0.4) | Both `indexAction()` and `migrateAction()` defensively check service availability, consistent with `index.php` login handler. |
| `catch (\Throwable)` in `MigrationCommand::execute()` | PR #189 (Step 2.0.4) | Catches all PHP 8.2+ errors. "Non-\Throwable error" is impossible in modern PHP — see Rule 2.2. |
| Auto-migration/rollback removed from PackageManager | PR #189 (Step 2.0.4b) | Extensions use explicit scripts.php hooks per original Pagekit design |
| Commented-out `rollbackExtension()` in blog `scripts.php` uninstall hook | PR #189 (Step 2.0.4b) | Intentional API usage example for extension developers, not commented-out legacy code. Rule 4 does not apply to documentation examples. |

This table MUST be updated when new deferred items are added to the ROADMAP.
Bugbot should re-read ROADMAP.md on every review to detect changes.

---

## 2. PHP Standards (PHP 8.2+ Strict)

### 2.1 Strict Types Declaration

For files matching `**/*.php` in `app/`:
If a changed PHP file does not contain `declare(strict_types=1);` as its
second line (after `<?php`), flag as **non-blocking Bug** titled
"Missing strict_types declaration".

**Note:** `strict_types` is enforced repo-wide since Step 2.1.3 (PR #201) by the
`cs-fixer` CI gate (`declare_strict_types`), so a missing declaration normally fails
CI before a review reaches Bugbot. Treat this as a non-blocking backstop.

### 2.2 PHP 8.2+ Error Model (CRITICAL)

This project requires **PHP 8.2+**. In modern PHP, `\Throwable` is the
root of the entire error hierarchy: both `\Exception` and `\Error` extend it.
There is **no such thing as a "non-\Throwable" catchable error** in PHP 8.2+.

Do NOT claim that `catch (\Throwable)` can "miss" errors or that
"non-\Throwable errors" exist — this is impossible in PHP 8.2+.
The only uncatchable conditions (OOM, segfault) terminate the process
entirely — no code can guard against those.

**Still flag these legitimate catch-related issues:**
- Silently swallowing errors (no logging, no re-throw, no return value)
- Catching `\Throwable` when only a specific exception type is expected
- Continuing execution with potentially corrupt state after catch

When reviewing error handling patterns, assume PHP 8.2+ semantics.

### 2.3 Typed Properties

If a changed file introduces a property without a type declaration
(e.g., `protected $foo` instead of `protected string $foo`), flag as
**non-blocking Bug** titled "Untyped property — PHP 8.2+ requires types".

**Clarification:** `mixed` IS a valid PHP 8.0+ type declaration. If `mixed`
is used as a placeholder with a TODO tag referencing Steps 2.1.4–2.1.6,
do NOT flag it. Only flag truly untyped properties (no type at all).

### 2.4 Return Types

If a changed file introduces a public/protected method without a return type
declaration, flag as **non-blocking Bug** titled "Missing return type".

### 2.5 Constructor Property Promotion

If a constructor assigns parameters to properties that could use promotion
(e.g., `$this->foo = $foo` in constructor body with matching parameter),
flag as **non-blocking Bug** suggesting Constructor Property Promotion.

---

## 3. Forbidden Patterns

### 3.1 No WordPress Code

If any changed file contains patterns matching:
`/\b(add_action|do_shortcode|WP_Query|get_post_meta|wp_posts|wp_options|the_content|the_title)\b/`

Flag as **blocking Bug** titled "WordPress code detected — this is Pagekit/Symfony".

### 3.2 No Laravel Facades

If any changed PHP file contains patterns matching:
`/\b(dd\(|collect\(|Str::|Arr::|Route::|\bFacade\b|Illuminate\\)/`

Flag as **blocking Bug** titled "Laravel pattern detected — use Symfony equivalents".
Exception: `dd()` may pass if `symfony/var-dumper` is in `composer.json`.

### 3.3 No Hardcoded Secrets

If any changed file contains patterns matching:
`/(password|secret|api_key|token|credential)\s*[:=]\s*['"][^'"]{8,}['"]/i`

Flag as **blocking Bug** titled "Potential hardcoded secret".
Files named `.env.example`, `*.test.*`, or `*Test.php` are exempt.

### 3.4 No eval/exec

If any changed PHP file contains `/\b(eval|exec|system|passthru|shell_exec|proc_open)\s*\(/`:

Flag as **blocking Bug** titled "Dangerous dynamic execution detected".

---

## 4. Container & DI Patterns

### 4.1 Factory Service Reuse

If a service is registered via `$app->factory(...)` and is injected into a
constructor as a stored property, flag as **blocking Bug** titled
"Factory service captured via constructor injection".
Factory services must be resolved fresh per use via `$container->get()` or
direct instantiation (e.g., `Finder::create()`).

### 4.2 Uninitialized Typed Properties

If a Module class declares a non-nullable typed property (e.g., `protected App $app`)
without a default value, and the property is assigned only in `main()`, flag as
**blocking Bug** titled "Non-nullable property without default — risk of TypeError".
The fix is `protected ?App $app = null` with a fallback like
`$this->app ?? App::getInstance()`.

### 4.3 ArrayAccess Read in New Code

If a PR introduces new `$app['x']` read patterns (not tagged as WRITE for Step 2.0.1d),
flag as **blocking Bug** titled "New ArrayAccess read — use \$app->get('x') instead".

### 4.4 Callable vs Method Equivalence

When a `StaticTrait` proxy call (e.g., `App::url($x)`) is migrated to a direct
method call (e.g., `$this->url->get($x)`), **verify whether `__invoke()` and
`get()` are actually different** before flagging. Many Pagekit service classes
implement `__invoke()` as a one-line delegation to `get()`:

```php
public function __invoke($path = '') { return $this->get($path); }
```

If `__invoke()` delegates to the same method being called, the migration is
**semantically identical**. Do NOT flag as a bug. Check the actual source code.

Known equivalent pairs:
- `UrlProvider::__invoke()` → `UrlProvider::get()`
- `ConfigManager::__invoke()` → `ConfigManager::get()`

### 4.5 Execution Context Verification

Before flagging potential runtime errors (e.g., "database table may not exist"),
**verify the execution order** by reading the calling context. For install scripts
(`install.php`, `install-demo.php`):

- These are loaded via `require_once` in `Installer::install()`.
- `Installer::install()` calls `runMigrations()` BEFORE loading install scripts.
- All database tables exist by the time install scripts execute.

Do NOT flag speculative runtime errors without verifying the actual call chain.

---

## 5. Security

### 5.1 SQL Injection

If any changed file constructs SQL by concatenating user input
(e.g., `"SELECT ... " . $request->get(...)` or `"WHERE id = $id"`),
flag as **blocking Bug** titled "Potential SQL injection — use parameterized queries".

### 5.2 XSS in Views

For files matching `**/*.php` in `**/views/` or `**/widgets/`:
If output is not escaped (e.g., `<?= $var ?>` without `htmlspecialchars` or `e()`),
flag as **non-blocking Bug** titled "Unescaped output in view — potential XSS".

### 5.3 CSRF Protection

If a controller action that handles POST/PUT/DELETE does not have a `csrf: true`
attribute or equivalent CSRF check, flag as **non-blocking Bug** titled
"Missing CSRF protection on state-changing endpoint".

---

## 6. Testing

### 6.1 Test Coverage for New Code

If the PR adds or modifies files in `app/system/src/`, `app/installer/src/`,
or `app/modules/*/src/` and there are no corresponding changes in
`**/Tests/**` or `tests/`, flag as **non-blocking Bug** titled
"No tests for backend changes".

**Exception:** Skip during infrastructure migration steps (2.0.x) where changes
are mechanical refactors (e.g., `$app['x']` → `$app->get('x')`, constructor
injection). Test coverage expansion for these is tracked in Step 2.1.9
(Issue #156). Only flag if the PR introduces **new logic or behavioral changes**
that are untested.

---

## 7. Frontend (Maintenance Mode)

### 7.1 No Vue 3 Syntax

For files matching `**/*.{js,vue}`:
If a changed file contains `<script setup>`, `defineProps`, `defineEmits`,
`ref(`, `reactive(`, `computed(` (Composition API), flag as **non-blocking Bug**
titled "Vue 3 syntax — frontend is Vue 2.6 until Step 3.4".

### 7.2 No New Mixins

If a changed Vue file introduces a new `mixins: [...]` declaration, flag as
**non-blocking Bug** titled "Avoid new mixins — hard to migrate to Vue 3".

---

## 8. Commit Messages

### 8.1 Conventional Commits

Commit messages in the PR must follow Conventional Commits v1.0.0:
`<type>[optional scope]: <description>`

Valid types: `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`,
`build`, `ci`, `chore`, `revert`.

If a commit message does not match this format, flag as **non-blocking Bug**
titled "Commit message does not follow Conventional Commits".

---

## 9. Autofix Guidance

When Bugbot Autofix resolves issues, it MUST:

1. Read `.cursor/ROADMAP.md` before making changes
2. Stay within the scope of the PR's target ROADMAP step
3. Add proper TODO tags per Rule 1.1 for any legacy patterns it cannot fully resolve
4. Run `./app/vendor/bin/phpunit` to verify no test regressions
5. Never introduce compatibility layers, adapters, or wrappers
6. Use Conventional Commits format for fix commits

If a fix would require changes outside the current ROADMAP step, Autofix should
add a comment explaining the issue and reference the correct future step instead
of making the change.
