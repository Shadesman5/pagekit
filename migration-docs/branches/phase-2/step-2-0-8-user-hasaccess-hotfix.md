# Step 2.0.8 — User::hasAccess() Hotfix (`create_function()` Removal)

**Branch:** `cursor/step-2-0-8-user-hasaccess-hotfix-60e0`
**ROADMAP Step:** 2.0.8 (Foundation Consolidation — User::hasAccess() Hotfix)
**GitHub Issue:** [#185](https://github.com/Shadesman5/pagekit/issues/185)
**Status:** ✅ Complete — Ready for Review
**Date:** 2026-04-27

---

## 🎯 Overview

Step 2.0.8 fixes a latent **fatal-on-PHP-8** bug inside the core authorization
helper `Pagekit\User\Model\User::hasAccess()`. The legacy implementation
delegated boolean expression evaluation to PHP's `create_function()`:

```php
// BEFORE (fatal on PHP 8.x)
if (!$fn = @create_function('', "return ({$exp});")) {
    throw new \InvalidArgumentException(sprintf(
        'Unable to parse the given access string "%s"', $expression
    ));
}
return (bool) $fn();
```

`create_function()` was deprecated in PHP 7.2 and **removed in PHP 8.0**.
Pagekit declares `"php": "^8.2"` in `composer.json`, so on every non-trivial
permission check (anything containing `&`, `|`, `(`, `)` or `!`) the call site
would have produced a fatal `Call to undefined function create_function()`.
The simple-permission and administrator short-circuits masked the regression in
trivial paths, but the moment any composite expression hit the fall-through —
e.g. `read && write`, `(admin || editor) && publish` — the entire
authorization stack would have crashed.

This step replaces the `create_function()`-based evaluator with a
**pure-PHP recursive-descent parser** living as a `private static` method on
the same class, with **no `eval()`**, no `Closure::fromCallable`, no
`assert()`, and no Symfony `ExpressionLanguage` dependency. Per the
No-Mercy / Aggressive Modernization rules (`.cursor/ROADMAP.md`):

- **Rule 1 (No Compatibility Layers):** no shim, no PHP-version branch.
- **Rule 2 (No Adapters):** no separate `PermissionExpressionEvaluator`
  service / class — the helper is `private static` on `User` because it has
  exactly one caller (Rule 1 + Rule 2 together).
- **Rule 4 (Delete Over Wrap):** the `if (!$fn = @create_function(...))` and
  `return (bool) $fn();` lines are physically deleted, not commented out, not
  toggled by a feature flag.

A **new dedicated test file** locks the behaviour in place: 37 unit tests, 765
suite assertions total, covering both the bare evaluator (via
`\ReflectionMethod` since the helper is `private static`) and `hasAccess()`
end-to-end via partial PHPUnit mocks.

---

## ✅ What Changed

### ✏️ Edited

- `app/system/modules/user/src/Model/User.php`
  - Replaced the `create_function()` block in `hasAccess()` with
    `try { return self::evaluateBooleanExpression($exp); } catch (\Throwable) { … }`
    that throws a clean `\InvalidArgumentException` with the original
    user-facing message (`Unable to parse the given access string "%s"`).
  - Added `private static function evaluateBooleanExpression(string $exp): bool`
    — a recursive-descent parser implementing the grammar:
    ```
    expr     → orExpr
    orExpr   → andExpr ( ('||' | '|') andExpr )*
    andExpr  → notExpr ( ('&&' | '&') notExpr )*
    notExpr  → '!' notExpr | atom
    atom     → '(' expr ')' | '0' | '1'
    ```
    with operator precedence `!` > `&&` > `||`. Both **single** (`&`, `|`)
    and **double** (`&&`, `||`) operators are accepted because the upstream
    sanitization regex in `hasAccess()` preserves single operators.
  - Preserved unchanged: the `isAdministrator() || empty($expression)`
    early-exit, the `!preg_match('/[&\(\)\|\!]/', $expression)` simple-permission
    short-circuit, and the regex reduction that produces the
    `0/1/&/|/!/()` string `$exp`.
- `phpstan-baseline.neon`
  - Removed the now-stale `Function create_function not found.` ignore entry
    scoped to `app/system/modules/user/src/Model/User.php` (6 lines). The
    suppression is part of the deletion (Rule 4).

### ➕ Added

- `app/system/modules/user/src/Tests/UserAccessTest.php` — **new file**
  (37 test cases, pure unit tests, no DB, no container, no kernel bootstrap;
  uses `getMockBuilder(User::class)->onlyMethods(['isAdministrator', 'hasPermission'])`
  for `hasAccess()` integration cases and `\ReflectionMethod` for the bare
  evaluator).

### ➖ Net diff

- `app/system/modules/user/src/Model/User.php` — `+96 / −9`
- `phpstan-baseline.neon` — `+0 / −6`
- `app/system/modules/user/src/Tests/UserAccessTest.php` — `+294` (new file)
- **3** paths touched, **0** shims, **0** adapters, **0** `@deprecated` markers,
  **0** new in-code TODOs.

---

## 🧱 Commits (Conventional Commits)

| SHA        | Subject                                                                  | Checklist Steps |
|------------|--------------------------------------------------------------------------|-----------------|
| `f99ac338` | `fix(user): replace create_function with safe boolean evaluator`         | #2 + #3 + #4    |
| `890c1c97` | `test(user): add UserAccessTest for boolean evaluator and hasAccess`     | #5              |

Each commit references `ROADMAP Step 2.0.8, Issue #185` in its body for
ROADMAP traceability. Steps #1 and #6 are pure verification gates and produced
no code changes (per the architect's plan).

---

## 🛡️ No-Mercy Compliance

| Rule                                              | Outcome |
|---------------------------------------------------|---------|
| **Rule 1 — No Compatibility Layers**              | ✅ No `eval()`, no `Closure::fromCallable`, no `assert()`, no `ExpressionLanguage`, no PHP-version branch |
| **Rule 2 — No Adapters**                          | ✅ No `PermissionExpressionEvaluator` class, no helper trait, no facade — single `private static` method on `User` |
| **Rule 3 — Breaking Changes Allowed Internally**  | ✅ The fatal-on-PHP-8 path is fixed; the public API surface (`User::hasAccess()` signature + behaviour for valid expressions) is identical |
| **Rule 4 — Delete Over Wrap**                     | ✅ `create_function()` block physically deleted; PHPStan baseline entry pruned alongside |
| **Rule 5 — Mandatory Flagging**                   | ✅ No new in-code TODOs needed; deferrals are documented in this branch doc + the architect plan |
| **PHP 8.2+ hygiene**                              | ✅ `declare(strict_types=1)` already present, new method has typed parameters and return type, `\Throwable` catch uses PHP 8 capture-less syntax |
| **No WP/Laravel artifacts**                       | ✅ |
| **Diff scope**                                    | ✅ Exactly the three paths from the architect's plan |

---

## 🧪 Test Results

### Static analysis
- ✅ `./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M` — `[OK] No errors` (baseline clean)

### PHPUnit
- ✅ `./app/vendor/bin/phpunit` — **326 tests, 765 assertions, 0 failures, 0 errors**
  - `UserAccessTest` discovered via the existing `app/system/modules/*/src/Tests` glob
  - Test count delta: 289 → 326 (+37)
  - Exit code 1 is solely due to **pre-existing** SMTP/network-dependent
    warnings + deprecations (unrelated to this step)

### Console
- ✅ `php pagekit setup` — clean (fresh DB / config bootstrap)
- ✅ `php pagekit list` — Pagekit 1.2.13, all commands listed

### Playwright (chromium only, per `AGENTS.md`)
- ✅ `tests/e2e/specs/01-setup/installation.spec.js` — 1 passed
- ✅ `tests/e2e/specs/02-core/authentication.spec.js` — 14 passed
  *(login flow exercises the authorization stack — the closest production-shaped smoke test for `hasAccess()`)*
- ✅ `tests/e2e/specs/02-core/dashboard.spec.js` — 10 passed

### Final ripgrep audit (live codebase only)
- ✅ `rg "create_function" app/ packages/ --glob "*.php"` → **0 functional hits**
  (one docblock mention in `User.php` at line 236 documents what the helper *replaces*; not live code)
- ✅ `rg "create_function" app/vendor --glob "*.php"` → **0 hits** (informational)
- ✅ `rg "eval\s*\(" app/system/modules/user/src/Model/User.php` → **0 functional hits**
  (one docblock mention only)

---

## 🧨 Behavioural Contract

### Inputs to the new evaluator (after the upstream regex reduction in `hasAccess()`)

The upstream regex maps every permission name to `0` or `1` based on
`hasPermission()`, then keeps only `0`, `1`, `&`, `|`, `!`, `(`, `)`. Whitespace
is preserved and the parser ignores it via `ctype_space()`.

| Input                  | Output  | Notes |
|------------------------|---------|-------|
| `'1'`                  | `true`  | atom |
| `'0'`                  | `false` | atom |
| `'1 && 1'`             | `true`  | AND, double operator |
| `'1 & 1'`              | `true`  | AND, single operator (regex preserves `&`) |
| `'0 || 1'`             | `true`  | OR, double operator |
| `'0 | 1'`              | `true`  | OR, single operator (regex preserves `|`) |
| `'!0'`                 | `true`  | NOT |
| `'(1 && 0) || 1'`      | `true`  | parentheses + composite |
| `'1 || 0 && 0'`        | `true`  | precedence: `&&` binds tighter than `||` (must NOT collapse to `(1||0) && 0`) |
| `'!(0 || (1 && !1))'`  | `true`  | nested NOT + parentheses |
| invalid (e.g. `'&&&'`) | throws  | `\InvalidArgumentException` with the original user-facing message |

### `hasAccess()` end-to-end (extract — full set lives in `UserAccessTest`)

Given a fixture user with `hasPermission('read') === true`,
`hasPermission('write') === true`, all other permissions `false`, and
`isAdministrator() === false`:

| Expression                                  | Result  |
|---------------------------------------------|---------|
| `'read'`                                    | `true`  |
| `'admin'`                                   | `false` |
| `'read && write'`                           | `true`  |
| `'read & write'`                            | `true`  |
| `'read || admin'`                           | `true`  |
| `'admin || superadmin'`                     | `false` |
| `'!admin'`                                  | `true`  |
| `'(read && write) || admin'`                | `true`  |
| `'read && (write || admin)'`                | `true`  |
| `'(read || admin) && (write || superadmin)'`| `true`  |
| `''` / `null`                               | `true` (early exit) |
| `'&&& invalid'`                             | throws `\InvalidArgumentException` |
| administrator + `'admin && write'`          | `true` (administrator short-circuit, even with zero permissions) |

---

## 📚 Out-of-Scope (Deferred — flagged with ROADMAP IDs)

| Concern                                                                                       | Tracked in |
|-----------------------------------------------------------------------------------------------|------------|
| `EntityManager` singleton, `ModelServiceLocator` / `IntlServiceLocator` static service locators | Step 2.1.6 (PHPStan Level 7→8) |
| ORM `Metadata` / `Relation` / `PropertyTrait` typing gaps                                     | Step 2.1.6 |
| `#[AllowDynamicProperties]` on `Node` / `Widget`                                              | Step 2.1.6 |
| Extracting `evaluateBooleanExpression()` into a standalone `PermissionExpressionEvaluator` service | Step 2.5 (Extension Safety System) — only if a future caller needs it; today it has exactly one caller, so per Rules 1 + 2 it stays inline |
| `strict_types` audit across the rest of the codebase                                          | Step 2.1.3 (`strict_types` Migration) |
| CSP / `eval()` ban as a project-wide enforcement                                              | Step 1.13.5 (CSP) / Step 4.1 (Basic Security) |

### Phase 1 audit closures

This step closes Phase 1 audit **§1.11 (ORM modernization)** **partially** — only
the `User::hasAccess()` `create_function()` finding is resolved here. The
remaining §1.11 findings (listed above) require Step 2.1.6 to also land before
the audit cell flips from ⚠️ to 🛡️. Per `push.mdc`, partial closures are
**not** flipped on the ROADMAP audit column in this PR.

---

## 📎 Related Documents

- Plan / TODO-Spec: `.cursor/tickets/PROMPT_2_0_8_User-hasAccess-Hotfix_plan.md`
- Task Prompt: `migration-docs/TODO/agent_prompts/Step-2_0-Foundation-Consolidation/PROMPT_2_0_8_User-hasAccess-Hotfix.md`
- Phase plan: `.cursor/ROADMAP.md` → Phase 2.0 → Step 2.0.8
- Previous step: `migration-docs/branches/phase-2/step-2-0-7-event-bridge-removal.md`
