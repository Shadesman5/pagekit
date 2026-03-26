## ARCHITECT OUTPUT
- **Current Step (ROADMAP):** 2.0.1e (documentation gap-fill)
- **Scope:** Documentation only — two missing files from prompt Section 14, plus update to existing extension migration guide
- **Deferred:** None — all code work for 2.0.1e is complete
- **Bridges:** None
- **GitHub Issue:** #166 (same PR #172)

---

### Gap Analysis

**Prompt Section 14 required two documentation files. Neither was created.**

| Required File | Status | Notes |
|---|---|---|
| `migration-docs/branches/PSR11_CONTAINER_STATICTRAIT_REMOVAL.md` | **MISSING** | Branch summary doc — all prior stages (1–4) have one |
| `migration-docs/PSR11_CONTAINER_FULL_MODERNIZATION.md` | **MISSING** | Full 2.0.1 a–e summary, architecture comparison, breaking changes |

**Additional gap found:**

| Item | Status | Notes |
|---|---|---|
| `migration-docs/PSR11_CONTAINER_EXTENSION_MIGRATION.md` Section "Deferred Changes (Step 2.0.1e)" | **STALE** | Lists patterns as "not yet removed" but they ARE now removed. Must be updated to reflect 2.0.1e completion. |

**Prompt sections vs delivery:**

| Prompt Section | Delivered? |
|---|---|
| 0. Safety Checks | ✅ |
| 1. Preparation | ✅ (discovery done, branch created) |
| 2. Instance magic calls | ✅ |
| 3. RouterTrait calls | ✅ |
| 4. EventTrait calls | ✅ |
| 5. App::* static shortcuts | ✅ |
| 6. App::getInstance() bridges | ✅ |
| 7. Intl global functions | ✅ |
| 8. Models → Repository pattern | ✅ (ModelServiceLocator used) |
| 9. Remove magic methods | ✅ |
| 10. Delete traits | ✅ |
| 11. Tests & verification | ✅ |
| 12. Final verification | ✅ |
| 13. Validation checklist | ✅ (in PR body) |
| **14. Documentation** | **❌ NOT DONE** |
| 1.2 Branch doc creation | ❌ Listed in prompt Section 1.2 as well |

---

### Checklist

#### Step 1: Create `migration-docs/branches/PSR11_CONTAINER_STATICTRAIT_REMOVAL.md`

**What:** Create the branch summary document for Step 2.0.1e, matching the format of prior stage docs (`PSR11_CONTAINER_STAGE3.md`, `PSR11_CONTAINER_STAGE4.md`).

**Content requirements (from prompt Section 14):**
1. **Header** — ROADMAP Step 2.0.1e, branch name `cursor/psr-11-static-trait-removal-464b`, issue #166, PR #172, status Complete
2. **Objective** — one paragraph summarizing the goal (remove all static/magic access, delete traits)
3. **Deleted code summary** — table of deleted files:
   - `StaticTrait.php`, `EventTrait.php`, `RouterTrait.php` (trait files)
   - `Container::__call()` method
   - `static::$instance` property and assignment in Container
4. **Created code summary** — table of new files:
   - `IntlServiceLocator.php` — purpose, location
   - `ModelServiceLocator.php` — purpose, location, deferred-to note
   - `ExceptionListenerWrapper.php` — purpose, location
   - `IntlServiceLocatorTest.php`, `EventDispatcherCompatibilityTest.php`
5. **Migration patterns — before/after examples** for EACH pattern type:
   - `App::abort()` → `throw new NotFoundHttpException()`
   - `App::redirect()` → `$this->router->redirect()`
   - `App::on/subscribe/trigger()` → `$app->get('events')->on/subscribe/trigger()`
   - `App::user()/db()/cache()/...` → constructor DI `$this->user`
   - `App::getInstance()->get('x')` → constructor DI
   - `$app->module('x')` → `$app->get('module')->get('x')`
   - `$app->config('x')` → `$app->get('config')('x')`
   - `$app->error(cb)` → `$app->get('events')->on('exception', new ExceptionListenerWrapper(cb))`
   - Intl functions: `App::translator()` → `IntlServiceLocator::getTranslator()`
   - Models: `App::url(...)` → `ModelServiceLocator::getUrl()->...`
6. **Extension migration notes** — how third-party extensions must update:
   - All `App::*` static calls removed — use constructor DI or `$app->get()`
   - `App::abort()` → throw Symfony HTTP exceptions
   - `App::redirect()` → inject `router` service, call `$this->router->redirect()`
   - Event registration → `$app->get('events')->on/subscribe()`
   - `__()`, `_c()`, `_i()` global functions unchanged (IntlServiceLocator is internal)
7. **Full file list** — categorized table of all changed files with pattern counts (extract from PR #172 body and git diff stats)
8. **Bug fixes section** — list of post-merge bugfixes (v1.2.1) with root cause and fix summary (from PR body)
9. **Deferred items** — EntityManager singleton (Step 2.1), ModelServiceLocator (Step 2.1)
10. **Validation results** — 274 tests, 658 assertions, 0 failures; `php pagekit setup` works; `php pagekit list` works

**Source data:** PR #172 body, git log on this branch, ticket checklist steps 1–16, prompt sections 1–13.

**Acceptance criteria:**
- File exists at `migration-docs/branches/PSR11_CONTAINER_STATICTRAIT_REMOVAL.md`
- Follows format conventions of `PSR11_CONTAINER_STAGE3.md` and `PSR11_CONTAINER_STAGE4.md`
- Contains all 10 content sections listed above

---

#### Step 2: Create `migration-docs/PSR11_CONTAINER_FULL_MODERNIZATION.md`

**What:** Create the final summary document for the complete 2.0.1 migration (all 5 sub-steps a–e).

**Content requirements (from prompt Section 14):**
1. **Header** — "PSR-11 Container Full Modernization — Complete Migration Summary", ROADMAP Step 2.0.1, status Complete
2. **Overview** — one paragraph: what was the PSR-11 Container Vollmodernisierung, why it was done, what the result is
3. **Sub-step summary table** — all 5 stages with links to their branch docs:

   | Step | Name | Branch Doc | PR |
   |---|---|---|---|
   | 2.0.1a | Container Core + Modules | `PSR11_CONTAINER_STAGE1.md` + `PSR11_CONTAINER_MIGRATION.md` | #161 |
   | 2.0.1b | DI Infrastructure | `PSR11_CONTAINER_DI_INFRASTRUCTURE.md` | #167 |
   | 2.0.1c | System/Installer/Console | `PSR11_CONTAINER_STAGE3.md` | #169 |
   | 2.0.1d | Packages + ArrayAccess Removal | `PSR11_CONTAINER_STAGE4.md` | #171 |
   | 2.0.1e | StaticTrait Removal + DI Final | `PSR11_CONTAINER_STATICTRAIT_REMOVAL.md` | #172 |

4. **Before/after architecture comparison** — two-column summary:
   - **Before (pre-2.0.1):** ArrayAccess service access, StaticTrait + EventTrait + RouterTrait, `__call()` + `__callStatic()` magic, `App::getInstance()` singleton, ~400+ static proxy calls, models accessing container directly
   - **After (post-2.0.1):** Pure PSR-11 ContainerInterface, explicit `get()`/`has()`/`set()`, constructor dependency injection, IntlServiceLocator for global functions, ModelServiceLocator transitional, zero magic methods, zero static traits
5. **Container API reference** — final clean API:
   - `get(string $id): mixed`
   - `has(string $id): bool`
   - `set(string $id, mixed $value): void`
   - `factory(string $id, Closure $callable): void`
   - `extend(string $id, Closure $callable): void`
   - `raw(string $id): mixed`
   - `keys(): array`
   - `remove(string $id): void`
6. **Breaking changes for extensions** — consolidated list from all 5 steps:
   - ArrayAccess (`$app['x']`) removed (2.0.1d)
   - `App::*` static shortcuts removed (2.0.1e)
   - `App::getInstance()` removed (2.0.1e)
   - `Container::__call()` removed (2.0.1e)
   - `App::abort()` / `App::redirect()` removed (2.0.1e)
   - PSR-11 exceptions (`NotFoundExceptionInterface`) (2.0.1a)
7. **Link to extension migration guide** — `PSR11_CONTAINER_EXTENSION_MIGRATION.md`
8. **Version progression** — 1.1.6 → 1.1.7 → 1.1.8 → 1.2.0 → 1.2.1
9. **Cumulative statistics** — total files changed, call sites migrated, tests passing across all 5 steps

**Source data:** All 5 branch docs, PR bodies, ROADMAP.md.

**Acceptance criteria:**
- File exists at `migration-docs/PSR11_CONTAINER_FULL_MODERNIZATION.md`
- Contains all 9 content sections listed above
- All links to branch docs and PRs are correct relative paths

---

#### Step 3: Update `migration-docs/PSR11_CONTAINER_EXTENSION_MIGRATION.md`

**What:** The existing extension migration guide has a "Deferred Changes (Step 2.0.1e)" section at the bottom that says patterns are "not yet removed." These are now fully removed. Update the guide to reflect the completed state.

**Changes required:**
1. Update the header applicability line: `Applies to: Pagekit 1.2.x+ (ROADMAP Step 2.0.1e completed)` (was `1.1.x+ (ROADMAP Step 2.0.1d)`)
2. **Replace** the "Deferred Changes (Step 2.0.1e)" section with a new section documenting the NOW-REMOVED patterns and their replacements:
   - `App::abort()` → throw Symfony HTTP exceptions (with code-to-exception mapping table)
   - `App::redirect()` → inject `router`, call `$this->router->redirect()`
   - `App::on/subscribe/trigger()` → `$app->get('events')->on/subscribe/trigger()`
   - `App::user()/db()/cache()/...` → constructor DI
   - `$app->module('x')` magic → `$app->get('module')->get('x')`
   - `$app->config('x')` magic → `$app->get('config')('x')`
3. **Update the constructor injection example** (After section) to remove the comment `// App::user() stays until Step 2.0.1e` and show the fully modernized version with `$this->user` via constructor DI
4. **Add** a "Complete Migration Checklist" section covering both 2.0.1d and 2.0.1e patterns
5. **Add** link to `PSR11_CONTAINER_FULL_MODERNIZATION.md` for the full migration summary

**Acceptance criteria:**
- No references to "deferred" or "not yet removed" patterns remain
- All before/after examples are up to date
- Extension authors reading this guide can fully migrate without referencing any other document
