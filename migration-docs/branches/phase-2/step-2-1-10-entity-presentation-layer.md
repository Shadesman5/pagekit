# Step 2.1.10 — Entity Presentation Layer

**Branch:** `feature/entity-presentation-layer`
**ROADMAP Step:** 2.1.10 (Entity Presentation Layer — DTO/Presenter)
**GitHub Issue:** [#204](https://github.com/Shadesman5/pagekit/issues/204)
**Pull Request:** [#219](https://github.com/Shadesman5/pagekit/pull/219)
**Status:** ✅ Complete — Ready for Review
**Date:** 2026-07-10

---

## 🎯 Overview

Replaces the `ModelServiceLocator` static service locator with constructor-DI
presenters for site `Node` and blog `Post` entities. Presentation concerns
(`getUrl`, `isAccessible`, `isCommentable`, enriched `toArray`) move out of
entities into `NodePresenter` / `PostPresenter`; API controllers, `MenuHelper`,
and views consume presenters or transient `data`-bag flags.

**Phase 1 audit 1.11 — PARTIAL closure:** removes the `ModelServiceLocator`
static-service-locator finding. Row **1.11 stays ⚠️** until Step 2.1.11 removes
the `EntityManager` singleton.

---

## ✅ What Changed

### New presenters + tests (Steps 1–2)

| File | Change |
|---|---|
| `app/system/modules/site/src/NodePresenter.php` | `getUrl`, `isAccessible`, `toArray` — deps: `UrlProvider`, `User` |
| `packages/pagekit/blog/src/PostPresenter.php` | `isCommentable`, `isAccessible`, `toArray` — deps: `UrlProvider`, `User`, blog `Module` |
| `app/system/modules/site/src/Tests/NodePresenterTest.php` | Unit tests (mocked `UrlProvider`/`User`) |
| `tests/Unit/Blog/PostPresenterTest.php` | Unit tests with local `bootstrap.php` for non-autoloaded blog classes |
| `tests/Unit/Blog/bootstrap.php` | `require_once` chain for blog package classes |

### Service registration

| File | Change |
|---|---|
| `app/system/modules/site/src/SiteModule.php` | Register `nodePresenter` factory; remove `ModelServiceLocator::init()` |
| `packages/pagekit/blog/index.php` | Register `postPresenter` factory in `events.boot` |

### Site call-site migration (Step 3)

| File | Change |
|---|---|
| `app/system/modules/site/src/Controller/NodeApiController.php` | Inject `$nodePresenter`; map nodes through `toArray()` |
| `app/system/modules/site/src/MenuHelper.php` | 4th ctor param `NodePresenter`; pre-resolve `url` on nodes |
| `app/system/modules/site/index.php` | Pass `nodePresenter` to `MenuHelper` |
| `app/system/modules/site/views/menu.php` | `$node->getUrl()` → `$node->get('url')` |
| `app/system/modules/site/views/widget-menu.php` | same |
| `packages/pagekit/theme-one/views/menu-navbar.php` | same |

### Blog call-site migration (Step 4)

| File | Change |
|---|---|
| `packages/pagekit/blog/src/Controller/PostApiController.php` | Inject `$postPresenter`; map posts through `toArray()` |
| `packages/pagekit/blog/src/Controller/CommentApiController.php` | Map `$posts` via presenter; `isCommentable()` gate |
| `packages/pagekit/blog/src/Controller/SiteController.php` | `isCommentable()` via presenter; set `commentable` data flag |
| `packages/pagekit/blog/views/posts.php` | `$post->isCommentable()` → `$post->get('commentable')` |
| `packages/pagekit/theme-one/views/blog/posts.php` | same |

### Entity cleanup + locator deletion (Step 5)

| File | Change |
|---|---|
| `app/system/modules/site/src/Model/Node.php` | Remove `getUrl`, `isAccessible`, `jsonSerialize` override; drop `accessible` from `$properties` |
| `packages/pagekit/blog/src/Model/Post.php` | Remove `isCommentable`, `isAccessible`, `jsonSerialize` override; drop `accessible` from `$properties` |
| `app/system/modules/site/src/ModelServiceLocator.php` | **Deleted** |

---

## 🧪 Test Results

| Gate | Result |
|---|---|
| PHPUnit (local) | 507 tests, 1168 assertions — 0 failures |
| PHPStan (local) | PASS |
| CI — phpunit (8.2) | ✅ success |
| CI — phpunit (8.3) | ✅ success |
| CI — phpstan | ✅ success |
| CI — cs-fixer | ✅ success |
| CI — security-audit | ✅ success |
| Cursor Bugbot | ✅ pass (no findings) |
| E2E installation.spec.js | 1/1 passed |
| E2E authentication.spec.js | 14/14 passed |
| E2E dashboard.spec.js | 10/10 passed |

**CI run:** https://github.com/Shadesman5/pagekit/actions/runs/29069103134

---

## 📋 Deferred

- **`EntityManager` singleton** + `NodeModelTrait` static `$nodes` cache → **Step 2.1.11 (#205)**
- **`IntlServiceLocator`** — permanent narrow locator for global `__()`; out of scope
- **`blog/UrlResolver` static cache** — blocked on routing factory DI (Step 1.8/2.5)
- **REST API v2 (Step 4.2)** — must consume these presenters later
