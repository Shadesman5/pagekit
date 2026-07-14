# Step 2.1.11 — EntityManager DI — remove singleton (Active-Record → Data-Mapper)

**Branch:** `feature/entitymanager-di`
**ROADMAP Step:** 2.1.11 (EntityManager DI — remove singleton)
**GitHub Issue:** [#205](https://github.com/Shadesman5/pagekit/issues/205)
**Pull Request:** [#222](https://github.com/Shadesman5/pagekit/pull/222)
**Status:** ✅ Complete — Ready for Review
**Started:** 2026-07-14 01:13
**Completed:** 2026-07-14 13:48

---

## 🎯 Overview

Removes the last global ORM state behind Phase 1 audit row 1.11: the
`EntityManager` singleton, the `ModelTrait` static Active-Record API, and the
RC-1 eager `db.em` boot hack. Introduces a generic `Repository<T>`,
`EntityEvent`-driven lifecycle handlers, serialization-map injection, and three
custom repositories (`NodeRepository`, `UserRepository`, `PostRepository`)
registered as container services. All site, user, widget, and blog call sites
migrate to constructor-injected repositories; Step 8 deletes the coexistence
scaffolding. Extensions must use DI repositories or `$app->get('db.em')` — the
static model API is gone.

**Phase 1 audit 1.11 — FULL closure:** combined with Steps 2.0.8 + 2.1.6 +
2.1.10, row **1.11 flips ⚠️ → 🛡️** at Finalize.

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

### Site module migration — repositories in, statics + RC-2 cache out (Step 4)

First **consuming** step: the site `node` service, both listeners, `MenuHelper` and
all five controllers now resolve the Step 3 `nodeRepository` / `pageRepository`
(generic `Repository<Page>`) instead of the `Node`/`Page`/`Role` static
Active-Record API, and the `NodeModelTrait` request-cache statics (RC-2) are
deleted. The `EntityManager` singleton and the `ModelTrait` statics stay in place
(removed in Step 8), so the existing suite stays green.

| File | Change |
|---|---|
| `app/system/modules/site/src/Model/NodeModelTrait.php` | **-86 lines (pure deletion).** Removed the static `$nodes` request cache + its cached `find`/`findAll`/`findByMenu`/`fixOrphanedNodes`, the `use ModelTrait { find as modelFind }` alias, and the RC-2 `TODO` block. The trait now carries **only** the two `EntityEvent` lifecycle handlers (`saving`/`deleting`, from Step 2). |
| `app/system/modules/site/src/Model/Node.php` | Add `use ModelTrait;` to the entity directly (see Key Decisions) — the `NodeModelTrait` alias removal above dropped the transitive import that supplied `Node`'s instance serialization/persistence API. |
| `app/system/modules/site/src/SiteModule.php` | `node` service + `registerType()` now consume the injected `nodeRepository` — `find($id, true)`, `findAll(true)`, `save($nodes->create([...]))` — replacing the `Node::find`/`findAll`/`create`/`save` statics. (The `nodeRepository`/`pageRepository` service *definitions* landed in Step 3.) |
| `app/system/modules/site/index.php` | `boot` wiring: `NodesListener` receives `nodeRepository`, `PageListener` receives `pageRepository`; the `view.init` `MenuHelper` gains a 5th arg `nodeRepository`. |
| `app/system/modules/site/src/Event/NodesListener.php` | Inject `NodeRepository`; `onRequest()` → `$this->nodes->findAll(true)`; `onRoleDelete()` → `$this->nodes->removeRole((int) $role->id)` (was `Node::findAll(true)` / `Node::removeRole($role)`). |
| `app/system/modules/site/src/Event/PageListener.php` | Inject generic `Repository<Page>`; `getPage()` find/create and the node-save/-delete hooks persist through the repo (`save($page, $data)`, `delete($page)`) instead of `Page::find`/`create` + instance `save`/`delete`. |
| `app/system/modules/site/src/MenuHelper.php` | Inject `NodeRepository` (5th ctor arg); menu tree via `$this->nodes->findByMenu($menu, true)`. Root placeholder `new Node(['path' => '/'])` kept (manually-`new`-ed, never serialized — decision 4). |
| `app/system/modules/site/src/Controller/NodeController.php` | Inject `nodeRepository` + generic `roleRepository` (`Repository<Role>`); `fixOrphanedNodes()`, node `find`/`create`, and role list via `$this->roleRepository->findAll()`. |
| `app/system/modules/site/src/Controller/NodeApiController.php` | Inject `nodeRepository`; list via `where(['menu' => …])` / `query()`, all find/create/save/delete through the repo. The per-site `instanceof Node` + `\LogicException` loop is **deleted** (PHPStan-dead under `QueryBuilder<Node>` typing — decision 5) → `array_values($query->get())`. |
| `app/system/modules/site/src/Controller/MenuApiController.php` | Inject `nodeRepository`; menu counts via `where(...)->count()`, rename/trash via `where(...)->update(...)`. |
| `app/system/modules/site/src/Controller/PageController.php` | Inject generic `Repository<Page>`; front-end page lookup via `find()`. |
| `app/system/modules/site/src/Controller/PageApiController.php` | New ctor injecting generic `Repository<Page>`; index via `array_values($this->pageRepository->findAll())`, `getAction()` via `find()`. The per-site `instanceof Page` guard loop is **deleted** (decision 5). |

### Tests (Step 4)

Reworked off the singleton harness: the site `Tests/` directory now has **zero**
`RunInSeparateProcess` / `PreserveGlobalState` / `primeEntityManager*` (their reason —
the process-static EM singleton — is no longer consumed here). Full suite green at
610 tests, PHPStan L8 exit 0.

| File | Change |
|---|---|
| `app/system/modules/site/src/Tests/NodeApiControllerTest.php` | Reworked: dropped process isolation + `primeEntityManager*`; mocks `NodeRepository` / `QueryBuilder<Node>` directly; the stale "mock-backed EntityManager singleton (isolated process)" class docblock rewritten to the repository mechanism. |
| `app/system/modules/site/src/Tests/MenuApiControllerTest.php` | Reworked for the new `nodeRepository` ctor arg; mocks the repo `where(...)->count()/update()` chain. |
| `app/system/modules/site/src/Tests/MenuHelperTest.php` | Updated for the new 5th ctor arg (`NodeRepository`). |
| `app/system/modules/site/src/Tests/NodeControllerTest.php` | **New.** `indexAction` orphan-repair redirect + `editAction` find/create and role listing, via mocked `NodeRepository` / `Repository<Role>` and a mocked `ModuleManager`-resolved `SiteModule`. |
| `app/system/modules/site/src/Tests/PageControllerTest.php` | **New.** `indexAction` page lookup + content-plugin path via a mocked `Repository<Page>` + `ContentHelper`; not-found path. |
| `app/system/modules/site/src/Tests/PageApiControllerTest.php` | **New.** `indexAction` `findAll()` + `getAction` `find()`/not-found via a mocked generic `Repository<Page>`. |
| `app/system/modules/site/src/Tests/NodesListenerTest.php` | **New.** `onRequest` route registration via `findAll(true)` + `onRoleDelete` → `removeRole((int) $role->id)`, mocked `NodeRepository`. |
| `app/system/modules/site/src/Tests/PageListenerTest.php` | **New.** `onNodeSave`/`onNodeDelete` + `getPage` find/create through a mocked generic `Repository<Page>`. |
| `app/system/modules/site/src/Tests/SiteModuleTest.php` | **New.** `main()` registers `nodeRepository`/`pageRepository`; `registerType()` auto-creates a protected type's node via `$nodes->save($nodes->create([...]))`. Light `new Application()` boot with the `nodeRepository` factory overridden by a mock (DiWiringTest precedent) — no kernel/DB. |
| `app/system/modules/site/src/Tests/bootstrap.php` | Extended to `require_once` the content module's `ContentHelper` — `Pagekit\Content\` is a runtime-loaded module absent from composer's autoload map, so PHPUnit cannot autoload the class `PageControllerTest` mocks (mirrors the blog Tests bootstrap). |

### User module migration — auth chain + hasPermission role loader (Step 5)

Second **consuming** step: the auth provider, both user listeners, the anonymous-user
factory, all seven touched controllers (six in the user module + the system-core
`AdminController`) and the debug auth collector now resolve the Step 3 `userRepository`
(`UserRepository`) / `roleRepository` (generic `Repository<Role>`) instead of the
`User`/`Role` static Active-Record finders. `User::hasPermission()` resolves roles
through a per-instance loader closure wired at hydration (Architecture decision 6), and
the `UserModelTrait` finders (`findByUsername`/`findByEmail`/`updateLogin`/`findRoles` +
`static $cached`; dead `findByLogin` dropped, Rule 4) are gone — that logic moved to
`UserRepository` in Step 3. The `EntityManager` singleton and the `ModelTrait` statics
stay in place (removed in Step 8), so the existing suite stays green.

| File | Change |
|---|---|
| `app/system/modules/user/src/Model/UserModelTrait.php` | Add the `#[ORM\Init]` handler `init(EntityEvent, User)` — attaches a per-instance role loader (`fn (array $ids) => $em->getRepository(Role::class)->query()->whereIn('id', $ids)->get()`; empty-ids → `[]`) wired to the *event's* EM (decision 6). Deleted the `findByUsername`/`findByEmail`/`findByLogin`/`updateLogin`/`findRoles` statics + `static $cached`. Trait now carries only `init` + `saving` (Step 2). |
| `app/system/modules/user/src/Model/User.php` | Add `setRoleLoader(\Closure)` + private `$roleLoader`; `hasPermission()` rewritten off the loader (keeps the `$permissions` memo; loader-missing → `\LogicException` for an un-hydrated `new User()`). `jsonSerialize()` keeps its `['password', 'activation']` ignore list (the Step 1 `\Closure`-skip covers the new loader property). |
| `app/system/modules/user/src/Auth/UserProvider.php` | Ctor `(PasswordEncoderInterface $encoder, UserRepository $users)`; `find`/`findByUsername` via the repo; `findByCredentials` strips `password` then delegates to `$users->findByCredentials()`. The local non-`User` instanceof guard is dropped (Step 1 central hydration guard — decision 5). |
| `app/system/modules/user/src/Event/AuthorizationListener.php` | Inject `UserRepository`; `onSystemInit()` builds the `UserProvider` from the injected encoder + repo. |
| `app/system/modules/user/src/Event/UserListener.php` | Inject `UserRepository`; `onUserLogin()` → `updateLogin($user)` (skips non-`User`), `onRoleDelete()` → `removeRole((int) $role->id)` (was `User::updateLogin` / `User::removeRole`). |
| `app/system/modules/user/index.php` | `boot` wiring: `AuthorizationListener` (4th arg) and `UserListener` both receive `userRepository`. |
| `app/system/modules/user/src/UserModule.php` | Anonymous `user` service now built via `$app->get('userRepository')->create(['roles' => [Role::ROLE_ANONYMOUS]])` (was the `User` static create). (`userRepository`/`roleRepository` service definitions landed in Step 3.) |
| `app/system/src/Controller/AdminController.php` | Inject `userRepository` (system-core controller); user find + `save($user, …)` through the repo. |
| `app/system/modules/user/src/Controller/ProfileController.php` | Inject `userRepository`; profile find + save via the repo. |
| `app/system/modules/user/src/Controller/RegistrationController.php` | Inject `userRepository`; registration find/create/save via the repo. |
| `app/system/modules/user/src/Controller/ResetPasswordController.php` | Inject `userRepository`; token lookups + password save via the repo. |
| `app/system/modules/user/src/Controller/UserController.php` | Inject `userRepository` + generic `roleRepository` (`Repository<Role>`); user CRUD + role list via the repos. |
| `app/system/modules/user/src/Controller/UserApiController.php` | Inject `userRepository` + `roleRepository`; all find/create/save/delete + `query()` through the repos. |
| `app/system/modules/user/src/Controller/RoleApiController.php` | Inject generic `roleRepository` (`Repository<Role>`); role find/create/save/delete + `where(…)`. |
| `app/modules/debug/src/DataCollector/AuthDataCollector.php` | Inject nullable `UserRepository`; roles column via `$this->users->findRoles($user)` (was `User::findRoles`). |
| `app/modules/debug/index.php` | `boot` passes `$app->get('userRepository')` to the `AuthDataCollector`. |
| `phpstan-baseline.neon` | Pruned the now-stale entries referencing the deleted `UserModelTrait` static finders (removals only — never `--generate-baseline`). |

### Tests (Step 5)

Reworked off the singleton harness: the user `Tests/` directory now has **zero**
`RunInSeparateProcess` / `PreserveGlobalState` / `primeEntityManager*` (the
process-static EM singleton they isolated is no longer consumed here). Full suite green
at 622 tests, PHPStan L8 exit 0.

| File | Change |
|---|---|
| `app/system/modules/user/src/Tests/UserProviderTest.php` | Reworked: dropped process isolation + `primeEntityManager()`; mocks `UserRepository` directly (`find`/`findByUsername`/`findByCredentials` delegation, password-strip, `validateCredentials` order gate). Stale `User::where()` / `ModelTrait::getManager()` docblock references rewritten to the repository mechanism. |
| `app/system/modules/user/src/Tests/UserListenerTest.php` | Reworked for the new `UserRepository` ctor arg; `onUserLogin` → `updateLogin` (non-`User` skip) and `onRoleDelete` → `removeRole((int) id)` via a mocked repo. Stale `User::updateLogin()` / `User::removeRole()` docblock rewritten. |
| `app/system/modules/user/src/Tests/AuthorizationListenerTest.php` | Reworked for the new 4th ctor arg (`UserRepository`); `onSystemInit` asserts the built `UserProvider` carries the injected encoder + repo (Reflection). |
| `app/system/modules/user/src/Tests/UserTest.php` | Reworked the deferred-integration docblock; **added** the previously-deferred uncached `hasPermission()` / `hasAccess()` unit tests via an injected fake role loader (`setRoleLoader`), plus the memoization and missing-loader-guard (`\LogicException`) cases. |
| `app/system/modules/user/src/Tests/UserModelTraitTest.php` | Extended (created in Step 2 for `saving`): added `init()` coverage — the wired loader resolves roles through the event EM (`getRepository(Role::class)->query()->whereIn('id', $ids)->get()`) and short-circuits on empty ids (`never()` on `getRepository`). |

### Widget module migration — controllers + PositionHelper de-static (Step 6)

Third **consuming** step: both widget controllers, `PositionHelper` and the `boot`
role-delete closure now resolve the Step 3 `widgetRepository` (generic
`Repository<Widget>`) — plus the site `nodeRepository` and generic `roleRepository`
in the admin controller — instead of the `Widget`/`Node`/`Role` static
Active-Record API, and `PositionHelper`'s two function-statics become instance
properties (decision 8). The `EntityManager` singleton and the `ModelTrait` statics
stay in place (removed in Step 8), so the existing suite stays green.

| File | Change |
|---|---|
| `app/system/modules/widget/src/PositionHelper.php` | Inject `Repository<Widget>` positionally (5th ctor arg); the active-widget set loads via `$this->widgets->where(['status' => 1])->get()` (was the `Widget` static query). The two function-statics (decision 8) become instance properties, **renamed `$activeWidgets` / `$renderedPositions`** — the plan's `$widgets`/`$positions` names would now collide with the injected `$widgets` repo — so each per-request helper memoizes its own lookups. |
| `app/system/modules/widget/src/Controller/WidgetController.php` | Inject `widgetRepository` (`Repository<Widget>`) + `nodeRepository` (`NodeRepository`) + generic `roleRepository` (`Repository<Role>`); widget list via `array_values(findAll())`, node list via `nodeRepository->query()->get()`, `editAction` find/create through the repo, role list via `roleRepository->findAll()` (was the `Widget`/`Node`/`Role` statics). |
| `app/system/modules/widget/src/Controller/WidgetApiController.php` | Inject `widgetRepository` (`Repository<Widget>`); index grouping, `get`, save, delete and the copy loop all find/create/save/delete through the repo. The former static-listing no-op `instanceof` guard is **deleted** (decision 5). |
| `app/system/modules/widget/index.php` | `view.init` `PositionHelper` gains a 5th arg `widgetRepository`; the `boot` `model.role.deleted` closure → `$app->get('widgetRepository')->removeRole((int) $role->id)` (was `Widget::removeRole`). (The `widgetRepository` service *definition* landed in Step 3.) |

### Tests (Step 6)

The widget module had no unit tests before, so — unlike the Step 4/5 reworks —
there is no singleton harness to strip; a fresh `bootstrap.php` scaffolds the
module. Full suite green at 640 tests, PHPStan L8 exit 0.

| File | Change |
|---|---|
| `app/system/modules/widget/src/Tests/bootstrap.php` | **New.** `Pagekit\__()` translation stub + `require_once`s the widget source classes in dependency order — the widget module (`Pagekit\Widget\`) is runtime-loaded and absent from composer's autoload map (mirrors the blog/site Tests bootstrap). |
| `app/system/modules/widget/src/Tests/PositionHelperTest.php` | **New.** Active-widget set loaded through a mocked `Repository<Widget>` (`where(['status' => 1])->get()`); asserts query-once instance memoization and that separate helper instances load their own state (the per-request isolation the former function-static could not give — the reason the old code would have needed process isolation), plus the access/node/type render gate. Mocked repo/PositionManager/WidgetManager/View — no DB or booted view. |
| `app/system/modules/widget/src/Tests/WidgetControllerTest.php` | **New.** `indexAction` widget/node/type/menu listing + `editAction` create-for-type / find / assigned-position resolution / role listing / not-found, via mocked `Repository<Widget>` / `NodeRepository` / `Repository<Role>`. |
| `app/system/modules/widget/src/Tests/WidgetApiControllerTest.php` | **New.** `indexAction` position grouping + unassigned fall-through, `getAction`, `saveAction` create/validate/persist (+ reject-invalid, update-missing), `deleteAction`, `copyAction` clone-with-reset-identity — mocked `Repository<Widget>` + a real Symfony validator (attribute mapping, `NodeApiControllerTest` precedent) driving the `validateOrFail()` gate. |

### Blog package migration — controllers + PostListener + UrlResolver bridge swap + RC-3 (Step 7)

Fourth **consuming** step (the last module migration before the Step 8 sweep): all
four blog controllers, `PostListener` and the `UrlResolver` routing bridge now
resolve the Step 3 `postRepository` (`PostRepository`) / `commentRepository`
(generic `Repository<Comment>`) — plus a generic `roleRepository` in the admin
controller — instead of the `Post`/`Comment`/`Role` static Active-Record API, and
the `PostModelTrait` `updateCommentInfo`/`getAuthors` statics are deleted. The
`EntityManager` singleton and the `ModelTrait` statics stay in place (removed in
Step 8), so the existing suite stays green.

| File | Change |
|---|---|
| `packages/pagekit/blog/src/UrlResolver.php` | **Bridge swap.** Adds `private static ?PostRepository $posts` + `setPostRepository()` to the **existing** 2.5 routing bridge (the Router builds resolvers via `new $class`, no DI — same `TEMPORARY BRIDGE … Step 2.5` tag as the neighboring `setCache`/`setModule`). Both `Post::where()` calls (`match()` slug→id, `generate()` id→params) now run through `self::$posts->where(...)->first()`, null-guarded with a `\LogicException` when the bridge was never wired at boot; the local `instanceof` guards are dropped (decision 5). Scoped repo reference inside an already-flagged bridge — **not** a new global manager (see Deferred → Step 2.5). |
| `packages/pagekit/blog/index.php` | `boot` adds `UrlResolver::setPostRepository($app->get('postRepository'))` beside the existing `setCache`/`setModule` setters, and wires `new PostListener($app->get('postRepository'))`. (The `postRepository`/`commentRepository` service *definitions* landed in Step 3.) |
| `packages/pagekit/blog/src/Event/PostListener.php` | Inject `PostRepository`; `onCommentChange()` → `updateCommentInfo($comment->post_id)`, `onRoleDelete()` → `removeRole((int) $role->id)` (was the `Post` statics). Stays an `EventInterface` subscriber to the platform `model.comment.*` / `model.role.deleted` events (decision 3) — it reaches the DB through the **injected** repo, not `$event->getEntityManager()` like the Step 2 lifecycle handlers. |
| `packages/pagekit/blog/src/Model/PostModelTrait.php` | Deleted the `updateCommentInfo`/`getAuthors` statics (that logic moved to `PostRepository` in Step 3). Trait now carries only the two Step 2 `EntityEvent` lifecycle handlers (`saving` slug-suffixing, `deleting` `@blog_comment` cascade) + `use ModelTrait`. |
| `packages/pagekit/blog/src/Controller/BlogController.php` | Inject `postRepository` (`PostRepository`) + generic `roleRepository` (`Repository<Role>`); author list via `postRepository->getAuthors()`, `editAction` find/create through the repo, comment-index post lookup via `find()`, role list via `roleRepository->findAll()`. (`Post`/`Comment::getStatuses()` stay — pure static enums by design; the `editAction` role/author raw DBAL queries on the injected `Connection` are unchanged.) |
| `packages/pagekit/blog/src/Controller/PostApiController.php` | Inject `postRepository`; `indexAction` builds off `query()`, and get/save/delete/copy + the bulk variants all find/create/save/delete through the repo. |
| `packages/pagekit/blog/src/Controller/CommentApiController.php` | Inject generic `commentRepository` (`Repository<Comment>`) + `postRepository`; comment listing via `query()`/`where(...)`, save/delete plus the min-idle / approved-once / parent + post lookups through the two repos. |
| `packages/pagekit/blog/src/Controller/SiteController.php` | Inject `postRepository`; front-end index / feed / single-post lookups via `where(...)->related('user')` through the repo. |
| `packages/pagekit/blog/src/Migrations/2025/Version20251023070000_CreateBlogTables.php` | **RC-3 (docs-only).** The stale `AUDIT FIX Step 2.0.5` comment is rewritten as a permanent upgrade note: pre-rename installs carry the old `Version001_CreateBlogTables` id in `migration_versions`, which must be updated to this class name so the baseline schema is not re-applied on upgrade. No schema change. |

### Tests (Step 7)

Like the Step 6 widget module, the blog controllers / `PostListener` / `UrlResolver`
had no prior unit tests, so — unlike the Step 4/5 reworks — there is no singleton
harness to strip; the existing `tests/Unit/Blog/bootstrap.php` is extended for the
newly-tested classes. Full suite green at 674 tests, PHPStan L8 exit 0 — after an
in-step fix to `bootstrap.php` (a stray `*/` inside a docblock closed the comment
early and tripped a `ParseError` on the first Tester run).

| File | Change |
|---|---|
| `tests/Unit/Blog/bootstrap.php` | Extended with the dependency-ordered `require_once`s for `UrlResolver`, `PostListener`, the four controllers and the content module's `ContentHelper` (blog / base-comment / content are runtime-loaded, absent from composer's autoload map) alongside the existing `__()` / `Pagekit\__()` translation stubs. Fixed after a Tester `ParseError` (stray `*/` in a docblock). |
| `tests/Unit/Blog/PostListenerTest.php` | **New.** `onCommentChange` → `updateCommentInfo(post_id)` / `onRoleDelete` → `removeRole((int) id)` delegation (asserts the Role→int narrowing the database module requires) + the subscription map, via a mocked `PostRepository`. |
| `tests/Unit/Blog/UrlResolverTest.php` | **New.** `match()` slug→id and `generate()` id→params through the bridged `PostRepository` (mocked `QueryBuilder`), the never-wired-bridge `\LogicException` guard, the not-found paths, and the cached-entry short-circuit that never queries the repo. Resets the private bridge statics by reflection between tests. |
| `tests/Unit/Blog/PostApiControllerTest.php` | **New.** get / save (create + validate via a real Symfony validator) / delete (ownership gate) / copy (clone-with-reset-identity) driven through a mocked `PostRepository` / `QueryBuilder`. |
| `tests/Unit/Blog/CommentApiControllerTest.php` | **New.** delete / bulk-delete find+delete delegation and the save-action access gates (post-comments / manage-comments / not-found) asserted to precede any repository lookup, via mocked `Repository<Comment>` / `PostRepository`. |
| `tests/Unit/Blog/SiteControllerTest.php` | **New.** `postAction` not-found + access-denied guards on a repository-loaded post (mocked `PostRepository` / `QueryBuilder`). |
| `tests/Unit/Blog/BlogControllerTest.php` | **New.** `settingsAction` / `postAction` (authors + statuses) and `editAction` redirect-on-invalid-id / ownership-reject, via mocked `PostRepository` / `Repository<Role>` / `Router` / `MessageBag`. |

### Kill the singleton + ModelTrait statics + boot hack (RC-1) + sweep gates (Step 8)

Terminal step — the deletion sweep that resolves the Step 1–7 coexistence (trait
statics **and** repositories in parallel). Removes the last global state behind
row 1.11: the `EntityManager` singleton, the `ModelTrait` static Active-Record
API, `AccessModelTrait::removeRole()`, and the RC-1 eager `db.em` boot block. No
new behaviour (`test-writer: skip`) — the existing suite covers the deletions.
All five success gates confirmed by the Verifier; full suite green at **673 tests**
(674 → 673 — the deleted `testGetInstance()`), PHPStan L8 exit 0, and the
end-of-ticket **E2E green at 25/25** (installation, authentication, dashboard
specs). `phpstan-baseline.neon` needed no Step 8 prune (not in the changed set —
its stale static-finder entries were already removed in Step 5). The `db.em` gate
reads as *one registration + many lazy factory consumers* by design — see Key
Decisions.

| File | Change |
|---|---|
| `app/modules/database/src/ORM/EntityManager.php` | Deleted `private static ?self $instance`, the ctor `self::$instance = $this` assignment, `getInstance()`, and both `Step 2.1.11` TODO tags. The class is singleton-free — reachable only as the `db.em` service. |
| `app/modules/database/src/ORM/ModelTrait.php` | Reduced to a serialization/property trait: deleted `getManager()` + the static Active-Record API (`getConnection`/`getMetadata`/`create`/`query`/`where`/`find`/`findAll`) and the instance `save()`/`delete()`; dropped the Step 1 transitional `toArray()` fallback so it is **map-only** (a raw-`new` instance now throws `\LogicException` on serialize). Now carries only `use PropertyTrait`, `setSerializationMap()`, `toArray()`, `jsonSerialize()`. |
| `app/system/modules/user/src/Model/AccessModelTrait.php` | Deleted the static `removeRole()` SQL (ported to `Repository::removeRole(int)` in Step 1; the last caller — blog `PostListener` — migrated in Step 7). Trait now carries only the `roles` column + pure `hasRole()`/`hasAccess()`. |
| `app/system/index.php` | **RC-1.** Deleted the eager `db.em` boot block + its TODO comment — the `events.boot` closure no longer force-resolves the EntityManager (`db.em` is now resolved lazily by the repository factories). |
| `app/modules/database/src/Tests/ORM/EntityManagerTest.php` | Deleted `testGetInstance()` (its subject is gone). Suite count 674 → 673. |

### Coverage gap pass (Finalize)

Post-PR Codecov patch-gap closure — controller and collector tests for Step 5
user-module and debug call sites that lacked dedicated coverage after the
repository migration. CS-Fixer follow-up on anonymous-class spacing in the new
files and on `EntityManagerTest`.

| File | Change |
|---|---|
| `app/modules/debug/src/Tests/AuthDataCollectorTest.php` | **New.** `findRoles()` delegation via injected `UserRepository`; disabled/unauthenticated paths. |
| `app/system/modules/user/src/Tests/UserControllerTest.php` | **New.** Admin index/edit via mocked `UserRepository` / `Repository<Role>`. |
| `app/system/modules/user/src/Tests/UserApiControllerTest.php` | **New.** API find/create/save/delete via mocked repos. |
| `app/system/modules/user/src/Tests/RoleApiControllerTest.php` | **New.** Role CRUD via mocked `Repository<Role>`. |
| `app/system/modules/user/src/Tests/RegistrationControllerTest.php` | **New.** Registration find/create/save via mocked `UserRepository`. |
| `app/system/modules/user/src/Tests/ResetPasswordControllerTest.php` | **New.** Token lookup + password save via mocked `UserRepository`. |
| `app/system/modules/user/src/Tests/bootstrap.php` | Extended for controller test `require_once` chain. |
| `app/system/modules/user/src/Tests/pagekit-translation-stub.php` | **New.** Translation stub for user controller tests. |

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
- **`Node` now `use`s `ModelTrait` directly (Step 4).** Deleting the
  `use ModelTrait { find as modelFind }` alias from `NodeModelTrait` (its cached static
  finders are gone) also removed the *transitive* `ModelTrait` that `Node` had been
  inheriting for its instance serialization/persistence API (`toArray()` /
  `setSerializationMap()` / `save()` / `delete()`). Those are still needed until the
  Step 8 static sweep, so `ModelTrait` is applied to the `Node` class directly — a
  behavior no-op, but it explains the otherwise-surprising new `use` line on the entity.
- **`UserAccessTest` needed no rework in Step 5, despite the plan's "adjust seeding" note.**
  The plan listed `UserAccessTest` among the Step 5 test reworks (adjust its seeding to
  the role loader), but it is absent from the changed files. It exercises `hasAccess()`
  with a partial `User` mock that stubs `isAdministrator()` + `hasPermission()`, so it
  never reaches the new per-instance role loader — the `hasPermission()` rewrite is
  invisible to it and no seeding change was required. The new uncached-loader
  `hasPermission()` / `hasAccess()` coverage lives in `UserTest` instead.
- **The `db.em` sweep gate means one *registration*, not one textual hit (Step 8).**
  The plan's gate `rg -n 'db\.em' app/ packages/ → exactly 1 hit` reads literally,
  but every repository service resolves `$app->get('db.em')` lazily (decision 2 —
  `db.em` stays the sole registration, no `em` alias / top-level EM service), so the
  search actually returns the one definition (`app/modules/database/index.php:82`)
  plus the factory consumers in `SiteModule`, `UserModule`, and the widget/blog
  `index.php` files (and a `SiteModuleTest` stub). The Verifier confirmed the *intent*
  — one `$app->set('db.em', …)`, multiple consumers by design — which is how a
  maintainer should read the gate.

---

## ⚠️ Breaking Changes (Extensions)

**The static Active-Record model API is removed.** Extensions, themes, and custom
modules that call the old class-level statics now fatal with
`Error: Call to undefined method`. Deleted in Step 8:

- `Model::find()` / `findAll()` / `where()` / `query()` / `create()` and the instance
  `save()` / `delete()` (the `ModelTrait` API), `ModelTrait::getManager()`,
  `EntityManager::getInstance()`, and `AccessModelTrait::removeRole()`.

**Migration path** — resolve a repository through DI, or go through the injected
`db.em` EntityManager:

- Container services (controllers inject **by constructor-param name**):
  `nodeRepository`, `pageRepository`, `userRepository`, `roleRepository`,
  `widgetRepository`, `postRepository`, `commentRepository`.
- Generic access: `$app->get('db.em')->getRepository(Entity::class)` →
  `find()` / `findAll()` / `where()` / `query()` / `create()` / `save()` / `delete()`;
  role cleanup via `->getRepository(Entity::class)->removeRole((int) $roleId)`.

**Serialization now requires hydration.** `toArray()` / `jsonSerialize()` stay on
entities but read a map injected by `EntityManager::load()`; a raw `new Entity(...)`
that is then serialized throws `\LogicException` (no serialization map). Likewise
`User::hasPermission()` needs a hydrated `User` (its role loader is attached at load)
— a manually-`new`-ed `User` throws.

---

## ⚠️ Risks & Rollout Notes

**Extension breakage on upgrade.** Any third-party module still calling
`Model::find()` / `save()` / `EntityManager::getInstance()` will fatal
immediately after upgrade — see Breaking Changes. Core and bundled packages are
fully migrated; extension authors must switch to container repository services
or `$app->get('db.em')->getRepository()`.

**`NodeRepository` cache semantics unchanged.** Request-cache invalidation on
save remains deferred to Step 4.3 — same behaviour as the former static cache.

**`UrlResolver` bridge survives this step.** The `setPostRepository()` static
bridge is tagged `TEMPORARY BRIDGE — Step 2.5`; routing factory DI is still
out of scope.

---

## 🔐 Security & Data Impact

**Auth chain now repository-backed.** `UserProvider`, login listeners, and all
user controllers resolve users through `UserRepository` — no static finders.
`User::hasPermission()` uses a per-instance role loader attached at hydration
(`#[ORM\Init]`); un-hydrated `new User()` throws `\LogicException` instead of
silently querying. The cross-user `static $cached` role cache is deleted.

**SQL injection surface reduced.** `UserRepository::findRoles` uses
`whereIn('id', …)` instead of string-interpolated `IN` clauses.

**No schema or migration changes.** RC-3 in the blog baseline migration is
docs-only (upgrade note for pre-rename installs).

---

## 🛡️ No-Mercy Compliance

| Rule | How satisfied |
|---|---|
| **1 — No compatibility layers** | Step 1–7 intra-PR coexistence (trait statics + repos) deleted in Step 8; no parallel APIs survive. |
| **2 — No adapters** | All call sites updated to inject repositories directly; no wrapper shims. |
| **3 — Breaking changes allowed internally** | Static AR API removed; platform helpers (`__()`, etc.) untouched. |
| **4 — Delete over wrap** | Dead `findByLogin`, per-site `instanceof` guards, `AccessModelTrait::removeRole`, RC-1 boot block — all deleted, not wrapped. |
| **5 — Flagging & audit debt** | RC-1/RC-2/RC-3 resolved; `UrlResolver::setPostRepository` tagged `TEMPORARY BRIDGE — Step 2.5`. `IntlServiceLocator` explicitly excluded from sweep gates. |

One pre-existing bridge extended (`UrlResolver`), not a new global manager —
per ticket Bridges spec.

---

## ✅ Verification (links only)

| Gate | Result |
|---|---|
| PHPUnit (Step 8 local) | 673 tests — 0 failures |
| PHPStan L8 (Step 8 local) | PASS |
| CI — cs-fixer | ✅ success |
| CI — phpstan | ✅ success |
| CI — phpunit (8.2) | ✅ success |
| CI — phpunit (8.3) | ✅ success |
| CI — security-audit | ✅ success |
| Coverage gap pass | ✅ added controller/collector tests + cs-fixer follow-up |
| Cursor Bugbot | ✅ pass (no findings) |
| E2E installation.spec.js | 1/1 passed |
| E2E authentication.spec.js | 14/14 passed |
| E2E dashboard.spec.js | 10/10 passed |

**CI run:** https://github.com/Shadesman5/pagekit/actions/runs/29337835999

**Finalize fix-loop:** cs-fixer on `EntityManagerTest` anonymous class; cs-fixer
on coverage-gap test files.

**Notable deviations:** `UserAccessTest` needed no rework (plan listed it —
see Key Decisions). `db.em` sweep gate reads as one registration + lazy factory
consumers, not a literal single hit (see Key Decisions). `UserProviderTest`
non-`User` guard absorbed one step early into the Step 1 central hydration
guard.

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

- **Step 4.3 (Performance Optimization)** — `EntityManager::invalidateCache()`,
  `NodeRepository` cache invalidation on save, role-cache reintroduction.
- **Step 2.5 (Extension Safety System)** — `UrlResolver` static bridge removal
  (incl. `setPostRepository()` added here), `RouteListener` permalink static,
  routing factory DI.
- **Step 2.1.12 ([#217](https://github.com/Shadesman5/pagekit/issues/217))** —
  `NodeController::$site` narrowing, `DataModelTrait::$data` typing, captcha
  accessors.
- **Step 4.2 (REST API v2)** — raw-entity JSON responses keep
  `jsonSerialize()` for now; API v2 must use presenters/DTOs.
- **`IntlServiceLocator`** — permanent narrow platform locator for global
  `__()`; deliberately outside Step 8 sweep gates.
- **Doctrine ORM swap** — non-goal; Pagekit's ORM only.

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/active/PROMPT_2_1_11_EntityManager-DI_plan.md` (→ move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_11_EntityManager-DI.md`
- Predecessor: Step 2.1.10 — Entity Presentation Layer (`step-2-1-10-entity-presentation-layer.md`)
- Successor: Step 2.1.12 ([#217](https://github.com/Shadesman5/pagekit/issues/217)) — Property typing & captcha accessors
