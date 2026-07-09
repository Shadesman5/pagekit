# Step 2.1.9: Test Coverage Expansion

<!-- conductor-mode: full -->

**ROADMAP:** 2.1.9. GitHub Issue: #156. Reference: `@ROADMAP.md`.

---

## CONTEXT

- **Depends on:** Step 2.1.2 (CI/CD with coverage reports)
- **Risk:** Low — ongoing improvement, no breaking changes
- **Nature:** This is an **ongoing** effort, not a one-time step. Coverage grows with each feature branch.
- **Current state:** ~44 test files. Step 2.1.2 established the coverage-reporting pipeline (CI `phpunit` job → `--coverage-text --coverage-clover`). **The Architect regenerates the current coverage baseline at ticket-planning time** — do NOT hardcode a stale figure. Issue #156 records a historical anchor (30 Apr 2026: ~2.29 % lines) for orientation only; the binding baseline is whatever the latest `--coverage-text` / CI run reports when the ticket is planned, and the CI threshold gate (§4.2) is pinned to that fresh number.

**Goal:** Systematically increase test coverage toward target levels across the codebase.

---

## 0. IMPORTANT — THIS IS NOT A ONE-SHOT TASK

Unlike other steps, 2.1.9 has **no dedicated branch**. Tests are added in every feature branch. This prompt defines the **strategy and targets**, not a single implementation task.

When invoking this prompt via the Orchestrator, the Architect should:
1. Identify the current coverage gaps (per module)
2. Create a prioritized list of modules needing tests
3. The Refactorer writes tests for the highest-priority gaps

---

## 1. COVERAGE TARGETS

| Area | Target | Priority |
|------|--------|----------|
| `app/modules/auth/` | 80%+ | High (security) |
| `app/modules/database/` | 80%+ | High (core) |
| `app/modules/filter/` | 80%+ | Medium |
| `app/modules/filesystem/` | 80%+ | Medium |
| `app/system/modules/user/` | 75%+ | High (security) |
| `app/system/modules/site/` | 75%+ | Medium |
| `app/system/modules/widget/` | 75%+ | Low |
| `app/system/modules/intl/` | 75%+ | Medium |
| `packages/pagekit/blog/` | 60%+ | Low |
| `packages/pagekit/theme-one/` | 30%+ | Low (mostly views) |

---

## 2. DISCOVERY — FIND COVERAGE GAPS

### 2.1. Generate coverage report

```bash
./app/vendor/bin/phpunit --coverage-html tmp/coverage-report --coverage-text
```

### 2.2. Identify untested modules

```bash
# List all src directories that have NO corresponding Tests directory
find app/modules/*/src app/system/modules/*/src -type d -name src | while read dir; do
    test_dir="${dir/src/src/Tests}"
    if [ ! -d "$test_dir" ]; then
        echo "NO TESTS: $dir"
    fi
done
```

### 2.3. Identify untested classes

From the coverage report, find classes with 0% coverage that are important.

---

## 3. TEST WRITING STRATEGY

### 3.1. Prioritize by risk

1. **Security-critical code** (auth, user, permissions) — test first
2. **Data-integrity code** (database, ORM, migrations) — test second
3. **Business logic** (site, blog, widgets) — test third
4. **Infrastructure** (routing, view, config) — test if time permits
5. **Views/templates** — lowest priority, hardest to test

### 3.2. Test patterns

**Unit tests** for:
- Service classes with business logic
- Validators and constraints
- Model methods (pure logic, no DB)
- Utility/helper classes

**Integration tests** for:
- Repository classes (need DB)
- API controllers (need request/response cycle)
- Module boot/registration

### 3.3. Edge-case scenarios

Include tests for real Pagekit scenarios:
- Large file uploads (Storage module)
- Concurrent admin actions (session handling)
- Database connection failures (ORM error handling)
- Invalid input (XSS attempts, SQL injection attempts)
- Permission boundary tests (user vs admin vs anonymous)
- **AddRelNofollowFilter XSS hardening** (from 2.1.1 review): 3 deactivated tests in `app/modules/filter/src/Tests/AddRelNofollowTest.php` cover obfuscation attacks (`<a/href=...>`, null-byte `<\0a\0>`, `rel="follow"` replacement). The filter regex needs hardening before these can pass.

---

## 3.4. SPECIFIC CARRIED-OVER TASKS (ROADMAP Phase 2 + audits)

Concrete items tracked for 2.1.9. **Verify current state before acting** — the Architect confirms each at ticket-planning time, since some may already be resolved (this prompt predates several fixes).

### Test hygiene / decoupling

- **Decouple core tests from extension config** — `RouterTest::testCacheKeyReflectsRouteAffectingOptions()` (`app/modules/routing/src/Tests/RouterTest.php`) uses the extension-specific `blog.permalink` option. `permalink` is defined by the blog package, not core. Replace it with a generic option name (e.g. `test.route_option`); the behaviour under test (route-affecting options must participate in the router cache key) is generic core logic. Principle: core tests must never depend on extension-specific config.

### PHP 8.x deprecation hygiene

- **`setAccessible()` forward-compat cleanup** — `ReflectionMethod/Property::setAccessible()` is a no-op since PHP 8.1 and `#[\Deprecated]` since PHP 8.5 (removal targeted for PHP 9). Delete all calls (safe on PHP 8.2+, no behaviour change). Locate with `rg 'setAccessible' app/`. Known sites: `RouterTest.php`, `QueryBuilderCacheTest.php`, `MessageTest.php` (tests) + production `Message.php`, `ExceptionListener.php`.
- **`StreamWrapper::$context` dynamic-property deprecation (Tech-Debt audit 2026-07-07, TD-16)** — PHP 8.2 emits *"Creation of dynamic property Pagekit\Filesystem\StreamWrapper::$context is deprecated"* 4× per test run (the single real source of the "4 PHP deprecations" suite baseline). Fix: declare `public $context;` (untyped on purpose — PHP may assign `null` or a stream-context resource) on `app/modules/filesystem/src/StreamWrapper.php`.

### Migration integration coverage (audit Step 2.0.4)

- **`PackageManager::enable()` / `uninstall()`** — no integration tests for auto-migrate on enable, auto-rollback on uninstall, or partial rollback to the pre-migration version (unit-level `MigrationService` methods are already covered via PR #189).
- **`MigrationCommand` CLI flow** — no integration test for the unified Doctrine-migrations + scripts pipeline with the version-bump guard.

---

## 4. COVERAGE TRACKING

### 4.1. CI integration

Coverage reports are generated in CI (Step 2.1.2 — `phpunit` job runs `--coverage-text --coverage-clover=coverage.xml`, uploaded as the `coverage-clover` artifact). Track trends over time.

### 4.2. Minimum-coverage threshold gate

Pin a minimum line-coverage threshold in CI and **fail PRs that drop below the documented baseline**. Start the threshold at the Architect-measured baseline (see CONTEXT) and ratchet it upward as coverage grows — never below.

### 4.3. Codecov / Coveralls integration

Wire Codecov or Coveralls for a README coverage badge **and per-PR coverage-delta comments** (consumes the `coverage.xml` Clover report from 4.1).

---

## AUDIT FINDINGS (Phase 1 Review)

The following test quality/coverage gaps were identified during the Phase 1 codebase audit:

- **E2E tests (Step 1.10.5):** Most were poorly created, not following best practices; only the first 3 tests are reasonably functional. Full E2E rework needed before relying on them for regression testing.
- ~~**`MigrationServiceTest`:** All tests skipped — write real migrate/rollback coverage~~ — **RESOLVED** in PR #189 (Step 2.0.4): 12 real migrate/rollback tests with in-memory SQLite (`tests/Unit/Migration/MigrationServiceTest.php`).
- **`MenuApiController`:** Uses manual validation (`trim`, `filter`, `BadRequestHttpException`, `\Exception`) instead of `#[Assert\...]` + `ValidatesRequestTrait` — add proper validation + tests. **Still open** (`app/system/modules/site/src/Controller/MenuApiController.php`); cross-cutting carryover from the 1.13 audit.
- **`assertEquals` usage:** ~200+ occurrences where `assertSame` (strict comparison) would be more appropriate — migrate incrementally as test files are touched.
- ~~**Query cache tests (`QueryBuilderCacheTest`):** Only test key consistency and suffix; do NOT test parameter collision or cache invalidation~~ — **Cache-key coverage RESOLVED**: the suite now covers non-serializable/collision-prone params (`testGetCacheKeyToleratesNonSerializableParams`), relation-name sensitivity + order-independence, and explicit cache-key discriminators. **Still to plan — data-integrity, NOT optional:** post-mutation cache *invalidation*. `EntityManager::save()` / `delete()` already call `invalidateCache()` → `$cache->clear()` (`app/modules/database/src/ORM/EntityManager.php:179,208,286`), but no test verifies a cached query result is actually evicted after a write. Add a regression test — it doubles as the safety net for the planned **Step 4.3** switch to tag-based invalidation (`TagAwareCacheInterface`).

## AUDIT FINDINGS (Step 2.0.1c Bugbot, PR #169)

- **DI-wiring integration tests:** the Stage-3 static-call → constructor-injection migration touched 25 controllers, 7 listeners, installer controllers, `PackageManager`, and several module classes (`CacheModule`, `DashboardModule`, `IntlModule`, …) without dedicated tests. Add integration tests for: controller constructor-injection resolution via `ControllerResolver`, the module `$app ?? App::getInstance()` fallback, factory-service behaviour (e.g. `finder` returns fresh instances), and `PackageManager` methods with/without container availability.

---

## SUCCESS CRITERIA

- Coverage increases with each change
- Core modules reach 80%+ coverage
- System modules reach 75%+ coverage
- Package modules reach 60%+ coverage
- Coverage reports in CI
- CI fails when line coverage drops below the documented baseline
- Codecov/Coveralls badge in README + per-PR coverage-delta comments
- Edge-case tests for critical scenarios

---

## VALIDATION CHECKLIST

- [ ] Coverage report can be generated locally
- [ ] Baseline (re)generated by the Architect at ticket-planning time
- [ ] Auth module: coverage ≥ 80%
- [ ] User module: coverage ≥ 75%
- [ ] Database module: coverage ≥ 80%
- [ ] Coverage trend is positive across feature branches
- [ ] CI minimum-coverage gate active (fails below baseline)
- [ ] Codecov/Coveralls badge + per-PR delta comments wired
- [ ] `setAccessible()` calls removed and `StreamWrapper::$context` declared (deprecation-clean suite)
- [ ] `RouterTest` decoupled from `blog.permalink`
- [ ] `AddRelNofollowFilter` hardened + 3 edge-case tests enabled
