# 🔧 Phase 1: Core Backend Modernization – Renewing the Foundation

**Status**: ✅ COMPLETED
**Priority**: HIGHEST
**Goal**: Complete modernization of the existing backend infrastructure. No new features!

> **Foundation First Principle**: Modernize all existing systems before building new features.

---

## Step 1.1: Complete Mailer Migration

- **Status**: ✅ COMPLETED
- **Branch**: `feature/symfony-mailer-migration`
- **Result**:
  - Swift Mailer → Symfony Mailer 5.4 migration complete
  - 42 tests implemented
  - Documentation in `MAIL_MIGRATION.md`
- **Re-Audit**: Verify against current modernization standards using `AGENT_PROMPT_AUDIT_AND_VERIFICATION.md` (compatibility layers, legacy code, PHP 8.2+)

---

## Step 1.2: Modernize Test Suite

- **Status**: ✅ COMPLETED
- **Branch**: `feature/phpunit-11-upgrade`
- **Result**: PHPUnit 9.6 → 11.x successful

---

### Step 1.3: Patch Critical Dependencies

- **Status**: ✅ PARTIALLY COMPLETED
- **Branch**: `feature/security-patches`
- **Result**:
  - ✅ Done: monolog/monolog ~2.1.1 → ^3.7
  - ✅ Done: Other security patches
  - ⚠️ DEFERRED: doctrine/annotations, doctrine/cache (for later steps)

---

### Step 1.3.5: Process Safe Dependabot Updates

- **Status**: ✅ COMPLETED
- **Branch**: `feature/dependabot-safe-updates`
- **Result**: All safe updates applied before Symfony upgrade

---

### Step 1.4: Safe Minor Dependency Updates

- **Status**: ✅ COMPLETED
- **Branch**: `feature/safe-minor-updates`
- **Result**: All safe minor updates without breaking changes applied

---

### Step 1.5: Doctrine DBAL 2.x → 3.x Update

- **Status**: ✅ COMPLETED
- **Branch**: `feature/doctrine-dbal-3x-update`
- **Result**:
  - ✅ doctrine/dbal: ~2.13 → ^3.8 successfully migrated
  - ✅ All Query Builder adaptations implemented
  - ✅ Result fetching methods updated
  - ✅ Connection configuration modernized
  - ℹ️ doctrine/cache: Stays on ~1.13 (requires PSR-6 migration)

---

### Step 1.6: Service Container PSR-11 Compatibility

- **Status**: ✅ COMPLETED
- **Branch**: `feature/psr11-container-compatibility`
- **Goal**: Make Pagekit Container PSR-11 compatible
- **Result**:
  - ✅ PSR-11 Container Interface implemented
  - ✅ Service definitions modernized
  - ✅ Dependency Injection Container extended
  - ✅ Backward compatibility for existing services ensured
  - ✅ All container tests passing

---

### Step 1.7: Event System Symfony 6.4 Compatibility

- **Status**: ✅ COMPLETED
- **Branch**: `feature/symfony-event-system-compatibility`
- **Goal**: Make Pagekit Event System compatible with Symfony 6.4
- **Result**:
  - ✅ Event Dispatcher Interface modernized
  - ✅ Event Listener typing improved
  - ✅ Symfony Event System integration completed
  - ✅ All event tests passing
  - ✅ Backward compatibility for existing event handlers ensured

---

### Step 1.8: Routing System Symfony 6.4 Compatibility

- **Status**: ✅ COMPLETED
- **Branch**: `feature/symfony-routing-compatibility`
- **Goal**: Make Pagekit Routing System compatible with Symfony 6.4
- **Result**:
  - ✅ Router class modernized with type hints
  - ✅ Route class made compatible with Symfony 6.4
  - ✅ UrlGenerator Interface updated
  - ✅ LINK_URL Constant fix implemented (string → integer)
  - ✅ Comprehensive test suite created (36 tests, all passing)
  - ✅ 100% backward compatibility ensured
  - ✅ Complete documentation created

---

### Step 1.9: The Symfony Upgrade

- **Status**: ✅ COMPLETED
- **Branch**: `feature/symfony-6.4-upgrade`
- **Goal**: Symfony 5.4 → 6.4 LTS
- **Result**:
  - ✅ All 21 Symfony components updated to 6.4.x
  - ✅ HTTP Foundation/Kernel migrated to 6.4
  - ✅ Routing System made compatible with 6.4
  - ✅ Console Commands adapted for 6.4
  - ✅ Symfony Mailer continues on 6.4
  - ✅ Event System compatible with 6.4
  - ✅ All breaking changes resolved
  - ✅ Return types and parameter types adapted
  - ✅ No more deprecation warnings
  - ✅ Performance maintained/improved
  - ✅ Complete documentation in SYMFONY_64_CHANGES.md

**Detailed Agent Prompt**:

```
TASK: Upgrade Symfony from 5.4 to 6.4 LTS - COMPLETE SYSTEMATIC APPROACH

0. CRITICAL RULE:
   ⚠️ AFTER EVERY SINGLE CHANGE:
   - Test console: php pagekit setup
   - Test web: curl http://localhost:8000 (MUST return 200, not 500!)
   - Test admin: curl http://localhost:8000/admin
   IF ANY TEST FAILS → STOP AND FIX BEFORE CONTINUING!

1. PREPARATION:
   - Create new branch: `feature/symfony-6.4-upgrade` from `develop`
   - Ensure PHP 8.2+ and PHPUnit 11 are working
   - Create test script: test_all.sh that tests console + web + admin
   - Run test script → ALL MUST PASS before starting
   - Create SYMFONY_64_CHANGES.md to document all changes

2. ANALYSIS PHASE:
   2.1 List all Symfony components in composer.json
   2.2 Review Symfony upgrade guides:
       - https://github.com/symfony/symfony/blob/6.4/UPGRADE-6.0.md
       - https://github.com/symfony/symfony/blob/6.4/UPGRADE-6.1.md
       - https://github.com/symfony/symfony/blob/6.4/UPGRADE-6.2.md
       - https://github.com/symfony/symfony/blob/6.4/UPGRADE-6.3.md
       - https://github.com/symfony/symfony/blob/6.4/UPGRADE-6.4.md

   2.3 Identify all breaking changes that affect Pagekit:
       - Method signature changes
       - Removed classes/interfaces
       - Changed configurations
       - Deprecated features now removed
       - New required parameters
       - Changed return types

   2.4 Find ALL Symfony dependencies in Pagekit:
       - Classes extending Symfony classes
       - Classes implementing Symfony interfaces
       - Methods overriding Symfony methods

       Known files that MUST be checked:
       - app/modules/view/src/PhpEngine.php
       - app/modules/view/src/Loader/FilesystemLoader.php
       - app/modules/routing/src/RequestContext.php
       - app/modules/application/src/Application/Console/Application.php
       - app/modules/database/src/Connection.php
       - app/modules/routing/src/Generator/UrlGenerator.php
       - app/modules/routing/src/Route.php
       - app/modules/log/src/Logger.php
       - app/modules/auth/src/Event/Event.php
       - app/modules/application/src/Application/Console/Command.php

3. UPDATE COMPOSER.JSON INCREMENTALLY:
   ⚠️ Update in small groups and test after each!

   Group 1 - Core (update first):
   - "symfony/http-foundation": "^6.4"
   - "symfony/http-kernel": "^6.4"
   RUN TESTS → Must still work!

   Group 2 - Routing:
   - "symfony/routing": "^6.4"
   RUN TESTS → Must still work!

   Group 3 - Console:
   - "symfony/console": "^6.4"
   RUN TESTS → Must still work!

   Group 4 - Templating & Translation:
   - "symfony/templating": "^6.4"
   - "symfony/translation": "^6.4"
   - "symfony/twig-bridge": "^6.4"
   RUN TESTS → Must still work!

   Group 5 - Rest:
   - "symfony/finder": "^6.4"
   - "symfony/framework-bundle": "^6.4"
   - "symfony/mailer": "^6.4"
   - "symfony/stopwatch": "^6.4"
   - "symfony/process": "^6.4"
   - "symfony/filesystem": "^6.4"
   - "symfony/error-handler": "^6.4"
   - "symfony/string": "^6.4"
   - "symfony/yaml": "^6.4"
   RUN TESTS → Must still work!

4. RUN COMPOSER UPDATE:
   - Run: composer update symfony/* --with-dependencies
   - Document any dependency conflicts
   - Resolve conflicts by updating related packages:
     * May need to update psr/log to ^2.0|^3.0
     * May need to update psr/cache to ^2.0|^3.0
   - Run: composer update to ensure all dependencies are resolved

5. FIX BREAKING CHANGES SYSTEMATICALLY:
   5.1 Method Return Types (CRITICAL for PHP 8.4):
       Based on Symfony 6.4 requirements:
       - PhpEngine::evaluate() → add ": string|false"
       - FilesystemLoader::load() → add ": Storage|false"
       - RequestContext::fromRequest() → add ": static"
       - Connection methods → check parent signatures
       - UrlGenerator methods → check parent signatures
       - Logger methods → check parent signatures
       - Event methods → check parent signatures
       - Command methods → check parent signatures
       - CHECK ALL OTHER OVERRIDDEN METHODS!

   5.2 Service Configuration Changes:
       - Check if services.yaml format changed
       - Update deprecated service aliases
       - Fix removed service definitions
       - Update autowiring configurations
       - Ensure all services are properly tagged

   5.3 Deprecated Service Aliases:
       - Find all deprecated aliases in Symfony docs
       - Replace with new service names
       - Update dependency injection

   5.4 Changed Method Signatures:
       - Compare ALL parent class methods with implementations
       - Add missing parameter type hints
       - Add missing return type hints
       - Fix parameter order if changed
       - Handle new required parameters

   5.5 Removed Deprecated Features:
       - Check what was deprecated in 5.4 and removed in 6.0+
       - Replace all deprecated method calls
       - Update deprecated configurations
       - Replace deprecated classes

   5.6 New Required Configurations:
       - Check if new config parameters are required
       - Add default values where needed
       - Update config files

   5.7 Changes in Event System:
       - Event listener method signatures
       - EventSubscriberInterface changes
       - Event dispatching changes
       - Event priorities

   5.8 Router Configuration Updates:
       - Route loading changes
       - Annotation/Attribute changes
       - URL generation changes
       - Route compilation changes

6. MODULE-BY-MODULE TESTING:
   Test in this EXACT order:

   6.1 Core Modules:
       - app/modules/application → php pagekit test application
         After: Test web + admin
       - app/modules/config → php pagekit test config
         After: Test web + admin
       - app/modules/database → php pagekit test database
         After: Test web + admin
       - app/modules/auth → php pagekit test auth
         After: Test web + admin

   6.2 Infrastructure Modules:
       - app/modules/session → php pagekit test session
         After: Test web + admin
       - app/modules/routing → php pagekit test routing
         After: Test web + admin
       - app/modules/kernel → php pagekit test kernel
         After: Test web + admin
       - app/modules/view → php pagekit test view
         After: Test web + admin

   6.3 System Modules:
       - app/system/modules/* → Test each individually
       - For each: Run tests, check web, check admin

   6.4 Packages:
       - packages/* → Test each package
       - For each: Run tests, check functionality

   For EACH module:
   - Run module-specific tests
   - Check for PHP deprecation warnings
   - Check for Symfony deprecation warnings
   - Fix any errors IMMEDIATELY
   - Update code as needed
   - Write new tests for changed behavior
   - Document changes in SYMFONY_64_CHANGES.md

7. SPECIFIC ATTENTION AREAS:
   7.1 HTTP Kernel Changes:
       - HttpKernelInterface methods
       - handle() method signature
       - Exception handling
       - Middleware/Event listeners

   7.2 Router Configuration:
       - Route collection building
       - Route matching algorithm
       - URL generation
       - Route caching

   7.3 Service Container:
       - PSR-11 compatibility (from step 1.6)
       - Container compilation
       - Service definitions
       - Compiler passes

   7.4 Event Dispatcher:
       - Event system compatibility (from step 1.7)
       - Listener registration
       - Subscriber interfaces
       - Event propagation

   7.5 Console Commands:
       - Command::execute() signature
       - Input/Output interfaces
       - Command registration
       - Console helpers

   7.6 Mailer:
       - Already on Symfony Mailer (from step 1.1)
       - Verify 6.4 specific changes
       - Test email sending

8. CREATE/UPDATE TESTS:
   - Write tests for any compatibility layers added
   - Update existing tests for new Symfony 6.4 APIs
   - Add integration tests for critical paths:
     * User registration flow
     * User login flow
     * Password reset flow
     * Content creation flow
     * File upload flow
     * Email sending flow
   - Ensure NO Symfony deprecation warnings
   - Run full test suite: vendor/bin/phpunit
   - All tests MUST pass

9. PERFORMANCE CHECK:
   Before upgrade:
   - Record page load times
   - Record memory usage
   - Record database query counts

   After upgrade:
   - Compare response times before/after
   - Check memory usage
   - Profile any slow areas
   - Document performance changes

   If performance degraded > 10%:
   - Investigate cause
   - Optimize or document reason

10. VALIDATION:
    Console validation:
    - Run: composer test (full suite)
    - Run: php pagekit about (check Symfony version shows 6.4)
    - Run: php pagekit list (all commands work)
    - Run: php pagekit setup (installs successfully)

    Browser validation - Test all major features:
    - User registration/login
    - Admin panel access and navigation
    - Content creation (pages, posts)
    - Media upload (images, files)
    - Email sending (password reset)
    - Widget management
    - Theme switching
    - Extension management

    Check logs:
    - No deprecation warnings in logs
    - No PHP errors
    - No Symfony errors

11. DOCUMENTATION:
    Update in SYMFONY_64_CHANGES.md:

    11.1 All Changed Files:
         - Complete list with diffs
         - Reason for each change

    11.2 All Code Modifications:
         - Return types added
         - Parameter types changed
         - Deprecated methods replaced
         - New patterns introduced

    11.3 Any New Patterns Introduced:
         - How to handle uninitialized properties
         - New service patterns
         - Event handling changes

    11.4 Performance Impact:
         - Before/after metrics
         - Any optimizations made

    11.5 Breaking Changes for Extensions:
         - What extension developers must change
         - Migration guide
         - Example updates

12. CREATE PULL REQUEST:
    - Title: "feat: Upgrade Symfony to 6.4 LTS"
    - Base: develop
    - Description:
      * Summary of all Symfony components updated
      * List of breaking changes addressed
      * Test results summary (with screenshots)
      * Performance comparison
      * Link to SYMFONY_64_CHANGES.md
    - Add labels: enhancement, symfony, major-update
    - Include evidence:
      * Screenshot of passing tests
      * Screenshot of working web interface
      * Screenshot of working admin panel

SUCCESS CRITERIA:
- All Symfony components on 6.4.x
- Zero failing tests
- No deprecation warnings
- All features working as before
- Performance maintained or improved
- Complete documentation
- PR ready with thorough testing evidence
- SYSTEM IS FULLY FUNCTIONAL FOR END USERS
```

---

### Step 1.10: Cache System PSR-6 Migration & doctrine/cache Removal

- **Status**: ✅ COMPLETED
- **Branch**: `feature/psr6-cache-migration`
- **Goal**: Migrate cache system from doctrine/cache to PSR-6
- **Result**:
  - ✅ doctrine/cache completely removed
  - ✅ symfony/cache: ^6.4 implemented
  - ✅ PSR-6 Adapter Layer created (Psr6Adapter.php)
  - ✅ All cache adapters migrated:
    - ArrayAdapter (Symfony ArrayAdapter)
    - FilesystemAdapter (Symfony FilesystemAdapter)
    - PhpFilesAdapter (Symfony PhpFilesAdapter)
    - ApcuAdapter (Symfony ApcuAdapter)
    - NullAdapter (Symfony NullAdapter)
  - ✅ Backward compatibility for old cache API ensured
  - ✅ Namespace support implemented
  - ✅ Cache clear command working
  - ✅ Route caching still functional
  - ✅ Module metadata caching active
  - ✅ Performance maintained
  - ✅ Complete documentation in PSR6_CACHE_MIGRATION.md

**Detailed Agent Prompt**:

```
TASK: Migrate Cache System from doctrine/cache to PSR-6

0. CRITICAL RULE:
   ⚠️ AFTER EVERY SINGLE CHANGE:
   - Test console: php pagekit list
   - Test web: curl http://localhost:8000 (MUST return 200, not 500!)
   - Test admin: curl http://localhost:8000/admin
   - Test cache clear: php pagekit clearcache
   IF ANY TEST FAILS → STOP AND FIX BEFORE CONTINUING!

1. PREPARATION: (already done)
   - Pull latest from develop: git pull origin develop
   - Create new branch: `feature/psr6-cache-migration` from `develop`
   - Create PSR6_CACHE_MIGRATION.md to document changes
   - Verify Symfony 6.4 cache component is available
   - Create backup of current cache implementation

2. ANALYZE CURRENT CACHE USAGE:
   2.1 Review Pagekit cache structure:
      - app/system/modules/cache/src/CacheModule.php
      - app/system/modules/cache/src/Cache/ArrayCache.php
      - app/system/modules/cache/src/Cache/ApcCache.php
      - app/system/modules/cache/src/Cache/FilesystemCache.php
      - app/system/modules/cache/src/Cache/PhpFileCache.php
      - app/system/modules/cache/src/Cache/NullCache.php

   2.2 Find all cache usage:
      - grep -r "doctrine/cache" app/ packages/
      - grep -r "->fetch(" app/ packages/
      - grep -r "->contains(" app/ packages/
      - grep -r "->save(" app/ packages/
      - grep -r "->delete(" app/ packages/

   2.3 Document current cache stores:
      - cache.main (system cache)
      - cache.phpfile (compiled PHP cache)
      - cache.module (module metadata)
      - cache.routes (routing cache)

3. CREATE PSR-6 ADAPTER LAYER:
   3.1 Create app/system/modules/cache/src/Adapter/Psr6Adapter.php
      - Implement backwards compatibility layer
      - Map old methods to PSR-6 methods

   3.2 Create specific adapters:
      - ArrayAdapter.php → Symfony\Component\Cache\Adapter\ArrayAdapter
      - ApcuAdapter.php → Symfony\Component\Cache\Adapter\ApcuAdapter
      - FilesystemAdapter.php → Symfony\Component\Cache\Adapter\FilesystemAdapter
      - PhpFilesAdapter.php → Symfony\Component\Cache\Adapter\PhpFilesAdapter
      - NullAdapter.php → Symfony\Component\Cache\Adapter\NullAdapter

4. UPDATE CACHE MODULE:
   4.1 Modify app/system/modules/cache/src/CacheModule.php:
      - Change service registration to use PSR-6
      - Keep backward compatibility aliases
      - Update factory methods

   4.2 Update cache configuration in app/system/config.php:
      'system/cache' => [
         'caches' => [
            'cache' => [
               'adapter' => 'filesystem', // instead of 'storage'
               'path' => "$path/tmp/cache",
               'default_lifetime' => 0
            ]
         ]
      ]

5. MIGRATION MAPPING:
   5.1 Method mapping (create compatibility layer first):
      OLD (doctrine/cache)          → NEW (PSR-6)
      ─────────────────────────────────────────────────
      $cache->contains($key)         → $cache->hasItem($key)
      $cache->fetch($key)            → $cache->getItem($key)->get()
      $cache->fetchMultiple($keys)   → $cache->getItems($keys)
      $cache->save($key, $data, $ttl)→ $item = $cache->getItem($key);
                                       $item->set($data);
                                       $item->expiresAfter($ttl);
                                       $cache->save($item);
      $cache->delete($key)           → $cache->deleteItem($key)
      $cache->deleteAll()            → $cache->clear()
      $cache->getStats()             → Custom implementation

   5.2 Special handling for:
      - Namespace support (used in routing cache)
      - TTL handling (0 = infinite in doctrine, null in PSR-6)
      - Serialization (automatic in PSR-6)

6. PHASED MIGRATION:
   Phase 1: Add PSR-6 support alongside doctrine/cache
      - Keep both systems running
      - Add deprecation notices
      - Test thoroughly

   Phase 2: Migrate core modules
      - app/modules/routing (route cache)
      - app/modules/application (container cache)
      - app/system/modules/cache (main cache)

   Phase 3: Migrate system modules
      - app/system/modules/theme
      - app/system/modules/widget
      - packages/pagekit/blog
      - possibly others

   Phase 4: Remove doctrine/cache
      - Remove from composer.json
      - Remove old cache classes
      - Clean up compatibility layer

7. SPECIFIC PAGEKIT CONSIDERATIONS:
   7.1 Route caching:
      - Located in app/modules/routing/src/RoutesLoader.php
      - Uses namespace feature heavily
      - Critical for performance

   7.2 Module metadata caching:
      - Located in app/modules/application/src/Module/ModuleManager.php
      - Caches module configurations
      - Must maintain structure

   7.3 View/Template caching:
      - Twig cache in app/modules/view/modules/twig
      - Must remain compatible

   7.4 Widget caching:
      - Dashboard widgets cache data
      - Check expiration handling

8. CREATE/UPDATE TESTS:
   8.1 Unit tests:
      - Test each adapter individually
      - Test backward compatibility layer
      - Test TTL handling
      - Test namespace support

   8.2 Integration tests:
      - Test route caching
      - Test module caching
      - Test cache clearing
      - Test cache warmup

   8.3 Performance tests:
      - Benchmark old vs new
      - Memory usage comparison
      - Speed comparison for common operations

9. VALIDATION CHECKLIST:
   [ ] Console commands work
   [ ] php pagekit clearcache works
   [ ] Route caching works
   [ ] Module metadata cached correctly
   [ ] Admin panel loads fast
   [ ] Blog posts cached
   [ ] Widget data cached
   [ ] No deprecation warnings
   [ ] No performance regression

10. DOCUMENTATION:
    10.1 In PSR6_CACHE_MIGRATION.md:
        - Complete API changes
        - Migration guide for extensions
        - Performance comparison
        - Breaking changes list

    10.2 Update code comments:
        - Add @deprecated tags
        - Document new methods
        - Explain migration path

    10.3 Create migration script:
        - For extension developers
        - Automated conversion tool
        - Find/replace patterns

11. ROLLBACK PLAN:
    - Keep doctrine/cache in require-dev initially
    - Feature flag for new cache system
    - Quick revert procedure documented

12. CREATE PULL REQUEST FILE:
    - Create PR_psr6_cache_migration.md with:
      * Title: "feat: Migrate cache system to PSR-6 and remove doctrine/cache"
      * Base: develop
      * Description:
        - ✅ PSR-6 cache implementation
        - ✅ Backward compatibility maintained
        - ✅ doctrine/cache removed
        - ✅ Performance improved/maintained
        - ✅ All tests passing
        - 📊 Performance metrics included
        - 📝 Migration guide for extensions
        - 🔗 Link to PSR6_CACHE_MIGRATION.md

SUCCESS CRITERIA:
✅ PSR-6 cache fully implemented
✅ doctrine/cache removed
✅ All cache operations working
✅ No performance regression (benchmark proof)
✅ All tests green
✅ Extensions still working
✅ Complete documentation
✅ Clean git history

─────────────────────────────────────────────────────────────────────

🎯 ADDITIONAL NOTES FOR THE AGENT:

IMPORTANT FILES TO CHECK:
• app/system/modules/cache/index.php - Cache Service Registration
• app/console/src/Commands/CacheClearCommand.php - Cache Clear Command
• app/modules/routing/src/Provider/RouteProvider.php - Route Cache Usage
• config.php - Cache Configuration

POTENTIAL PITFALLS:
1. Namespace support - doctrine/cache has its own namespace implementation
2. TTL differences - 0 vs null for "never expire"
3. Serialization - PSR-6 handles this automatically
4. Stats/Metrics - PSR-6 does not have getStats()

TESTING STRATEGY:
• Test after every step
• No large changes at once
• Always have a backup plan
• Document performance comparisons
```

---

### Step 1.10.5: E2E Testing Infrastructure with Playwright

- **Status**: ✅ COMPLETED
- **Branch**: `feature/e2e-testing-playwright`
- **Goal**: Modern end-to-end test suite as a safety net for core modernization
- **Prerequisite**: Step 1.10 (PSR-6 Cache) completed
- **Memory Usage**: <1GB (Playwright ~100MB + test assets ~500MB)
- **Performance Impact**: ≈ 0% on production (dev-dependencies only, separate containers)
- **Strategic Advantage**: Tests protect the remaining core updates (1.11-1.14)
- **Why Playwright**: Uses existing Node.js + Docker infrastructure, modern performance, better browser support
- **Components**:
  - Playwright Framework Setup (JavaScript/Node.js)
  - Multi-Browser Automation (Chrome, Firefox, Safari, Edge)
  - Docker Test Environment (uses existing infrastructure)
  - Complete User Workflow Tests (>20 scenarios)
  - Integration with Vue.js 2.6 Frontend Stack
  - UIkit 3.5 Component Testing
  - Foundation for future CI/CD integration

**Detailed Agent Prompt**:

```
TASK: Implement Modern E2E Testing with Playwright as Safety Net for Pagekit

0. CRITICAL RULES FOR TEST CREATION:
   ⚠️ TESTS MUST BE COMPLETELY ISOLATED:
   - NEVER touch production database or files
   - Use docker-compose.e2e.yml (separate containers)
   - Test database: pagekit_e2e_test (NOT pagekit!)
   - Test port: 8180 (NOT 8080!)
   - Storage: ./storage-e2e (NOT ./storage!)

   ⚠️ AFTER CREATING EACH TEST FILE:
   - Run test locally: npx playwright test [new-test-file]
   - Verify no production impact: docker ps (check container names)
   - Ensure main app still works: curl http://localhost:8080
   - Document test in E2E_TEST_CATALOG.md
   IF PRODUCTION IS AFFECTED → STOP AND ISOLATE!

1. PREPARATION & CONTEXT CHECK:
   - Create branch: `feature/e2e-testing-playwright` from `develop`
   - Verify prerequisites:
     * Docker running: docker ps
     * Node.js 18+: node --version
     * Pagekit accessible: curl http://localhost:8080
     * PHPUnit works: composer test
   - Study existing patterns:
     * Read app/modules/*/src/Tests/*.php for test patterns
     * Analyze Vue components in app/system/modules/*/app/components/
     * Review UIkit usage in app/system/modules/*/views/
   - Create documentation:
     * E2E_TESTING_FOUNDATION.md (architecture & setup)
     * E2E_TEST_CATALOG.md (list of all tests with descriptions)
     * tests/e2e/README.md (quick start guide)

2. PLAYWRIGHT SETUP (Pagekit-optimized):
   2.1 Install dependencies:
       npm install --save-dev @playwright/test dotenv
       npx playwright install chromium firefox webkit

   2.2 Create playwright.config.js:
       - baseURL: 'http://localhost:8080'
       - timeout: 30000 (enough for slow operations)
       - retries: 2 (handle flaky tests)
       - workers: 4 (parallel execution)
       - screenshot: 'only-on-failure'
       - video: 'retain-on-failure'
       - trace: 'on-first-retry'

   2.3 Add package.json scripts:
       "test:e2e": "playwright test"
       "test:e2e:headed": "playwright test --headed"
       "test:e2e:debug": "playwright test --debug"
       "test:e2e:ui": "playwright test --ui"
       "test:e2e:report": "playwright show-report"
       "test:e2e:install": "playwright test tests/e2e/specs/001-installation.spec.js"

3. DOCKER TEST ENVIRONMENT:
   3.1 Create docker-compose.e2e.yml:
       - Copy docker-compose.yml as base
       - Rename ALL services: web → web-e2e, mysql → mysql-e2e
       - Change ports: 8080→8180, 3306→3307, 8081→8181
       - Change database: pagekit → pagekit_e2e_test
       - Add volumes: ./storage-e2e, ./tmp-e2e
       - Set environment: APP_ENV=test, DEBUG=true

   3.2 Create test data fixtures:
       - tests/e2e/fixtures/fresh-install.sql
       - tests/e2e/fixtures/demo-content.sql
       - tests/e2e/fixtures/test-users.json

   3.3 Helper scripts:
       - scripts/e2e-start.sh (start test environment)
       - scripts/e2e-reset.sh (reset to clean state)
       - scripts/e2e-stop.sh (cleanup)

4. PAGEKIT TEST HELPERS (tests/e2e/helpers/):
   4.1 pagekit-auth.js:
       - loginAsAdmin(page)
       - loginAsUser(page, username, password)
       - logout(page)
       - checkPermission(page, permission)

   4.2 pagekit-content.js:
       - createPage(page, title, content, markdown=false)
       - createPost(page, title, content, status='published')
       - uploadMedia(page, filePath)
       - addWidget(page, type, position)

   4.3 pagekit-ui.js:
       - waitForVueComponent(page, selector)
       - waitForUIkitModal(page)
       - checkUIkitNotification(page, text, type='success')
       - closeUIkitModals(page)

   4.4 pagekit-system.js:
       - clearCache(page)
       - runMaintenance(page, task)
       - checkSystemStatus(page)
       - installExtension(page, extensionPath)

5. CRITICAL PAGEKIT TEST SCENARIOS:
   5.1 Installation Tests (001-installation.spec.js):
       - Fresh install with MySQL 8.4
       - Fresh install with SQLite 3
       - Installation error handling
       - Database connection validation

   5.2 Authentication Tests (002-authentication.spec.js):
       - Admin login/logout
       - User registration (if enabled)
       - Password reset flow
       - Remember me functionality
       - Permission checks

   5.3 Content Management Tests (003-content.spec.js):
       - Create/Edit/Delete Page (with Vue.js editor)
       - Create/Edit/Delete Blog Post
       - Markdown vs HTML editor switching
       - Media upload (images, files)
       - Draft/Publish workflow

   5.4 Vue.js Component Tests (004-vue-components.spec.js):
       - Dashboard widgets (widget-panel.vue)
       - Editor component (editor.vue)
       - Node/Page management (node-page.vue)
       - Settings panels (Vue.js forms)
       - Reactive data updates

   5.5 UIkit Integration Tests (005-uikit.spec.js):
       - Modal dialogs
       - Dropdown menus
       - Notifications
       - Sortable lists
       - Tab navigation

   5.6 System Tests (006-system.spec.js):
       - Cache operations (PSR-6)
       - Database migrations
       - Extension installation
       - System updates
       - Maintenance mode

6. TEST EXECUTION STRATEGY:
   6.1 Local Development:
       - Run specific test: npx playwright test [test-file]
       - Debug mode: npx playwright test --debug
       - UI mode: npx playwright test --ui

   6.2 CI/CD Preparation:
       - Create .github/workflows/e2e-tests.yml template
       - Docker-in-Docker setup for GitHub Actions
       - Test result artifacts
       - Failure notifications

   6.3 Performance Targets:
       - Single test: < 30 seconds
       - Full suite: < 10 minutes (parallel)
       - Critical path: < 3 minutes

7. SUCCESS CRITERIA & DELIVERABLES:
   ✅ 20+ test scenarios covering all critical paths
   ✅ Multi-browser support (Chrome, Firefox, Safari, Mobile)
   ✅ Complete Docker isolation (zero production impact)
   ✅ Vue.js 2.6 component interactions tested
   ✅ UIkit 3.5 UI patterns validated
   ✅ MySQL 8.4 and SQLite 3 both tested
   ✅ Visual regression tests for admin panel
   ✅ Test execution < 10 minutes (parallel)
   ✅ Documentation complete (setup, writing tests, CI/CD)
   ✅ Helper functions for common Pagekit operations
   ✅ Ready to protect Core updates (Steps 1.11-1.14)

8. DOCUMENTATION REQUIREMENTS:
   - E2E_TESTING_FOUNDATION.md with architecture overview
   - E2E_TEST_CATALOG.md listing all tests with purpose
   - tests/e2e/README.md with quick start guide
   - Helper function documentation
   - Troubleshooting guide
   - Best practices for writing new tests
```

---

### Step 1.11: ORM Layer Modernization

- **Status**: ✅ COMPLETED
- **Branch**: `feature/orm-modernization`
- **Goal**: Modernize and optimize Pagekit ORM
- **Prerequisite**: Step 1.10 completed
- **Tasks**:
  - Optimize EntityManager for PHP 8.2+
  - Extend typed properties in entities
  - Implement improved lazy loading
  - Integrate query result caching with PSR-6

**Detailed Agent Prompt**:

```
TASK: Modernize Pagekit ORM Layer

0. CRITICAL RULE:
   ⚠️ AFTER EVERY SINGLE CHANGE:
   - Test console: php pagekit setup
   - Test web: curl http://localhost:8000 (MUST return 200, not 500!)
   - Test admin: curl http://localhost:8000/admin
   - Test E2E (Playwright):
     * Run fresh installation test: npx playwright test tests/e2e/specs/01-setup/installation.spec.js
     * Run targeted ORM E2E tests (only the ones you add for this change)
     * NEVER run the full E2E suite; only installation + feature-specific tests
   IF ANY TEST FAILS → STOP AND FIX BEFORE CONTINUING!

1. PREPARATION:
   - Create new branch: `feature/orm-modernization` from `develop`
   - Create ORM_MODERNIZATION.md to document changes
   - Ensure PSR-6 cache is working

2. ANALYZE CURRENT ORM:
   - Review app/modules/database/src/ORM/
   - Identify areas for modernization
   - List all entity models in system
   - Document current performance issues

3. MODERNIZE ENTITYMANAGER:
   - Update EntityManager for PHP 8.2+
   - Add better type hints and return types
   - Implement improved lazy loading
   - Add query result caching with PSR-6

4. UPDATE ENTITY MODELS:
   - Add PHP 8 typed properties to all entities
   - Implement proper constructors
   - Add readonly properties where applicable
   - Update getters/setters with proper types

5. IMPLEMENT QUERY CACHING:
   - Integrate PSR-6 cache for query results
   - Add cache invalidation strategies
   - Configure cache TTL per entity type
   - Document caching patterns

6. OPTIMIZE RELATIONS:
   - Improve lazy loading for relations
   - Add eager loading support
   - Optimize N+1 query problems
   - Add relation caching

7. CREATE/UPDATE TESTS:
   - Write unit/integration tests for ORM improvements
   - Add Playwright E2E tests to cover critical user flows affected by ORM:
     * CRUD for pages/posts, lists with relations, admin data views
   - After each major step, run:
     * npx playwright test tests/e2e/specs/01-setup/installation.spec.js
     * Your targeted ORM E2E tests
   - Performance benchmarks
   - Cache invalidation tests
   - Lazy loading tests

8. VALIDATION:
   - Run full unit/integration test suite
   - Run E2E installation test + targeted ORM E2E tests (must be green)
   - Test all CRUD operations
   - Verify performance improvements
   - Check memory usage

9. CREATE PULL REQUEST:
   - Title: "feat: Modernize ORM layer for PHP 8.2+"
   - Include performance comparisons
   - Document all improvements

SUCCESS CRITERIA:
- ORM fully modernized for PHP 8.2+
- Performance improved measurably
- All entities use typed properties
- Query caching working with PSR-6
- All unit/integration and E2E tests passing
```

---

### Step 1.12: Database Migration System

- **Status**: ✅ COMPLETED
- **Branch**: `feature/database-migrations`
- **Goal**: Integrate a professional database migration system
- **Prerequisite**: Step 1.11 completed
- **Options**:
  - Doctrine Migrations (recommended due to DBAL 3.x)

**Detailed Agent Prompt**:

```
TASK: Implement Professional Database Migration System

0. CRITICAL RULE:
   ⚠️ AFTER EVERY SINGLE CHANGE:
   - Test console: php pagekit setup
   - Test web: curl http://localhost:8000 (MUST return 200, not 500!)
   - Test admin: curl http://localhost:8000/admin (MUST return 200, not 500! after redirect to /admin/login)
   IF ANY TEST FAILS → STOP AND FIX BEFORE CONTINUING!

1. PREPARATION:
   - Create new branch: `feature/database-migrations` from `develop`
   - Create DATABASE_MIGRATIONS.md to document system
   - Review current update/install mechanisms

2. EVALUATE MIGRATION TOOLS:
   - Compare Doctrine Migrations vs Phinx
   - Consider integration complexity
   - Choose based on DBAL 3.x compatibility
   - Recommend: Doctrine Migrations

3. IMPLEMENT MIGRATION SYSTEM:
   - Install doctrine/migrations via composer
   - Create migrations configuration
   - Set up migrations directory structure
   - Integrate with Pagekit console

4. CREATE MIGRATION COMMANDS:
   - pagekit:migrate - Run migrations
   - pagekit:migrate:status - Show status
   - pagekit:migrate:generate - Generate new
   - pagekit:migrate:rollback - Rollback

5. CONVERT EXISTING SCHEMA:
   - Create initial migration from current schema
   - Document all existing tables
   - Add version tracking table
   - Test fresh installation

6. UPDATE INSTALLER:
   - Integrate migrations in installer
   - Update app/installer/src/Installer.php
   - Ensure compatibility with existing installs (from future perspective, after Pagekit CMS is modernized, meaning version 2.0.0 = "existing install" → 2.1.0 = "update")
   - Add migration checks

7. CREATE DOCUMENTATION:
   - How to create migrations
   - Migration best practices
   - Rollback procedures
   - Extension migration support

8. CREATE TESTS:
   - Test migration execution
   - Test rollback functionality
   - Test installer integration
   - Test update procedures

9. CREATE PULL REQUEST:
   - Title: "feat: Add database migration system"
   - Include migration examples
   - Document all commands

SUCCESS CRITERIA:
- Migration system fully integrated
- All console commands working
- Installer uses migrations
- Rollback functionality tested
- Complete documentation
```

---

### Step 1.13: Validation System Update

- **Status**: ✅ COMPLETED
- **Branch**: `feature/symfony-validator`
- **Goal**: Integrate Symfony Validator
- **Prerequisite**: Step 1.12 completed
- **Components**:
  - Symfony Validator (Backend)
  - Constraints with Attributes
  - Integration with existing frontend
  - Symfony Forms (optional for Admin)

**Detailed Agent Prompt**:

```
<!-- migration-docs/TODO/agent_prompts/PROMPT_SYMFONY_VALIDATOR_OPTIMIZED.md -->
```

---

### Step 1.13.5: Complete Template Security Modernization

- **Status**: ✅ COMPLETED
- **Branch**: `feature/complete-template-security`
- **Goal**: Bring template security to gold standard (no intermediate steps!)
- **Philosophy**: "Playground Approach" — do it right the first time instead of iterating
- **Tasks**:
  - Remove eval() from PhpEngine (dead code)
  - Data attributes instead of inline scripts (gold standard!)
  - Complete CSP without unsafe-inline/unsafe-eval
  - Modern security headers in .htaccess
  - Security audit & tests

**Priority**: 🔴 HIGH (Security!)

**Detailed Agent Prompt**:

```
TASK: Complete Template Security Modernization (Gold Standard Approach)

=================================================================================
PHILOSOPHY: "Playground" - Doing It Right The First Time
=================================================================================

WHY GOLD STANDARD DIRECTLY (No intermediate steps)?

Context:
- This is version 1.0.x - development/modernization phase
- NOT for production customers yet
- Version 2.0.0 will be the first production-ready release
- We have time to do it right!!!

Decision: Skip intermediate solutions (like nonce), go directly to best practice
- Avoids refactoring twice (nonce now → data-attributes later)
- "Playground" allows experimentation with modern approaches
- Version 2.0 gets perfect security from day one

THIS STEP: Complete security modernization to industry best practices!

=================================================================================
TEMPLATE SYSTEM CLARIFICATION
=================================================================================

Pagekit currently uses:
- ✅ 100% PHP templates (.php files) - NOT Twig!
- ✅ 0 .twig files found (TwigEngineAdapter exists but unused)
- ✅ PHP templates work great for CMS (keep them!)
- ✅ Backend uses PHP + Vue.js (hybrid approach)
- ✅ Twig optional for developers who prefer it

Decision: Keep PHP templates, they're modern and working fine!

=================================================================================
PHASE 1: eval() Dead Code Removal
=================================================================================

1.1. Analyze current eval() usage:
   `bash
   cd c:/Projekte/pagekit
   grep -n "eval(" app/modules/view/src/PhpEngine.php
   grep -n "eval(" app/modules/view/src/Engine/PhpEngine.php
   `
   Expected: Lines 168, 174 in PhpEngine.php + Line 122 in Engine/PhpEngine.php

1.2. Remove eval() from app/modules/view/src/PhpEngine.php:
   File: app/modules/view/src/PhpEngine.php

   Line 164-176 BEFORE:
   `php
   if (file_exists($templatePath)) {
       require $templatePath;
   } else {
       // Treat as string template
       eval('?>' . $templatePath);  // ← REMOVE THIS!
   }
   `

   Line 164-176 AFTER:
   `php
   if (file_exists($templatePath)) {
       require $templatePath;
   } else {
       throw new \RuntimeException(sprintf('Template file not found: %s', $templatePath));
   }
   `

   Do the same for the second occurrence (line ~174)

1.3. Remove eval() from app/modules/view/src/Engine/PhpEngine.php:
   File: app/modules/view/src/Engine/PhpEngine.php

   Line 119-125 BEFORE:
   `php
   if (isset($storage['path']) && file_exists($storage['path'])) {
       require $storage['path'];
   } elseif (isset($storage['content'])) {
       eval('?>' . $storage['content']);  // ← REMOVE THIS!
   }
   `

   Line 119-125 AFTER:
   `php
   if (isset($storage['path']) && file_exists($storage['path'])) {
       require $storage['path'];
   } else {
       throw new \RuntimeException('Invalid storage: path not found or content not supported');
   }
   `

1.4. Test that system still works (NO fresh install!):
   `bash
   # Test existing installation
   curl http://localhost:8000 | grep -i "pagekit"
   # Should return 200 OK with "Pagekit" in HTML

   # Test admin access (check status code)
   curl -I http://localhost:8000/admin
   # Should return 302 (redirect to login) or 200 (if logged in)

   # Test CLI
   php pagekit --version
   # Should output version number

   # If no installation detected
   php pagekit setup
   # Should install the system without a password, for testing.
   `

1.5. Commit changes:
   `bash
   git add app/modules/view/src/PhpEngine.php app/modules/view/src/Engine/PhpEngine.php
   git commit -m "security: remove eval() dead code from template engines"
   `
=================================================================================
PHASE 2: Data-Attributes Implementation (Gold Standard!)
=================================================================================

Goal: Replace inline <script>var $pagekit = {...}</script> with clean data-attributes
Result: NO inline JavaScript at all! Perfect CSP!

2.1. Update DataHelper to use data-attributes:
   File: app/modules/view/src/Helper/DataHelper.php

   Update render() method (line ~71):

   BEFORE:
   `php
   public function render(): string
   {
       $output = '';
       foreach ($this->data as $name => $value) {
           $output .= sprintf("        <script>var %s = %s;</script>\n", $name, json_encode($value, $this->encodingOptions));
       }
       return $output;
   }
   `

   AFTER:
   `php
   public function render(): string
   {
       // Store config in data-attribute on body tag
       // JavaScript will read this later (no inline scripts!)
       $config = [
           'data' => $this->data
       ];

       // Output as data-attribute for JavaScript to consume
       $encoded = htmlspecialchars(json_encode($config, $this->encodingOptions), ENT_QUOTES, 'UTF-8');
       return sprintf("        <script id=\"pagekit-data\" type=\"application/json\" data-config='%s'></script>\n", $encoded);
   }
   `

   Explanation:
   - NO inline JavaScript execution!
   - Config stored in data-attribute
   - JavaScript reads it later from DOM
   - Perfect for strict CSP!

2.2. Create JavaScript config loader:
   File: app/assets/js/config-loader.js (NEW!)

   `javascript
   /**
    * Pagekit Config Loader
    * Reads configuration from data-attributes (no inline scripts!)
    */
   (function() {
       'use strict';

       // Read config from data-attribute
       var configScript = document.getElementById('pagekit-data');
       if (!configScript) {
           console.warn('Pagekit config not found');
           return;
       }

       try {
           var config = JSON.parse(configScript.getAttribute('data-config'));

           // Expose data as global variables (backward compatibility)
           if (config.data) {
               Object.keys(config.data).forEach(function(key) {
                   window[key] = config.data[key];
               });
           }
       } catch (e) {
           console.error('Failed to parse Pagekit config:', e);
       }
   })();
   `

2.3. Register config-loader in View system:
   File: app/modules/view/index.php or app/system/modules/view/index.php

   Find the 'view.scripts' event and add:
   `php
   $scripts->register('pagekit-config', 'app/assets/js/config-loader.js', [], ['defer' => false]);
   `

   Make sure it loads BEFORE other scripts that need the config!

2.4. Update head template to ensure proper order:
   Scripts must load in this order:
   1. pagekit-config.js (reads data-attributes)
   2. other scripts (use the config)

   The View system should handle this automatically via dependency management.

2.5. Test data-attribute implementation:
   `bash
   # Check HTML output
   curl http://localhost:8000 | grep 'data-config'
   # Should show: <script id="pagekit-data" type="application/json" data-config='{"data":{"$pagekit":{...}}}'></script>

   # Verify NO inline scripts (except the JSON data holder)
   curl http://localhost:8000 | grep '<script>' | grep -v 'data-config' | grep -v 'src='
   # Should return NOTHING (all scripts are external or data-attributes)
   `

2.6. Test JavaScript access:
   - Open http://localhost:8000 in browser
   - Open Console (F12)
   - Type: `console.log($pagekit)`
   - Should output config object (backward compatible!)

2.7. Commit changes:
   `bash
   git add app/modules/view/src/Helper/DataHelper.php app/assets/js/config-loader.js app/modules/view/index.php
   git commit -m "security: replace inline scripts with data-attributes (gold standard CSP)"
   `

IMPORTANT: This is the CLEAN solution!
- ✅ NO inline JavaScript execution
- ✅ Perfect CSP without exceptions
- ✅ Backward compatible (global vars still work)
- ✅ Modern best practice

=================================================================================
PHASE 3: Complete Security Headers Modernization
=================================================================================

3.1. Update .htaccess with STRICT CSP (no unsafe-* needed!):
   File: .htaccess (line ~48)

   BEFORE:
   `apache
   Header set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self';"
   `

   AFTER:
   `apache
   # Content Security Policy - STRICT (no unsafe-inline, no unsafe-eval!)
   # Since we use data-attributes (no inline scripts), we can be very strict
   Header set Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self';"
   `

   Changes:
   - ✅ script-src: NO unsafe-inline, NO unsafe-eval (strict!)
   - ⚠️ style-src: keeps 'unsafe-inline' for now (UIkit inline styles, can fix later)
   - ✅ object-src 'none': No Flash/plugins
   - ✅ base-uri 'self': Prevent base tag injection
   - ✅ form-action 'self': Forms only submit to same origin

3.2. Add additional modern security headers:
   File: .htaccess (after CSP section)

   ADD:
   `apache
   # Additional Modern Security Headers

   # Cross-Origin-Embedder-Policy
   Header always set Cross-Origin-Embedder-Policy "require-corp"

   # Cross-Origin-Opener-Policy
   Header always set Cross-Origin-Opener-Policy "same-origin"

   # Cross-Origin-Resource-Policy
   Header always set Cross-Origin-Resource-Policy "same-origin"
   `

3.3. Update existing security headers (review and modernize):
   File: .htaccess (existing headers section ~22-49)

   Review and ensure all are optimal:
   - HSTS: Already good ✅
   - X-Content-Type-Options: Already good ✅
   - X-Frame-Options: Already good ✅
   - Referrer-Policy: Consider changing to "strict-origin-when-cross-origin" (more privacy)
   - Permissions-Policy: Review and add more restrictions if needed

3.4. Test CSP in browser:
   `bash
   # Start browser with developer tools
   # Open http://localhost:8000
   # Open Developer Console (F12) → Console tab
   `

   Check for:
   - ❌ NO CSP violations (all clean!)
   - ✅ All scripts load from external files
   - ✅ $pagekit variable available (from data-attributes)
   - ✅ Vue.js works
   - ✅ Admin interface works

3.5. Test with CSP validator:
   Visit: https://csp-evaluator.withgoogle.com/
   Paste your CSP policy
   Check for:
   - ✅ No unsafe-inline in script-src
   - ✅ No unsafe-eval
   - ✅ Score should be good (maybe warning about style-src, that's okay for now)

3.6. Commit changes:
   `bash
   git add .htaccess
   git commit -m "security: implement strict CSP and modern security headers"
   `

RESULT: Industry-leading security headers!
- ✅ Perfect CSP (no unsafe-* in script-src)
- ✅ Modern Cross-Origin policies
- ✅ HSTS, X-Frame-Options, etc. all optimal
- ✅ Ready for security audit

=================================================================================
PHASE 4: Testing & Documentation
=================================================================================

4.1. Run E2E Tests (with fresh install):
   `bash
   # Reset to clean state (removes config.php and database)
   ./scripts/e2e-reset.sh

   # Start test server
   ./scripts/e2e-start.sh

   # Test fresh installation
   npx playwright test tests/e2e/specs/01-setup/installation.spec.js

   # Test admin functionality
   npx playwright test tests/e2e/specs/02-admin/login.spec.js

   # Stop test server
   ./scripts/e2e-stop.sh
   `

4.2. Manual Testing Checklist:
   - [ ] Admin login works (http://localhost:8000/admin)
   - [ ] Dashboard loads without errors
   - [ ] Vue.js components render correctly
   - [ ] Frontend pages load (http://localhost:8000)
   - [ ] No console errors in browser (F12)
   - [ ] Inline scripts work (check $pagekit variable exists)
   - [ ] CSP violations only for expected/external sources

4.3. Create branch documentation:
   File: migration-docs/branches/feature-template-security-hardening.md

   Document:
   - All changes made
   - Why eval() was removed
   - How data-attributes implementation works (no inline scripts!)
   - Why Twig migration was deferred (Twig/Vue conflict)
   - Test results

4.4. Create PR documentation:
   File: migration-docs/pull-requests/PR_template_security.md

   Brief summary with:
   - Security improvements
   - Breaking changes: NONE!
   - Test status
   - Link to detailed branch docs

4.5. Update CHANGELOG-NEW.md:
   Add entry:
   `markdown
   ## [1.0.X] - [Date]

   ### Security
   - Remove eval() dead code from template engines
   - Implement data-attributes for config (no inline executable scripts!)
   - Harden Content Security Policy (remove unsafe-inline, unsafe-eval from script-src)
   `

=================================================================================
SUCCESS CRITERIA (Gold Standard!)
=================================================================================

MUST HAVE ✅:
- ✅ eval() completely removed from both PhpEngine files
- ✅ Data-attributes implementation (NO inline scripts!)
- ✅ config-loader.js loads config from data-attributes
- ✅ Backward compatibility: $pagekit, $debugbar globals still work
- ✅ Strict CSP without 'unsafe-inline' and 'unsafe-eval' in script-src
- ✅ Modern security headers (COEP, COOP, CORP)
- ✅ All existing functionality works (no breaking changes!)
- ✅ No console errors in browser
- ✅ No CSP violations
- ✅ E2E tests pass (fresh install + admin + frontend)
- ✅ Documentation complete

MUST NOT ❌:
- ❌ NO intermediate solutions (nonce, etc.)
- ❌ NO PHP template format changes
- ❌ NO breaking changes to existing API
- ❌ NO regression in functionality

=================================================================================
IMPORTANT NOTES FOR AGENT
=================================================================================

1. **"Playground" Philosophy (BUT: Must Work!):**
   This version (1.0.x) is for DEVELOPMENT, not production!
   - Version 2.0.0 will be first customer-ready release
   - We have time to do it RIGHT, not just fast
   - Skip intermediate solutions → Go directly to Gold Standard
   - Background Agent makes this feasible (minutes not weeks!)

   ⚠️ CRITICAL: "Playground" means experimentation is allowed,
   BUT system MUST be functional after each step!
   - All existing features must work
   - No broken functionality
   - Thorough testing required before commit

2. **Why Data-Attributes (Gold Standard)?**
   Best practices hierarchy:
   1. Data-attributes (this step!) → ✅ GOLD STANDARD
   2. Nonce-based inline scripts → Good compromise (SKIPPED!)
   3. unsafe-inline → Bad (old state)

   Decision: Skip #2, go directly to #1
   - Avoids refactoring twice
   - Perfect CSP from day one
   - Modern best practice

3. **PHP Templates & <?= Syntax:**
   - Pagekit uses 100% PHP templates (.php files) - NOT Twig!
   - 0 .twig files found (TwigEngineAdapter exists but unused)
   - <?= is MODERN (PHP 5.4+, recommended by PSR-12)
   - Backend uses PHP + Vue.js (hybrid approach)
   - Keep PHP templates - they work great!

4. **Backward Compatibility:**
   Global variables MUST still work:
   - Old: <script>var $pagekit = {...}</script>
   - New: config-loader.js sets window.$pagekit = ...
   - Result: Existing code doesn't break!

5. **Test Commands:**
   `bash
   # Fresh install test:
   ./scripts/e2e-reset.sh  # Clean state first!
   npx playwright test tests/e2e/specs/01-setup/installation.spec.js

   # Existing installation test:
   curl http://localhost:8000 | grep 'data-config'  # Check data-attributes
   curl http://localhost:8000 | grep '<script>' | grep -v 'data-config' | grep -v 'src='  # Should be empty!
   `

6. **CSP Testing:**
   - Browser Console (F12) → Check for CSP violations
   - Should be ZERO violations!
   - Use https://csp-evaluator.withgoogle.com/ to validate policy
   - Target: A+ rating (except style-src, that's okay)

   Note: "Playground" = experimentation allowed, but functionality must be verified!
```

---

### Step 1.14: Doctrine Annotations to PHP 8 Attributes Migration (FINAL STEP!)

- **Status**: ✅ COMPLETED
- **Branch**: `feature/doctrine-annotations-to-attributes-final`
- **Goal**: Migrate from Doctrine Annotations to PHP 8 Attributes
- **Prerequisite**: ⚠️ ALL core updates (1.1 - 1.13.5) MUST be completed!
- **Important**: This is the FINAL step of the core modernization!

**Why deferred?**

- PHP 8 Attributes require modern infrastructure
- PSR-11 Container must be available
- Symfony 6.4 Event/Routing System must be compatible
- Doctrine DBAL 3.x must be compatible
- PSR-6 Cache System must be implemented

**Note**: The Attribute classes and the code are already prepared but must only be activated after the infrastructure updates are complete. The cache migration to PSR-6 was planned as a separate Step 1.10 and has already been completed.

**Detailed Agent Prompt**:

```
TASK: RE-APPLY Doctrine Annotations to PHP 8 Attributes Migration

IMPORTANT: This is a RE-APPLICATION after infrastructure is ready!

0. CRITICAL RULE:
   ⚠️ AFTER EVERY SINGLE CHANGE:
   - Test console: php pagekit setup
   - Test web: curl http://localhost:8000 (MUST return 200, not 500!)
   - Test admin: curl http://localhost:8000/admin
   IF ANY TEST FAILS → STOP AND FIX BEFORE CONTINUING!

1. PREPARATION:
   - Create new branch from `develop`
   - VERIFY all previous steps (1.3.5-1.13.5) are completed
   - Ensure Symfony 6.4, PSR-11 Container, modern Event/Routing systems are working
   - Copy existing Attribute classes from old branch if needed

2. RE-ACTIVATE ATTRIBUTE SYSTEM:
   - The Attribute classes already exist from previous attempt
   - Update loaders to use AttributeLoader instead of AnnotationLoader
   - Update listeners to use Attributes
   - Controllers/Models already have Attributes (just need activation)

3. REMOVE ANNOTATIONS:
   - Remove doctrine/annotations package
   - Remove all old Annotation classes
   - Remove AnnotationRegistry from autoload.php

4. VALIDATION:
   - Test with modern infrastructure (should work now!)
   - Verify PSR-11 Container loads Attribute-based services
   - Verify Symfony 6.4 Event System handles Attribute-based events
   - Verify Symfony 6.4 Routing handles Attribute-based routes

5. CREATE PULL REQUEST:
   - Title: "feat: Complete Attribute Migration with proper infrastructure"
   - Include note about previous attempt and why it works now

SUCCESS CRITERIA:
- System fully functional with Attributes
- No more Annotations in codebase
- doctrine/annotations package removed
- All tests passing
- Complete documentation
```

---
