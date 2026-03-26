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
**IF ANY FAILS → STOP AND FIX!**

**Before starting:** Branch from `develop`.

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
- Target all PHP source directories (NOT vendor)
- Use `app/vendor/autoload.php` as bootstrap
- Start at Level 5

### 1.3. Run PHPStan and generate baseline

```bash
./app/vendor/bin/phpstan analyse --generate-baseline
```

This creates `phpstan-baseline.neon`. Add it to `phpstan.neon`:

```neon
includes:
    - phpstan-baseline.neon
```

Document the number of baseline errors.

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

Change ruleset from `@PSR2` to `@PSR12`:

```php
'@PSR12' => true,  // was: '@PSR2' => true
```

**⚠️ Do NOT add `declare_strict_types` rule.** That comes in Step 2.1.3.

Keep all existing additional rules (array_syntax, ordered_imports, etc.).

### 3.3. Run PHP-CS-Fixer

```bash
./app/vendor/bin/php-cs-fixer fix
```

This is formatting only — no semantic changes. Commit the formatting changes separately from tool installation.

### 3.4. Verify no regressions

```bash
./app/vendor/bin/phpunit
```

---

## 4. DOCUMENTATION

Document baseline metrics:
- PHPStan Level 5 baseline error count
- Number of PHP files analyzed
- Code style changes applied (PSR-2 → PSR-12 diff summary)

---

## SUCCESS CRITERIA

- PHPStan installed and runs at Level 5 with baseline
- `phpstan.neon` and `phpstan-baseline.neon` committed
- `roave/security-advisories` installed
- PHP-CS-Fixer upgraded to `@PSR12`
- Code formatted with PSR-12
- No `declare(strict_types=1)` changes
- All PHPUnit tests pass

---

## VALIDATION CHECKLIST

- [ ] `phpstan.neon` exists and is valid
- [ ] `phpstan-baseline.neon` exists
- [ ] `./app/vendor/bin/phpstan analyse` passes (zero errors above baseline)
- [ ] `.php-cs-fixer.php` uses `@PSR12`
- [ ] `roave/security-advisories` in `composer.json`
- [ ] All PHPUnit tests pass
- [ ] No `strict_types` changes
