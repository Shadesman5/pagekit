# Step 2.0.8: Critical Hotfix — `User::hasAccess()` `create_function()` Removal

**ROADMAP:** 2.0.8 — Foundation Consolidation (Critical Hotfix).  
**GitHub Issue:** #185.  
**Prerequisite:** None — this is a critical runtime fix that can be executed at any point.  
**Priority:** HIGHEST.

**Closes Phase 1 audit (partial):** Step 1.11 (ORM Modernization) — the `create_function()` removal closes the User-model portion of the 1.11 finding. The remaining 1.11 findings (`EntityManager` singleton, `ModelServiceLocator` / `IntlServiceLocator` static service locators, ORM `Metadata` / `Relation` / `PropertyTrait` typing gaps, `#[AllowDynamicProperties]` on `Node` / `Widget`) live in **Step 2.1.6** (PHPStan Level 8). **Step 1.11 ⚠️ → 🛡️ requires both 2.0.8 and 2.1.6 to land.** Do **not** flip 1.11 to 🛡️ in the ROADMAP after this step alone.

**Also read:** `.cursor/ROADMAP.md` (5 aggressive rules).

---

## 1. CONTEXT

### 1.1 The bug

`User::hasAccess()` in `app/system/modules/user/src/Model/User.php` (~line 221–227) uses `create_function()` to evaluate composite permission expressions. `create_function()` was **removed in PHP 8.0** and triggers a **Fatal Error** on PHP 8.2+.

### 1.2 Current code (the problem)

```php
public function hasAccess(?string $expression): bool
{
    $user = $this;

    if ($this->isAdministrator() || empty($expression)) {
        return true;
    }

    // Simple permission — no operators
    if (!preg_match('/[&\(\)\|\!]/', $expression)) {
        return $this->hasPermission($expression);
    }

    // Replace each permission name with 0 or 1
    $exp = preg_replace('/[^01&\(\)\|!]/', '',
        preg_replace_callback('/[a-z_][a-z-_\.:\d\s]*/i',
            fn ($permission) => (int) $user->hasPermission(trim($permission[0])),
            $expression
        )
    );

    // BUG: create_function() removed in PHP 8.0
    if (!$fn = @create_function("", "return $exp;")) {
        throw new \InvalidArgumentException(
            sprintf('Unable to parse the given access string "%s"', $expression)
        );
    }

    return (bool) $fn();
}
```

### 1.3 How it works (the clever part to preserve)

The existing logic is actually smart:
1. `preg_replace_callback` maps each permission name to `0` or `1` (by calling `hasPermission()`)
2. `preg_replace` strips everything except `0`, `1`, `&`, `|`, `!`, `(`, `)`
3. Result is a pure boolean expression like `(1&&0)||1`
4. `create_function` was used to evaluate this string

**Only step 4 needs replacement.** The regex-based reduction in steps 1–3 is safe and should be preserved.

### 1.4 Why NOT `eval()`

- Violates CSP goals (Step 1.13.5)
- Security risk even with sanitized input
- Explicitly forbidden by ROADMAP rules

---

## 2. SAFETY & VERIFICATION

**After the change:**

```bash
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
php pagekit list
```

---

## 3. REPLACEMENT: SIMPLE BOOLEAN EXPRESSION EVALUATOR

### 3.1 Approach

After steps 1–3, the expression is already reduced to a string containing only: `0`, `1`, `&`, `|`, `!`, `(`, `)`. This is a **trivial grammar**.

**CRITICAL:** The sanitization regex `preg_replace('/[^01&\(\)\|!]/', '', ...)` preserves **single** `&` and `|` characters. Users may write `perm1 | perm2` or `perm1 & perm2` (single operators). After sanitization this becomes `0|1` or `0&1`. The old `create_function("", "return 0|1;")` evaluated these as PHP bitwise OR/AND, which for `0`/`1` values produces identical results to logical `||`/`&&`. The parser **must handle both single and double operators** or permissions with single-character operators will silently fail.

```
expr     → orExpr
orExpr   → andExpr ( ('||' | '|') andExpr )*
andExpr  → notExpr ( ('&&' | '&') notExpr )*
notExpr  → '!' notExpr | atom
atom     → '(' expr ')' | '0' | '1'
```

### 3.2 Implementation

Replace the `create_function` block with a private static method on `User` (or a small utility class if preferred — but the expression is only used here):

```php
private static function evaluateBooleanExpression(string $exp): bool
{
    $pos = 0;
    $len = strlen($exp);

    $parseOr = null;
    $parseAnd = null;
    $parseNot = null;
    $parseAtom = null;

    $parseOr = function () use (&$parseAnd, &$pos, $len, $exp): bool {
        $result = $parseAnd();
        while ($pos < $len && $exp[$pos] === '|') {
            $pos++;
            if ($pos < $len && $exp[$pos] === '|') {
                $pos++;
            }
            $result = $parseAnd() || $result;
        }
        return $result;
    };

    $parseAnd = function () use (&$parseNot, &$pos, $len, $exp): bool {
        $result = $parseNot();
        while ($pos < $len && $exp[$pos] === '&') {
            $pos++;
            if ($pos < $len && $exp[$pos] === '&') {
                $pos++;
            }
            $result = $parseNot() && $result;
        }
        return $result;
    };

    $parseNot = function () use (&$parseNot, &$parseAtom, &$pos, $exp): bool {
        if ($pos < strlen($exp) && $exp[$pos] === '!') {
            $pos++;
            return !$parseNot();
        }
        return $parseAtom();
    };

    $parseAtom = function () use (&$parseOr, &$pos, $len, $exp): bool {
        if ($pos < $len && $exp[$pos] === '(') {
            $pos++;
            $result = $parseOr();
            if ($pos < $len && $exp[$pos] === ')') {
                $pos++;
            }
            return $result;
        }
        if ($pos < $len) {
            $val = $exp[$pos] === '1';
            $pos++;
            return $val;
        }
        return false;
    };

    return $parseOr();
}
```

Then in `hasAccess()`, replace the `create_function` block:

```php
// Before (REMOVED):
// if (!$fn = @create_function("", "return $exp;")) { ... }
// return (bool) $fn();

// After:
try {
    return self::evaluateBooleanExpression($exp);
} catch (\Throwable) {
    throw new \InvalidArgumentException(
        sprintf('Unable to parse the given access string "%s"', $expression)
    );
}
```

### 3.3 Alternative: even simpler (if you prefer)

Since the expression is already sanitized to only `01&|!()`, a **stack-based evaluator** or even `preg_match`-based reduction loop would also work. The recursive descent parser above is preferred because it correctly handles operator precedence (`!` > `&&` > `||`) and nested parentheses.

**Do NOT** use `eval()`, `Closure::fromCallable`, `assert()`, or any other code execution mechanism.

---

## 4. TESTS

### 4.1 Unit tests for `hasAccess()` composite expressions

**File:** Add to existing User test file or create `app/system/modules/user/src/Tests/UserAccessTest.php`

Test cases (use a User mock/fixture with known permissions):

```php
// Setup: User has permissions 'read' and 'write', but NOT 'admin'

// Simple (existing — should still work)
$this->assertTrue($user->hasAccess('read'));
$this->assertFalse($user->hasAccess('admin'));

// AND (double)
$this->assertTrue($user->hasAccess('read && write'));
$this->assertFalse($user->hasAccess('read && admin'));

// AND (single — must work identically to &&)
$this->assertTrue($user->hasAccess('read & write'));
$this->assertFalse($user->hasAccess('read & admin'));

// OR (double)
$this->assertTrue($user->hasAccess('read || admin'));
$this->assertFalse($user->hasAccess('admin || superadmin'));

// OR (single — must work identically to ||)
$this->assertTrue($user->hasAccess('read | admin'));
$this->assertFalse($user->hasAccess('admin | superadmin'));

// NOT
$this->assertTrue($user->hasAccess('!admin'));
$this->assertFalse($user->hasAccess('!read'));

// Parentheses
$this->assertTrue($user->hasAccess('(read && write) || admin'));
$this->assertFalse($user->hasAccess('(admin && write) || superadmin'));

// Nested
$this->assertTrue($user->hasAccess('read && (write || admin)'));
$this->assertFalse($user->hasAccess('admin && (write || read)'));

// Complex
$this->assertTrue($user->hasAccess('(read || admin) && (write || superadmin)'));

// Edge: empty, null, admin user
$this->assertTrue($user->hasAccess(''));
$this->assertTrue($user->hasAccess(null));

// Invalid expression
$this->expectException(\InvalidArgumentException::class);
$user->hasAccess('&&& invalid');
```

### 4.2 Test the evaluator directly (optional)

If `evaluateBooleanExpression` is a separate method, test the pure boolean logic:

```php
$this->assertTrue(User::evaluateBooleanExpression('1'));
$this->assertFalse(User::evaluateBooleanExpression('0'));
$this->assertTrue(User::evaluateBooleanExpression('1&&1'));
$this->assertFalse(User::evaluateBooleanExpression('1&&0'));
$this->assertTrue(User::evaluateBooleanExpression('0||1'));
// Single operators (bitwise-style, common in user input)
$this->assertTrue(User::evaluateBooleanExpression('1&1'));
$this->assertFalse(User::evaluateBooleanExpression('1&0'));
$this->assertTrue(User::evaluateBooleanExpression('0|1'));
$this->assertFalse(User::evaluateBooleanExpression('0|0'));
$this->assertTrue(User::evaluateBooleanExpression('!0'));
$this->assertFalse(User::evaluateBooleanExpression('!1'));
$this->assertTrue(User::evaluateBooleanExpression('(1&&0)||1'));
$this->assertFalse(User::evaluateBooleanExpression('(0||0)&&1'));
```

---

## 5. SUCCESS CRITERIA

- [ ] `create_function()` completely removed from `User::hasAccess()`
- [ ] No `eval()` or other code execution used as replacement
- [ ] Boolean expression evaluator correctly handles: `&&`, `||`, `!`, `()`, nested expressions
- [ ] Operator precedence correct: `!` > `&&` > `||`
- [ ] All existing simple permission checks still work
- [ ] Unit tests cover all patterns from Section 4.1
- [ ] `./app/vendor/bin/phpunit` green
- [ ] `./app/vendor/bin/phpstan analyse` green
- [ ] No `create_function` references remain in the codebase: `rg "create_function" app/ packages/`

---

## 6. VERIFY: NO OTHER `create_function` USAGE

After fixing `User.php`, confirm no other files use `create_function`:

```bash
rg "create_function" app/ packages/ --glob "*.php"
```

Expected: **zero hits**. If any remain, fix them in this same step.

---

**End of prompt.**
