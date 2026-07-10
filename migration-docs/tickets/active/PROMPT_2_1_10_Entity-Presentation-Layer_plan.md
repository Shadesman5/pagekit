## ARCHITECT OUTPUT

- **Current Step (ROADMAP):** 2.1.10 (Entity Presentation Layer — `ModelServiceLocator` → DTO/Presenter). GitHub Issue: #204. Predecessor Step 2.1.9 merged (PR #218); Step 2.1.6 (PR #212) already type-narrowed the locator. `<!-- conductor-mode: full -->` (full modernization ticket). **Closes Phase 1 audit Step 1.11 — PARTIAL:** removes the `ModelServiceLocator` static-service-locator finding; **1.11 stays ⚠️** at Finalize (flips to 🛡️ only when the `EntityManager` singleton is removed in Step 2.1.11).

- **Scope (files + modules):**
  - **New — presenters (constructor-DI, no static state):**
    - `app/system/modules/site/src/NodePresenter.php` → `Pagekit\Site\NodePresenter` (`getUrl`, `isAccessible`, `toArray`). Deps: `UrlProvider`, `User`.
    - `packages/pagekit/blog/src/PostPresenter.php` → `Pagekit\Blog\PostPresenter` (`isCommentable`, `isAccessible`, `toArray`). Deps: `UrlProvider`, `User`, blog `Module`.
  - **New — tests:** `app/system/modules/site/src/Tests/NodePresenterTest.php`; `tests/Unit/Blog/PostPresenterTest.php` + `tests/Unit/Blog/bootstrap.php` (see **DI + test constraints** below).
  - **Site module:** `src/Model/Node.php` (entity cleanup), `src/SiteModule.php` (register `nodePresenter`, remove locator init), `src/MenuHelper.php` (inject presenter; URL pre-resolution), `index.php` (MenuHelper instantiation), `src/Controller/NodeApiController.php` (presenter arrays), `views/menu.php`, `views/widget-menu.php`.
  - **Blog package:** `src/Model/Post.php` (entity cleanup), `index.php` (register `postPresenter`), `src/Controller/PostApiController.php` (presenter arrays), `src/Controller/CommentApiController.php` (map `$posts` + comment gate), `src/Controller/SiteController.php` (comment `enabled` + list-view commentable flag), `views/posts.php`.
  - **Theme-one package:** `views/menu-navbar.php`, `views/blog/posts.php`.
  - **Delete:** `app/system/modules/site/src/ModelServiceLocator.php`.

- **DI + test constraints (verified — critical, non-obvious):**
  - **Controller DI is BY PARAMETER NAME.** `ControllerResolver::instantiateController()` resolves each constructor arg via `$container->has($paramName)` / `->get($paramName)` (no type autowiring, no FQCN fallback). Therefore register the presenters under the container names **`nodePresenter`** / **`postPresenter`** and name every injected controller param **`$nodePresenter`** / **`$postPresenter`**.
  - **Registration:** `nodePresenter` via `$app->set('nodePresenter', fn ($app) => new NodePresenter($app->get('url'), $app->get('user')))` in `SiteModule::main()`. `postPresenter` via `$app->set('postPresenter', fn ($app) => new PostPresenter($app->get('url'), $app->get('user'), $app->get('module')->get('blog')))` in the blog `index.php` `events.boot` closure. Factory closures defer resolution to request time (services available).
  - **`MenuHelper` is instantiated manually** in `site/index.php` `view.init` (`new MenuHelper($app->get('menu'), $app->get('user'), $app->get('node'))`) — pass `$app->get('nodePresenter')` positionally as a 4th arg (param name free).
  - **Blog is NOT in `composer.json` autoload** (`Pagekit\Blog\` unmapped — runtime package) and **`packages/` is NOT in the PHPUnit testsuite** (`phpunit.xml.dist` covers only `app/modules/*/src/Tests`, `app/system/modules/*/src/Tests`, `tests/Unit`). ⇒ The `NodePresenter` test lives in the site `src/Tests` dir (autoloaded, in-suite). The `PostPresenter` test **must** live under `tests/Unit/Blog/` with a local `bootstrap.php` that `require_once`s the non-autoloaded blog classes (`Post`, `PostModelTrait`, `Comment` + parent/traits as the require-chain needs, `PostPresenter`) — mirror `tests/Unit/Package/bootstrap.php` + `tests/Unit/Console/bootstrap.php`.

- **Serialization mechanism (verified) — why the entity cleanup is safe:**
  - `Node`/`Post` get `jsonSerialize()`+`toArray()` from `ModelTrait` (via `NodeModelTrait`/`PostModelTrait`). `ModelTrait::toArray()` calls `PropertyTrait::getProperties($this)`, which **eagerly invokes every virtual accessor** in the `protected static $properties` map (Node: `accessible`⇒`isAccessible`; Post: `author`⇒`getAuthor`, `published`⇒`isPublished`, `accessible`⇒`isAccessible`). So the locator is reached through `$properties['accessible']` even when a caller seeds `accessible` in `$data`. ⇒ Removing the locator **requires** deleting `accessible` from `$properties` on both entities (and deleting `isAccessible`). `getAuthor`/`isPublished` are pure (no locator) and **stay** on `Post`; keep `author`/`published` in `Post::$properties`.
  - **Keep `implements \JsonSerializable`** on both entities (Architect refinement of prompt §2.3): after deleting the enriched overrides the inherited `ModelTrait::jsonSerialize()` (= plain `toArray()`) still satisfies the interface, matching every other ORM entity (`Page`, `Widget`, `Comment`, …). Removing the interface from only these two would be inconsistent and risks `get_object_vars` serialization if any residual path passes a raw entity.
  - **Only 3 JSON boundaries consume the enriched fields** (`url`/`accessible`/`comments_pending`) and thus need presenter mapping — verified against the Vue/admin views:
    - `NodeApiController::indexAction/getAction/saveAction` → `site/views/admin/index.php` uses `item.url`, `item.accessible`; `site/app/views/index.js:374` uses `node.url`.
    - `PostApiController::indexAction/getAction/saveAction` → `blog/views/admin/post-index.php:81-83` uses `post.url`, `post.accessible`.
    - `CommentApiController::indexAction` `$posts` → `blog/views/admin/comment-index.php:73` uses `post.url`, `post.accessible`.
  - **No change needed** for `WidgetController` (`$data.config.nodes`) or `BlogController::editAction` (`$data.post`): their Vue consumers use only plain columns (`node.title/id/menu`, `post.title/slug/status/date/data.*/comment_status/roles`) — verified — so the inherited plain `jsonSerialize()` covers them with no regression.

- **Deferred (out of scope — flag, do not touch):**
  - **Step 2.1.11 (#205):** `EntityManager` singleton (`static::$instance`/`getInstance()`), `NodeModelTrait` static `$nodes` cache (`app/system/modules/site/src/Model/NodeModelTrait.php:18` — its `Must be refactored later` flag gets its `Step 2.1.11` ID there, not here), and the `db.em` boot line (`app/system/index.php`). This ticket removes **only** the presentation locator.
  - **`IntlServiceLocator`** — permanent narrow locator for global `__()`; out of scope.
  - **`blog/UrlResolver` static cache** — blocked on routing factory DI (Step 1.8/2.5); out of scope.
  - **REST API v2 (Step 4.2)** — must *consume* these presenters later (P6 / SL-1 guardrail); do not build parallel serialization.

- **Bridges:** **None.** All changes are complete migrations/deletions within this one PR (Aggressive Rule 4: Delete over Wrap). No new static locator or temporary bridge is introduced (Rule 5). No `// TODO` bridge tags are added by this ticket.

- **Audit-findings compliance (prompt "AUDIT FINDINGS"):** (a) `ModelServiceLocator` deleted; (b) `Node::getUrl(mixed)` relocated to `NodePresenter::getUrl(Node, int|string)` — **no `mixed` survives**; (c) **do NOT reintroduce `#[AllowDynamicProperties]`** — the transient view values use the `DataModelTrait` `data` bag (`$node->set('url', …)`/`$node->get('url')`, `$post->set('commentable', …)`/`$post->get('commentable')`), the same mechanism already used for `active`, **not** dynamic properties; (d) 4.2 must use these presenters (recorded under Deferred).

- **Full caller inventory (fresh `rg` at planning time — build the checklist from this, not the prompt's list alone):**
  - **`ModelServiceLocator` refs:** class file `app/system/modules/site/src/ModelServiceLocator.php`; `Node.php:9` (import), `:96` (`getUrl`→`getUrl()`), `:101` (`isAccessible`→`getUser`); `Post.php:8` (import), `:130` (`isCommentable`→`getModule`), `:151` (`isAccessible`→`getUser`), `:162` (`jsonSerialize`→`getUrl`); `SiteModule.php:24` (`init`).
  - **`Node::getUrl()`:** `Node::jsonSerialize()` (self, deleted); `MenuHelper.php:107` (`getUrl('base')`); `views/menu.php:8`, `views/widget-menu.php:8`, `theme-one/views/menu-navbar.php:8`.
  - **`Node` → JSON:** `NodeApiController::indexAction` (`return $nodes`), `getAction` (`return $node`), `saveAction` (`'node' => $node`). (`WidgetController` nodes — plain, no change.)
  - **`Post::isCommentable()`:** `SiteController.php:194`, `CommentApiController.php:240`, `blog/views/posts.php:25`, `theme-one/views/blog/posts.php:28`.
  - **`Post::isAccessible()`:** no direct callers (only via `$properties['accessible']`). `hasAccess()`/`isPublished()` are used directly and **stay**.
  - **`Post` → JSON:** `PostApiController::indexAction` (`compact('posts',…)`), `getAction` (`return ?Post`), `saveAction` (`'post' => $post`); `CommentApiController::indexAction` (`$posts` from related posts). (`BlogController::editAction` `$data.post` — plain, no change.)

- **Checklist:**

  1. **`NodePresenter` + service registration + unit test.**
     - Add `app/system/modules/site/src/NodePresenter.php` (`namespace Pagekit\Site`), constructor `(private readonly UrlProvider $url, private readonly User $user)`:
       - `getUrl(Node $node, int|string $referenceType = UrlGenerator::ABSOLUTE_PATH): string|false` → `$this->url->get($node->link, [], $referenceType)`.
       - `isAccessible(Node $node, ?User $user = null): bool` → `(bool) ($node->status && $node->hasAccess($user ?? $this->user))`.
       - `toArray(Node $node): array` → `$node->toArray(['url' => $this->getUrl($node, UrlProvider::BASE_PATH), 'accessible' => $this->isAccessible($node)])` (reproduces the pre-refactor `Node::jsonSerialize()` shape exactly).
     - Register `nodePresenter` in `SiteModule::main()` (additive — keep `ModelServiceLocator::init($app)` for now).
     - Add `app/system/modules/site/src/Tests/NodePresenterTest.php` (require the existing `bootstrap.php`): `getUrl()` with a mocked `UrlProvider`; `isAccessible()` for published/unpublished node × user with/without access; `toArray()` includes `url`+`accessible` keys (mock `Node::toArray()`/`hasAccess()` + set public props to stay kernel-free). No entity edits yet → suite stays green.

  2. **`PostPresenter` + service registration + unit test.**
     - Add `packages/pagekit/blog/src/PostPresenter.php` (`namespace Pagekit\Blog`), constructor `(private readonly UrlProvider $url, private readonly User $user, private readonly Module $blog)`:
       - `isCommentable(Post $post): bool` → `$autoclose = $this->blog->config('comments.autoclose') ? $this->blog->config('comments.autoclose_days') : 0; return (bool) ($post->comment_status && (!$autoclose || $post->date >= new \DateTime("-{$autoclose} day")));` (blog `Module` is injected non-null → drop the old null branch).
       - `isAccessible(Post $post, ?User $user = null): bool` → `$post->isPublished() && $post->hasAccess($user ?? $this->user)`.
       - `toArray(Post $post): array` → build `['url' => $this->url->get('@blog/id', ['id' => $post->id ?: 0], UrlProvider::BASE_PATH)]`; add `comments_pending` when `$post->comments` is loaded (count `Comment::STATUS_PENDING`); add `accessible => $this->isAccessible($post)`; `return $post->toArray($data)` (reproduces pre-refactor `Post::jsonSerialize()` + the `$properties['accessible']` field; `author`/`published` still flow from the retained entity `$properties`).
     - Register `postPresenter` in the blog `index.php` `events.boot` closure (additive).
     - Add `tests/Unit/Blog/PostPresenterTest.php` + `tests/Unit/Blog/bootstrap.php` (require_once the non-autoloaded blog classes — see DI + test constraints): `isCommentable()` autoclose enabled/disabled; `toArray()` `url` uses `@blog/id`, `comments_pending` present only when comments loaded, `accessible` key present; `isAccessible()` published × access. No entity edits yet → suite stays green.

  3. **Site call-site migration to `NodePresenter` (entity untouched).**
     - `NodeApiController`: inject `$nodePresenter`; `indexAction` → `array_map(fn (Node $n) => $this->nodePresenter->toArray($n), $nodes)` (return `array<int, array<string,mixed>>`); `getAction` → `return $this->nodePresenter->toArray($node)` (return `array`); `saveAction` → `'node' => $this->nodePresenter->toArray($node)`. Update method return types + `@return` docblocks to the array shapes (no baseline additions).
     - `MenuHelper`: add `NodePresenter $nodePresenter` (4th constructor param); replace `MenuHelper.php:107` `$node->getUrl('base')` → `$this->nodePresenter->getUrl($node, UrlProvider::BASE_PATH)`; in the node loop (beside `$node->set('active', …)`) add `$node->set('url', $this->nodePresenter->getUrl($node))`. Update the `new MenuHelper(...)` call in `site/index.php` to pass `$app->get('nodePresenter')`.
     - Views `app/system/modules/site/views/menu.php:8`, `views/widget-menu.php:8`, `packages/pagekit/theme-one/views/menu-navbar.php:8`: `$node->getUrl()` → `$node->get('url')`.
     - `Node` entity remains intact this step (its enriched `jsonSerialize`/`getUrl`/`isAccessible` are now only reachable via `WidgetController` raw-node serialization; locator still present) → suite stays green.

  4. **Blog call-site migration to `PostPresenter` (entity untouched).**
     - `PostApiController`: inject `$postPresenter`; `indexAction` → map `$posts` through `$this->postPresenter->toArray(...)` before `compact(...)`; `getAction` → return `$post ? $this->postPresenter->toArray($post) : null`; `saveAction` → `'post' => $this->postPresenter->toArray($post)`. Update return types/docblocks.
     - `CommentApiController`: inject `$postPresenter`; in `indexAction` map the collected `$posts` through `$this->postPresenter->toArray(...)` before return; in `saveAction:240` replace `$post->isCommentable()` → `$this->postPresenter->isCommentable($post)` (keep `$post->isPublished()`).
     - `SiteController`: inject `$postPresenter`; `postAction:194` `'enabled' => $post->isCommentable()` → `$this->postPresenter->isCommentable($post)`; in `indexAction`, for each `$post` set `$post->set('commentable', $this->postPresenter->isCommentable($post))` (transient `data`-bag flag).
     - Views `packages/pagekit/blog/views/posts.php:25` and `packages/pagekit/theme-one/views/blog/posts.php:28`: `$post->isCommentable()` → `$post->get('commentable')`.
     - `Post` entity remains intact this step → suite stays green.

  5. **Entity cleanup + delete `ModelServiceLocator` (atomic legacy removal).**
     - `Node.php`: delete `getUrl()`, `isAccessible()`, `jsonSerialize()`; remove `accessible` from `$properties` (drop the now-empty `$properties`); remove `use Pagekit\Site\ModelServiceLocator`; **keep** `implements \JsonSerializable` (inherited plain `jsonSerialize`); keep the `UrlGenerator` import only if still referenced (it is not after `getUrl` moves — remove it).
     - `Post.php`: delete `isCommentable()`, `isAccessible()`, `jsonSerialize()`; remove `accessible` from `$properties` (keep `author`/`published`); remove `use Pagekit\Site\ModelServiceLocator` and the now-unused `User` import if unreferenced; **keep** `implements \JsonSerializable`.
     - `SiteModule::main()`: remove the `ModelServiceLocator::init($app)` line (keep the `nodePresenter` registration from Step 1).
     - `git rm app/system/modules/site/src/ModelServiceLocator.php`.
     - **Success gate:** `rg -n "ModelServiceLocator" app/ packages/` returns **0** hits. `WidgetController`/`BlogController` raw entities now serialize via the plain inherited `jsonSerialize()` (columns + retained pure `$properties`) — verified sufficient for their consumers → suite stays green.

## EXECUTION STATE
<!-- Machine-readable progress index for the Orchestrator/Conductor. Mirrors the Checklist 1:1
     (same numbers + short titles). Size hint per step: S = small/atomic, M = medium,
     L = large or loop-risk. A step orchestrator flips its box to [x] in the SAME commit as that
     step's code (after Tester PASS) — no self-referential SHA. -->
- [x] Step 1 (M) — NodePresenter + service + unit test
- [x] Step 2 (M) — PostPresenter + service + unit test (tests/Unit/Blog + bootstrap)
- [x] Step 3 (L) — Site call-site migration to NodePresenter (NodeApiController, MenuHelper, menu views)
- [x] Step 4 (L) — Blog call-site migration to PostPresenter (PostApiController, CommentApiController, SiteController, posts views)
- [ ] Step 5 (M) — Entity cleanup (Node/Post) + delete ModelServiceLocator + SiteModule init

## TESTING STRATEGY
- **Per step (Tester subagent):** PHPUnit + PHPStan (mandatory after every checklist step).
  - PHPStan runs at the config-defined level; require exit 0 and **no `phpstan-baseline.neon` additions / no `count` increments** (`git diff phpstan-baseline.neon` shows removals only, or is empty). No new entries are expected: the view swaps (`getUrl()`→`get('url')`, `isCommentable()`→`get('commentable')`) reuse existing view vars (`$node`/`$post`) so they add no `variable.undefined`; the entity files and `ModelServiceLocator.php` have no baseline entries. Fix controller return-type/docblock changes inline (never `--generate-baseline`).
  - PHPUnit runs unconditionally and must stay green every step (Steps 1–2 add tests; Steps 3–5 keep the suite green by construction — each step leaves the code in a consistent state).
  - Step 1 asserts `NodePresenterTest` runs and passes; Step 2 asserts `PostPresenterTest` is **discovered** (it lives under `tests/Unit/Blog/`, in the testsuite) and passes.
- **Final run (after Early Push):** two **sequential** stages — (1) the **Orchestrator** waits on the PHP Quality CI jobs (`phpunit (8.2)`, `phpunit (8.3)`, `phpstan`, `cs-fixer`, `security-audit`) via `gh run watch` (a watch, not a test) until green; (2) **then** it delegates the 3 Playwright E2E specs to the **Tester subagent** (E2E only runs once CI is green). Focus areas: admin site/node API (`url`+`accessible` present), blog post API + public blog pages (post `url`, comment-form visibility), front-end menus (links render). Both must pass. See `.cursor/agents/tester.md` § End-of-ticket tests for the E2E commands and `.cursor/rules/orchestrator-subagent-workflow.mdc` § Final Test for the workflow position.
