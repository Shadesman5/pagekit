## ARCHITECT OUTPUT
- **Current Step (ROADMAP):** 2.0.1d
- **Scope:** packages/pagekit/blog/ (controllers, models, listeners, UrlResolver, scripts.php, index.php), packages/pagekit/theme-one/ (index.php, functions.php), app/modules/application/src/Container.php (add `set()`), app/modules/application/src/Application.php (`$this['x']` → `$this->set()`/`$this->get()`), ALL `$app['x'] = ...` service registrations across app/ and packages/, app/modules/application/src/Tests/ContainerTest.php, migration-docs/PSR11_CONTAINER_EXTENSION_MIGRATION.md, migration-docs/branches/PSR11_CONTAINER_STAGE4.md
- **Deferred:** Step 2.0.1e — `App::abort()`, `App::redirect()`, `App::on()`, `App::trigger()`, `App::message()`, `App::content()`, `App::feed()`, `App::response()`, `App::filter()`, `App::url()`, `App::routes()`, `App::subscribe()` static calls stay (StaticTrait removal). Step 2.0.1e — `App::module('blog')` in models/UrlResolver stays as `App::getInstance()->get('module')->get('blog')` workaround (Repository pattern). Step 2.0.1e — `__call()` magic on Container stays.
- **Bridges:**
  - `App::module('blog')` in Model/Post.php, UrlResolver.php, controller constructors → use `App::getInstance()->get('module')->get('blog')` pattern. Tag: `// TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e`
  - `App::user()`, `App::db()`, `App::cache()`, `App::router()`, `App::request()`, `App::url()`, `App::content()`, `App::feed()`, `App::response()`, `App::filter()` static calls in packages/ stay as-is. Tag: `// TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)` (only add if not already tagged)
  - `App::abort()`, `App::redirect()`, `App::message()` in packages/ stay. Deferred to 2.0.1e.
- **Checklist:**

### Step 1: Add `set()` method to Container
- File: `app/modules/application/src/Container.php`
- Add `public function set(string $id, mixed $value): void` with same logic as `offsetSet()`
- Update `factory()` and `extend()` to call `set()` instead of `offsetSet()`
- Update constructor to call `set()` instead of `offsetSet()`
- **Test:** PHPUnit passes, Container still works with both ArrayAccess and `set()`

### Step 2: Migrate Application.php from ArrayAccess to set()/get()
- File: `app/modules/application/src/Application.php`
- `$this['events'] = ...` → `$this->set('events', ...)`
- `$this['module'] = ...` → `$this->set('module', ...)`
- `$this['kernel']->handle(...)` → `$this->get('kernel')->handle(...)`
- `$this['kernel']->terminate(...)` → `$this->get('kernel')->terminate(...)`
- **Test:** PHPUnit passes

### Step 3: Migrate app/modules/ service registrations to set()
- Files: `app/modules/application/index.php`, `app/modules/kernel/index.php`, `app/modules/database/index.php`, `app/modules/routing/index.php`, `app/modules/auth/index.php`, `app/modules/session/index.php`, `app/modules/view/index.php`, `app/modules/view/modules/twig/index.php`, `app/modules/config/index.php`, `app/modules/cookie/index.php`, `app/modules/feed/index.php`, `app/modules/filesystem/index.php`, `app/modules/filter/index.php`, `app/modules/log/index.php`, `app/modules/markdown/index.php`, `app/modules/migration/index.php`, `app/modules/debug/index.php`
- Every `$app['x'] = ...` → `$app->set('x', ...)`
- **Test:** PHPUnit passes

### Step 4: Migrate app/system/ service registrations to set()
- Files: `app/system/index.php`, `app/system/src/SystemModule.php`, `app/system/src/ValidatorServiceProvider.php`, `app/system/modules/widget/index.php`, `app/system/modules/user/src/UserModule.php`, `app/system/modules/site/src/SiteModule.php`, `app/system/modules/intl/src/IntlModule.php`, `app/system/modules/finder/index.php`, `app/system/modules/mail/index.php`, `app/system/modules/info/index.php`, `app/system/modules/content/index.php`
- Every `$app['x'] = ...` → `$app->set('x', ...)`
- Remove `// TODO: Must be refactored in Step 2.0.1d` tags from these lines (they're being resolved now)
- **Test:** PHPUnit passes

### Step 5: Migrate app/installer/ and app/console/ service registrations to set()
- Files: `app/installer/index.php`, `app/installer/app.php`, `app/console/app.php`, `app/system/app.php`
- `$app['autoloader'] = $loader` → `$app->set('autoloader', $loader)`
- `$app['package'] = ...` → `$app->set('package', ...)`
- Remove `// TODO: Must be refactored in Step 2.0.1d` tags
- **Test:** PHPUnit passes

### Step 6: Migrate packages/pagekit/blog/ ArrayAccess reads
- File: `packages/pagekit/blog/scripts.php`
  - `$app['migration']->migrateExtension(...)` → `$app->get('migration')->migrateExtension(...)`
  - `$app['migration']->rollbackExtension(...)` → `$app->get('migration')->rollbackExtension(...)`
  - `isset($app['cache'])` → `$app->has('cache')`
  - `$app['cache']->clear()` → `$app->get('cache')->clear()`
- File: `packages/pagekit/blog/index.php`
  - `$app['url']->get(...)` → `$app->get('url')->get(...)`
- **Test:** PHPUnit passes

### Step 7: Migrate packages/pagekit/blog/ controllers to constructor injection
- File: `packages/pagekit/blog/src/Controller/BlogController.php` — add constructor DI for `module` service; replace `App::module('blog')` with injected module, `App::user()` stays (deferred to 2.0.1e), `App::db()` stays (deferred)
- File: `packages/pagekit/blog/src/Controller/PostApiController.php` — add constructor DI for `module` service; replace `App::module('blog')` with injected, `App::user()`/`App::request()`/`App::filter()` stay (deferred)
- File: `packages/pagekit/blog/src/Controller/CommentApiController.php` — already has constructor, migrate `App::module('blog')` and `App::user()` to constructor DI using injected services
- File: `packages/pagekit/blog/src/Controller/SiteController.php` — already has constructor, migrate `App::module('blog')` to constructor DI
- For controllers: use constructor injection with `$module` parameter to get ModuleManager, then `$module->get('blog')` in constructor. Tag remaining `App::*` static calls: `// TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)` where not already tagged.
- **Test:** PHPUnit passes

### Step 8: Migrate packages/pagekit/blog/ listeners to constructor injection via index.php
- File: `packages/pagekit/blog/src/Event/RouteListener.php` — uses `App::router()`, `App::cache()`, `App::routes()`. These are StaticTrait calls deferred to 2.0.1e. Add TODO tags if not present.
- File: `packages/pagekit/blog/src/Event/PostListener.php` — no container usage, no changes needed.
- File: `packages/pagekit/blog/src/Content/ReadmorePlugin.php` — no container usage, no changes needed.
- File: `packages/pagekit/blog/index.php` — update `'boot'` event to pass DI services to listeners if any listener gets constructor injection. Since RouteListener uses only static calls (deferred), no injection changes needed. Just tag deferred items.
- **Test:** PHPUnit passes

### Step 9: Migrate packages/pagekit/blog/ models and UrlResolver
- File: `packages/pagekit/blog/src/Model/Post.php` — `App::module('blog')` calls stay but tag with `// TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e` (models cannot use constructor DI). `App::user()`, `App::url()`, `App::content()` stay (StaticTrait, deferred).
- File: `packages/pagekit/blog/src/UrlResolver.php` — `App::cache()`, `App::module('blog')`, `App::abort()` stay. Tag with `// TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e` for module/cache calls.
- **Test:** PHPUnit passes

### Step 10: Migrate packages/pagekit/theme-one/
- File: `packages/pagekit/theme-one/index.php` — uses `$app->isAdmin()` (ok, it's a method), `use ($app)` closures reference `$app` but don't use ArrayAccess. No `$app['x']` calls. No changes needed.
- File: `packages/pagekit/theme-one/functions.php` — uses `App::view()->url($url)` which is a StaticTrait call. Tag deferred to 2.0.1e if not tagged.
- **Test:** PHPUnit passes

### Step 11: Remove ArrayAccess from Container
- File: `app/modules/application/src/Container.php`
  - Remove `\ArrayAccess` from `implements` clause
  - Delete `offsetGet()`, `offsetSet()`, `offsetExists()`, `offsetUnset()` methods
  - Remove `#[\ReturnTypeWillChange]` attribute
  - Remove the backward compatibility TODO comment at top
  - Add `// TODO: Remove __call() magic in Step 2.0.1e (StaticTrait Removal)` on `__call()` if not present
- Verify: `rg '\$app\[' app/ packages/ --type php` returns no results
- Verify: `rg 'isset\(\$app\[' app/ packages/ --type php` returns no results
- Verify: `rg '\$this\[' app/modules/application/src/ --type php` returns no results
- **Test:** PHPUnit passes

### Step 12: Update ContainerTest
- File: `app/modules/application/src/Tests/ContainerTest.php`
  - Rewrite `testArrayAccessImplementation()` → `testSetAndGetMethods()` using `set()`/`get()`/`has()`
  - Update all `$this->container['x'] = ...` → `$this->container->set('x', ...)`
  - Update all `$this->container['x']` reads → `$this->container->get('x')`
  - Update all `isset($this->container['x'])` → `$this->container->has('x')`
  - Update all `unset($this->container['x'])` — remove or skip (offsetUnset no longer exists; test removal separately if needed)
  - Add dedicated `testSetMethod()` with: scalar, closure, factory override prevention
  - Update `testOffsetGetThrowsExceptionForUndefined` → `testGetThrowsNotFoundException` (use `Psr\Container\NotFoundExceptionInterface`)
  - Update `testOffsetSetThrowsExceptionWhenOverriding` → `testSetThrowsExceptionWhenOverriding`
  - Rename `testConstructorWithInitialValues` to use `get()` assertions
  - Keep `testCallMethod`, `testFactory`, `testExtend`, `testRaw`, `testKeys` — update internal ArrayAccess to `set()`/`get()`
- **Test:** PHPUnit passes (all 261+ tests)

### Step 13: Create extension migration guide
- File: `migration-docs/PSR11_CONTAINER_EXTENSION_MIGRATION.md`
- Content per task prompt Section 7: service access table, service registration table, controller DI table, exception types
- **Test:** File exists, content is accurate

### Step 14: Create branch documentation
- File: `migration-docs/branches/PSR11_CONTAINER_STAGE4.md`
- Content: migration summary, ArrayAccess removal details, `set()` API, link to extension guide, deferred items for 2.0.1e
- **Test:** File exists

### Step 15: Final validation
- Verify no `$app['x']` anywhere: `rg '\$app\[' app/ packages/ --type php`
- Verify no `$this['x']` in Container/Application: `rg '\$this\[' app/modules/application/src/ --type php`
- Verify no `isset($app['x'])`: `rg 'isset\(\$app\[' app/ packages/ --type php`
- Verify Container class has no `ArrayAccess`: `rg 'ArrayAccess' app/modules/application/src/Container.php`
- Run full PHPUnit: `./app/vendor/bin/phpunit`
- Run `php pagekit setup` and `php pagekit list`
- All must pass
