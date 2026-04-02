# Step 2.1.8: Infection Mutation Testing

**ROADMAP:** 2.1.8. GitHub Issue: #155. Reference: `@ROADMAP.md`.

---

## CONTEXT

- **Depends on:** Step 2.1.6 (PHPStan Level 8) + minimum ~60% test coverage on target modules
- **Risk:** Low — test tooling only, no production code changes
- **Current state:** Infection is NOT installed. No mutation testing config exists.

**Goal:** Install Infection and achieve 80%+ mutation score for security-critical modules (auth, user management).

---

## 0. SAFETY CHECKS

This step installs a dev tool and runs mutation tests. No production code changes.

**Before starting:** Verify: Branch is Up-to-Date with `develop`. (Steps 2.1.6 + sufficient coverage merged).

---

## 1. INSTALL INFECTION

```bash
composer require --dev infection/infection
```

Binary at: `./app/vendor/bin/infection`

---

## 2. CONFIGURE INFECTION

### 2.1. Create `infection.json.dist`

```json
{
    "$schema": "https://raw.githubusercontent.com/infection/infection/master/resources/schema.json",
    "source": {
        "directories": [
            "app/modules/auth/src",
            "app/system/modules/user/src"
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

### 2.2. Scope — critical modules ONLY

| Module | Why | Include |
|--------|-----|---------|
| `app/modules/auth/` | Authentication logic — security critical | ✅ |
| `app/system/modules/user/` | User management, roles, permissions | ✅ |
| Views, templates, Markdown | Too slow, not security-critical | ❌ |
| Database/ORM | Infrastructure, not business logic | ❌ |
| Blog package | Content, not security-critical | ❌ |

---

## 3. RUN INFECTION

```bash
./app/vendor/bin/infection --threads=4
```

### 3.1. Analyze results

- **MSI (Mutation Score Indicator):** Target 80%+
- **Covered MSI:** Target 80%+
- **Escaped mutants:** Each escaped mutant = a potential missing test case

### 3.2. Fix escaped mutants

For each escaped mutant:
1. Understand what the mutation does (e.g. "changed `===` to `!==`")
2. Write a test that catches this mutation
3. Re-run Infection to confirm the mutant is now killed

**⚠️ Do NOT change production code to kill mutants.** Only add or improve tests.

---

## 4. CI INTEGRATION (OPTIONAL)

If Infection should run in CI:

```yaml
# In .github/workflows/php-quality.yml (optional job)
- name: Mutation Testing
  run: ./app/vendor/bin/infection --threads=4 --min-msi=80
```

This is optional for Step 2.1.8 — can be added later.

---

## SUCCESS CRITERIA

- Infection installed and configured
- 80%+ MSI for `app/modules/auth/` and `app/system/modules/user/`
- `infection.json.dist` committed
- No production code changes (only test improvements)
- All PHPUnit tests still pass

---

## VALIDATION CHECKLIST

- [ ] `infection/infection` in `composer.json` (require-dev)
- [ ] `infection.json.dist` configured for auth + user modules
- [ ] `./app/vendor/bin/infection` runs successfully
- [ ] MSI ≥ 80% for target modules
- [ ] Covered MSI ≥ 80%
- [ ] All PHPUnit tests pass
