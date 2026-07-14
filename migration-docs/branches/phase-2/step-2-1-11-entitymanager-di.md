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

### Lifecycle handlers → EntityEvent (Step 2)

Second additive step: the five lifecycle handlers stop reaching global model
statics and instead use the `EntityManager` carried by the Step 1 `EntityEvent`.
Signatures change `EventInterface` → `EntityEvent`; the trait finders and the EM
singleton stay in place (removed in Step 8), so the existing suite stays green.

| File | Change |
|---|---|
| `app/system/modules/site/src/Model/NodeModelTrait.php` | `saving`/`deleting` → `(EntityEvent, Node)`. `saving` pulls the EM off the event: `getConnection()`, `getRepository(Node::class)` (slug-uniqueness loop + parent lookup), `getMetadata(Node::class)->getTable()` (child-path `UPDATE`). `deleting` re-parents children via `getRepository(Node::class)->where(…)->get()` + `$em->save($child)`; the inline `instanceof Node` guard is dropped (Step 1 central hydration guard — decision 5). |
| `app/system/modules/user/src/Model/RoleModelTrait.php` | `saving` → `(EntityEvent, Role)`; the new-role `MAX(priority)+1` lookup runs on `$event->getEntityManager()->getConnection()`. |
| `app/system/modules/user/src/Model/UserModelTrait.php` | `saving` signature only → `(EntityEvent, User)`; body stays pure in-memory (guarantees `ROLE_AUTHENTICATED`) and never touches the EM. |
| `packages/pagekit/blog/src/Model/PostModelTrait.php` | `saving`/`deleting` → `(EntityEvent, Post)`; slug-uniqueness via `getRepository(Post::class)`, comment cascade via `getConnection()->delete('@blog_comment', …)`. |
| `app/system/modules/comment/src/Model/CommentModelTrait.php` | `deleting` → `(EntityEvent, Comment)`; re-parents replies via `getRepository($comment::class)` — the concrete runtime class, not `Comment::class` (see Key Decisions). |

### Tests (Step 2)

| File | Change |
|---|---|
| `app/system/modules/site/src/Tests/NodeModelTraitTest.php` | **New.** `saving()` on a real in-memory SQLite EM (`bootSqliteManager()` precedent) where SQL semantics bite — slug suffixing, parent-path nesting, cross-menu / self / missing-parent reset, parent-scoped next priority; `deleting()` re-parenting via a mocked repository/query chain with `save()`-count assertions. |
| `app/system/modules/user/src/Tests/RoleModelTraitTest.php` | **New.** `saving()` next-priority via a mocked `Connection`/`Result` — new-role-queries-once vs persisted-role-touches-nothing. |
| `app/system/modules/user/src/Tests/UserModelTraitTest.php` | **New.** `saving()` authenticated-role guarantee (add / preserve-and-append / no-duplicate); bare mock EM (the handler never queries). |
| `app/system/modules/comment/src/Tests/CommentModelTraitTest.php` | **New.** `deleting()` reply re-parent delegation via a mocked repository/query; asserts the concrete-class lookup (`getRepository(CommentEntity::class)`). |
| `app/system/modules/comment/src/Tests/bootstrap.php` | **New.** Requires the trait + abstract `Comment` + fixture in dependency order — the comment module is not on composer's autoload map (mirrors the blog Tests bootstrap). |
| `app/system/modules/comment/src/Tests/Fixtures/CommentEntity.php` | **New.** Concrete subclass of the abstract mapped-superclass `Comment` so the shared `deleting()` handler can run against a real instance. |
| `tests/Unit/Blog/PostModelTraitTest.php` | **New.** `saving()` modified-stamp + slug suffixing, `deleting()` `@blog_comment` cascade; mocked EM/repository/connection; reuses the existing blog Tests bootstrap. |

### Custom repositories + container wiring (Step 3)

Third additive step: three custom repositories and seven container services (three
custom + four generic) are wired in, but **nothing consumes them yet** — the module
`node`/`user` services, controllers, listeners and the trait statics keep their
current shape until Steps 4–7, so the existing suite stays green. Each custom repo
subclasses the Step 1 generic `Repository<T>` and resolves its own `Metadata`.

| File | Change |
|---|---|
| `app/system/modules/site/src/Model/NodeRepository.php` | **New.** `extends Repository<Node>`; ctor `(EntityManager $em, CacheItemPoolInterface $cache)` resolving metadata via `$em->getMetadata(Node::class)`. Ports the former `NodeModelTrait` static `$nodes` cache onto the injected pool — `find`/`findAll`/`findByMenu` gain a `bool $cached` flag (full-set memo on `findAll(true)`, per-id memo on `find($id, true)`, shared instances) — plus `fixOrphanedNodes(): int`. |
| `app/system/modules/user/src/Model/UserRepository.php` | **New.** `extends Repository<User>`; finders `findByUsername`/`findByEmail`/`findByCredentials`/`updateLogin`/`findRoles` ported off the `UserModelTrait` statics. `findRoles` uses `whereIn('id', $user->roles)` (no string-interpolated `IN`) with an empty-`roles` guard returning `[]`; dead `findByLogin` not ported (Rule 4); no cross-user static cache. |
| `packages/pagekit/blog/src/Model/PostRepository.php` | **New.** `extends Repository<Post>`; `updateCommentInfo(int)` recounts approved comments via `getRepository(Comment::class)`, `getAuthors()` — ported off the `PostModelTrait` statics. |
| `app/system/modules/site/src/SiteModule.php` | `main()` registers `nodeRepository` (`new NodeRepository($app->get('db.em'), new ArrayAdapter(0, false))` — `storeSerialized: false` preserves the static cache's shared-object semantics) + generic `pageRepository`. |
| `app/system/modules/user/src/UserModule.php` | `main()` registers `userRepository` (`new UserRepository($app->get('db.em'))`) + generic `roleRepository`. |
| `app/system/modules/widget/index.php` | `main` registers generic `widgetRepository` (`$app->get('db.em')->getRepository(Widget::class)`). |
| `packages/pagekit/blog/index.php` | `boot` registers `postRepository` (`new PostRepository($app->get('db.em'))`) + generic `commentRepository` (blog `Comment::class`), alongside the existing `postPresenter`. |

### Tests (Step 3)

| File | Change |
|---|---|
| `app/system/modules/site/src/Tests/NodeRepositoryTest.php` | **New.** Cached `find`/`findAll`/`findByMenu` semantics against a real `ArrayAdapter(0, false)` — shared-instance identity, per-id vs full-set memo, `fixOrphanedNodes` delegation. |
| `app/system/modules/user/src/Tests/UserRepositoryTest.php` | **New.** Mock-backed finder delegation (`findByUsername`/`findByEmail`/`findByCredentials`/`updateLogin`) + `findRoles` `whereIn` path and empty-`roles` guard. |
| `tests/Unit/Blog/PostRepositoryTest.php` | **New.** Mock-backed `updateCommentInfo` (approved-comment recount via the `Comment` repository) + `getAuthors`. |
| `tests/Unit/Blog/bootstrap.php` | Extended with the extra `require_once`s for `PostRepository` (blog classes are not on composer's autoload map). |

---

## 🧠 Key Decisions (Rationale)

- **Central hydration guard absorbed the per-site `UserProvider` check one step early.**
  `EntityManager::load()`'s new type-guard made `UserProviderTest`'s non-`User` guard
  case redundant, so it was deleted in Step 1 rather than Step 5 (its planned home);
  the equivalent assertion now lives in the Step 1 EM hydration-guard test. Surfaced by
  the production gate (Tester) and fixed in-step.
- **`CommentModelTrait::deleting` targets the concrete entity (`$comment::class`), not `Comment::class`.**
  System `Comment` is an abstract mapped-superclass (blog `Comment` inherits it), so the
  plan's literal `getRepository(X::class)` would resolve the abstract parent and miss the
  real table; the handler reads the concrete runtime class off the instance instead. This
  is also why the comment module gained its first unit-test scaffolding — a concrete
  `CommentEntity` fixture + a `bootstrap.php` (the module is not on composer's autoload
  map), mirroring the blog Tests bootstrap.

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
