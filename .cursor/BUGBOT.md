# Pagekit Bugbot Review Rules

> **Canonical rules:** `.cursor/ROADMAP.md` (THE 5 AGGRESSIVE RULES).
> Bugbot MUST read and enforce ROADMAP.md on every review. If a rule here
> conflicts with ROADMAP.md, ROADMAP.md wins.

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

---

## 2. PHP Standards (PHP 8.2+ Strict)

### 2.1 Strict Types Declaration

For files matching `**/*.php` in `app/`:
If a changed PHP file does not contain `declare(strict_types=1);` as its
second line (after `<?php`), flag as **non-blocking Bug** titled
"Missing strict_types declaration".

### 2.2 Typed Properties

If a changed file introduces a property without a type declaration
(e.g., `protected $foo` instead of `protected string $foo`), flag as
**non-blocking Bug** titled "Untyped property — PHP 8.2+ requires types".

### 2.3 Return Types

If a changed file introduces a public/protected method without a return type
declaration, flag as **non-blocking Bug** titled "Missing return type".

### 2.4 Constructor Property Promotion

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
