# PSR-11 Container Vollmodernisierung – Closure & No Mercy Audit

**ROADMAP:** 2.0.1 (Parent Issue #145). Reference: `@ROADMAP.md`.

---

## PURPOSE

All 5 sub-steps of the PSR-11 Container Vollmodernisierung (2.0.1a–e) have been implemented and merged. This task performs the **final closure verification and No Mercy Audit** for the parent Issue #145.

**This is NOT an implementation task.** No new features are added. This task:
1. Verifies every acceptance criterion from Issue #145 is satisfied in the current `develop` codebase
2. Performs a full "No Mercy" audit (ROADMAP Rule 5: Mandatory Flagging & Audit Debt)
3. Identifies and fixes any remaining audit debt (stale TODOs, leftover patterns, missing docs)
4. Updates the ROADMAP tracking table to reflect completion + audit status
5. Posts audit results as comment on Issue #145 (do NOT close — user closes manually)
6. Runs E2E Playwright tests as final integration validation

---

## CONTEXT

**Completed Sub-Steps (all merged into `develop`):**

| Sub-Step | Issue | PR   | Merged     | Scope |
|----------|-------|------|------------|-------|
| 2.0.1a   | #162  | #161 | 2026-02-23 | Container Core + Core Modules (Stage 1+2) |
| 2.0.1b   | #163  | #167 | 2026-02-27 | DI Infrastructure (ControllerResolver) |
| 2.0.1c   | #164  | #169 | 2026-03-18 | System/Installer/Console + DI Migration |
| 2.0.1d   | #165  | #171 | 2026-03-19 | Packages + ArrayAccess Removal |
| 2.0.1e   | #166  | #172 | 2026-03-26 | StaticTrait Removal + DI Final |

**Parent Issue #145 Acceptance Criteria (from GitHub):**

- [ ] Container implements PSR-11 natively (get, has, set)
- [ ] No ArrayAccess on Container
- [ ] No StaticTrait, EventTrait, RouterTrait
- [ ] Zero `App::` static calls
- [ ] Zero magic methods (__call, __callStatic)
- [ ] All controllers use constructor injection
- [ ] All listeners use constructor injection
- [ ] Models use repository pattern
- [ ] Extension migration guide published
- [ ] All tests pass

---

## 0. SAFETY CHECKS (CRITICAL)

**Test environment:** From workspace root. Console: `php pagekit`. PHPUnit: `./app/vendor/bin/phpunit`. For curl/Playwright: start app first with `php -S localhost:8080 index.php`.

**Before starting:** Ensure you are on `develop` with all 5 PRs merged.

```bash
git checkout develop
git pull origin develop
git log --oneline -10
```

---

## 1. ACCEPTANCE CRITERIA VERIFICATION

Verify each criterion from Issue #145 against the **live codebase**. For each criterion, run the specified checks and document PASS/FAIL with evidence.

### 1.1. Container implements PSR-11 natively (get, has, set)

```bash
# Verify Container class implements ContainerInterface
rg 'implements.*ContainerInterface' app/modules/application/src/Container.php
# Verify get(), has(), set() are defined as public methods (not via trait/magic)
rg 'public function (get|has|set)\(' app/modules/application/src/Container.php
```

### 1.2. No ArrayAccess on Container

```bash
# Verify Container does NOT implement ArrayAccess
rg 'ArrayAccess' app/modules/application/src/Container.php
# Verify no bracket-access patterns remain
rg '\$app\[' app/ packages/ --type php
rg '\$this\[' app/modules/application/ --type php
```

### 1.3. No StaticTrait, EventTrait, RouterTrait

```bash
# Trait files must NOT exist
ls app/modules/application/src/Application/Traits/ 2>/dev/null
# Verify Application class does NOT use these traits
rg 'use (StaticTrait|EventTrait|RouterTrait)' app/modules/application/src/Application.php
```

### 1.4. Zero `App::` static calls

```bash
# Find ALL App:: usages (exclude use/namespace/class declarations)
rg 'App::' app/ packages/ --type php -n | grep -v '^\s*use ' | grep -v '\\\\App' | grep -v 'class App ' | grep -v '@var' | grep -v '// ' | grep -v '\*'
# Expected: ZERO results (or only harmless references like docblocks/comments)
```

### 1.5. Zero magic methods (__call, __callStatic)

```bash
rg '__call\(' app/modules/application/src/Container.php
rg '__callStatic\(' app/modules/application/src/ --type php
rg 'static::\$instance' app/modules/application/src/Container.php
```

### 1.6. All controllers use constructor injection

```bash
# List all controller classes and check for constructor DI
rg 'class \w+Controller' app/system/ packages/ --type php -l
# Verify NO App:: or App::getInstance() in controllers
rg '(App::|App::getInstance)' app/system/modules/*/src/Controller/*.php packages/*/src/Controller/*.php --type php -n 2>/dev/null
```

### 1.7. All listeners use constructor injection

```bash
# List all listener classes
rg 'class \w+Listener' app/system/ packages/ --type php -l
# Verify NO App:: or App::getInstance() in listeners
rg '(App::|App::getInstance)' app/system/modules/*/src/Event/*.php packages/*/src/Event/*.php --type php -n 2>/dev/null
```

### 1.8. Models use repository pattern

```bash
# Verify NO App:: or App::getInstance() in model files
rg '(App::|App::getInstance)' app/system/modules/*/src/Model/*.php packages/*/src/Model/*.php --type php -n 2>/dev/null
# Verify repository classes exist where needed
rg 'class \w+Repository' app/ packages/ --type php -l
```

### 1.9. Extension migration guide published

```bash
# Verify migration docs exist
ls migration-docs/branches/PSR-11-Container/
# Verify key documents:
# - PSR11_CONTAINER_FULL_MODERNIZATION.md (overall summary)
# - PSR11_CONTAINER_STATICTRAIT_REMOVAL.md (final sub-step docs)
# - Extension migration guide content
```

### 1.10. All unit tests pass

```bash
./app/vendor/bin/phpunit
php pagekit list
```

**Note:** E2E Playwright tests run as the final step (Section 7) after all audit fixes and commits are done.

---

## 2. NO MERCY AUDIT (ROADMAP Rules 1–5)

The audit checks for violations of the 5 Aggressive Rules across the ENTIRE scope of 2.0.1.

### 2.1. Rule 1: No Compatibility Layers

```bash
# Search for shim, compat, legacy, bridge, wrapper patterns
rg -i '(shim|compat|legacy|wrapper|bridge)' app/modules/application/src/ --type php -n
# Check if Psr11Adapter still exists
ls app/modules/application/src/Psr11Adapter.php 2>/dev/null
```

### 2.2. Rule 2: No Adapters

```bash
# Search for adapter classes in container/application module
rg 'class.*Adapter' app/modules/application/src/ --type php -n
rg 'class.*Adapter' app/modules/kernel/src/ --type php -n
```

### 2.3. Rule 3: Breaking Changes + System Functional

```bash
# All tests must pass (see 1.10)
# Verify no old API patterns coexist with new
rg 'offsetGet|offsetSet|offsetExists|offsetUnset' app/modules/application/src/Container.php
```

### 2.4. Rule 4: Delete Over Wrap

```bash
# Verify deleted files are actually gone
ls app/modules/application/src/Application/Traits/StaticTrait.php 2>/dev/null
ls app/modules/application/src/Application/Traits/EventTrait.php 2>/dev/null
ls app/modules/application/src/Application/Traits/RouterTrait.php 2>/dev/null
ls app/modules/application/src/Psr11Adapter.php 2>/dev/null
```

### 2.5. Rule 5: Mandatory Flagging & Audit Debt

```bash
# Find ALL TODO markers related to PSR-11 / container / DI
rg 'TODO.*(?i)(container|psr-11|static|trait|magic|__call|ArrayAccess|DI|inject)' app/ packages/ --type php -n
# Find TEMPORARY BRIDGE markers (should be ZERO after 2.0.1e)
rg 'TEMPORARY BRIDGE' app/ packages/ --type php -n
# Find markers referencing 2.0.1 sub-steps
rg 'Step 2\.0\.1' app/ packages/ --type php -n
# Find any stale TODO markers pointing to completed steps
rg 'TODO.*2\.0\.1[a-e]' app/ packages/ --type php -n
```

---

## 3. AUDIT DEBT RESOLUTION

For each finding from Step 2, categorize and resolve:

### 3.1. Stale TODO Markers

If any TODO markers reference completed steps (2.0.1a–e), they are **stale audit debt**:
- If the referenced work was actually done → **delete the TODO marker**
- If the referenced work was NOT done → **flag as regression and fix**

### 3.2. Remaining TEMPORARY BRIDGE Patterns

Any `TEMPORARY BRIDGE` marker still present is a **blocker**. These must be resolved:
- Identify what the bridge was for
- Replace with the proper modern pattern
- Delete the marker

### 3.3. Leftover Legacy Patterns

Any remaining `App::`, `__call`, `ArrayAccess`, or `StaticTrait` usage is a **regression**:
- Fix the pattern using constructor DI or explicit `$app->get()` calls
- Verify tests pass after the fix

### 3.4. Missing Documentation

If the extension migration guide or final summary docs are incomplete:
- Complete `migration-docs/branches/PSR-11-Container/PSR11_CONTAINER_FULL_MODERNIZATION.md`
- Ensure it covers: architecture changes, breaking changes for extensions, before/after examples

---

## 4. ROADMAP UPDATE

After all verifications pass and audit debt is resolved, update `.cursor/ROADMAP.md`:

### 4.1. Parent Step 2.0.1

Change the parent step row:

```
| 2.0.1  | ↳ PSR-11 Container Vollmodernisierung | ✅     | 🛡️    | #145  | #174 | Audit Passed |
```

### 4.2. Sub-Steps 2.0.1a–e

Update audit column from ⏳ to 🛡️ for all sub-steps:

```
| 2.0.1a | ↳ Container Core + Modules (S1+S2)    | ✅     | 🛡️    | #162  | #161    | Audit Passed     |
| 2.0.1b | ↳ DI Infrastructure                   | ✅     | 🛡️    | #163  | #167    | Audit Passed     |
| 2.0.1c | ↳ System/Installer/Console + DI       | ✅     | 🛡️    | #164  | #169    | Audit Passed     |
| 2.0.1d | ↳ Packages + ArrayAccess Removal      | ✅     | 🛡️    | #165  | #171    | Audit Passed     |
| 2.0.1e | ↳ StaticTrait Removal + DI Final      | ✅     | 🛡️    | #166  | #172    | Audit Passed     |
```

### 4.3. Next Step Pointer

Update the "Responsibility" column of the next step (2.0.2) to `**Current Step**` if appropriate.

### 4.4. Audit Column Rules

- 🛡️ = ALL checks from Step 1 + Step 2 PASS with zero findings
- ⚠️ = Findings exist that were documented but deferred (with proper TODO markers pointing to future steps)
- If audit finds issues that CANNOT be resolved in this task, mark as ⚠️ and document the specific findings

---

## 5. ISSUE COMMENT (do NOT close)

### 5.1. Post audit results as comment on Issue #145

**⚠️ Do NOT close the issue.** The user will close it manually after reviewing the audit results.

Add a comment to Issue #145 with the audit results:

```bash
gh issue comment 145 --body "$(cat <<'EOF'
## ✅ PSR-11 Container Vollmodernisierung — Closure Audit Complete

### Acceptance Criteria
- [x] Container implements PSR-11 natively (get, has, set)
- [x] No ArrayAccess on Container
- [x] No StaticTrait, EventTrait, RouterTrait
- [x] Zero `App::` static calls
- [x] Zero magic methods (__call, __callStatic)
- [x] All controllers use constructor injection
- [x] All listeners use constructor injection
- [x] Models use repository pattern
- [x] Extension migration guide published
- [x] All tests pass (PHPUnit + E2E Playwright)

### No Mercy Audit
- Rule 1 (No Compat Layers): ✅
- Rule 2 (No Adapters): ✅
- Rule 3 (Breaking OK + Functional): ✅
- Rule 4 (Delete Over Wrap): ✅
- Rule 5 (Flagging & Audit Debt): ✅

### Sub-Steps
| Sub-Step | PR | Audit |
|----------|----|-------|
| 2.0.1a | #161 | 🛡️ |
| 2.0.1b | #167 | 🛡️ |
| 2.0.1c | #169 | 🛡️ |
| 2.0.1d | #171 | 🛡️ |
| 2.0.1e | #172 | 🛡️ |

All criteria met. Ready for user to close.
EOF
)"
```

(Adjust checkmarks based on actual findings. If any criterion FAILS, do NOT close — document the failure and escalate to the user.)

## 6. COMMIT & PUSH

If any code changes were made during audit debt resolution (Step 3):

```bash
# Commit audit fixes
git add -A
git commit -m "audit(container): resolve PSR-11 audit debt (ROADMAP 2.0.1)"

# Commit ROADMAP update
git add .cursor/ROADMAP.md
git commit -m "docs(roadmap): mark PSR-11 Container 2.0.1 as audit passed"
```

If NO code changes were needed (clean audit):

```bash
# Only ROADMAP update
git add .cursor/ROADMAP.md
git commit -m "docs(roadmap): mark PSR-11 Container 2.0.1 as audit passed"
```

---

## 7. E2E PLAYWRIGHT TESTS (FINAL STEP)

**This is the very last step — run AFTER all audit fixes are committed and pushed.**

E2E tests validate the full integration: installation, authentication, and dashboard functionality. These tests start from a clean state and exercise the real application stack.

### 7.1. Start the Pagekit development server

```bash
php -S localhost:8080 index.php
```

Wait for the server to be ready (check with `curl -s -o /dev/null -w "%{http_code}" http://localhost:8080`).

### 7.2. Run the 3 E2E test suites

Run each suite sequentially. ALL must pass:

```bash
npx playwright test tests/e2e/specs/01-setup/installation.spec.js
npx playwright test tests/e2e/specs/02-core/authentication.spec.js
npx playwright test tests/e2e/specs/02-core/dashboard.spec.js
```

### 7.3. Stop the server

```bash
# Kill the PHP dev server process after tests complete
kill %1 2>/dev/null || true
```

### 7.4. On E2E failure

If any E2E test fails:
- Capture the failure output (screenshot, error message)
- Investigate whether the failure is caused by PSR-11 migration changes or a pre-existing issue
- If caused by migration: fix the issue, re-run PHPUnit, commit the fix, re-run E2E
- If pre-existing: document as known issue in the audit comment but do NOT block the audit

**⚠️ IMPORTANT:** Do NOT use `php pagekit setup` as a substitute for E2E tests. The setup command installs a near-empty instance and does not validate real application behavior. The Playwright tests exercise the full user-facing stack.

---

## SUCCESS CRITERIA

- ALL 10 acceptance criteria from Issue #145 verified with evidence
- No Mercy Audit (5 rules) passed with zero unresolved findings
- Stale TODO markers cleaned up
- Zero `TEMPORARY BRIDGE` markers remain
- ROADMAP updated: 2.0.1 + all sub-steps show ✅ + 🛡️
- Issue #145 comment posted on GitHub with audit results (issue NOT closed — user closes manually)
- All PHPUnit tests pass
- All 3 E2E Playwright tests pass (installation, authentication, dashboard)
- `php pagekit list` works

---

## VALIDATION CHECKLIST (Final)

- [ ] On `develop` branch with all 5 PRs merged
- [ ] Acceptance criterion 1.1–1.10: ALL PASS
- [ ] Audit rule 2.1–2.5: ALL PASS
- [ ] Stale TODOs removed or updated
- [ ] TEMPORARY BRIDGE markers: ZERO
- [ ] ROADMAP.md updated (2.0.1 = ✅ 🛡️ Audit Passed)
- [ ] Issue #145 comment posted with audit results
- [ ] PHPUnit tests green
- [ ] E2E Playwright tests green (installation, authentication, dashboard)
- [ ] Changes committed and pushed
