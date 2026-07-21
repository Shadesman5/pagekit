# Step 2.1.14: PHP Version Upgrade (8.2 → 8.5)

<!-- conductor-mode: full -->

**ROADMAP:** 2.1.14. GitHub Issue: #231. Reference: `@ROADMAP.md`, `PHASE_2_MODERNISING.md` §2.1.14, `migration-docs/TODO/ROADMAP-PHP-UPGRADE-ANALYSE.md`.

---

## CONTEXT

- **Depends on:** Step 2.1.13 preferred (security first); must land **before** 2.2 / 2.3 / 2.8 / 2.9.
- **Risk:** Medium — runtime + Dev-Tool resolution; Symfony 6.4 stays (no 7.x); DBAL stays on 3.x.
- **Current:** `composer.json` `"php": "^8.2"`, `config.platform.php: "8.2.0"`; CI matrix `8.2`/`8.3`; `.cursor/Dockerfile` `php:8.3-cli`; installer `REQUIRED_PHP_VERSION = '8.2.0'`.
- **Target:** Minimum **PHP 8.5** (latest stable at planning: confirm patch at execution).

**Goal:** Raise the PHP minimum to 8.5 once, with all version SSoT consumers and quality gates green — **no** language-feature tourism (that is Step 2.8).

---

## 0. SAFETY CHECKS (CRITICAL)

**Before starting:**

1. Branch up-to-date with `develop` (and 2.1.13 if already merged).
2. Baseline green on current stack:
   ```bash
   ./app/vendor/bin/phpunit
   ./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
   ```

**After composer/platform bump — every checklist step:**

```bash
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
# if Infection config still runs on this branch:
./app/vendor/bin/infection --threads=max || true  # Architect decides required vs optional in ticket
```

**IF ANY FAILS → STOP AND FIX.**

**Local runtime:** Agents/CI must actually run **PHP 8.5** for verification (update `.cursor/Dockerfile` early so Cloud Agents match).

---

## 1. DISCOVERY

Inventory every version SSoT consumer (Architect refreshes paths at plan time):

```bash
rg -n '"php"|platform|8\.2|8\.3|REQUIRED_PHP_VERSION' composer.json app/installer/requirements.php .github/workflows/ .cursor/Dockerfile README.md AGENTS.md .cursor/ROADMAP.md
php -v
composer show | rg -i 'phpunit|infection|php-cs-fixer|phpstan'
```

Note current Dev-Tool constraints in `composer.json` `require-dev`.

---

## 2. UPGRADE WORK

### 2.1. Composer platform + require

1. `composer.json`:
   - `"php": "^8.5"`
   - `config.platform.php`: `"8.5.0"`
2. `composer update` (resolve lockfile on 8.5 platform).
3. Bump Dev-Tools as needed for 8.5 compatibility (likely: `friendsofphp/php-cs-fixer`, `phpunit/phpunit`, `infection/infection`, PHPStan plugins). Prefer minimal constraint bumps that unlock green gates — no unrelated major churn.

### 2.2. Version SSoT consumers (all must agree)

| Consumer | Action |
| -------- | ------ |
| `app/installer/requirements.php` | `REQUIRED_PHP_VERSION` → `8.5.0` (+ recommendation strings) |
| `.github/workflows/php-quality.yml` | Matrix → `8.5` (drop 8.2/8.3 support); job names / coverage `if:` conditions |
| Other workflows mentioning PHP | Align to 8.5 |
| `.cursor/Dockerfile` | `FROM php:8.5-cli` (extensions still install) |
| `README.md` / badges | PHP 8.5+ |
| `AGENTS.md` | Note 8.5 if it documents runtime |
| `.cursor/ROADMAP.md` Technical Stack | PHP 8.5+ after this step lands |

### 2.3. Own-code deprecation cleanup

- Fix **Pagekit** deprecations that fail under 8.5 / PHPUnit `failOnDeprecation` paths (e.g. residual `setAccessible`, own `__sleep`/`__wakeup` if any).
- Do **not** upgrade Symfony to 7 or DBAL to 4 to silence upstream noise — stay on `^6.4` / `^3.8`; document remaining vendor deprecations if gates require ignores (prefer fixing own code).

### 2.4. Quality gates

All must pass on PHP 8.5:

- PHPUnit
- PHPStan Level 8 (no new baseline entries)
- CS-Fixer dry-run (CI job)
- Security audit job
- Infection (existing auth/user scope) if still gated
- Playwright smoke (Final Test)

---

## 3. OUT OF SCOPE

- Symfony 6.4 → 7 (Step **4.2**)
- Doctrine DBAL 3 → 4 (Step **4.3**)
- Property Hooks / FQCN Autowiring / Fail-Fast sweep (Step **2.8.x**)
- Coverage / MSI ratchet (Step **2.9**)
- Repo-wide rewrite to pipe operator / “use every 8.5 feature”
- Keeping PHP 8.2 or 8.3 in the CI matrix (minimum raise = drop older)

---

## 4. TESTING

**Per checklist step:** PHPUnit + PHPStan on 8.5.

**Final Test:** CI PHP Quality on 8.5 green → Playwright 3 smoke specs.

**Manual smoke:** `php pagekit setup` (or existing install) boots; admin login works.

---

## SUCCESS CRITERIA

- `composer.json` requires `^8.5` with `platform.php` `8.5.0`; lockfile resolves
- Installer, CI, Dockerfile, README/ROADMAP stack all report **8.5+**
- Quality gates green on PHP 8.5
- No Symfony 7 / DBAL 4 / 2.8 language adoption mixed into this PR
- Branch doc records dependency bumps and any remaining vendor deprecation policy
