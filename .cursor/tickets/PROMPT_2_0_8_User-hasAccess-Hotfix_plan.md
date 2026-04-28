## ARCHITECT OUTPUT
- **Current Step (ROADMAP):** 2.0.8 (Hotfix: `create_function()` in `User::hasAccess()`)
- **Scope:**
  - `app/system/modules/user/src/Model/User.php` — replace the `create_function()`-based evaluator inside `hasAccess()` (~lines 209–228) with a safe boolean-expression evaluator. Preserve the existing two short-circuits (administrator / empty / single-permission) and the regex reduction to a `0/1/&/|/!/()` string. Add a private static method `evaluateBooleanExpression(string $exp): bool` (recursive descent parser) on the same class. Strict types stay enabled (file already has `declare(strict_types=1);`).
  - `app/system/modules/user/src/Tests/UserAccessTest.php` — **new file**. Unit tests for the boolean evaluator (via `\ReflectionMethod` since the method is `private static`) AND for composite expressions in `hasAccess()` using a small `User` test fixture / partial mock with deterministic `hasPermission()` and `isAdministrator()` behaviour. Test directory matches the existing convention used by `mail` / `intl` / `cache` modules and is already covered by the root `phpunit.xml.dist` glob `app/system/modules/*/src/Tests`.
- **Deferred:**
  - Step 2.1.6 (PHPStan Level 7→8) — the rest of the Phase 1 §1.11 ORM-modernization audit findings (`EntityManager` singleton, `ModelServiceLocator` / `IntlServiceLocator` static service locators, ORM `Metadata` / `Relation` / `PropertyTrait` typing gaps, `#[AllowDynamicProperties]` on `Node` / `Widget`). Do **not** flip the §1.11 ROADMAP audit cell from ⚠️ to 🛡️ at the end of this ticket — it stays ⚠️ until 2.1.6 also lands.
  - Step 2.1.3 (`strict_types` Migration) — only `User.php` is touched here, and it already declares strict types; no other files need a `declare(strict_types=1);` audit as part of this ticket.
  - Step 4.1 (Basic Security) / Step 1.13.5 (CSP) — `eval()` and any other code-execution mechanism are explicitly forbidden as the replacement; this ticket already enforces that.
  - Refactoring `User::hasAccess()` into a separate `PermissionExpressionEvaluator` service / class — out of scope. Per Rule 1 (NO COMPATIBILITY LAYERS) and Rule 2 (NO ADAPTERS), the helper stays a `private static` method on `User` since it has exactly one caller. If a future step (e.g., 2.5 Extension Safety System) needs to share it, the extraction belongs there.
- **Bridges:** None.
  - Rule 4 (DELETE OVER WRAP): the `create_function()` line is **physically deleted** from `User::hasAccess()` (not commented out, not toggled by a feature flag, not kept behind a PHP-version check).
  - Rule 1 (NO COMPATIBILITY LAYERS): no `eval()`, no `Closure::fromCallable`, no `assert()`, no `ExpressionLanguage` dependency. The only acceptable replacement is the recursive-descent parser specified in the prompt §3.2.
  - Rule 2 (NO ADAPTERS): no separate "evaluator service" class, no helper trait, no facade — one `private static` method on `User`, called exactly once by `hasAccess()`.
  - Rule 5 (MANDATORY FLAGGING): no temporary-bridge TODOs are introduced. If, during refactoring, an unavoidable hack is needed it MUST use ROADMAP IDs only:
    - `// TODO: AUDIT FIX Step 2.0.8 (User::hasAccess Hotfix)` — fallback marker for a non-trivial fix surfaced during this step.
    - `// TODO: Must be refactored in Step 2.1.6 (PHPStan Level 8)` — only if a typing gap that 2.1.6 will close blocks the patch.
- **Checklist:** see below.

## Checklist

1. **Pre-flight baseline & scope confirmation**
   - Capture green baseline before any code change:
     - `./app/vendor/bin/phpunit`
     - `./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M`
     - `php pagekit list`
   - Pre-existing red is a blocker — escalate to architect. Do not proceed otherwise.
   - Confirm the ONLY occurrence of `create_function` in the codebase is in `User.php`:
     - `rg -n "create_function" app/ packages/ --glob "*.php"`
     - Expected: exactly one hit at `app/system/modules/user/src/Model/User.php`.
   - Confirm the test directory pattern is wired into the root config:
     - `rg -n "system/modules/\*/src/Tests" phpunit.xml.dist` — must show the include line.
   - No code changes in this step.

2. **Add `evaluateBooleanExpression()` to `User.php`**
   - Edit only `app/system/modules/user/src/Model/User.php`.
   - Add a new `private static function evaluateBooleanExpression(string $exp): bool` method at the bottom of the class, just before `jsonSerialize()` (or after `hasAccess()`, whichever keeps the existing method ordering tidy).
   - Implement the recursive-descent parser exactly as specified in the prompt §3.2:
     - Grammar: `expr → orExpr` | `orExpr → andExpr ( ('||' | '|') andExpr )*` | `andExpr → notExpr ( ('&&' | '&') notExpr )*` | `notExpr → '!' notExpr | atom` | `atom → '(' expr ')' | '0' | '1'`.
     - Operator precedence: `!` > `&&` > `||`.
     - Both single (`&`, `|`) and double (`&&`, `||`) operators MUST be accepted (the upstream sanitization regex preserves single operators; failing to handle them would silently break existing call sites).
     - Pure-PHP, no `eval()`, no `Closure::fromCallable`, no `assert()`, no `ExpressionLanguage` dependency, no other code-execution primitive.
   - Do **NOT** call this method from `hasAccess()` yet — keep the change isolated.
   - Per-step gate (mandatory): PHPUnit + PHPStan. Both must stay green; the new method is unused so PHPStan may flag it as dead code only if the project has `deadCode` rules enabled (currently it does not, per the existing baseline). If PHPStan complains, address the warning in this step before moving on.

3. **Wire `hasAccess()` to the new evaluator and delete `create_function`**
   - Same file: `app/system/modules/user/src/Model/User.php`.
   - Replace the entire `create_function`-based block (currently lines ~223–227) with:
     ```php
     try {
         return self::evaluateBooleanExpression($exp);
     } catch (\Throwable) {
         throw new \InvalidArgumentException(
             sprintf('Unable to parse the given access string "%s"', $expression)
         );
     }
     ```
   - Per Rule 4: physically delete the `if (!$fn = @create_function(...))` line and the `return (bool) $fn();` line. Do not comment them out.
   - Preserve the unchanged early-exit logic (`isAdministrator() || empty($expression)`), the simple-permission short-circuit (`!preg_match('/[&\(\)\|\!]/', $expression)`), and the existing regex reduction that produces `$exp`.
   - Do not introduce any new TODO markers unless something genuinely cannot be resolved here; if so, use the ROADMAP-ID format from the Bridges section above.
   - Per-step gate (mandatory): PHPUnit + PHPStan. Existing PHPUnit suite must remain green. The new evaluator is now reachable via `hasAccess()`, so static analysis must still pass.

4. **Verify `create_function` is fully eradicated from the codebase**
   - Run: `rg -n "create_function" app/ packages/ --glob "*.php"`.
   - Expected: **zero hits**. If any remain (vendor excluded — `app/vendor` is composer-managed), fix them in this same step rather than deferring.
   - Run: `rg -n "create_function" app/vendor --glob "*.php"` for awareness only — vendor hits are out of scope but should be noted in the commit message if present (no fix in vendor; that is dependency-update territory).
   - Per-step gate (mandatory): PHPUnit + PHPStan.

5. **Add unit tests for the evaluator and `hasAccess()` composite expressions**
   - New file: `app/system/modules/user/src/Tests/UserAccessTest.php`.
   - File MUST start with `<?php` and `declare(strict_types=1);`, namespace `Pagekit\User\Tests`, extend `PHPUnit\Framework\TestCase`.
   - **Section A — Pure evaluator tests** (use `\ReflectionMethod` to invoke the `private static` `evaluateBooleanExpression`):
     - `'1'` → true, `'0'` → false
     - `'1&&1'` → true, `'1&&0'` → false
     - `'0||1'` → true, `'0||0'` → false
     - `'1&1'` → true, `'1&0'` → false (single-operator `&` MUST behave like `&&`)
     - `'0|1'` → true, `'0|0'` → false (single-operator `|` MUST behave like `||`)
     - `'!0'` → true, `'!1'` → false
     - `'(1&&0)||1'` → true, `'(0||0)&&1'` → false
     - Operator precedence: `'1||0&&0'` → true (must NOT collapse to `(1||0)&&0`)
     - Nested: `'!(0||(1&&!1))'` → true
   - **Section B — `hasAccess()` integration tests** (use a small fixture: a `User` subclass or partially-mocked instance that overrides `isAdministrator()` (returns `false`) and `hasPermission()` to return `true` for `read` and `write`, `false` for everything else; alternatively use the PHPUnit mocking API with `getMockBuilder(User::class)->onlyMethods(['isAdministrator', 'hasPermission'])`). Cover **at minimum** every assertion from prompt §4.1:
     - Simple: `$user->hasAccess('read')` → true; `$user->hasAccess('admin')` → false
     - AND double: `'read && write'` → true; `'read && admin'` → false
     - AND single: `'read & write'` → true; `'read & admin'` → false
     - OR double: `'read || admin'` → true; `'admin || superadmin'` → false
     - OR single: `'read | admin'` → true; `'admin | superadmin'` → false
     - NOT: `'!admin'` → true; `'!read'` → false
     - Parentheses: `'(read && write) || admin'` → true; `'(admin && write) || superadmin'` → false
     - Nested: `'read && (write || admin)'` → true; `'admin && (write || read)'` → false
     - Complex: `'(read || admin) && (write || superadmin)'` → true
     - Empty / null: `$user->hasAccess('')` → true; `$user->hasAccess(null)` → true
     - Administrator short-circuit: a fixture with `isAdministrator()` returning `true` and **zero** permissions returns `true` for any expression including `'admin && write'`.
     - Invalid expression: `$user->hasAccess('&&& invalid')` throws `\InvalidArgumentException` (use `expectException` or a separate test method).
   - Tests MUST NOT bootstrap the full Pagekit application (no DB, no container, no kernel); the `User` model fixture / mock approach keeps them pure unit tests so they run in the existing PHPUnit suite without new fixtures.
   - If the chosen fixture approach exposes a typing or mocking issue (e.g., `findRoles()` static call from the `hasPermission()` body), the test MUST mock `hasPermission()` itself rather than reaching into `findRoles` — keep tests narrow.
   - Per-step gate (mandatory): PHPUnit + PHPStan. The new test file participates in PHPStan analysis (the root config includes `app/system`); fix any complaints in this step.

6. **Final consolidated audit (mirrors prompt §5 + §6)**
   - `rg -n "create_function" app/ packages/ --glob "*.php"` → zero hits.
   - `rg -n "eval\s*\(" app/system/modules/user/src/Model/User.php` → zero hits (sanity check that the replacement did not regress).
   - `./app/vendor/bin/phpunit` — green; the new `UserAccessTest` cases all run and pass.
   - `./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M` — green.
   - `php pagekit list` — green (no fatal at boot; the change touches authorization which the console bootstraps).
   - `php pagekit setup` — clean (final-run only, not per-step).
   - Playwright E2E (installation, login, dashboard) — green (final-run only, not per-step). Login exercises the authorization stack and is the closest production-shaped smoke test for `hasAccess()`.
   - Confirm `.cursor/ROADMAP.md` §1.11 audit cell stays ⚠️ (do **not** flip to 🛡️ — that requires Step 2.1.6 to also land, per the prompt's "Closes Phase 1 audit (partial)" note).

## TESTING STRATEGY
- **Per step:** PHPUnit + PHPStan (mandatory after every checklist step)
- **Final run (after all steps):** PHPUnit + PHPStan + `php pagekit setup` + `php pagekit list` + Playwright E2E (installation, login, dashboard)
