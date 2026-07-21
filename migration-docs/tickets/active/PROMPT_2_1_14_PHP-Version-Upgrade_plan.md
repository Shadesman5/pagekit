# Ticket: Step 2.1.14 — PHP Version Upgrade (8.2 → 8.5)

## ARCHITECT OUTPUT
- **Current Step (ROADMAP):** 2.1.14 — PHP Version Upgrade (8.2 → 8.5). GitHub Issue #231 (sub-issue of #147 / Step 2.1). Base branch `develop`. Symfony stays `^6.4`, DBAL stays `^3.8`.
- **Scope:**
  - `composer.json` + `composer.lock` — `require.php` `^8.2` → `^8.5`, `config.platform.php` `8.2.0` → `8.5.0`, Infection constraint `>=0.29 <0.33` → `>=0.33 <0.35` (cap existed only for the dropped PHP 8.2 CI leg; 0.33/0.34 require PHP `^8.3`), full `composer update` lock refresh.
  - `index.php` — runtime version guard `'8.2'` → `'8.5'` (line 5).
  - `app/installer/requirements.php` — `REQUIRED_PHP_VERSION` `'8.2.0'` → `'8.5.0'` (line 364) + OPcache recommendation string "PHP 8.2+" → "PHP 8.5+" (line 518).
  - `app/system/modules/site/src/MenuHelper.php` — fix the one own-code PHP 8.5 deprecation (line 127: null used as array offset; the synthetic root node has `parent_id = null`).
  - `.github/workflows/php-quality.yml` — matrix `['8.2','8.3']` → `['8.5']`; three `if: matrix.php == '8.3'` conditions (lines 67, 86, 102) → `'8.5'`; hard-coded `8.3` in the phpstan / cs-fixer / security-audit jobs (`Setup PHP 8.3` names, `php-version: '8.3'`, `php-8.3` cache keys) → `8.5`.
  - `.travis.yml` — DELETE (dead Travis CI config carrying an 8.2/8.3/8.4 matrix; CI is GitHub Actions, and keeping 8.2/8.3 CI matrices is explicitly out of scope).
  - `Dockerfile` (repo root) — `FROM php:8.4-apache` → `FROM php:8.5-apache` (version alignment only; production image redesign stays in 2.3).
  - `README.md` — badge (line 3) + lines 113, 134, 143, 155, 242, 451 → 8.5+.
  - `.cursor/rules/pagekit-context.mdc` — "PHP 8.2+" → "PHP 8.5+" (lines 23, 53).
  - `.cursor/ROADMAP.md` — Rule 3 bullet "stricter PHP 8.2+ code (8.5+ after Step 2.1.14)" → "stricter PHP 8.5+ code"; Technical Stack "PHP Version: 8.2+ today → 8.5+ after Step 2.1.14" → "PHP Version: 8.5+ (strict types mandatory)". (Header pointer / tracking row stay with Finalize.)
  - `migration-docs/TODO/PHASE_2_MODERNISING.md` — §2.2 + §2.9 amendments for Deferred items (land with this ticket commit).
  - **No changes:** `.cursor/Dockerfile` (already `FROM php:8.5-cli`), `.cursor/install.sh` (comments already 8.5), `.cursor/environment.json` (no PHP refs), `AGENTS.md` (states no runtime minimum), `docs-site/` (see Deferred).
- **Deferred:**
  - **Step 2.2 (CI/CD Pipeline)** — docs-site quality dashboard: `quality-dashboard.js` + `quality-snapshot.demo.json` still render 8.2/8.3 PHPUnit matrix legs; the dashboard is rebuilt against the live snapshot in 2.2, so matrix-key alignment happens there (PHASE §2.2 amended). Version SSoT guard automation (composer.json → CI/requirements/Dockerfile/README) already lives in §2.2 "What".
  - **Step 2.9 (Phase 2 Closeout)** — the 2 PHPUnit doc-comment metadata deprecations (`ConfigManagerTest::testGet`, `MigrationServiceTest`) + flipping `phpunit.xml.dist` `failOn*` gates; PHPUnit stays `^11.0` here (11.5 is green on 8.5 — a 12/13 major now would be tourism and needs the metadata cleanup first) (PHASE §2.9 amended).
  - **Step 2.3 (Docker Production)** — root `Dockerfile` multi-stage/Alpine redesign; this ticket only aligns the `FROM` version (already in §2.3 "What", no amendment).
  - **Non-goals:** Symfony 7 (4.2), DBAL 4 (4.3), Property Hooks / Autowiring / Fail-Fast (2.8.x), coverage-ratchet raise (2.9), PHP 8.5 syntax feature-tourism.
- **Bridges:** None. No parallel old/new behavior, no shims, no TODO-Spec tags required. (The Develop-Branch Ruleset rename below is a maintainer ops handoff at Finalize, not a code bridge.)
- **⚠️ Discovery notes (Refactorer MUST heed):**
  1. **The agent VM already runs PHP 8.5.8 with PCOV enabled** — the prompt's "Current" line is stale for `.cursor/` files: `.cursor/Dockerfile` is already `php:8.5-cli` and `.cursor/install.sh` comments already say 8.5. Do NOT touch them.
  2. **Baseline is green on 8.5** (pre-bump): PHPUnit 718 tests / 2039 assertions OK; PHPStan L8 clean; `composer why-not php 8.5.0` reports no blocking package.
  3. **Exactly one own-code PHP 8.5 deprecation exists:** `MenuHelper.php:127` "Using null as an array offset is deprecated" — triggered because the synthetic root node (`$nodes[0]`) has `parent_id = null` and `isset($nodes[null])` itself triggers the deprecation. The null check must short-circuit BEFORE the array offset. `MenuHelperTest` already exercises both branches (2 tests currently trigger it).
  4. **Infection cap:** installed 0.32.6 under `>=0.29 <0.33`; 0.33.x/0.34.x require PHP `^8.3` (verified on Packagist) and have no PHPUnit conflict entries. After the platform bump the resolver should pick 0.34.x. If 0.34 misbehaves at runtime with PHPUnit 11.5, fall back to `>=0.33 <0.34` and record why in the branch doc.
  5. **Dev tools already 8.5-capable:** PHPUnit 11.5.55, CS-Fixer 3.94.2 (constraint `^3.94` picks up 3.95.x), PHPStan 2.1.46 + plugins — no major bumps needed; "minimal Dev-Tool bumps" = whatever `composer update` resolves within existing constraints.
  6. **Ruleset "Protect for Develop-Branch" (id 8144609) requires status contexts `phpunit (8.2)` and `phpunit (8.3)`.** Renaming the matrix to `['8.5']` makes the job report as `phpunit (8.5)`, so the old required contexts will show as "Expected" forever on the PR. A repo admin must update the ruleset contexts to `phpunit (8.5)` — the agent's `gh` is read-only and lacks admin. Finalize MUST put this maintainer action in the PR body. `gh run watch` at Final Test watches the actual workflow run (not ruleset contexts) and is NOT blocked by this.
  7. **Keep the coverage-floor provenance comment** in `php-quality.yml` (line ~96, "3.86% … PHP 8.3.6, 2026-07-09") — it documents where the pinned 3.8 floor was measured (historical fact), and `MIN_LINE_COVERAGE: '3.8'` stays unchanged.
  8. **Raising the minimum PHP version is a breaking change** — commit as `feat!:` (or `BREAKING CHANGE:` footer) per conventional commits; the version-bump skill decides the magnitude at Finalize.
- **Checklist:**
  1. **Composer platform bump + Infection cap lift + lock refresh.** In `composer.json`: `"php": "^8.5"`, `config.platform.php: "8.5.0"`, `"infection/infection": ">=0.33 <0.35"`. Run `composer update` (all other constraints unchanged). Verify in `composer.lock`: Symfony packages stay 6.4.x, `doctrine/dbal` stays 3.x, `phpunit/phpunit` stays 11.x, Infection resolves to 0.34.x (or 0.33.x per discovery note 4). Sanity: `composer validate` + `composer audit --locked --no-interaction --abandoned=ignore`. Gates: PHPUnit + PHPStan + Infection smoke (see TESTING STRATEGY). Commit: `composer.json`, `composer.lock`.
  2. **Runtime minimums: entry guard + installer requirement.** `index.php` line 5: `'8.2'` → `'8.5'`. `app/installer/requirements.php`: `REQUIRED_PHP_VERSION = '8.5.0'` (line 364); OPcache recommendation "(highly recommended for PHP 8.2+)" → "(highly recommended for PHP 8.5+)" (line 518). No logic changes.
  3. **Fix the PHP 8.5 null-array-offset deprecation in `MenuHelper`.** Line 127: short-circuit on `$node->parent_id !== null` before the `$nodes[$node->parent_id]` offset (e.g. `$parent = $node->parent_id !== null && isset($nodes[$node->parent_id]) ? $nodes[$node->parent_id] : null;`). Acceptance: `./app/vendor/bin/phpunit --display-deprecations` reports ZERO PHP deprecations (the 2 PHPUnit metadata deprecations remain — owned by 2.9).
  4. **CI to 8.5 + remove dead Travis config.** `php-quality.yml`: matrix → `['8.5']` (keep matrix syntax for future legs); the three `if: matrix.php == '8.3'` → `'8.5'`; phpstan / cs-fixer / security-audit jobs: step names `Setup PHP 8.3` → `Setup PHP 8.5`, `php-version: '8.3'` → `'8.5'`, cache keys `php-8.3` → `php-8.5`. Keep `MIN_LINE_COVERAGE` and the floor-provenance comment untouched (discovery note 7). Delete `.travis.yml`. Record the Ruleset maintainer action (discovery note 6) for the Finalize PR body.
  5. **Runtime docs sweep.** Root `Dockerfile`: `FROM php:8.5-apache`. `README.md`: badge → `php-8.5%2B`; line 113 "PHP 8.2 to 8.4 compatibility" → "PHP 8.5+ compatibility"; line 134 "Minimum PHP 8.2+ (supports PHP 8.2, 8.3, 8.4)" → "Minimum PHP 8.5+"; line 143 Monolog "PHP 8.4 support" → "PHP 8.5 support"; line 155 "8.2 or higher (supports 8.2, 8.3, 8.4)" → "8.5 or higher"; line 242 "PHP 8.4 with Apache" → "PHP 8.5 with Apache"; line 451 "current PHP 8.2+ standards" → "current PHP 8.5+ standards". `.cursor/rules/pagekit-context.mdc` lines 23 + 53 → "PHP 8.5+". `.cursor/ROADMAP.md` Rule 3 bullet + Technical Stack line → plain "8.5+" (drop the "after Step 2.1.14" clauses). Leave `docs-site/` phase-history texts (`strategy.md`, `conductor-dashboard.js`) untouched — they describe Phase 1 as delivered, not the runtime minimum.

## EXECUTION STATE
<!-- Machine-readable progress index for the Orchestrator/Conductor. Mirrors the Checklist 1:1
     (same numbers + short titles). Size hint per step: S = small/atomic, M = medium,
     L = large or loop-risk. A step orchestrator flips its box to [x] in the SAME commit as that
     step's code + tests (after full step PASS incl. test-writer when applicable) -->
- [x] Step 1 (L) — Composer: php ^8.5 + platform 8.5.0 + Infection cap lift + lock refresh
- [x] Step 2 (S) — Runtime minimums: index.php guard + installer REQUIRED_PHP_VERSION → 8.5
- [x] Step 3 (S) — Fix PHP 8.5 null-array-offset deprecation in MenuHelper
- [ ] Step 4 (M) — CI to 8.5: php-quality.yml matrix/jobs + delete .travis.yml
- [ ] Step 5 (M) — Runtime docs sweep: Dockerfile, README, pagekit-context, ROADMAP stack

## TESTING STRATEGY
- **Per step (production gate):** Refactorer → Verifier → Tester (PHPUnit + PHPStan) — production code must be green before any new tests are written. All local runs execute natively on PHP 8.5.8 (agent VM).
- **Per step (coverage — inline light):** test-writer → Verifier (test files only) → Tester (PHPUnit + PHPStan) — **skip** when the step changes no production PHP under `app/` or `packages/`; see per-step notes.
- **Per step notes:**
  - Step 1: `test-writer: skip` (composer manifest/lock only). **Extra required tester gate:** Infection smoke on the existing `infection.json.dist` scope (PCOV is enabled in the VM): `./app/vendor/bin/infection --threads=max` must meet the configured `minMsi`/`minCoveredMsi` 80 — proves the resolved Infection version runs on PHP 8.5 + PHPUnit 11.5. On runtime failure of 0.34.x → fall back per discovery note 4.
  - Step 2: `test-writer: skip` — version-literal strings only, no logic branches (the automated Version SSoT guard lands with Step 2.2).
  - Step 3: test-writer runs (light) — extend `MenuHelperTest` only if the synthetic-root null-`parent_id` branch is not already asserted. Extra tester check: `./app/vendor/bin/phpunit --display-deprecations` → 0 PHP deprecations.
  - Step 4: `test-writer: skip` (CI config only). Step 5: `test-writer: skip` (docs only).
- **E2E (Execute — last checklist step only):** when ticking Step 5 completes every `## EXECUTION STATE` box, the Orchestrator delegates `"final E2E run"` to Tester (3 Playwright specs, local PASS/FAIL) — the installation + authentication specs cover the prompt's manual gate (`php pagekit setup` + admin login). **Not** gated on PR/CI — see `.cursor/agents/tester.md` § End-of-ticket E2E.
- **Finalize:** Orchestrator opens PR (breaking change: `feat!:` / `BREAKING CHANGE:` footer) → waits on PHP Quality CI (`gh run watch`; jobs now report on PHP 8.5) → Bugbot → version/CHANGELOG/ROADMAP. **PR body MUST include the maintainer action:** update Ruleset "Protect for Develop-Branch" required status checks `phpunit (8.2)` + `phpunit (8.3)` → `phpunit (8.5)` (admin-only; discovery note 6).
