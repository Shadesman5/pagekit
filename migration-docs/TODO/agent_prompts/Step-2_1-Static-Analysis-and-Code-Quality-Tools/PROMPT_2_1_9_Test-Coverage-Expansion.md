# Step 2.1.9: Test Coverage Expansion

**ROADMAP:** 2.1.9. GitHub Issue: #156. Reference: `@ROADMAP.md`.

---

## CONTEXT

- **Depends on:** Step 2.1.2 (CI/CD with coverage reports)
- **Risk:** Low — ongoing improvement, no breaking changes
- **Nature:** This is an **ongoing** effort, not a one-time step. Coverage grows with each feature branch.
- **Current state:** ~44 test files, coverage percentage TBD (documented after 2.1.2 establishes baseline)

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

## 4. COVERAGE TRACKING

### 4.1. CI integration

Coverage reports are generated in CI (Step 2.1.2). Track trends over time.

### 4.2. Coverage badges (optional)

Add coverage badge to README if CI generates coverage artifacts.

---

## AUDIT FINDINGS (Phase 1 Review)

The following test quality/coverage gaps were identified during the Phase 1 codebase audit:

- **E2E tests (Step 1.10.5):** Most were poorly created, not following best practices; only the first 3 tests are reasonably functional. Full E2E rework needed before relying on them for regression testing.
- **`MigrationServiceTest`:** All tests are currently **skipped** — write real migrate/rollback/status test coverage
- **`MenuApiController`:** Uses manual validation (`trim`, `filter`, `BadRequestHttpException`) instead of `#[Assert\...]` + `ValidatesRequestTrait` — add proper validation + tests
- **`assertEquals` usage:** ~200+ occurrences where `assertSame` (strict comparison) would be more appropriate — migrate incrementally as test files are touched
- **Query cache tests (`QueryBuilderCacheTest`):** Only test key consistency and suffix; do NOT test parameter collision or cache invalidation — add regression tests

---

## SUCCESS CRITERIA

- Coverage increases with each change
- Core modules reach 80%+ coverage
- System modules reach 75%+ coverage
- Package modules reach 60%+ coverage
- Coverage reports in CI
- Edge-case tests for critical scenarios

---

## VALIDATION CHECKLIST

- [ ] Coverage report can be generated locally
- [ ] Coverage baseline documented
- [ ] Auth module: coverage ≥ 80%
- [ ] User module: coverage ≥ 75%
- [ ] Database module: coverage ≥ 80%
- [ ] Coverage trend is positive across feature branches
