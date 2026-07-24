# Step 2.1.8: Infection Mutation Testing

**ROADMAP:** 2.1.8. GitHub Issue: #155. Reference: `@ROADMAP.md`.

---

## CONTEXT

- **Depends on:** Step 2.1.6 (PHPStan Level 8) completed.
- **Risk:** Low (test tooling + tests only, no production changes to satisfy the tool). **Effort:** Medium — a real unit-test baseline for the security core is written here, not just a tool install.
- **Current state:**
  - Infection is **NOT** installed. No mutation-testing config exists.
  - The "~60% coverage" figure in the ROADMAP prerequisite is **NOT yet met** for the target modules — this step establishes it for the security core (see §3). Actual baseline today:
    - `app/modules/auth/`: 2 test files (`AuthTest`, `DatabaseHandlerTest`). **`NativePasswordEncoder` (password hash/verify) has ZERO tests.** `DatabaseHandler::read()` / `destroy()` are untested (only `write()` is covered).
    - `app/system/modules/user/`: 1 test file (`UserAccessTest` — permission evaluator + `hasAccess()`). `UserProvider` (credential validation), `Role`, `AccessListener` / `AuthorizationListener` (access enforcement) are untested.

**Goal:** Install Infection and achieve **80%+ mutation score on the security-critical classes** of the auth + user modules. Where those classes lack tests, **write the tests here** — mutation testing is meaningless without a solid test base, and killing an escaped mutant *is* adding a targeted test.

**Boundary vs. Step 2.1.9 (Test Coverage Expansion):**
- **2.1.8 = depth** on the auth + user **security core** (hashing, credential validation, session handling, permission enforcement).
- **2.1.9 = breadth** across all modules (database, filter, filesystem, site, blog, …) **and the user/auth controllers** (better covered by request/response + E2E). Do NOT try to mutation-test the 7 user controllers here.

---

## 0. SAFETY CHECKS

This step installs a dev tool and writes tests. **No production code is changed to satisfy Infection** (see §4.2 for the one nuance: a mutant exposing a real bug is a finding, not a mutant to ignore).

**Before starting — verify all of these:**

1. **Branch up-to-date with `develop`** (Step 2.1.6 merged).
2. **A coverage driver is available** — Infection **cannot run without Xdebug or PCOV**. Verify:
   ```bash
   php -m | grep -iE 'xdebug|pcov'
   ```
   CI uses Xdebug (`.github/workflows/php-quality.yml` → `coverage: xdebug`). If neither is present locally/in the agent VM, enable one first (`XDEBUG_MODE=coverage` for Xdebug). This is the #1 first-run blocker.
3. **The full PHPUnit suite is green** before adding Infection:
   ```bash
   ./app/vendor/bin/phpunit
   ```

---

## 1. INSTALL INFECTION

```bash
composer require --dev infection/infection
```

- Binary at: `./app/vendor/bin/infection` (non-standard vendor dir — see `AGENTS.md`).
- **PHPUnit 11 compatibility:** this project pins `phpunit ^11.0`. Ensure the resolved Infection version supports PHPUnit 11 (Infection **≥ 0.29**). If Composer resolves an older release, require an explicit `^0.29` (or newer) constraint — an incompatible Infection fails during initialization.

---

## 2. CONFIGURE INFECTION

### 2.1. Create `infection.json.dist`

Scope is narrowed to the **security-critical classes** (Controllers are deferred to 2.1.9):

```json
{
    "$schema": "https://raw.githubusercontent.com/infection/infection/master/resources/schema.json",
    "source": {
        "directories": [
            "app/modules/auth/src",
            "app/system/modules/user/src/Model",
            "app/system/modules/user/src/Auth",
            "app/system/modules/user/src/Event"
        ],
        "excludes": [
            "Tests"
        ]
    },
    "logs": {
        "text": "tmp/infection/infection.log",
        "summary": "tmp/infection/summary.log"
    },
    "tmpDir": "tmp/infection",
    "phpUnit": {
        "configDir": ".",
        "customPath": "app/vendor/bin/phpunit"
    },
    "mutators": {
        "@default": true
    },
    "minMsi": 80,
    "minCoveredMsi": 80
}
```

- `phpUnit.configDir: "."` matches the repo-root `phpunit.xml.dist`.
- Commit **only `infection.json.dist`**. The local `infection.json` copy is gitignored (see checklist).

### 2.2. Scope — security-critical classes ONLY

| Target | Why | Include |
|--------|-----|---------|
| `app/modules/auth/src` (`Auth`, `Handler/DatabaseHandler`, `Encoder/NativePasswordEncoder`) | Login, session persistence, password hashing — security critical | ✅ |
| `app/system/modules/user/src/Model` (`User`, `Role`) | Permission/authorization logic | ✅ |
| `app/system/modules/user/src/Auth` (`UserProvider`) | Credential validation | ✅ |
| `app/system/modules/user/src/Event` (`AccessListener`, `AuthorizationListener`, …) | Access enforcement at request time | ✅ |
| `app/system/modules/user/src/Controller/*` (7 controllers) | Request/response + E2E territory | ❌ → **2.1.9** |
| Views, templates, Markdown | Too slow, not security-critical | ❌ |
| Database/ORM, Blog package | Infrastructure / content, not this step | ❌ → **2.1.9** |

### 2.3. MSI vs. Covered MSI (read before running)

- **MSI (Mutation Score Indicator)** = killed / **all** mutants. Bounded by line coverage: mutants on *uncovered* lines can never be killed, so low coverage caps MSI.
- **Covered MSI** = killed / **covered** mutants. Measures the **quality of the tests you already have**.

Use **Covered MSI** to gauge test quality while iterating, and drive **overall MSI** up to 80% by **writing the missing tests** in §3 — **not** by lowering the gate. If a class genuinely cannot be unit-tested without DB/kernel, flag it for 2.1.9 integration coverage rather than silently dropping the threshold.

---

## 3. ESTABLISH THE TEST BASELINE (write the critical tests)

Mutation testing needs a test base. Because the target classes are under-tested today (see CONTEXT), this step **writes the missing unit tests first**.

### 3.1. Measure the baseline

```bash
# Scoped coverage for the two target modules
./app/vendor/bin/phpunit --coverage-text \
  --coverage-filter app/modules/auth/src \
  --coverage-filter app/system/modules/user/src
```

Record the starting coverage per class (goes into the branch doc later).

### 3.2. High-value targets — ensure tests exist (highest security value first)

| Class | Method(s) | Status today |
|-------|-----------|--------------|
| `Auth\Encoder\NativePasswordEncoder` | `hash()`, `verify()` (incl. salt-rejection branch) | **0 tests** |
| `User\Auth\UserProvider` | `validateCredentials()`, `findByCredentials()`, `find()`, `findByUsername()` | **0 tests** |
| `Auth\Handler\DatabaseHandler` | `read()`, `destroy()` | untested (only `write()` covered) |
| `User\Model\Role` | `hasPermission()`, `addPermission()`, `clearPermissions()`, `isAdministrator()`/`isAnonymous()`/`isAuthenticated()` | **0 tests** |
| `User\Model\User` | `isAuthenticated()`, `isAdministrator()`, `isActive()`, `isBlocked()`, `getStatusText()` | partial (only `hasAccess()`/evaluator) |
| `User\Event\AuthorizationListener` / `AccessListener` | `onAuthorize()`, `onFailure()`, `subscribe()` | **0 tests** |

Write focused unit tests (mocks over DB/kernel where possible — follow the existing `AuthTest` / `UserAccessTest` mocking style). `NativePasswordEncoder` and `UserProvider::validateCredentials()` are the top priority: they gate authentication.

**After every batch of new tests:**
```bash
./app/vendor/bin/phpunit
```
Keep the suite green.

---

## 4. RUN INFECTION & KILL MUTANTS

```bash
XDEBUG_MODE=coverage ./app/vendor/bin/infection --threads=4
```

(Omit `XDEBUG_MODE` if PCOV is the active driver.)

### 4.1. Analyze results

- **MSI** and **Covered MSI** — target 80%+ on the configured classes.
- **Escaped mutants** — each one is a candidate for a missing/weak test case.

### 4.2. Handle escaped mutants (in priority order)

For each escaped mutant:
1. Understand the mutation (e.g. `===` → `!==`, `&&` → `||`, boundary flip).
2. **Default action — add/strengthen a test** that catches it, then re-run Infection to confirm the kill.
3. **If the mutant reveals a genuine bug or dead code** → that is a real finding. Fix it (a legitimate production change and a *win* for the step) **or** flag it via the `github-issue-creator` skill if out of scope. Do **not** hide it.
4. **If the mutant is a true equivalent mutant** (behaviourally identical, un-killable) → mark it with an Infection `@` ignore / config `ignore` entry with a one-line justification. Do not chase an impossible 100%.

**⚠️ Do NOT change production code merely to make a mutant go away.** Only add tests, fix real bugs, or ignore documented equivalents.

---

## 5. CI INTEGRATION — DEFERRED TO STEP 2.2

Mutation testing is slow and is **not** wired into CI in this step. It is tracked for **Step 2.2 (CI/CD Pipeline)** as a **non-blocking, scheduled/manual** job (same rationale as the weekly cross-browser E2E job — catch regressions without blocking every PR). A tracking note is added to the Step 2.2 entry in `PHASE_2_MODERNISING.md`.

Do **not** add an Infection job to `.github/workflows/php-quality.yml` here.

---

## SUCCESS CRITERIA

- Infection installed (PHPUnit-11-compatible version) and `infection.json.dist` committed.
- Coverage driver documented as a prerequisite; suite green throughout.
- Missing unit tests written for the high-value security targets (§3.2).
- **80%+ MSI and 80%+ Covered MSI** on the configured security-critical classes.
- No production code changed to satisfy the tool (real bugs found → fixed/flagged; equivalent mutants → documented ignores).
- All PHPUnit tests still pass.

---

## VALIDATION CHECKLIST

- [ ] Coverage driver (Xdebug/PCOV) verified before running Infection
- [ ] `infection/infection` (≥ 0.29, PHPUnit 11 compatible) in `composer.json` (require-dev)
- [ ] `infection.json.dist` scoped to security-critical classes (auth src + user Model/Auth/Event; **no controllers**)
- [ ] `infection.json` added to `.gitignore` (only `.dist` committed)
- [ ] Baseline coverage of the target classes measured and recorded
- [ ] Tests added for `NativePasswordEncoder`, `UserProvider`, `DatabaseHandler::read/destroy`, `Role`, `User` status flags, authorization listeners
- [ ] `./app/vendor/bin/infection` runs successfully
- [ ] MSI ≥ 80% and Covered MSI ≥ 80% for the configured classes
- [ ] Any real bugs found by mutants are fixed or flagged (issue); equivalent mutants documented
- [ ] CI integration NOT added here (tracked in Step 2.2)
- [ ] All PHPUnit tests pass
