## ARCHITECT OUTPUT
- **Current Step (ROADMAP):** 2.0.5 (Composer & Autoload Hygiene)
- **Scope:**
  - `composer.json` (root) — `autoload.psr-4`, `require`, `require-dev` blocks
  - `composer.lock` (regenerate after each section)
  - `.gitignore` (verify lockfiles still tracked, no changes expected)
  - Any PHP call sites that depend on packages flagged for removal (`paragonie/random-lib`, `symfony/process`, `symfony/yaml`, `symfony/twig-bridge`, `symfony/framework-bundle`, `paragonie/sodium_compat`) — must update imports if usages exist
- **Deferred:**
  - Step 2.0.6 (Test Infrastructure Cleanup) — module-level `phpunit.xml.dist` cleanup, PHPUnit attribute migration, leftover `Doctrine\Common\Cache\ArrayCache` import — NOT touched here
  - Step 2.0.7 (Event Dispatcher Bridge Removal) — leave any event-related shims alone
  - Step 2.0.8 (Hotfix `create_function()` in User) — out of scope
  - Step 2.1.x (PHPStan tightening) — only run existing PHPStan baseline, do not raise levels
  - Real public API / CI/CD pipeline (Step 2.2) — only prepare a healthy base, do not introduce CI files
- **Bridges:**
  - None planned. This step is pure cleanup — `// TODO: TEMPORARY BRIDGE` markers are NOT expected.
  - If `paragonie/random-lib` is replaced with native `random_bytes()` / `random_int()` and any non-trivial fallback is needed, mark as `// TODO: AUDIT FIX Step 2.0.5 (Autoload Hygiene)`.
  - If a removed dependency turns out to be transitively required and must stay as a direct require, document inline in `composer.json` next to the entry (no shim, no wrapper).

## Checklist

1. **Pre-flight verification (lockfile + baseline)**
   - Run `git ls-files composer.lock yarn.lock` — confirm both tracked (Step 2.0.3 / PR #187 work). If missing, re-add and commit before any other change.
   - Capture baseline: `composer validate --strict`, `./app/vendor/bin/phpunit`, `php pagekit list`. Expect green; any pre-existing red is a blocker — escalate.
   - Capture baseline `composer install --dry-run` output for later diffing.

2. **Remove dead PSR-4 autoload mappings**
   - In `composer.json` `autoload.psr-4`:
     - Delete `"Pagekit\\Theme\\": "app/system/modules/theme/src"` (target dir does not exist).
     - Delete `"Pagekit\\Package\\": "app/system/modules/package/src"` (module path does not exist).
   - Verify zero real usages with:
     - `rg "Pagekit\\\\Theme\\\\" app/ packages/ --glob "*.php"`
     - `rg "Pagekit\\\\Package\\\\" app/ packages/ --glob "*.php"`
   - **Audit finding (PHASE_2 §Step 2.0.5):** confirm `Pagekit\Installer\Package\*` (under `app/installer/src/Package/`, mapped via `Pagekit\\Installer\\`) is NOT confused with the deleted `Pagekit\Package\` namespace.
   - Run `composer dump-autoload`.
   - Run per-step gate: `composer validate --strict` + `./app/vendor/bin/phpunit` + `php pagekit list` + PHPStan (existing baseline). Must pass before continuing.

3. **Investigate and remove unused direct dependencies (one at a time, gated)**
   For each candidate below:
     a. Run `composer why <package>` — if only self-required and no PHP usage, proceed; otherwise document and skip.
     b. Run `rg "<expected-namespace-fragment>" app/ packages/ tests/ --glob "*.php" -l` to confirm zero direct PHP imports.
     c. Run `composer remove <package>` (use `--dev` for dev deps).
     d. Per-step gate: `composer validate --strict` + `./app/vendor/bin/phpunit` + `php pagekit list` + PHPStan baseline.

   Candidates (audit findings from PHASE_2 §Step 2.0.5):
     - `symfony/framework-bundle` — search `Symfony\\Bundle\\FrameworkBundle`
     - `symfony/twig-bridge` — search `Symfony\\Bridge\\Twig`
     - `symfony/yaml` — search `Symfony\\Component\\Yaml` (also check Twig transitive need before removing)
     - `symfony/process` — search `Symfony\\Component\\Process`
     - `paragonie/sodium_compat` — search `ParagonIE\\Sodium`
     - `doctrine/data-fixtures` (require-dev) — search `Doctrine\\Common\\DataFixtures`

   If a candidate is actually used: keep the direct require and add an inline JSON sibling note explaining why (no shims). DELETE OVER WRAP.

4. **Align `symfony/validator` to the Symfony 6.4 LTS line**
   - `rg "Symfony\\\\Component\\\\Validator" app/ packages/ tests/ --glob "*.php" -l`
   - Inspect listed files for any 7.x-only Validator API (constraints, attributes, options) introduced during Step 2.0.2 (Validator-Translator Integration).
   - **Default action (Option A):** change `"symfony/validator": "^7.4"` → `"symfony/validator": "^6.4"` in `composer.json`, run `composer update symfony/validator --with-all-dependencies`.
   - **Only if a documented 7.x-only feature is in active use (Option B):** keep `^7.4` and add an inline `_comment` field in `composer.json` explaining the deviation; reference Step 2.0.5 in the comment.
   - Per-step gate: `composer validate --strict` + `./app/vendor/bin/phpunit` + `php pagekit list` + PHPStan baseline.

5. **Modernize `paragonie/random-lib` constraint or replace with native PHP**
   - `rg "RandomLib|random-lib|RandomGenerator" app/ packages/ tests/ --glob "*.php"` to enumerate call sites.
   - **Preferred (DELETE OVER WRAP):** if call sites are few and trivially replaceable, swap to native `random_bytes()` / `random_int()` and `composer remove paragonie/random-lib`. Update each call site directly — no adapter, no wrapper class.
   - **Fallback:** if call sites are non-trivial or need broader refactor, only loosen the constraint from `~2.0.1` to `^2.0` in `composer.json`. Add a `// TODO: Must be refactored in Step 2.0.5 (Autoload Hygiene)` next to the call sites still using `RandomLib` only if replacement is deferred to a sub-step (e.g. 2.0.5b) — otherwise no TODO needed.
   - Per-step gate: `composer validate --strict` + `./app/vendor/bin/phpunit` + `php pagekit list` + PHPStan baseline.

6. **Widen `psr/log` to match `psr/cache` pattern (audit finding)**
   - In `composer.json`, change `"psr/log": "^2.0"` → `"psr/log": "^2.0|^3.0"` (matches existing `psr/cache` pattern, allows Monolog 3.x's PSR Log 3.x interfaces).
   - Run `composer update psr/log --with-all-dependencies`.
   - Per-step gate: `composer validate --strict` + `./app/vendor/bin/phpunit` + `php pagekit list` + PHPStan baseline.

7. **Final `composer.json` consistency pass**
   - Re-read `composer.json` end-to-end: confirm only Symfony 6.4 (or documented deviation) on `symfony/*`, no `^7.x` mixed.
   - Confirm autoload no longer references non-existent paths.
   - Run `composer dump-autoload --optimize` once at the end.
   - Confirm `composer.lock` was updated and is committed (not gitignored).

8. **Final acceptance gate (mirrors PROMPT §7 + §8)**
   - `composer validate --strict`
   - `composer install --dry-run` — must show zero pending operations on a clean clone
   - `./app/vendor/bin/phpunit`
   - `php pagekit list`
   - PHPStan baseline
   - Playwright E2E (installation, login, dashboard) — to confirm no runtime regression in admin UI from removed/realigned dependencies

## TESTING STRATEGY
- **Per step:** PHPUnit + PHPStan (mandatory after every checklist step)
- **Final run (after all steps):** PHPUnit + PHPStan + `php pagekit setup` + `php pagekit list` + Playwright E2E (installation, login, dashboard)
