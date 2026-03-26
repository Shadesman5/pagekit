# Step 2.1.5: PHPStan Level 6→7 (Null Safety)

**ROADMAP:** 2.1.5. GitHub Issue: #152. Reference: `@ROADMAP.md`.

---

## CONTEXT

- **Depends on:** Step 2.1.4 (PHPStan Level 6 — all return types declared)
- **Risk:** Medium — requires understanding whether `null` is truly possible or a wrong type declaration
- **What Level 7 checks:** Union types with subtypes, property types must be declared, stricter null analysis.

**Goal:** Add property types to all class properties, enforce null safety, and pass PHPStan Level 7.

---

## 0. SAFETY CHECKS (CRITICAL)

**AFTER EVERY BATCH:**
```bash
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse
```
**IF ANY FAILS → STOP AND FIX!**

**Before starting:** Branch from `develop` (Step 2.1.4 merged).

---

## 1. PREPARATION

### 1.1. Bump PHPStan level

```neon
parameters:
    level: 7
```

### 1.2. Discover errors

```bash
./app/vendor/bin/phpstan analyse --no-progress 2>&1 | head -200
```

Categorize errors:
- **Missing property types** → add typed properties
- **"Call to method on null"** → add null checks or fix type declaration
- **"Parameter expects X, X|null given"** → add null guard or make parameter nullable

---

## 2. ADD PROPERTY TYPES

### 2.1. Typed properties

```php
// BEFORE:
protected $name;
private $items = [];

// AFTER:
protected string $name;
private array $items = [];
```

### 2.2. Nullable properties

If a property can be null (e.g. uninitialized until set):

```php
private ?string $name = null;
```

### 2.3. Constructor property promotion

Where readable, use constructor promotion:

```php
public function __construct(
    private readonly string $name,
    private readonly array $config = [],
) {}
```

---

## 3. FIX NULL SAFETY ISSUES

### 3.1. Decision process for "Call to method on null"

For each error, ask:
1. **Can this actually be null at runtime?** → If yes, add a null guard
2. **Is the type declaration wrong?** → If the value is always set before this point, fix the type (remove `?`)
3. **Is this a logic bug?** → Fix the bug

### 3.2. Prefer fixing types over adding guards

```php
// BAD: Adding a guard to hide a wrong type
if ($this->service !== null) {
    $this->service->doSomething();
}

// GOOD: Fix the type if service is always initialized
private ServiceInterface $service; // not nullable — initialized in constructor
```

**⚠️ Verifier should scrutinize null guards** — the Refactorer may add `!== null` checks where the real fix is a correct type declaration.

---

## SUCCESS CRITERIA

- PHPStan Level 7 passes
- All class properties have type declarations
- No new baseline entries (or documented exceptions)
- No incorrect null guards (types are correct, not just guarded)
- All PHPUnit tests pass

---

## VALIDATION CHECKLIST

- [ ] `phpstan.neon` level set to 7
- [ ] All properties typed
- [ ] Null safety issues resolved (types fixed, not just guarded)
- [ ] `./app/vendor/bin/phpstan analyse` passes at Level 7
- [ ] All PHPUnit tests pass
- [ ] Baseline updated
