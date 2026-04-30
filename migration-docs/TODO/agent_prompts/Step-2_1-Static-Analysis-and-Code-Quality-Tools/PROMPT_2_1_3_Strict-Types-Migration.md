# Step 2.1.3: `strict_types` Migration

**ROADMAP:** 2.1.3. GitHub Issue: #150. Reference: `@ROADMAP.md`.

---

## CONTEXT

- **Depends on:** Step 2.1.2 (CI/CD — regressions caught immediately)
- **Risk:** Medium-High — `strict_types` changes runtime behavior, can cause `TypeError`
- **Current state:** ~137 of ~790 PHP files have `strict_types` (~17%). The remaining **~650 files** need migration in this step. Numbers are indicative — the Architect re-counts during ticket planning (see § 1.1).
- **Scope:** 1 PR for the entire step. Granularity: one commit per migration group; the exact number of groups (and therefore commits) is determined by the Architect during ticket planning based on a fresh per-directory file count. The migration order in § 2 below is a recommendation, not a contract.

**Goal:** Add `declare(strict_types=1)` to ALL PHP files, module-by-module, fixing TypeErrors as they appear.

**⚠️ This is NOT a trivial formatting change.** When `strict_types` is enabled, PHP enforces type coercion strictly — `foo("123")` where `foo(int $x)` becomes a TypeError. Every module must be tested after migration.

---

## 0. SAFETY CHECKS (CRITICAL)

**AFTER EVERY MODULE GROUP (per checklist step, Tester subagent):**
```bash
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
```
**IF ANY FAILS → STOP AND FIX before proceeding to the next module!**

**Before starting:** Verify the branch is up-to-date with `develop` and Step 2.1.2 (CI/CD Quality Gates) is merged.

**End-of-ticket (Final Test, after Early Push):** the Tester subagent waits on the four PHP Quality CI jobs (`phpunit (8.2)`, `phpunit (8.3)`, `phpstan`, `cs-fixer`, `security-audit`) via `gh run watch` and runs the 3 Playwright E2E specs locally in parallel. Both must pass. See `.cursor/rules/orchestrator-subagent-workflow.mdc` § Final Test for the exact flow.

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

### 3.4. Verify PHPStan baseline (DO NOT regenerate)

After fixing all TypeErrors in a module:

```bash
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
```

**Pass criteria:** exit code 0 (no new errors beyond `phpstan-baseline.neon`).

**⚠️ DO NOT run `--generate-baseline`.** That command **overwrites** the baseline and silently swallows every current error as "acceptable" — including new strict-typing regressions introduced by this migration. New errors that surface during `strict_types` migration are real type-safety regressions and must be **fixed in code**, not added to the baseline. Baseline entries that disappear (because `strict_types` resolved them) are a positive signal — leave the baseline file alone, the next clean PHPStan run will simply not reference them. (See `.cursor/agents/tester.md` § PHPStan for the corresponding tester rule.)

### 3.5. Commit

One commit per module group. Conventional Commit format:

```
refactor(types): add strict_types to app/modules/filter
```

---

## 4. FINALIZE

### 4.1. Pre-toggle dry-run (catch missed files)

Before flipping the CS-Fixer rule, verify zero files were missed in groups 1–7:

```bash
rg --files-without-match 'declare\(strict_types' app/modules/ app/system/ app/installer/ app/console/ packages/ --type php | wc -l
# Expected: 0

./app/vendor/bin/php-cs-fixer fix --dry-run --diff --no-interaction --show-progress=none --allow-risky=yes
# Expected: no violations
```

If either command reports leftovers, the offending files were missed in their group. Add `strict_types` and commit them as a follow-up to the relevant group **before** proceeding to § 4.2.

### 4.2. Enable `declare_strict_types` in PHP-CS-Fixer

After zero leftovers are confirmed, update `.php-cs-fixer.php`:

```php
'declare_strict_types' => true,
```

This ensures new files automatically get the declaration in future PRs.

### 4.3. Final verification

```bash
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
./app/vendor/bin/php-cs-fixer fix --dry-run --diff --no-interaction --show-progress=none --allow-risky=yes
```

All three must exit 0. Any failure indicates a regression that must be fixed before the Final Test (CI + E2E) is delegated to the Tester subagent.

---

## AUDIT FINDINGS (Phase 1 Review)

The following was identified during the Phase 1 codebase audit:

- **~28 test files** currently lack `declare(strict_types=1)` — include these in the migration alongside their respective modules
- Test files without strict_types are spread across: Filter, Filesystem, Cookie, Auth, Database, Config, Session, Routing modules

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

> Group breakdown is the recommended starting point — Architect may split or merge groups during ticket planning. The checklist below uses the recommended 7-group structure as a placeholder.

- [ ] Group 1 (filter, filesystem, cookie): done + PHPUnit + PHPStan green
- [ ] Group 2 (auth): done + PHPUnit + PHPStan green
- [ ] Group 3 (database): done + PHPUnit + PHPStan green
- [ ] Group 4 (remaining core modules): done + PHPUnit + PHPStan green
- [ ] Group 5 (system modules): done + PHPUnit + PHPStan green
- [ ] Group 6 (installer, console): done + PHPUnit + PHPStan green
- [ ] Group 7 (packages): done + PHPUnit + PHPStan green
- [ ] § 4.1 dry-run: zero files without `strict_types`, zero CS-Fixer violations
- [ ] § 4.2 `declare_strict_types` rule enabled in `.php-cs-fixer.php`
- [ ] § 4.3 final verification: PHPUnit + PHPStan + CS-Fixer all exit 0
- [ ] Final Test (Tester subagent, post-Early-Push): all four CI jobs green via `gh run watch` + 3 Playwright E2E specs green locally
- [ ] PHPStan baseline file (`phpstan-baseline.neon`) **NOT regenerated** during the migration; only contains pre-existing entries (some may have disappeared, that's expected)
