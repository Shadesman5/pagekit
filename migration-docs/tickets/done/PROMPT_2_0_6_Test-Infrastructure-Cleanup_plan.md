## ARCHITECT OUTPUT
- **Current Step (ROADMAP):** 2.0.6 (Test Infrastructure Cleanup)
- **Scope:**
  - Root `phpunit.xml.dist` — fix test path casing (`tests/Unit` is correct on disk; verify and align)
  - `app/modules/filter/phpunit.xml.dist` — delete (PHPUnit 9 schema, broken bootstrap path)
  - `app/modules/filesystem/phpunit.xml.dist` — delete (PHPUnit 9 schema, broken bootstrap path)
  - `app/modules/cookie/phpunit.xml.dist` — delete (PHPUnit 9 schema, broken bootstrap path)
  - `app/modules/auth/phpunit.xml.dist` — delete (PHPUnit 9 schema, broken bootstrap path)
  - `app/modules/filter/src/Tests/PregReplaceTest.php` — `@dataProvider` → `#[DataProvider]`
  - `app/modules/filter/src/Tests/StripNewlinesTest.php` — `@dataProvider` → `#[DataProvider]`
  - `app/modules/filesystem/src/Tests/PathTest.php` — `@dataProvider` → `#[DataProvider]` (3 occurrences)
  - `app/modules/filesystem/src/Tests/LocatorTest.php` — `@dataProvider` → `#[DataProvider]`
  - `app/system/modules/mail/src/Tests/MailerTest.php` — `@group` → `#[Group]`
  - `app/system/modules/mail/src/Tests/Integration/MailIntegrationTest.php` — `@group` → `#[Group]`
  - `app/system/modules/mail/src/Tests/Controller/MailControllerTest.php` — `@group` → `#[Group]`
  - `app/modules/config/src/Tests/ConfigManagerTest.php` — remove `Doctrine\Common\Cache\ArrayCache` import; modernize mocks (`returnValue()` → `willReturn()`); adapt to current `ConfigManager` constructor signature
  - `app/modules/routing/src/Loader/RoutesLoader.php` — replace empty `catch (\InvalidArgumentException $e) {}` (line 99–100) with debug-aware logging / re-throw
- **Deferred:**
  - Step 2.0.7 (Event Dispatcher Bridge Removal) — `SymfonyEventDispatcherBridge` cleanup is the next ticket; do NOT touch event-dispatcher code or services here
  - Step 2.0.8 (Hotfix `create_function()` in User) — out of scope; ignore even if encountered while reading test files
  - Step 2.1.3 (`strict_types` Migration) — adding `declare(strict_types=1);` to the ~28 test files lacking it is explicitly tracked in 2.1.3; do NOT do it as part of this ticket (the prompt §9 says "welcome but not required" — to keep the diff minimal and the scope honest, we skip it; only revisit if a touched test file genuinely requires it for the migration to compile)
  - Step 2.1.4–2.1.6 (PHPStan level raises) — only run the existing PHPStan baseline for verification; do not raise levels
  - Audit findings tied to other steps (e.g., `assertEquals` → `assertSame` sweep, `MenuApiController` validation, `MailerTest::send()` return type) — those are listed in Steps 2.1.x / 2.0.4 review and stay out of scope here
- **Bridges:** None.
  - This is a test-infrastructure cleanup, not a runtime refactor. No `// TODO: TEMPORARY BRIDGE` markers expected.
  - Per Rule 4 (DELETE OVER WRAP): the four old `phpunit.xml.dist` files are deleted, not migrated to PHPUnit 11 schema, because the root config already covers their test directories via the `app/modules/*/src/Tests` glob.
  - Per Rule 1 (NO COMPATIBILITY LAYERS): `RoutesLoader` exception handler is rewritten in place — no separate "logger adapter" class.
  - If `ConfigManagerTest` exercises cache behavior that the current `ConfigManager` no longer has, the affected test methods are deleted (Rule 4) rather than wrapped in skip / `markTestIncomplete`.
  - All TODO markers, if any leftover hack is unavoidable, MUST use ROADMAP IDs only:
    - `// TODO: Must be refactored in Step 2.1.3 (strict_types Migration)` — only if a touched test file requires partial strict_types work
    - `// TODO: AUDIT FIX Step 2.0.6 (Test Infrastructure Cleanup)` — fallback for any non-trivial test fix surfaced during this step

## Checklist

1. **Pre-flight baseline**
   - Confirm `tests/` casing on disk: `ls -la tests/` (already verified: `tests/Unit` and `tests/e2e` exist with capital U).
   - Capture green baseline: `./app/vendor/bin/phpunit` and PHPStan baseline. Pre-existing red is a blocker — escalate.
   - Enumerate all targets one more time so the Refactorer has a fixed list:
     - `rg -l "phpunit.xml" app/ packages/ --glob "*.dist"`
     - `rg "@dataProvider|@group|@test\b|@covers|@depends|@requires" app/ packages/ --glob "*Test.php" -l`
     - `rg "returnValue\(|returnValueMap\(|returnCallback\(" app/ packages/ --glob "*Test.php"`
     - `rg "use Doctrine\\\\Common\\\\Cache|use Doctrine\\\\Common\\\\Annotations" app/ packages/ --glob "*Test.php"`
     - `rg "backupStaticAttributes|convertErrorsToExceptions|syntaxCheck" app/ packages/`
   - No code changes in this step.

2. **Delete old module-level `phpunit.xml.dist` files**
   - Delete:
     - `app/modules/filter/phpunit.xml.dist`
     - `app/modules/filesystem/phpunit.xml.dist`
     - `app/modules/cookie/phpunit.xml.dist`
     - `app/modules/auth/phpunit.xml.dist`
   - Confirm tests from those modules still discovered via the root config:
     - `./app/vendor/bin/phpunit --list-tests | grep -iE "filter|filesystem|cookie|auth"` — must be non-empty for each.
   - Confirm no other PHPUnit 9 configs remain: `rg -l "phpunit.xml" app/ packages/ --glob "*.dist"` → should be empty.
   - Per-step gate: PHPUnit + PHPStan.

3. **Audit & align root `phpunit.xml.dist` test path casing**
   - On disk the directory is `tests/Unit` (capital U) — root config already references `tests/Unit`, so the **literal "fix" in the prompt is a no-op on the current tree**.
   - Action: explicitly verify by re-reading both `phpunit.xml.dist` and `tests/` on disk; if they ever diverge (e.g. someone adds `tests/unit`), align the XML to whatever the canonical directory name is. If they already match, document this in the commit message and move on — no XML edit required.
   - Confirm `--list-tests` still discovers `tests/Unit/Migration/*` and `tests/Unit/Validator/*`.
   - Per-step gate: PHPUnit + PHPStan.

4. **Migrate `@dataProvider` PHPDoc → `#[DataProvider]` attribute**
   - Files (exhaustive):
     - `app/modules/filter/src/Tests/PregReplaceTest.php`
     - `app/modules/filter/src/Tests/StripNewlinesTest.php`
     - `app/modules/filesystem/src/Tests/PathTest.php` (3 occurrences)
     - `app/modules/filesystem/src/Tests/LocatorTest.php`
   - For each: add `use PHPUnit\Framework\Attributes\DataProvider;`, replace each `@dataProvider provideX` PHPDoc with `#[DataProvider('provideX')]` directly above the method.
   - Final check: `rg "@dataProvider" app/ packages/ --glob "*Test.php"` → zero hits.
   - Per-step gate: PHPUnit + PHPStan.

5. **Migrate `@group` PHPDoc → `#[Group]` attribute**
   - Files (exhaustive):
     - `app/system/modules/mail/src/Tests/MailerTest.php`
     - `app/system/modules/mail/src/Tests/Integration/MailIntegrationTest.php`
     - `app/system/modules/mail/src/Tests/Controller/MailControllerTest.php`
   - For each: add `use PHPUnit\Framework\Attributes\Group;`, replace each `@group <name>` PHPDoc with `#[Group('<name>')]` on the same target (class- or method-level, matching the original placement).
   - Final check: `rg "@group" app/ packages/ --glob "*Test.php"` → zero hits.
   - Per-step gate: PHPUnit + PHPStan.

6. **Migrate any remaining PHPDoc test annotations**
   - Run: `rg "@test\b|@covers|@depends|@requires" app/ packages/ --glob "*Test.php"`.
   - If matches found, migrate to attributes (`#[Test]`, `#[CoversClass(X::class)]`, `#[Depends('m')]`, `#[RequiresPhp('8.2')]`, etc.) and add the matching `use PHPUnit\Framework\Attributes\…;` import.
   - If no matches, the step is a verified no-op — note this in the commit message.
   - Per-step gate: PHPUnit + PHPStan.

7. **Fix legacy `Doctrine\Common\Cache\ArrayCache` import in `ConfigManagerTest`**
   - File: `app/modules/config/src/Tests/ConfigManagerTest.php`.
   - Remove `use Doctrine\Common\Cache\ArrayCache;`.
   - Inspect the current `ConfigManager` constructor (`Connection $connection, array $config`) — no cache argument.
   - Rewrite affected test setup so it calls the modern constructor. Any test method that *only* exercised cache behavior through the (now-gone) cache argument MUST be deleted (Rule 4: DELETE OVER WRAP), not skipped.
   - Re-run `rg "use Doctrine\\\\Common\\\\Cache|use Doctrine\\\\Common\\\\Annotations" app/ packages/ --glob "*Test.php"` → zero hits.
   - Per-step gate: PHPUnit + PHPStan.

8. **Modernize PHPUnit mock patterns in `ConfigManagerTest` (and any leftovers)**
   - In `app/modules/config/src/Tests/ConfigManagerTest.php`, replace every `->will($this->returnValue(X))` with `->willReturn(X)`.
   - Sweep the rest: `rg "returnValue\(" app/ packages/ --glob "*Test.php"` → zero hits.
   - Audit (do not auto-modernize unless idiomatic): `rg "returnValueMap\(|returnCallback\(|returnSelf\(|onConsecutiveCalls\(" app/ packages/ --glob "*Test.php"` — leave these unless usage is demonstrably wrong; if changed, document in the commit message.
   - Per-step gate: PHPUnit + PHPStan.

9. **Fix silent exception swallowing in `RoutesLoader::addController()`**
   - File: `app/modules/routing/src/Loader/RoutesLoader.php` (around lines 85–100).
   - Replace `catch (\InvalidArgumentException $e) { }` with a debug-aware handler:
     - Re-throw when `$app->get('debug')` is truthy.
     - In production, log via `$app->get('log')->warning(...)` if the `log` service is available; otherwise fall back to `error_log(...)`.
   - Match the exception variable name and surrounding style; no new helper classes (Rule 2: NO ADAPTERS).
   - No new TODO markers — this is the fix, not a deferral.
   - Per-step gate: PHPUnit + PHPStan + `php pagekit list` (the latter to confirm route loading still bootstraps without exceptions in normal CLI flow).

10. **Final consolidated audit (mirrors PROMPT §10 + §11)**
    - `rg -l "phpunit.xml" app/ packages/ --glob "*.dist"` → only the root `phpunit.xml.dist` (or empty if scoped to subtrees).
    - `rg "backupStaticAttributes|convertErrorsToExceptions|syntaxCheck" app/ packages/` → zero hits.
    - `rg "@dataProvider|@group" app/ packages/ --glob "*Test.php"` → zero hits.
    - `rg "returnValue\(" app/ packages/ --glob "*Test.php"` → zero hits.
    - `rg "use Doctrine\\\\Common\\\\Cache" app/ packages/ --glob "*Test.php"` → zero hits.
    - `./app/vendor/bin/phpunit` — green.
    - `php pagekit list` — green.
    - PHPStan baseline — green.
    - Playwright E2E (installation, login, dashboard) — green (final-run only, not per-step).

## TESTING STRATEGY
- **Per step:** PHPUnit + PHPStan (mandatory after every checklist step)
- **Final run (after all steps):** PHPUnit + PHPStan + `php pagekit setup` + `php pagekit list` + Playwright E2E (installation, login, dashboard)
