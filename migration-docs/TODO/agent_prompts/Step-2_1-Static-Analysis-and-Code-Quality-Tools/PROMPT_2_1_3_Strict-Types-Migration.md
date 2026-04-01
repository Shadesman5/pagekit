# Step 2.1.3: `strict_types` Migration

**ROADMAP:** 2.1.3. GitHub Issue: #150. Reference: `@ROADMAP.md`.

---

## CONTEXT

- **Depends on:** Step 2.1.2 (CI/CD — regressions caught immediately)
- **Risk:** Medium-High — `strict_types` changes runtime behavior, can cause `TypeError`
- **Current state:** ~137 of ~790 PHP files have `strict_types` (~17%). The rest are untyped.

**Goal:** Add `declare(strict_types=1)` to ALL PHP files, module-by-module, fixing TypeErrors as they appear.

**⚠️ This is NOT a trivial formatting change.** When `strict_types` is enabled, PHP enforces type coercion strictly — `foo("123")` where `foo(int $x)` becomes a TypeError. Every module must be tested after migration.

---

## 0. SAFETY CHECKS (CRITICAL)

**AFTER EVERY MODULE GROUP (per checklist step):**
```bash
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse
```
**IF ANY FAILS → STOP AND FIX before proceeding to the next module!**

**Before starting:** Verify: Branch is Up-to-Date with `develop`. (Step 2.1.2 merged).

---

## 1. PREPARATION

### 1.1. Discover current state

```bash
# Count PHP files WITHOUT strict_types (excluding vendor)
rg -L 'declare\(strict_types' app/modules/ app/system/ app/installer/ app/console/ packages/ --type php --files-without-match | wc -l

# Count PHP files WITH strict_types
rg -l 'declare\(strict_types' app/modules/ app/system/ app/installer/ app/console/ packages/ --type php | wc -l
```

### 1.2. Identify files per module group

For each directory below, list files needing `strict_types`:

```bash
rg --files-without-match 'declare\(strict_types' app/modules/filter/ --type php
rg --files-without-match 'declare\(strict_types' app/modules/filesystem/ --type php
# ... repeat per module
```

---

## 2. MIGRATION ORDER (sequential, one group per checklist step)

Migrate in this order — small/well-tested modules first, risky modules last:

### Group 1: Small Core Modules (low risk, well-tested)
1. `app/modules/filter/`
2. `app/modules/filesystem/`
3. `app/modules/cookie/`

### Group 2: Auth & Security (critical, well-tested)
4. `app/modules/auth/`

### Group 3: Database Core (careful — runtime behavior critical)
5. `app/modules/database/`

### Group 4: Remaining Core Modules
6. `app/modules/routing/`
7. `app/modules/view/`
8. `app/modules/kernel/`
9. `app/modules/application/`
10. `app/modules/config/`
11. `app/modules/markdown/`
12. All other `app/modules/*` not yet covered

### Group 5: System Modules
13. `app/system/modules/user/`
14. `app/system/modules/site/`
15. `app/system/modules/widget/`
16. All other `app/system/modules/*`
17. `app/system/src/` (ValidatorServiceProvider, ValidatesRequestTrait, etc.)

### Group 6: Installer & Console
18. `app/installer/`
19. `app/console/`

### Group 7: Packages (last)
20. `packages/pagekit/blog/`
21. `packages/pagekit/theme-one/`
22. All other `packages/*`

---

## 3. PER-MODULE MIGRATION PATTERN

For each module:

### 3.1. Add `declare(strict_types=1)`

Add to the top of every PHP file (after `<?php` tag, before namespace):

```php
<?php

declare(strict_types=1);

namespace ...;
```

**⚠️ Some files already have it** — skip those. Some files may have `<?php` with no namespace (e.g. `index.php` config files) — add `strict_types` after `<?php` regardless.

### 3.2. Run tests

```bash
./app/vendor/bin/phpunit
```

### 3.3. Fix TypeErrors

Common fixes:
- `(int)$value` where a string is passed to an int parameter
- `(string)$value` where an int is passed to a string parameter
- `(float)$value` for numeric conversions
- `(bool)$value` for boolean coercion
- Nullable parameters: `?int $x` instead of `int $x` if `null` is a valid input
- Array type hints: verify array contents match expected types

### 3.4. Update PHPStan baseline

After fixing all TypeErrors in a module:

```bash
./app/vendor/bin/phpstan analyse --generate-baseline
```

### 3.5. Commit

One commit per module group. Conventional Commit format:

```
refactor(types): add strict_types to app/modules/filter
```

---

## 4. FINALIZE

### 4.1. Enable `declare_strict_types` in PHP-CS-Fixer

After ALL files have `strict_types`, update `.php-cs-fixer.php`:

```php
'declare_strict_types' => true,
```

This ensures new files automatically get the declaration.

### 4.2. Final verification

```bash
# Zero files without strict_types
rg --files-without-match 'declare\(strict_types' app/modules/ app/system/ app/installer/ app/console/ packages/ --type php | wc -l
# Expected: 0

./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse
```

---

## SUCCESS CRITERIA

- ALL PHP files have `declare(strict_types=1)`
- All PHPUnit tests pass
- PHPStan baseline updated
- No runtime TypeError regressions
- `declare_strict_types` rule enabled in PHP-CS-Fixer
- Migration was done module-by-module with tests between each group

---

## VALIDATION CHECKLIST

- [ ] Group 1 (filter, filesystem, cookie): done + tests green
- [ ] Group 2 (auth): done + tests green
- [ ] Group 3 (database): done + tests green
- [ ] Group 4 (remaining core modules): done + tests green
- [ ] Group 5 (system modules): done + tests green
- [ ] Group 6 (installer, console): done + tests green
- [ ] Group 7 (packages): done + tests green
- [ ] Zero files without `strict_types`
- [ ] PHP-CS-Fixer `declare_strict_types` rule enabled
- [ ] All PHPUnit tests pass
- [ ] PHPStan baseline updated
