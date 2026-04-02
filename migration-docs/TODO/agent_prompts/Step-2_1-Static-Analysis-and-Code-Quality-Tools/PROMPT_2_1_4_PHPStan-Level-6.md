# Step 2.1.4: PHPStan Level 5→6 (Return Types)

**ROADMAP:** 2.1.4. GitHub Issue: #151. Reference: `@ROADMAP.md`.

---

## CONTEXT

- **Depends on:** Step 2.1.3 (`strict_types` Migration)
- **Risk:** Low — mechanical work, high volume but simple patterns
- **What Level 6 checks:** Missing return type declarations. Every method must declare its return type.

**Goal:** Add missing return types to all methods so PHPStan passes at Level 6 with no new baseline entries.

---

## 0. SAFETY CHECKS (CRITICAL)

**AFTER EVERY BATCH:**
```bash
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse
```
**IF ANY FAILS → STOP AND FIX!**

**Before starting:** Verify: Branch is Up-to-Date with `develop`. (Step 2.1.3 merged).

---

## 1. PREPARATION

### 1.1. Bump PHPStan level

Update `phpstan.neon`:

```neon
parameters:
    level: 6
```

### 1.2. Discover errors

```bash
./app/vendor/bin/phpstan analyse --no-progress 2>&1 | head -100
```

Count total errors. These are overwhelmingly "Missing return type" errors at Level 6.

### 1.3. Plan batches

Group by module/directory. Work through:
1. `app/modules/` (core infrastructure)
2. `app/system/` (system modules)
3. `app/installer/` + `app/console/`
4. `packages/`

---

## 2. ADD RETURN TYPES

### 2.1. Common patterns

```php
// BEFORE:
public function getName() { return $this->name; }

// AFTER:
public function getName(): string { return $this->name; }
```

### 2.2. Decision rules

| Return pattern | Type declaration |
|---------------|-----------------|
| Always returns string | `: string` |
| Returns string or null | `: ?string` |
| Returns array | `: array` |
| Returns void (no return) | `: void` |
| Returns self/static | `: static` or `: self` |
| Returns multiple types | `: string\|int` (union type) |
| Returns mixed (truly unknown) | `: mixed` |
| Interface method — keep compatible | Match interface signature |

### 2.3. Avoid over-using `mixed`

`mixed` is a fallback. Before using it, check:
- Can the type be narrowed? (e.g. `mixed` → `string|int|null`)
- Is this a container/registry pattern where `mixed` is genuinely correct?
- For PSR-11 `get()`: `mixed` is correct (returns any service type)

### 2.4. Clean up union types

If a method returns `string|int`, consider: is this intentional or a legacy artifact? If the method can be simplified to return one type, do so. If not, the union type is fine.

---

## 3. UPDATE BASELINE

After all return types are added:

```bash
./app/vendor/bin/phpstan analyse --generate-baseline
```

Ideally, the baseline should shrink significantly or reach zero new entries for Level 6.

---

## SUCCESS CRITERIA

- PHPStan Level 6 passes
- No new baseline entries (or minimal documented exceptions)
- All methods have explicit return types
- All PHPUnit tests pass

---

## 3. KNOWN ISSUES (from 2.1.1 review)

### 3.1. AuthDataCollector — UserInterface mismatch

**Problem:** `Auth::getUser()` returns `UserInterface` (only `getId()`, `getUsername()`, `getPassword()`), but `AuthDataCollector` calls `isAuthenticated()` and `User::findRoles($user)` which only exist on the concrete `User` class. PHPStan Level 6 will flag this as calling undefined methods on `UserInterface`.

**Options:**
- Extend `UserInterface` with `isAuthenticated(): bool` — changes the auth module contract
- Type-narrow `$user` to `User` in `AuthDataCollector` via `instanceof` — keeps interface minimal

**Files:** `app/modules/debug/src/DataCollector/AuthDataCollector.php`, `app/modules/auth/src/UserInterface.php`

---

## VALIDATION CHECKLIST

- [ ] `phpstan.neon` level set to 6
- [ ] `app/modules/` methods typed
- [ ] `app/system/` methods typed
- [ ] `app/installer/` + `app/console/` methods typed
- [ ] `packages/` methods typed
- [ ] `AuthDataCollector` UserInterface issue resolved
- [ ] `./app/vendor/bin/phpstan analyse` passes at Level 6
- [ ] All PHPUnit tests pass
- [ ] Baseline updated
