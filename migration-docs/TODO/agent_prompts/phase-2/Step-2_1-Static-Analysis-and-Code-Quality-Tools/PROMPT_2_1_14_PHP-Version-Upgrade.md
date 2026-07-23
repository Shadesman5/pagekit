# Step 2.1.14: PHP Version Upgrade (8.2 → 8.5)

<!-- conductor-mode: full -->

**ROADMAP:** 2.1.14. GitHub Issue: #231. Reference: `@ROADMAP.md`, `PHASE_2_MODERNISING.md` §2.1.14.

---

## CONTEXT

- **Depends on:** 2.1.13 preferred; must land **before** 2.2 / 2.3 / 2.8 / 2.9.
- **Risk:** Medium. Symfony stays `^6.4`, DBAL stays `^3.8`.
- **Current:** `composer.json` `"php": "^8.2"`, `platform.php: "8.2.0"`; CI `8.2`/`8.3`; Dockerfile `php:8.3-cli`; installer `8.2.0`; Infection `>=0.29 <0.33` (8.2-matrix cap).
- **Target:** Minimum PHP **8.5**.

**Goal:** Raise PHP minimum to 8.5 once (SSoT + gates green). No 2.8 language tourism.

---

## 0. SAFETY CHECKS

**Before starting:**

1. Branch up-to-date with `develop`.
2. Baseline green on current stack:

```bash
php -v
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
```

After platform bump, same PHPUnit + PHPStan each checklist step. Infection: Architect decides required vs optional.

**IF ANY FAILS → STOP AND FIX.**

---

## 1. DISCOVERY

```bash
rg -n '"php"|platform|8\.2|8\.3|8\.4|REQUIRED_PHP_VERSION|php:8\.' \
  composer.json app/installer/requirements.php .github/workflows/ \
  .cursor/Dockerfile .cursor/install.sh .cursor/environment.json \
  .cursor/rules/pagekit-context.mdc README.md AGENTS.md .cursor/ROADMAP.md docs-site/
composer show | rg -i 'phpunit|infection|php-cs-fixer|phpstan'
```

---

## 2. WORK

### 2.1 Composer

- `"php": "^8.5"`, `config.platform.php`: `"8.5.0"`
- `composer update`
- Minimal Dev-Tool bumps for 8.5 (CS-Fixer, PHPUnit, Infection, PHPStan plugins). Re-evaluate Infection `<0.33` cap.

### 2.2 SSoT consumers

| Consumer | Action |
| -------- | ------ |
| `app/installer/requirements.php` | `REQUIRED_PHP_VERSION` → `8.5.0` (+ recommendation strings) |
| `.github/workflows/php-quality.yml` | Matrix → `8.5`; drop 8.2/8.3; fix hard-coded 8.3 jobs / coverage `if:` / cache keys |
| Other workflows with PHP | → 8.5 |
| `.cursor/Dockerfile` | `FROM php:8.5-cli` (+ comments) |
| `.cursor/install.sh` | Comments `8.3` → `8.5` |
| `README.md` | Badge + Requirements/Features/Docker text → 8.5+ |
| `.cursor/rules/pagekit-context.mdc` | 8.2+ → 8.5+ |
| `AGENTS.md` / `docs-site/` | If they state the runtime minimum |
| `.cursor/ROADMAP.md` Technical Stack | 8.5+ on finalize |

### 2.3 Own-code deprecations

Fix Pagekit deprecations that fail under 8.5 / PHPUnit `failOnDeprecation`. No Symfony 7 / DBAL 4 to silence vendors.

### 2.4 Gates (on PHP 8.5)

PHPUnit, PHPStan L8 (no new baseline), CS-Fixer dry-run, security audit, Infection scope if gated, Playwright smoke (Final Test).

---

## 3. OUT OF SCOPE

- Symfony 7 (4.2), DBAL 4 (4.3), Property Hooks / Autowiring / Fail-Fast (2.8.x), coverage ratchet (2.9)
- Feature-tourism syntax rewrites
- Keeping 8.2/8.3 in the CI matrix

---

## 4. TESTING

Per step: PHPUnit + PHPStan. Final: CI PHP Quality on 8.5 → Playwright 3 smoke. Manual: `php pagekit setup` + admin login.

---

## SUCCESS CRITERIA

- `^8.5` + `platform.php` `8.5.0`; lock resolves
- Installer, CI, Dockerfile, README, runtime docs, ROADMAP → **8.5+**
- Infection not capped only for dropped 8.2 leg (or documented why)
- Gates green on PHP 8.5
- No Symfony 7 / DBAL 4 / 2.8 mixed in
