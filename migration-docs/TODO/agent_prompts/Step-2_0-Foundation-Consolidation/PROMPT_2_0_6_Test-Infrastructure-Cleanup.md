# Step 2.0.6: Test Infrastructure Cleanup

**ROADMAP:** 2.0.6 — Foundation Consolidation.  
**GitHub Issue:** TBD (create before execution).  
**Prerequisite:** Step 2.0.5 (Composer & Autoload Hygiene) merged on your branch.

**Also read:** `.cursor/ROADMAP.md` (5 aggressive rules), `migration-docs/TODO/PHASE_2_MODERNISING.md` (Step 2.0.6 section).

---

## 1. CONTEXT

### 1.1 Why this step exists

Phase 1 Step 1.2 upgraded PHPUnit from 9.6 to 11.x. The core test suite runs, but the upgrade left behind:

- **4 old module-level `phpunit.xml.dist` files** with PHPUnit 9 schema attributes (`backupStaticAttributes`, `convertErrorsToExceptions`, `syntaxCheck`, `<filter><whitelist>`) and broken bootstrap paths pointing to `../../../../vendor/autoload.php` instead of `app/vendor/autoload.php`
- **Case-sensitivity mismatch** in root `phpunit.xml.dist` (`tests/Unit` vs. actual `tests/unit`) — breaks on Linux CI
- **PHPDoc annotations** (`@dataProvider`, `@group`) instead of PHP 8 attributes
- **Legacy test import** — `ConfigManagerTest` still uses `Doctrine\Common\Cache\ArrayCache`
- **Old mock style** — `$this->returnValue(...)` instead of `willReturn(...)`

### 1.2 Goal

Single, consolidated PHPUnit configuration; all test annotations as PHP 8 attributes; no legacy imports; modern mock patterns. The test suite must be fully functional on case-sensitive filesystems (Linux CI).

---

## 2. SAFETY & VERIFICATION

**Workspace root.** PHPUnit: `./app/vendor/bin/phpunit`.

**After each numbered section:**

```bash
./app/vendor/bin/phpunit
```

If anything fails → fix before continuing.

---

## 3. REMOVE OLD MODULE-LEVEL PHPUNIT CONFIGS

### 3.1 Files to delete

| File | Reason |
|------|--------|
| `app/modules/filter/phpunit.xml.dist` | PHPUnit 9 schema, broken bootstrap path |
| `app/modules/filesystem/phpunit.xml.dist` | PHPUnit 9 schema, broken bootstrap path |
| `app/modules/cookie/phpunit.xml.dist` | PHPUnit 9 schema, broken bootstrap path |
| `app/modules/auth/phpunit.xml.dist` | PHPUnit 9 schema, broken bootstrap path |

### 3.2 Verify tests still discovered

After deletion, confirm that tests from these modules are still picked up by the root `phpunit.xml.dist`:

```bash
./app/vendor/bin/phpunit --list-tests | grep -i "filter\|filesystem\|cookie\|auth"
```

If any tests are **not** discovered, add their source directories to the root config's `<testsuites>`.

### 3.3 Check for other old configs

```bash
rg -l "phpunit.xml" app/ packages/ --glob "*.dist"
```

If more are found with PHPUnit 9 schema, apply the same treatment.

---

## 4. FIX TEST PATH CASE-SENSITIVITY

### 4.1 Root `phpunit.xml.dist`

**File:** `phpunit.xml.dist` (workspace root)

Check the `<testsuites>` section for `tests/Unit` and compare with the actual directory name on disk.

```bash
ls -la tests/
```

If the directory is `tests/unit` (lowercase), update the config to match:

```xml
<directory>tests/unit</directory>
```

**Why:** Linux filesystems are case-sensitive. `tests/Unit` ≠ `tests/unit`. CI on Linux (GitHub Actions, Docker) will silently skip the directory.

---

## 5. MIGRATE ANNOTATIONS → PHP 8 ATTRIBUTES

### 5.1 `@dataProvider` → `#[DataProvider]`

**Import needed:** `use PHPUnit\Framework\Attributes\DataProvider;`

**Files with `@dataProvider` (6 occurrences in 5 files):**

```bash
rg "@dataProvider" app/ packages/ --glob "*Test.php" -l
```

Known files:
- `StripNewlinesTest.php`
- `PregReplaceTest.php`
- `PathTest.php` (3 occurrences)
- `LocatorTest.php`

For each, replace:

```php
// Before:
/**
 * @dataProvider provideData
 */
public function testSomething($input, $expected): void

// After:
#[DataProvider('provideData')]
public function testSomething($input, $expected): void
```

### 5.2 `@group` → `#[Group]`

**Import needed:** `use PHPUnit\Framework\Attributes\Group;`

**Files with `@group`:**

```bash
rg "@group" app/ packages/ --glob "*Test.php" -l
```

Known files (Mail tests):
- `MailerTest.php`
- `MailIntegrationTest.php`
- `MailControllerTest.php`

For each, replace:

```php
// Before:
/**
 * @group network
 */
class MailIntegrationTest extends TestCase

// After:
#[Group('network')]
class MailIntegrationTest extends TestCase
```

**Note:** Class-level and method-level `@group` both become `#[Group(...)]` attributes placed on the class or method respectively.

### 5.3 Check for other PHPDoc test annotations

```bash
rg "@test|@covers|@depends|@requires" app/ packages/ --glob "*Test.php"
```

If found, migrate those too:
- `@test` → `#[Test]` (from `PHPUnit\Framework\Attributes\Test`)
- `@covers` → `#[CoversClass(ClassName::class)]`
- `@depends` → `#[Depends('methodName')]`
- `@requires` → `#[RequiresPhp('8.2')]` etc.

---

## 6. FIX LEGACY TEST IMPORTS

### 6.1 `ConfigManagerTest` — Remove `Doctrine\Common\Cache\ArrayCache`

**File:** `app/modules/config/src/Tests/ConfigManagerTest.php`

- Remove `use Doctrine\Common\Cache\ArrayCache;`
- Find where `ArrayCache` is instantiated or referenced in the test
- Replace with the appropriate mock or Symfony `ArrayAdapter` if a real cache is needed for the test
- Adapt the test to the **current** `ConfigManager` constructor signature (which takes `Connection` + `array $config`, no cache argument)

**Verify the test class still makes sense:** If `ConfigManager` no longer accepts a cache, any test methods exercising cache behavior through `ConfigManager` should be updated or removed.

### 6.2 Search for other Doctrine legacy imports in tests

```bash
rg "use Doctrine\\\\Common\\\\Cache" app/ packages/ --glob "*Test.php"
rg "use Doctrine\\\\Common\\\\Annotations" app/ packages/ --glob "*Test.php"
```

Fix any remaining hits.

---

## 7. MODERNIZE MOCK PATTERNS

### 7.1 `returnValue()` → `willReturn()`

**File:** `app/modules/config/src/Tests/ConfigManagerTest.php`

Replace:

```php
// Before:
->will($this->returnValue(true))

// After:
->willReturn(true)
```

### 7.2 Search for other instances

```bash
rg "returnValue\(" app/ packages/ --glob "*Test.php"
```

Fix all occurrences. `willReturn()` is the modern equivalent and more readable.

### 7.3 Check for `returnValueMap`, `returnCallback` etc.

```bash
rg "returnValueMap\(|returnCallback\(|returnSelf\(|onConsecutiveCalls\(" app/ packages/ --glob "*Test.php"
```

These are still valid in PHPUnit 11 but review if the usage is idiomatic.

---

## 8. FIX SILENT EXCEPTION SWALLOWING IN ROUTESLOADER

**File:** `app/modules/routing/src/Loader/RoutesLoader.php`

Around line 85–100, an `InvalidArgumentException` is caught with an **empty catch block** (`catch (\InvalidArgumentException $e) { }`). This silently ignores errors when controller classes cannot be loaded — making debugging extremely difficult (routes silently disappear).

**Fix:** At minimum, log the exception. In dev mode, consider re-throwing:

```php
catch (\InvalidArgumentException $e) {
    if ($app->get('debug')) {
        throw $e;
    }
    // In production: log and skip this route
    if ($app->has('log')) {
        $app->get('log')->warning('Route loading failed: ' . $e->getMessage());
    }
}
```

If the container/logger is not available at this point in the lifecycle, a simple `error_log()` is acceptable as a fallback.

---

## 9. OPTIONAL: `declare(strict_types=1)` IN TEST FILES (tracked in Step 2.1.3)

**Note:** This is the primary task of Step **2.1.3** (strict_types Migration). However, if you encounter test files during this step that are trivial to fix (no type conflicts), adding `declare(strict_types=1)` is welcome but **not required**.

The ~28 test files without strict_types are tracked for 2.1.3.

---

## 10. FINAL VALIDATION

```bash
./app/vendor/bin/phpunit
php pagekit list
```

Additionally, verify no old PHPUnit configs remain:

```bash
rg -l "backupStaticAttributes|convertErrorsToExceptions|syntaxCheck" app/ packages/
```

Expected: zero hits.

---

## 11. SUCCESS CRITERIA

- [ ] All 4 old module-level `phpunit.xml.dist` files deleted (or migrated to PHPUnit 11 schema if still needed)
- [ ] Root `phpunit.xml.dist` test path matches actual directory casing
- [ ] All `@dataProvider` replaced with `#[DataProvider(...)]`
- [ ] All `@group` replaced with `#[Group(...)]`
- [ ] No `Doctrine\Common\Cache\ArrayCache` imports in test files
- [ ] No `returnValue()` mock style remaining
- [ ] `RoutesLoader` no longer silently swallows exceptions (logging or re-throw in debug)
- [ ] `./app/vendor/bin/phpunit` green — all tests pass
- [ ] No PHPUnit 9 config attributes anywhere in the project

---

**End of prompt.**
