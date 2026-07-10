# Step 2.1.10: Entity Presentation Layer (ModelServiceLocator → DTO/Presenter)

<!-- conductor-mode: full -->

**ROADMAP:** 2.1.10. GitHub Issue: #204. Reference: `@ROADMAP.md`.

---

## CONTEXT

- **Depends on:** Step 2.1.6 (PHPStan Level 7→8) — `ModelServiceLocator::getUrl()` / `getUser()` / `getModule()` return types are already narrowed to concrete types (`UrlProvider`, `User`, `ModuleInterface|null`). This step removes the locator entirely.
- **Risk:** Medium-High — touches entity serialization, menu rendering, blog front-end, and every API endpoint that returns `Node` / `Post` entities.
- **Closes Phase 1 audit:** **Step 1.11 (ORM Modernization)** — **partial:** removes the `ModelServiceLocator` static service-locator finding; **1.11 stays ⚠️** until the `EntityManager` singleton is removed in **Step 2.1.11**.
- **Background:** `ModelServiceLocator` is the last static service locator in the model layer, tagged in-code:

```php
// TODO: Must be refactored in Step 2.1.10 (Entity Presentation Layer) — replace ModelServiceLocator with proper DTO/presenter pattern (GitHub #204)
```

**Goal:** Delete `ModelServiceLocator` and move presentation/infrastructure concerns out of `Node` / `Post` into DI-based presenters (or explicit method parameters). Entities become pure domain/persistence objects; API and view serialization runs through injected presenters.

---

## 0. SAFETY CHECKS (CRITICAL)

**Before starting — verify all of these:**

1. **Branch up-to-date with `develop`** (Step 2.1.6 merged).
2. **Full suite green:**
   ```bash
   ./app/vendor/bin/phpunit
   ./app/vendor/bin/phpstan analyse
   ```

**After every checklist batch:**

```bash
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse
```

**IF ANY FAILS → STOP AND FIX!**

---

## 1. DISCOVERY — TRACE ALL CALLERS (Architect mandatory)

The Architect **must** run these scans at ticket-planning time and enumerate every call site in the ticket checklist. Do **not** rely on this prompt's list alone — reconcile with a fresh `rg` pass.

```bash
# The locator itself + wiring
rg -n "ModelServiceLocator" app/ packages/

# Node presentation concerns
rg -n "->getUrl\(|->isAccessible\(|Node::" app/system/modules/site/ packages/pagekit/theme-one/ app/system/modules/widget/

# Post presentation concerns
rg -n "->isCommentable\(|->isAccessible\(|Post::" packages/pagekit/blog/

# Entity JSON API path (JsonResponseListener auto-serializes JsonSerializable returns)
rg -n "JsonSerializable|jsonSerialize" app/system/modules/site/src/Model/ packages/pagekit/blog/src/Model/
rg -n "return \$node|return \$post|return \$nodes|return \$entity" app/system/modules/site/src/Controller/ packages/pagekit/blog/src/Controller/
```

**Known production touchpoints (verify + extend at planning time):**

| Concern | Current location | Services needed |
|---------|------------------|-----------------|
| Node URL | `Node::getUrl()` | `UrlProvider` |
| Node API JSON (`url`, `accessible`) | `Node::jsonSerialize()` + `$properties['accessible']` | `UrlProvider`, `User` |
| Post API JSON (`url`, `accessible`, `comments_pending`) | `Post::jsonSerialize()` + `$properties` | `UrlProvider`, `User` |
| Post comment gate | `Post::isCommentable()` | blog `Module` config |
| Post/page access gate | `Post::isAccessible()`, `Node::isAccessible()` | `User` |
| PHP menu templates | `system/site/menu.php`, `widget-menu.php`, `theme-one/views/menu-navbar.php` | `UrlProvider` (via presenter) |
| Menu active-path logic | `MenuHelper::getRoot()` (`$node->getUrl('base')`) | `UrlProvider` (via presenter) |
| Blog front templates | `blog/views/posts.php`, `theme-one/views/blog/posts.php` | blog config (via presenter) |
| Blog controllers | `SiteController`, `CommentApiController`, `PostApiController`, `NodeApiController` | presenters at API boundary |
| Locator init | `SiteModule::main()` → `ModelServiceLocator::init($app)` | **delete** |

**JSON API mechanism:** `Pagekit\Kernel\Event\JsonResponseListener` wraps any controller return value that is `array` or `JsonSerializable` in a `JsonResponse`. `NodeApiController` and `PostApiController` currently return raw entities — their `jsonSerialize()` overrides are the API contract. After this step, controllers must return presenter output (arrays or dedicated DTOs), not entity `jsonSerialize()` with injected services.

---

## 2. ARCHITECTURE — PRESENTER LAYER (recommended design — the Architect may refine)

The design below is a recommendation. Class names, method signatures, and presenter-vs-parameter injection may be adjusted, provided no static locator remains in the model layer and the existing API/view output is preserved.

### 2.1. Target classes

Introduce **constructor-DI presenters** (names may vary; keep them discoverable):

| Class | Module | Injected dependencies (minimum) |
|-------|--------|----------------------------------|
| `NodePresenter` | `app/system/modules/site/` | `UrlProvider`, `User` |
| `PostPresenter` | `packages/pagekit/blog/` | `UrlProvider`, `User`, blog `Module` (or `ModuleManager` + module name) |

**No static locators. No container passed as God-DI.** Register presenters as container services (`site/index.php`, `blog/index.php`) and inject into controllers/helpers.

### 2.2. Presenter responsibilities

Move these **out of the entities** into presenters (or explicit parameters — presenter is preferred for API parity):

**`NodePresenter`**

- `getUrl(Node $node, int|string $referenceType = UrlGenerator::ABSOLUTE_PATH): string|false`
  - Type `$referenceType` as `int|string` — match `UrlProvider::get()` (`BASE_PATH` string + int `LINK_URL=100` / `UrlGenerator` constants).
- `isAccessible(Node $node, ?User $user = null): bool` — status + `hasAccess()` with injected current user as default
- `toArray(Node $node): array` — API shape currently produced by `Node::jsonSerialize()`:
  - All scalar fields via `$node->toArray()` **without** virtual `accessible` calling entity locator
  - Add computed `url` (base path) and `accessible` from presenter methods

**`PostPresenter`**

- `isCommentable(Post $post): bool` — blog `comments.autoclose` / `comments.autoclose_days` logic (today in `Post::isCommentable()`)
- `isAccessible(Post $post, ?User $user = null): bool`
- `toArray(Post $post): array` — API shape currently produced by `Post::jsonSerialize()`:
  - `url` via `@blog/id` route
  - `comments_pending` when comments relation loaded
  - virtual properties `author`, `published`, `accessible` (preserve existing API field names)

### 2.3. Entity cleanup (Aggressive Rule 4: Delete over Wrap)

From `Node` / `Post`:

- **Delete** `getUrl()`, `isAccessible()`, `jsonSerialize()` overrides (and `isCommentable()` on `Post`)
- **Remove** `$properties` entries that only existed to feed locator-backed methods (`accessible` on both; keep `author` / `published` on `Post` only if they remain pure domain — `getAuthor()` / `isPublished()` do not use the locator and may stay on the entity, but API output must come from the presenter)
- **Remove** `use Pagekit\Site\ModelServiceLocator`
- **Remove** `implements \JsonSerializable` from `Node` / `Post` **if** no override remains (they inherit `ModelTrait::jsonSerialize()` → plain `toArray()` without `url`). Confirm no caller relies on the enriched entity JSON outside presenter-wired paths.

From the site module:

- **Delete** `app/system/modules/site/src/ModelServiceLocator.php`
- **Delete** `ModelServiceLocator::init($app)` from `SiteModule::main()`

### 2.4. Call-site migration patterns

**API controllers** (`NodeApiController`, `PostApiController`, and any other controller returning entities to the admin JSON API):

- Inject the matching presenter
- Map entities before return: `array_map($presenter->toArray(...), $nodes)` or single `$presenter->toArray($node)`
- Return `array` (or a typed DTO implementing `JsonSerializable` **without** reaching into entities for services — optional; plain `array` is fine)

**`MenuHelper`:**

- Inject `NodePresenter` (or `UrlProvider` + `User` if the Architect keeps URL-only helper methods)
- Pass presenter into view parameters **or** resolve URLs inside the helper before `getRoot()` path-matching (`getUrl('base')` logic at `MenuHelper.php:107`)
- Update `system/site/menu.php`, `system/site/widget-menu.php`, and `packages/pagekit/theme-one/views/menu-navbar.php` to use the presenter (e.g. `$nodePresenter->getUrl($node)`) — **do not** leave broken `$node->getUrl()` calls

**Blog front / comment gate:**

- `SiteController`, `CommentApiController`: inject `PostPresenter`; replace `$post->isCommentable()` / `$post->isAccessible()` with presenter calls
- PHP views (`blog/views/posts.php`, `theme-one/views/blog/posts.php`): receive a boolean from the controller or a view variable from a presenter — **no** entity method that reaches services

**Widget admin JSON** (`WidgetController` returns `Node::query()->get()`): trace whether those nodes are JSON-serialized to the Vue admin. If yes, map through `NodePresenter` at the controller.

### 2.5. Explicit non-goals (defer)

- **`EntityManager` singleton / Active-Record `Model::find()`** — Step 2.1.11 (#205). Do not scope singleton removal here.
- **`IntlServiceLocator`** — separate permanent narrow locator for global `__()` functions; out of scope.
- **`blog/UrlResolver` static cache** — blocked on routing factory DI (Step 1.8); out of scope.
- **REST API v2 (Step 4.2)** — must *consume* these presenters later; do not build parallel serialization.

---

## 3. WORK ITEMS (non-exhaustive)

The §1 touchpoints are a starting set. Build the checklist from a fresh caller trace and add any call site it surfaces.

- Discovery/inventory of every caller (file:line)
- `NodePresenter` + service registration + unit tests
- `PostPresenter` + service registration + unit tests
- Site-module call-site migration (entity cleanup, `NodeApiController`, `MenuHelper`, menu/widget views, `WidgetController` if its nodes are serialized)
- Blog-package call-site migration (entity cleanup, `PostApiController`, `SiteController`, `CommentApiController`, blog/theme views)
- Delete `ModelServiceLocator` + its `SiteModule` init wiring
- Regression/edge-case tests + PHPStan baseline check

---

## 4. TESTING

### 4.1. PHPUnit (required)

There are **no** existing `NodeTest` / `PostTest` files — add focused unit tests for the presenters:

- **NodePresenter:** `getUrl()` with a stub/mock `UrlProvider`; `isAccessible()` with published/unpublished node + user with/without role; `toArray()` includes `url` + `accessible` keys matching pre-refactor shape.
- **PostPresenter:** `isCommentable()` with autoclose enabled/disabled; `toArray()` `url` uses `@blog/id`; `comments_pending` when comments loaded.

Use in-memory stubs — **do not** require a full kernel boot for unit tests unless an integration test is explicitly justified.

### 4.2. PHPStan

- `./app/vendor/bin/phpstan analyse` — zero new baseline entries.
- `Node::getUrl(mixed)` must not survive; presenter uses `int|string`.

### 4.3. Playwright E2E (Final Test)

Run the 3 sound E2E specs after CI is green. Pay special attention to:

- Admin site/node API (nodes list/detail still expose `url` + `accessible`)
- Blog post API + public blog pages (post `url`, comment form visibility)
- Front-end menus (links render correctly)

---

## 5. AGGRESSIVE MODERNIZATION RULES

1. **NO COMPATIBILITY LAYERS** — do not keep `ModelServiceLocator` "just for extensions". Delete it.
2. **NO ADAPTERS** — do not wrap the locator; update every call site.
3. **DELETE OVER WRAP** — remove entity methods that hide service access; no `@deprecated` shim on `Node::getUrl()`.
4. **INTERNAL BREAKING CHANGES ALLOWED** — entity public API may shrink; update all internal callers in the same PR. Extension authors who called `Node::getUrl()` must use injected services/presenters (document in branch doc if any extension-facing surface changes).
5. **NO NEW BRIDGES** — do not introduce a second static locator "temporarily".

---

## AUDIT FINDINGS (Phase 1 Review — scoped to this step)

- **`ModelServiceLocator`** — static service locator in the model layer; Step 2.1.6 type-narrowed only; **this step removes it** and closes the last 1.11 locator finding.
- **`Node::getUrl(mixed $referenceType)`** — residual `mixed` from 2.1.6 audit; fixed by relocating to presenter as `int|string`.
- **`#[AllowDynamicProperties]` on Node** — removed in 2.1.6; do not reintroduce dynamic properties on entities.
- **Sequencing P6 / SL-1** — any 4.2 work must use presenters from this step, not entity `jsonSerialize()`.

---

## SUCCESS CRITERIA

- `ModelServiceLocator.php` is **deleted**; `rg ModelServiceLocator app/ packages/` returns **zero** hits
- `Node` / `Post` contain **zero** imports or calls to `ModelServiceLocator`
- Presentation logic (`getUrl`, `isAccessible`, `isCommentable`, enriched JSON) lives in DI-based presenters
- Admin JSON API responses for nodes/posts still include `url`, `accessible`, and post-specific computed fields (no regressions)
- Front-end menus and blog comment gates behave as before
- PHPUnit + PHPStan pass; no new baseline entries
- Playwright E2E (3 specs) pass at Final Test

---

## VALIDATION CHECKLIST

_Acceptance bar — not the full checklist._

- [ ] Caller inventory documented in ticket (fresh `rg` at planning time)
- [ ] `NodePresenter` registered and covered by unit tests
- [ ] `PostPresenter` registered and covered by unit tests
- [ ] `Node` entity: locator methods removed; `mixed $referenceType` gone
- [ ] `Post` entity: locator methods removed
- [ ] `NodeApiController` / `PostApiController` return presenter arrays (not locator-backed `jsonSerialize()`)
- [ ] `MenuHelper` + menu views migrated (`getUrl` via presenter)
- [ ] Blog controllers + views migrated (`isCommentable` / `isAccessible` via presenter)
- [ ] `SiteModule::main()` no longer calls `ModelServiceLocator::init()`
- [ ] `ModelServiceLocator.php` deleted
- [ ] `./app/vendor/bin/phpunit` passes
- [ ] `./app/vendor/bin/phpstan analyse` passes (no new baseline entries)
- [ ] Playwright E2E (3 specs) pass
- [ ] Branch doc notes any extension-facing API change for `Node::getUrl()` removal
- [ ] ROADMAP Finalize: Step 1.11 audit cell **stays ⚠️** (partial — `ModelServiceLocator` removed here; 1.11 flips to 🛡️ only with the `EntityManager` singleton removal in Step 2.1.11)
