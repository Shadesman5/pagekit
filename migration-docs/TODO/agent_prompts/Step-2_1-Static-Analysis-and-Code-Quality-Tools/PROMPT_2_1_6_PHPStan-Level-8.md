# Step 2.1.6: PHPStan Level 7→8 (Strict Typing)

**ROADMAP:** 2.1.6. GitHub Issue: #153. Reference: `@ROADMAP.md`.

---

## CONTEXT

- **Depends on:** Step 2.1.5 (PHPStan Level 7 — property types and null safety)
- **Risk:** Medium — may require architecture decisions (interfaces, generics)
- **What Level 8 checks:** No `mixed` without explicit operations, strict call checks, type-safe comparisons.

**Goal:** Eliminate `mixed` where avoidable, introduce template parameters for generic collections where appropriate, and pass PHPStan Level 8.

---

## 0. SAFETY CHECKS (CRITICAL)

**AFTER EVERY BATCH:**
```bash
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse
```
**IF ANY FAILS → STOP AND FIX!**

**Before starting:** Branch from `develop` (Step 2.1.5 merged).

---

## 1. PREPARATION

### 1.1. Bump PHPStan level

```neon
parameters:
    level: 8
```

### 1.2. Discover errors

Level 8 errors are typically:
- **"Parameter has no type"** (function params)
- **"Cannot call method on mixed"** → narrow the type
- **"Cannot access property on mixed"**
- **"Binary operation on mixed"**

---

## 2. ELIMINATE `mixed`

### 2.1. Narrow types where possible

```php
// BEFORE:
public function process($data) { ... }

// AFTER:
public function process(array $data): array { ... }
```

### 2.2. Justified `mixed` exceptions

Some uses of `mixed` are correct and unavoidable:
- **PSR-11 Container** `get()` returns `mixed` — this is correct per specification
- **Event/config data** — dynamic plugin data is genuinely mixed
- **Template rendering** — view variables are mixed by design

Document justified exceptions with PHPDoc:

```php
/** @return mixed Genuinely unknown type — plugin API return value */
```

### 2.3. PHPDoc generics for collections

Where arrays have known structure:

```php
/** @var array<string, mixed> */
private array $config;

/** @return array<int, User> */
public function getUsers(): array { ... }
```

---

## 3. ARCHITECT DECISION POINTS

Some Level 8 fixes may require interface changes or design decisions. If the Refactorer encounters:
- A method returning `mixed` that's used by multiple callers with different expectations → Escalate to Architect
- An interface method that needs a generic type parameter → Architect decides if generics are worth the complexity
- A significant refactor needed to narrow a type → Architect scopes it

---

## 4. LEVEL 9 — DO NOT USE

**❌ Level 9 is explicitly excluded.** It's too strict for a CMS with dynamic extension APIs. Level 8 is the target ceiling.

---

## SUCCESS CRITERIA

- PHPStan Level 8 passes
- No baseline entries (or minimal, documented, justified exceptions)
- `mixed` only used where genuinely unavoidable (documented)
- No architecture regressions
- All PHPUnit tests pass

---

## VALIDATION CHECKLIST

- [ ] `phpstan.neon` level set to 8
- [ ] `mixed` eliminated where avoidable
- [ ] Justified `mixed` uses documented
- [ ] Generic PHPDoc annotations where useful
- [ ] `./app/vendor/bin/phpstan analyse` passes at Level 8
- [ ] All PHPUnit tests pass
- [ ] Baseline updated (zero or documented exceptions only)
