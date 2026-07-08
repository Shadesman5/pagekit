# Step 2.1.7 — QueryBuilder API Standardization

**Branch:** `feature/PROMPT_2_1_7_QueryBuilder-API`
**ROADMAP Step:** 2.1.7 (Static Analysis & Code Quality — QueryBuilder API Standardization)
**GitHub Issue:** [#154](https://github.com/Shadesman5/pagekit/issues/154)
**Pull Request:** [#215](https://github.com/Shadesman5/pagekit/pull/215)
**Status:** ✅ Complete — Ready for Review
**Date:** 2026-07-08

---

## 🎯 Overview

Step 2.1.7 modernizes the Pagekit database QueryBuilder execution API for Doctrine DBAL 3.x,
normalizes the legacy `json_array` DBAL type to `json`, and closes Phase 1 audit debt for
**Step 1.5 (Doctrine DBAL 3.x)**.

The migration was executed in **6 checklist steps**, plus post-review hardening of three latent
QueryBuilder bugs surfaced by follow-up review (see "Post-review hardening" below).

---

## ✅ What Changed

### QueryBuilder execution API (Step 1)

| File | Change |
|---|---|
| `app/modules/database/src/Query/QueryBuilder.php` | Split legacy `execute()` into `executeQuery(): Result` + `executeStatement(): int`; deleted public `execute()` |
| `app/modules/database/src/ORM/QueryBuilder.php` | Internal calls + `@method` docblock updated |
| `app/system/modules/site/src/Model/NodeModelTrait.php` | `:88` `->select('n.id')->executeQuery()`, `:163` `->executeQuery()` |
| `app/system/src/Validator/Constraints/UniqueValidator.php` | `:69` `->executeQuery()` |
| `packages/pagekit/blog/src/Controller/BlogController.php` | `:108`/`:114` column selects rewritten with `->select(...)->executeQuery()` |
| `packages/pagekit/blog/src/Model/PostModelTrait.php` | `:32` `->executeQuery()` |

### JSON DBAL type normalization (Step 2)

- `JsonArrayType::getName()` returns `'json'`; redundant `json_array` registration removed.
- `DataModelTrait` Column attribute `json_array` → `json` (property type deferred to 2.1.12).
- `ORM/ModelTrait::toArray()` case updated; `Connection::registerCustomTypeMappings()` cleaned.

### DBAL 3 audit-fix (Steps 3–4)

- Deleted `Connection::exec()` (zero callers).
- `Utility.php` — `createSchemaManager()`, `introspectSchema()`, dead `migrate()` removed.
- `Installer.php` — `createSchemaManager()`.
- `DbUtil.php` test helper — full DBAL 3 rewrite with explicit teardown strategy.

### Tests (Step 5)

- `executeQuery()` / `executeStatement()` coverage (UPDATE + DELETE + Result type).
- JSON column round-trip smoke test via `JsonArrayType`.

### Generics (Step 6)

- `@template T of object` annotations on ORM `QueryBuilder::get()`/`first()` and related fetch paths.

### Post-review hardening (follow-up bug reports)

Three latent QueryBuilder bugs surfaced by post-review (local Bugbot + follow-up reports) were fixed:

- **`Query\QueryBuilder::executeStatement()` write-type guard.** The method previously fell through to a
  `DELETE` whenever the write type was not exactly `'update'` — including when it was still unset on a
  SELECT-configured builder. A mistaken call (also reachable via the ORM `QueryBuilder::__call` proxy)
  could `DELETE` every row of the FROM table. It now uses a `match` that throws a `LogicException` unless
  `update()`/`delete()` selected the write type.
- **`ORM\QueryBuilder` result-cache key.** The key is derived from the SQL, bound parameters and
  eager-load relation *names* (common ORM practice, cf. Doctrine's result cache) rather than by
  serializing, reflecting or materializing the constraint closures — which respectively crashed on
  Closures, missed values read via a bound `$this`, or executed the constraints on every lookup. A new
  fluent `cacheKey(?string $key)` discriminator (akin to `setResultCacheId`) disambiguates queries that
  share SQL, params and relation names but load different related data via a dynamic constraint. Bound
  parameters are normalized so a non-serializable binding can never break key generation; the `cache()`
  signature is unchanged.

> **Scope note:** the ORM `cache()` API originates from **Step 1.11 (ORM Modernization)**, not 2.1.7 —
> these are hotfixes that rode along on this branch.

**Re-verified:** PHPUnit 338 green · PHPStan level 8 clean · local Bugbot clean.

---

## ⚠️ Breaking Changes (Extensions)

- **`Pagekit\Database\Query\QueryBuilder::execute()` removed.** Use `executeQuery(): Result` for
  SELECT and `executeStatement(): int` for UPDATE/DELETE.
- **Column-argument `execute('col')` pattern removed.** Call `->select('col')->executeQuery()` instead.
- **DBAL type name `json_array` unregistered.** Entity Column attributes should use `type: 'json'`.
- **`executeStatement()` requires a write type.** It now throws a `LogicException` unless `update()` or
  `delete()` was called first (previously it silently issued a `DELETE`). Callers using the normal
  `->update([...])` / `->delete()` fluent API are unaffected.

---

## 🧪 Test Results

| Gate | Result |
|---|---|
| PHPUnit (local) | 332 tests, 0 failures |
| PHPStan (local) | PASS |
| CI — phpunit (8.2) | ✅ success |
| CI — phpunit (8.3) | ✅ success |
| CI — phpstan | ✅ success |
| CI — cs-fixer | ✅ success |
| CI — security-audit | ✅ success |
| E2E installation.spec.js | 1/1 passed |
| E2E authentication.spec.js | 14/14 passed |
| E2E dashboard.spec.js | 10/10 passed |

**CI run:** https://github.com/Shadesman5/pagekit/actions/runs/28910608910

---

## 📋 Phase 1 Audit Closure

Closes **Step 1.5 (Doctrine DBAL 3.x) ⚠️ → 🛡️** — resolves `Connection::exec()`, deprecated
`getSchemaManager()` / `createSchema()`, `Utility::migrate()` Comparator debt, and `DbUtil` `exec()` usage.
