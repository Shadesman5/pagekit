# Step 2.0.5: Composer & Autoload Hygiene

**ROADMAP:** 2.0.5 — Foundation Consolidation.  
**GitHub Issue:** #182.  
**Prerequisite:** Step 2.0.4 (Package/Migration System Redesign) merged on your branch.

**Also read:** `.cursor/ROADMAP.md` (5 aggressive rules), `migration-docs/TODO/PHASE_2_MODERNISING.md` (Step 2.0.5 section), `migration-docs/TODO/MODERNISATION_STRATEGY.md`.

---

## 1. CONTEXT

### 1.1 Why this step exists

Phase 1 audits (Steps 1.3, 1.4) revealed several `composer.json` / autoload issues that were not blockers at the time but become blockers for CI/CD (Step 2.2):

- **`composer.lock` is gitignored** — builds are not reproducible
- **Dead PSR-4 mappings** point to non-existent directories
- **Unused direct dependencies** inflate the vendor tree
- **Version inconsistency** — `symfony/validator: ^7.4` while rest is `^6.4`
- **Tight pinning** — `paragonie/random-lib: ~2.0.1` blocks safe updates

### 1.2 Goal

A clean, consistent `composer.json` with no dead entries, a versioned lockfile, and aligned dependency constraints. After this step, `composer install` from a fresh clone produces an identical vendor tree.

---

## 2. SAFETY & VERIFICATION

**Workspace root.** PHPUnit: `./app/vendor/bin/phpunit`. Console: `php pagekit list`.

**After each numbered section:**

```bash
composer validate --strict
./app/vendor/bin/phpunit
php pagekit list
```

If anything fails → fix before continuing.

---

## 3. VERSION LOCKFILE — ALREADY DONE

> **Shipped early in Step 2.0.3 (PR #187).** Both `/composer.lock` and `/yarn.lock`
> were removed from `.gitignore` and committed. `.cursor/install.sh` was rewritten
> to use `composer install` (not `update`) for reproducible builds.
>
> **No action needed in this step.** Verify the lockfiles are tracked:
> ```bash
> git ls-files composer.lock yarn.lock
> ```
> Expected: both listed. If not, re-add them.

---

## 4. DEAD PSR-4 MAPPINGS

**File:** `composer.json` → `autoload.psr-4`

### 4.1 Remove `Pagekit\Theme\`

The mapping `"Pagekit\\Theme\\": "app/system/modules/theme/src"` points to a directory that **does not exist** (`app/system/modules/theme/` has no `src/` subdirectory). Remove the entry.

### 4.2 Remove `Pagekit\Package\`

The mapping `"Pagekit\\Package\\": "app/system/modules/package/src"` points to a module path that **does not exist** at all (`app/system/modules/package/` is missing). Remove the entry.

### 4.3 Verify no code references these namespaces

```bash
rg "Pagekit\\\\Theme\\\\" app/ packages/ --glob "*.php"
rg "Pagekit\\\\Package\\\\" app/ packages/ --glob "*.php"
```

If any code **does** reference these namespaces, investigate where the classes actually live and fix the mapping instead of deleting. Expected: zero hits (or only in `installer/` where `Pagekit\Package` might be the real `app/installer/src/Package/` namespace — check carefully).

**IMPORTANT:** `Pagekit\Installer\Package\*` classes exist under `app/installer/src/Package/` and are mapped via `"Pagekit\\Installer\\": "app/installer/src"`. This is a **different** namespace from `Pagekit\Package\`. Confirm no confusion before deleting.

### 4.4 Regenerate autoload

```bash
composer dump-autoload
```

---

## 5. UNUSED DIRECT DEPENDENCIES

**CRITICAL:** Before removing any dependency, run `composer why <package>` to verify it is not required transitively by another package that needs it pinned.

### 5.1 Candidates to investigate

| Package | Observation | Action |
|---------|-------------|--------|
| `symfony/framework-bundle` | No `use FrameworkBundle` or `use Symfony\Bundle\FrameworkBundle` in `app/` or `packages/` | Remove if `composer why` confirms only self-require |
| `symfony/twig-bridge` | No `use Symfony\Bridge\Twig` found | Same |
| `symfony/yaml` | No `use Symfony\Component\Yaml` found | Same — but check if Twig or another dep needs it transitively |
| `symfony/process` | No `use Symfony\Component\Process` found | Same |
| `paragonie/sodium_compat` | No `use ParagonIE\Sodium` found; likely transitively pulled by `random-lib` | Remove from direct `require` if `composer why` shows only transitive need |
| `doctrine/data-fixtures` (require-dev) | No `use Doctrine\Common\DataFixtures` found | Remove if unneeded; can be re-added when fixtures are introduced |

### 5.2 For each removal

```bash
composer why <package>
# If only self-referencing or truly unused:
composer remove <package>
# For dev:
composer remove --dev <package>
```

### 5.3 After all removals

```bash
composer validate --strict
./app/vendor/bin/phpunit
php pagekit list
```

---

## 6. VERSION ALIGNMENT

### 6.1 `symfony/validator` — Major mix

Currently `^7.4` while all other Symfony packages are `^6.4`.

**Decision required:**

- **Option A (recommended):** Align to `^6.4` — keeps the entire Symfony stack on one LTS line. Check if Step 2.0.2 (Validator-Translator Integration) introduced 7.x-only features. If so, evaluate backporting to 6.4 API.
- **Option B:** Keep `^7.4` and document why (e.g. a specific Validator feature is needed). Add a code comment in `composer.json` explaining the intentional deviation.

**To check:**

```bash
rg "Symfony\\\\Component\\\\Validator" app/ packages/ --glob "*.php" -l
```

Review those files for any API that only exists in Validator 7.x but not 6.4.

### 6.2 `paragonie/random-lib` — tight pin

Currently `~2.0.1` (allows only `2.0.x`). Loosen to `^2.0` to allow minor updates.

**Alternatively:** Evaluate if `random-lib` can be replaced entirely with PHP's native `random_bytes()` / `random_int()` (available since PHP 7.0, well-established in 8.2+). If replacement is trivial (few call sites), do it here. If complex, create a TODO for a future step.

```bash
rg "RandomLib|random-lib|RandomGenerator" app/ packages/ --glob "*.php"
```

### 6.3 `psr/log` — consider widening

Currently `^2.0` which excludes `psr/log` 3.x (used by Monolog 3.x in practice). Check if widening to `^2.0|^3.0` is appropriate (matching `psr/cache`'s constraint pattern).

---

## 7. FINAL VALIDATION

```bash
composer validate --strict
composer install --dry-run
./app/vendor/bin/phpunit
php pagekit list
```

All must pass. The lockfile must reflect the new state.

---

## 8. SUCCESS CRITERIA

- [x] `composer.lock` is tracked in git (done in Step 2.0.3, PR #187)
- [ ] No dead PSR-4 mappings in `composer.json`
- [ ] Unused direct dependencies removed (or documented if intentionally kept)
- [ ] `symfony/validator` version aligned with stack or documented deviation
- [ ] `paragonie/random-lib` constraint loosened or native replacement evaluated
- [ ] `composer validate --strict` passes
- [ ] `./app/vendor/bin/phpunit` green
- [ ] `php pagekit list` OK

---

**End of prompt.**
