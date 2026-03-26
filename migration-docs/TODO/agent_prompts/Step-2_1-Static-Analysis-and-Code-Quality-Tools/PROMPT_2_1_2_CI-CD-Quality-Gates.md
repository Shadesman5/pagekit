# Step 2.1.2: CI/CD Integration & Quality Gates

**ROADMAP:** 2.1.2. GitHub Issue: #149. Reference: `@ROADMAP.md`.

---

## CONTEXT

- **Depends on:** Step 2.1.1 (Tooling-Setup — PHPStan, CS-Fixer, security advisories installed)
- **Risk:** Low — CI configuration only, no code changes
- **Current state:**
  - `.github/workflows/` exists with 3 automation workflows (issue-cleanup, sync-metadata, auto-add-to-project)
  - No PHP quality workflows (no PHPUnit, PHPStan, CS-Fixer, or security audit in CI)

**Goal:** Add GitHub Actions workflows so every PR is automatically checked. Establish quality gates.

---

## 0. SAFETY CHECKS

This step only creates workflow YAML files. No application code changes. Verify workflows work by pushing the branch and checking GitHub Actions output.

**Before starting:** Branch from `develop` (Step 2.1.1 merged).

---

## 1. PHP QUALITY WORKFLOW

### 1.1. Create `.github/workflows/php-quality.yml`

Single workflow with multiple jobs, triggered on `push` and `pull_request` to `main` and `develop`:

**Job 1: PHPUnit Tests**
- PHP versions: 8.2, 8.3 (matrix)
- Cache composer dependencies (`app/vendor`)
- Run: `./app/vendor/bin/phpunit`

**Job 2: PHPStan Analysis**
- Single PHP version (8.3)
- Run: `./app/vendor/bin/phpstan analyse`
- Fails on any new errors above baseline

**Job 3: PHP-CS-Fixer Check**
- Single PHP version (8.3)
- Run: `./app/vendor/bin/php-cs-fixer fix --dry-run --diff`
- Fails on style violations

**Job 4: Security Audit**
- Run: `composer audit`
- Fails on known vulnerabilities

### 1.2. Caching strategy

```yaml
- name: Cache Composer
  uses: actions/cache@v4
  with:
    path: app/vendor
    key: ${{ runner.os }}-composer-${{ hashFiles('composer.lock') }}
    restore-keys: ${{ runner.os }}-composer-
```

**⚠️ Pagekit vendor dir is `app/vendor/`**, not `vendor/`. Cache path and install command must reflect this.

### 1.3. Composer install command

```yaml
- name: Install dependencies
  run: composer install --no-interaction --prefer-dist --no-progress
```

This respects the `"vendor-dir": "app/vendor"` config in `composer.json`.

---

## 2. CODE COVERAGE BASELINE

### 2.1. Add coverage generation to PHPUnit job

```yaml
- name: Run PHPUnit with coverage
  run: ./app/vendor/bin/phpunit --coverage-text --coverage-clover=coverage.xml
```

### 2.2. Document current coverage

After the first CI run, capture the coverage percentage as the baseline. This will be tracked over time.

---

## 3. QUALITY GATES (Branch Protection)

Document the recommended branch protection rules (user applies manually):

- Require status checks before merge: `phpunit`, `phpstan`, `cs-fixer`, `security-audit`
- Require branches to be up to date before merge
- Require 1 approval for external contributors

**⚠️ Do NOT configure branch protection via API** — this requires admin access. Document the settings for the user to apply.

---

## 4. WORKFLOW VALIDATION

After pushing the workflow files:

```bash
# Verify workflow files are valid YAML
# GitHub Actions will validate on push
git push origin HEAD
```

Check GitHub Actions tab for:
- All 4 jobs run successfully
- PHPStan passes (baseline respected)
- CS-Fixer reports no violations (or expected diff)
- Security audit passes
- PHPUnit tests pass

---

## SUCCESS CRITERIA

- GitHub Actions workflow runs 4 jobs on every PR
- PHPStan check enforced (against baseline)
- CS-Fixer check enforced (dry-run mode)
- Security audit enforced
- PHPUnit tests run in CI
- Coverage baseline documented
- Quality gates documented for branch protection

---

## VALIDATION CHECKLIST

- [ ] `.github/workflows/php-quality.yml` exists
- [ ] PHPUnit job runs with PHP 8.2 + 8.3 matrix
- [ ] PHPStan job runs and respects baseline
- [ ] CS-Fixer job runs in dry-run mode
- [ ] Security audit job runs
- [ ] Caching configured for `app/vendor/`
- [ ] Coverage report generated
- [ ] All jobs pass on push
