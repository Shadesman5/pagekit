# Step 2.1.11 — EntityManager DI — remove singleton (Active-Record → Data-Mapper)

**Branch:** `feature/entitymanager-di`
**ROADMAP Step:** 2.1.11 (EntityManager DI — remove singleton)
**GitHub Issue:** [#205](https://github.com/Shadesman5/pagekit/issues/205)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-07-14 01:13
**Completed:** _TBD_

---

## 🎯 Overview

_TBD_

---

## ✅ What Changed

### ORM core — Repository, EntityEvent, serialization map, central guard (Step 1)

Additive step: the `EntityManager` singleton and every `ModelTrait` static stay in
place (removed in Step 8), so the existing suite stays green.

| File | Change |
|---|---|
| `app/modules/database/src/ORM/EntityEvent.php` | **New.** `EntityEvent extends Event`; ctor `(string $name, EntityManager $em)` + `getEntityManager()` — carries the emitting EM to lifecycle handlers. |
| `app/modules/database/src/ORM/Repository.php` | **New.** Generic `Repository<T>` (`query`/`where`/`find`/`findAll`/`create`/`save`/`delete`); `removeRole(int)` ports the `AccessModelTrait` SQL, int-only (no `Role` import) with a `roles`-field `\LogicException` guard. |
| `app/modules/database/src/ORM/SerializableModelInterface.php` | **New.** `setSerializationMap()` contract, satisfied by `ModelTrait`. |
| `app/modules/database/src/ORM/EntityManager.php` | Add `getRepository()` (per-instance repo map); `find()` re-routed through it (old `"{$entity}::find"` callable deleted); `trigger()` emits `EntityEvent`; `load()` gains the central hydration type-guard + serialization-map injection. Singleton `$instance`/`getInstance()` left in place (Step 8). |
| `app/modules/database/src/ORM/Metadata.php` | Add lazy `getSerializationMap()` → `{relations, fieldTypes}` as plain arrays. |
| `app/modules/database/src/ORM/ModelTrait.php` | Add `setSerializationMap()` + `$_serializationMap`; `toArray()` reads the injected map (transitional fallback to `static::getMetadata()`, dropped in Step 8) and skips `_`-prefixed + `\Closure` values. |
| `app/system/modules/site/src/Model/{Node,Page}.php`, `app/system/modules/user/src/Model/{User,Role}.php`, `app/system/modules/widget/src/Model/Widget.php`, `packages/pagekit/blog/src/Model/Post.php`, `app/system/modules/comment/src/Model/Comment.php` | Add `implements SerializableModelInterface` (system `Comment` is the abstract entity; blog `Comment` inherits). |

### Tests (Step 1)

| File | Change |
|---|---|
| `app/modules/database/src/Tests/ORM/RepositoryTest.php` | **New.** find/create/save/delete/removeRole delegation + `removeRole` guard (mocked EM/Metadata/QueryBuilder). |
| `app/modules/database/src/Tests/ORM/EntityEventTest.php` | **New.** `trigger()` propagates the emitting EM to handlers. |
| `app/modules/database/src/Tests/ORM/MetadataTest.php` | **New.** `getSerializationMap()` relations + fieldTypes. |
| `app/modules/database/src/Tests/ORM/ModelTraitTest.php` | **New.** `toArray()` map consumption + `_`-prefix / `\Closure` skip. |
| `app/modules/database/src/Tests/ORM/Fixtures/SerializableFixtureEntity.php` | **New.** Fixture entity for the serialization-map tests. |
| `app/modules/database/src/Tests/ORM/EntityManagerTest.php` | Hydration-guard test (mismatched `newInstance` vs `getClass` throws) + serialization-map injection; `testLoadCreatesEntityWithData` mock updated (`getClass()` → `\stdClass::class`). |
| `app/system/modules/user/src/Tests/UserProviderTest.php` | Removed the non-`User` guard case (now covered centrally — see Key Decisions). |

---

## 🧠 Key Decisions (Rationale)

- **Central hydration guard absorbed the per-site `UserProvider` check one step early.**
  `EntityManager::load()`'s new type-guard made `UserProviderTest`'s non-`User` guard
  case redundant, so it was deleted in Step 1 rather than Step 5 (its planned home);
  the equivalent assertion now lives in the Step 1 EM hydration-guard test. Surfaced by
  the production gate (Tester) and fixed in-step.

---

## ⚠️ Breaking Changes (Extensions)

_TBD_

---

## ⚠️ Risks & Rollout Notes

_TBD / None_

---

## 🔐 Security & Data Impact

_TBD / None_

---

## 🛡️ No-Mercy Compliance

_TBD_

---

## ✅ Verification (links only)

- CI run: _TBD_
- Notable deviations: _TBD / None_

---

## 📋 Phase 1 Audit Closure

**Full closure of Phase 1 audit Step 1.11 (ORM Modernization) — planned at Finalize.**
This step removes the last `EntityManager` singleton / static Active-Record
finding behind row 1.11. At **Finalize**, ROADMAP row 1.11 Audit flips
⚠️ → 🛡️ as the full closure combining Steps 2.0.8 + 2.1.6 + 2.1.10 with this
step (2.1.11). Step 2.1.10 already removed `ModelServiceLocator` as a partial
closure; row 1.11 stayed ⚠️ pending this step.

---

## 📚 Deferred / Out-of-Scope

_TBD_

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/active/PROMPT_2_1_11_EntityManager-DI_plan.md` (→ move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_11_EntityManager-DI.md`
- Predecessor: Step 2.1.10 — Entity Presentation Layer (`step-2-1-10-entity-presentation-layer.md`)
- Successor: Step 2.1.12 ([#217](https://github.com/Shadesman5/pagekit/issues/217)) — _TBD_

---

## 📊 <Step-specific appendix>

_TBD — remove this section if not applicable._
