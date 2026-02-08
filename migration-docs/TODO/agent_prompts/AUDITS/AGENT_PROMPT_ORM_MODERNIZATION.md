## 🤖 Important Instructions for Background Agents

**⚠️ READ 3 TIMES - NO SKIMMING!**

**ALL AGENTS MUST**:

0. **Reading & Language Rules**:

   - READ entire task 3x before starting
   - "MIGRATION" means REPLACE (not compatibility!)
   - ALL code/commits/docs in ENGLISH
   - Communication with user in GERMAN
   - Execute EVERY step - no skipping!

1. **System & Dependency Rules**:

   - All requirements (PHP, Node.js versions, dependencies) are defined in `composer.json` and `package.json`. These files are the single source of truth.
   - Before adding any new dependency, analyze the existing ones to ensure compatibility.
   - After making changes to JS/Vue files, the frontend assets MUST be re-compiled using the appropriate `yarn` script.

2. **Branch Strategy**:

   - Before branching, ALWAYS update `develop`: `git checkout develop && git pull`
   - Always branch from `develop`
   - Use EXACT branch name from task
   - Never commit directly to `develop`

3. **Test-Driven Development (CRITICAL)**:

   - **Initial Verification**: Run `npx playwright test tests/e2e/specs/01-setup/installation.spec.js` + `./app/vendor/bin/phpunit` BEFORE starting work
   - **During Development**: Create debug logs, unit tests, and E2E tests IMMEDIATELY for each new feature
   - **After Each Step**: Run fresh installation (`npx playwright test tests/e2e/specs/01-setup/installation.spec.js`) + your feature tests + PHPUnit
   - **NEVER run all E2E tests** - only installation test for fresh install + your specific feature tests
   - **Analyze first**: Understand test structure before creating tests. Every test file should include `beforeAll()` and `beforeEach()` from tests\e2e\specs\02-core\dashboard.spec.js
   - **Correct PHPUnit path**: Always use `./app/vendor/bin/phpunit` (vendor-dir is custom in Pagekit!)
   - Tests MUST be green before PR file
   - Work in small steps with test verification after each
   - Coverage should not decrease

4. **Pull Request Workflow**:

   - DON'T create PR itself
   - Create PR .md file after completion
   - PR from feature branch → `develop`
   - Brief summary only (link to details)

5. **Documentation (3 Layers)**:

   - CHANGELOG-2025.md: summary
   - migration-docs/branches/[branch].md: ALL details
   - migration-docs/pull-requests/PR\_[branch].md: Essential summary + test status
   - Update README.md if system requirements change

6. **Task Isolation**:

   - Only work on specified tasks
   - No "side-fixes" outside of scope
   - Blockers: Try 3 solutions, then continue with rest

7. **Security (CRITICAL)**:

   - NEVER commit passwords/tokens/API keys
   - Check .gitignore BEFORE every commit
   - Use environment variables for secrets
   - Remove & rotate if exposed

8. **Status Reporting**:
   - ✅ = Complete
   - ⚠️ = Partial (with reason)
   - ❌ = Blocked (with detailed reason)
   - NEVER STOP - finish what's possible!

---

## TASK: ORM Layer Modernization for PHP 8.2+

**Status**: ⏳ Als-nächstes  
**Branch**: `feature/orm-modernization`  
**Ziel**: Pagekit ORM modernisieren und optimieren für PHP 8.2+  
**Voraussetzung**: Schritt 1.10 (PSR-6 Cache) abgeschlossen

**Components**:

- EntityManager für PHP 8.2+ optimieren
- Typed Properties in allen Entities implementieren
- Besseres Lazy Loading implementieren
- Query Result Caching mit PSR-6 integrieren
- Eager Loading Support hinzufügen
- N+1 Query Probleme beheben

---

### 🔧 YOUR WORKFLOW:

#### **STEP 1: CREATE THE PLAN FIRST** ⚠️ MANDATORY

Before you start coding, use the `create_plan` tool to create a structured plan with TODOs.

**⚠️ CRITICAL: VERIFY PLAN ACCURACY**

This task description is based on the current understanding of the system - **YOU must verify it during analysis!**

When creating your plan, include a **Step 2: Analysis & Verification** where you:

- ✓ Check that all mentioned files exist at the specified paths
- ✓ Verify the ORM architecture matches the assumptions below
- ✓ Identify any missing critical components not mentioned here
- ✓ Validate that the proposed approach fits the actual system design
- ✓ Check for dependencies or side effects not covered

**If you discover discrepancies**: Document them and adjust your implementation approach!

---

**Your plan should include these steps (9-10 main tasks):**

##### 1. Environment Setup & Initial Verification

**Goal**: Ensure clean starting point with passing tests

- Update `develop` and create feature branch: `git checkout develop && git pull && git checkout -b feature/orm-modernization`
- Install dependencies: `composer install && yarn install`
- Compile frontend: `yarn compile-js --mode=production`
- **Initial test verification** (MANDATORY):
  - Run E2E installation: `npx playwright test tests/e2e/specs/01-setup/installation.spec.js`
  - Run PHPUnit: `./app/vendor/bin/phpunit`
  - All tests MUST pass before continuing
- Create documentation file: `migration-docs/branches/feature-orm-modernization.md`

##### 2. Analysis Phase & Plan Verification ⚠️ CRITICAL

**Goal**: Understand current ORM structure and VERIFY this task description is accurate

- Review `app/modules/database/src/ORM/` structure:
  - EntityManager.php (main entry point)
  - Metadata.php and MetadataManager.php
  - Annotation/\* (entity mapping annotations)
  - Relation/\* (relationship handling)
  - QueryBuilder.php
- Identify all entity models across system:
  - `app/system/src/*/Model/`
  - `packages/pagekit/*/src/Model/`
- Document current state:
  - Which files lack type hints?
  - What are the actual performance bottlenecks?
  - Where are N+1 query problems?
  - What query caching exists already?
- **VERIFY ASSUMPTIONS**: Check that all files/paths mentioned in this task exist
- **IDENTIFY GAPS**: Note any critical components missing from this task description
- Add all findings to `migration-docs/branches/feature-orm-modernization.md`
- **ADJUST YOUR PLAN** if you found discrepancies!

##### 3. Modernize EntityManager

**Goal**: Update EntityManager for PHP 8.2+ with type safety and caching

**Files to modify**: `app/modules/database/src/ORM/EntityManager.php`

- Add strict types declaration: `declare(strict_types=1);`
- Add proper type hints to all methods (parameters + return types)
- Use union types where needed (PHP 8.0+)
- Implement PSR-6 cache integration for query results:

  ```php
  use Psr\Cache\CacheItemPoolInterface;

  private CacheItemPoolInterface $cache;
  ```

- Add cache configuration (TTL settings per entity type)
- Improve lazy loading mechanism
- Add debug logging for cache hits/misses
- **CREATE UNIT TESTS** for EntityManager changes immediately
- **Test Checkpoint**:
  - Fresh install: `npx playwright test tests/e2e/specs/01-setup/installation.spec.js`
  - PHPUnit: `./app/vendor/bin/phpunit`
  - Smoke test: Load entities in admin panel

##### 4. Update Entity Models with Typed Properties

**Goal**: Add PHP 8 typed properties to all entity models

**Strategy**: Work incrementally, one entity at a time, test after each!

For each entity model:

- Convert properties to typed properties:
  ```php
  // Before: protected $id;
  // After: protected ?int $id = null;
  ```
- Add `readonly` properties where applicable (e.g., timestamps)
- Update constructors with constructor property promotion where beneficial
- Add proper type hints to all getters/setters
- Update PHPDoc blocks
- **Test after EACH entity update**: Run PHPUnit + basic E2E test

**Priority entities** (do these first):

1. User model (`app/system/src/User/Model/User.php`)
2. Page model (`app/system/src/Page/Model/Page.php`)
3. Node model (`app/system/src/Node/Model/Node.php`)
4. Role/Permission models
5. Extension models (Blog, Menucards if exists, etc.)

##### 5. Implement Query Result Caching

**Goal**: Add PSR-6 based query result caching with smart invalidation

**Files to modify**:

- `app/modules/database/src/ORM/QueryBuilder.php`
- `app/modules/database/src/ORM/MetadataManager.php`

**Implementation**:

- Add cache support to QueryBuilder:
  - Method: `->cache(int $ttl)` for query-level caching
  - Automatic cache key generation based on query SQL + parameters
- Implement cache invalidation strategies:
  - On entity save/update/delete → invalidate related caches
  - Manual invalidation via cache tags
- Add cache configuration per entity type (via annotations or config)
- Document caching patterns in `migration-docs/branches/feature-orm-modernization.md`
- **CREATE CACHE TESTS**: Cache hits, invalidation, TTL expiry
- **Test Checkpoint**: Verify caching works without breaking existing functionality

##### 6. Optimize Relations & Add Eager Loading

**Goal**: Improve lazy loading and add eager loading support

**Files to modify**: `app/modules/database/src/ORM/Relation/*.php`

- BelongsTo.php
- HasMany.php
- HasOne.php
- ManyToMany.php

**Implementation**:

- Improve lazy loading in all relation classes
- Add eager loading support:
  ```php
  // Usage example:
  $users = User::query()->with('roles')->get();
  ```
- Implement relation result caching
- Fix N+1 query problems:
  - Detect relation access patterns
  - Auto-batch related queries
- Add debug logging for relation queries
- **CREATE RELATION TESTS**: Lazy loading, eager loading, N+1 prevention
- **Test Checkpoint**: Verify all existing relations still work correctly

##### 7. Comprehensive Testing

**Goal**: Create full test coverage for all ORM improvements

**Unit/Integration Tests** (PHPUnit):

- EntityManager tests (created in step 3)
- Entity model tests with typed properties
- Query caching tests (cache hits, misses, invalidation)
- Cache invalidation tests
- Relation tests (lazy loading, eager loading)
- Performance benchmarks (before/after comparisons)

**E2E Tests** (Playwright):

- Create `tests/e2e/specs/03-orm/orm-operations.spec.js`:
  - CRUD operations for pages/posts
  - List views with relations (pages with user info, roles, etc.)
  - Admin data views (users table, roles table)
  - Cache performance validation
- After each major component, run:
  - `npx playwright test tests/e2e/specs/01-setup/installation.spec.js`
  - Your ORM-specific E2E tests
  - Full PHPUnit suite

##### 8. Final Validation & Performance Testing

**Goal**: Ensure all improvements work and performance is measurably better

**Complete test suite run**:

- PHPUnit: `./app/vendor/bin/phpunit` (ALL tests must pass)
- E2E: Installation test + ORM-specific tests (all green)
- Manual verification:
  - Test console: `php pagekit setup`
  - Test web: `curl http://localhost:8000` (must return 200)
  - Test admin: `curl http://localhost:8000/admin` (must return 200)
  - Login to admin panel, test CRUD operations manually

**Performance validation**:

- Measure query counts (before/after) for common operations
- Measure response times (before/after)
- Verify cache hit ratios (should be >70% for repeated queries)
- Check memory usage
- **Document all metrics** in `migration-docs/branches/feature-orm-modernization.md`

**Code quality**:

- Run linter/static analysis if available
- Verify all files have proper type hints
- Check debug logging is in place and working

##### 9. Documentation & PR Preparation

**Goal**: Create comprehensive documentation

**Update documentation files**:

1. **`migration-docs/branches/feature-orm-modernization.md`**:

   - Complete technical details
   - Before/after code examples
   - Performance metrics (with numbers!)
   - Breaking changes (if any)
   - Any plan adjustments you made during analysis

2. **`CHANGELOG-2025.md`**:

   - Summary of improvements
   - Migration notes for developers

3. **`migration-docs/pull-requests/PR_orm_modernization.md`**:

   - Brief summary
   - Key improvements list
   - Test status (all green ✅)
   - Performance improvements (with metrics)
   - Link to detailed branch documentation

4. **`README.md`** (only if ORM usage patterns changed for developers)

**DO NOT**: Create actual GitHub PR (will be done manually by user)

---

#### **STEP 2: EXECUTE THE PLAN** 🚀

After creating the plan with the `create_plan` tool:

1. **Work through each TODO systematically**
2. **Test after EVERY change** (console + web + tests)
3. **Work incrementally** - one component/entity at a time
4. **Mark TODOs as complete** as you finish them
5. **Keep debug logs visible** for troubleshooting
6. **If ANY test fails** → STOP and FIX before continuing!

---

### 📋 SUCCESS CRITERIA:

✅ EntityManager fully modernized with PHP 8.2+ types and PSR-6 caching  
✅ All entity models use typed properties  
✅ Query result caching working with configurable TTL  
✅ Eager loading support implemented and tested  
✅ N+1 query problems resolved  
✅ Measurable performance improvements (documented with metrics)  
✅ All PHPUnit tests passing  
✅ All E2E tests passing (installation + ORM-specific)  
✅ Debug logging in place for troubleshooting  
✅ Comprehensive documentation completed (3 layers)

---

### ⚠️ CRITICAL TESTING REMINDERS:

- **NEVER run all E2E tests** - only installation + ORM-specific tests
- **Use correct PHPUnit path**: `./app/vendor/bin/phpunit` (NOT `vendor/bin/phpunit`!)
- **Test after EVERY change**: console + web + test suite
- **Work incrementally**: One entity/component at a time
- **Keep debug logs visible**: Never delete them until user says so
- **If tests fail**: STOP and FIX before continuing
- **Verify plan accuracy**: During analysis phase, check all assumptions!

---

**START BY CREATING THE PLAN, THEN EXECUTE IT!** 🎯
