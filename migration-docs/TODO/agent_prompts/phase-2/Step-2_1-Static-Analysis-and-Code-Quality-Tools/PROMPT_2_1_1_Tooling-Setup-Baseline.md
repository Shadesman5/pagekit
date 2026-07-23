# Step 2.1.1: Tooling-Setup & Baseline

**ROADMAP:** 2.1.1. GitHub Issue: #148. Reference: `@ROADMAP.md`.

---

## CONTEXT

- **Depends on:** Step 1.14 (Doctrine Attributes) — completed
- **Risk:** Low — tool installation and formatting only, no behavioral changes
- **Current state:**
  - PHPStan: NOT installed, no config, no baseline
  - PHP-CS-Fixer: Config exists (`.php-cs-fixer.php`) with `@PSR2`, but NOT in `composer.json`
  - `roave/security-advisories`: NOT installed
  - ESLint/Prettier: Already configured — no changes needed

**Goal:** Install quality tools, create PHPStan baseline, upgrade code formatting to PSR-12. No `strict_types` changes in this step.

---

## 0. SAFETY CHECKS (CRITICAL)

**AFTER EVERY LOGICAL CHANGE:**
```bash
./app/vendor/bin/phpunit
```
**Expected: ~280 tests, all passing.** If significantly fewer tests run, investigate configuration issues.
**IF ANY FAILS → STOP AND FIX!**

**Before starting:** Verify: Branch is Up-to-Date with `develop`.

---

## 1. INSTALL PHPStan

### 1.1. Add PHPStan to composer

```bash
composer require --dev phpstan/phpstan phpstan/phpstan-doctrine phpstan/phpstan-symfony
```

**⚠️ Pagekit vendor dir:** `app/vendor/`. The binary will be at `./app/vendor/bin/phpstan`.

### 1.2. Create `phpstan.neon`

Create `phpstan.neon` in workspace root:

```neon
includes:
    - app/vendor/phpstan/phpstan-doctrine/extension.neon
    - app/vendor/phpstan/phpstan-symfony/extension.neon

parameters:
    level: 5
    paths:
        - app/modules
        - app/system
        - app/installer
        - app/console
        - packages
    excludePaths:
        - app/vendor
        - app/modules/*/vendor
        - */node_modules/*
        - tmp
        - storage
    bootstrapFiles:
        - app/vendor/autoload.php
```

Adjust paths based on discovery. The config must:
- Include the Doctrine and Symfony extension `.neon` files (without these, the extensions are installed but inactive)
- Target all PHP source directories (NOT vendor)
- Use `app/vendor/autoload.php` as bootstrap
- Start at Level 5

**Note:** `phpstan-symfony` is useful for recognizing Symfony attributes (`#[Route]`, `#[Assert\...]`) and HTTP Foundation types. It does NOT require a Symfony container XML dump — Pagekit uses its own PSR-11 container, not a standard Symfony container.

### 1.3. Run PHPStan and generate baseline

```bash
./app/vendor/bin/phpstan analyse --generate-baseline
```

This creates `phpstan-baseline.neon`. Add it to the existing `includes` section in `phpstan.neon`:

```neon
includes:
    - phpstan-baseline.neon
    - app/vendor/phpstan/phpstan-doctrine/extension.neon
    - app/vendor/phpstan/phpstan-symfony/extension.neon
```

Add a comment at the top of `phpstan-baseline.neon` with the total error count and date for future reference.

### 1.4. Verify PHPStan runs clean

```bash
./app/vendor/bin/phpstan analyse
```

Must pass (zero errors above baseline).

---

## 2. INSTALL SECURITY ADVISORIES

```bash
composer require --dev roave/security-advisories:dev-latest
```

This prevents installing packages with known vulnerabilities.

---

## 3. UPGRADE PHP-CS-FIXER TO PSR-12

### 3.1. Add PHP-CS-Fixer to composer (if not present)

```bash
composer require --dev friendsofphp/php-cs-fixer
```

### 3.2. Update `.php-cs-fixer.php`

**Two changes required:**

1. Change ruleset from `@PSR2` to `@PSR12`:

```php
'@PSR12' => true,  // was: '@PSR2' => true
```

2. Remove `'packages'` from the `->exclude([...])` array in the Finder. The current config excludes `packages/` from formatting, but PHPStan analyzes it. Both tools should cover the same scope.

**⚠️ Do NOT add `declare_strict_types` rule.** That comes in Step 2.1.3.

Keep all existing additional rules (array_syntax, ordered_imports, etc.).

### 3.3. Run PHP-CS-Fixer

```bash
./app/vendor/bin/php-cs-fixer fix
```

This is formatting only — no semantic changes.

### 3.4. Verify no regressions

```bash
./app/vendor/bin/phpunit
```

---

## 4. DOCUMENTATION

Include baseline metrics in the **PR description**:
- PHPStan Level 5 baseline error count (also recorded as comment in `phpstan-baseline.neon`)
- Number of PHP files analyzed
- Code style changes applied (PSR-2 → PSR-12 diff summary)

---

## SUCCESS CRITERIA

- PHPStan installed and runs at Level 5 with baseline (Doctrine + Symfony extensions active)
- `phpstan.neon` and `phpstan-baseline.neon` committed
- `roave/security-advisories` installed
- PHP-CS-Fixer upgraded to `@PSR12`, `packages` no longer excluded from formatting
- Code formatted with PSR-12
- No `declare(strict_types=1)` changes
- All PHPUnit tests pass

---

## VALIDATION CHECKLIST

- [ ] `phpstan.neon` exists and includes Doctrine + Symfony extension `.neon` files
- [ ] `phpstan-baseline.neon` exists with error count comment
- [ ] `./app/vendor/bin/phpstan analyse` passes (zero errors above baseline)
- [ ] `.php-cs-fixer.php` uses `@PSR12` and no longer excludes `packages`
- [ ] `roave/security-advisories` in `composer.json`
- [ ] All PHPUnit tests pass (~280 tests)
- [ ] No `strict_types` changes
