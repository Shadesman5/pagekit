# PSR-11 Container Stage 5: StaticTrait Removal & DI Final

**ROADMAP Step:** 2.0.1e
**Branch:** `cursor/psr-11-static-trait-removal-464b`
**GitHub Issue:** #166
**Pull Request:** #172
**Previous Stage:** [PSR-11 Container Stage 4](step-2-0-1d-packages-arrayaccess-removal.md)
**Status:** Complete
**Version:** 1.1.8 → 1.2.0 (trait removal) → 1.2.1 (bugfixes)

---

## Objective

Eliminate all static and magic service access from the Pagekit container — `App::*()` static proxy calls, `Container::__call()` magic dispatch, `App::getInstance()` singleton bridges, EventTrait/RouterTrait/StaticTrait usage — and delete the three Application traits entirely. After this step the Container is pure PSR-11: explicit `get()`, `has()`, `set()` with constructor dependency injection throughout. ~260+ call sites were migrated across 106 files.

---

## Deleted Code Summary

| Item | Location | Purpose Removed |
|------|----------|-----------------|
| `StaticTrait.php` | `app/modules/application/src/Application/Traits/` | `__callStatic()` proxy — enabled `App::service()` static shortcuts |
| `EventTrait.php` | `app/modules/application/src/Application/Traits/` | `on()`, `subscribe()`, `trigger()`, `error()` instance methods on Application |
| `RouterTrait.php` | `app/modules/application/src/Application/Traits/` | `abort()`, `redirect()`, `forward()` instance methods on Application |
| `Container::__call()` | `app/modules/application/src/Container.php` | Magic method dispatch — enabled `$app->service()` dynamic calls |
| `static::$instance` property | `app/modules/application/src/Container.php` | Singleton assignment in constructor (`class_uses` check for StaticTrait) |
| `Traits/` directory | `app/modules/application/src/Application/Traits/` | Empty after trait deletion |

---

## Created Code Summary

| File | Location | Purpose |
|------|----------|---------|
| `IntlServiceLocator.php` | `app/system/modules/intl/src/IntlServiceLocator.php` | Permanent narrow static locator for `__()`, `_c()`, `_i()` global functions. Replaces `App::translator()` and `App::intl()` in function files that cannot use constructor DI. Wired during IntlModule boot. |
| `ModelServiceLocator.php` | `app/system/modules/site/src/ModelServiceLocator.php` | Transitional static locator for `url` and `user` services used by model serialization (`Post::jsonSerialize()`, `Node::getUrl()`). Tagged: `// TODO: Must be refactored in Step 2.1 (Static Analysis)` — replace with DTO/presenter pattern. |
| `ExceptionListenerWrapper.php` | `app/modules/kernel/src/Event/ExceptionListenerWrapper.php` | Wraps typed exception callbacks for the `exception` event. Replaces `$app->error()` (RouterTrait) with `$app->get('events')->on('exception', new ExceptionListenerWrapper(callback))`. Filters by exception type before invoking. |
| `IntlServiceLocatorTest.php` | `app/system/modules/intl/src/Tests/IntlServiceLocatorTest.php` | 5 unit tests: set/get translator, set/get intl, uninitialized throws RuntimeException. |
| `EventDispatcherCompatibilityTest.php` | `app/modules/application/src/Tests/EventDispatcherCompatibilityTest.php` | Tests for `SymfonyEventDispatcherBridge::dispatch()` — verifies events forwarded to Pagekit dispatcher. |

---

## Migration Patterns — Before/After

### 1. `App::abort()` → Typed Symfony HTTP Exceptions

```php
// BEFORE
App::abort(404, 'Post not found.');
App::abort(403, 'Insufficient permissions.');
App::abort(400, 'Invalid request.');

// AFTER
throw new NotFoundHttpException('Post not found.');
throw new AccessDeniedHttpException('Insufficient permissions.');
throw new BadRequestHttpException('Invalid request.');
```

**Exception mapping:**

| Code | Symfony Exception Class |
|------|------------------------|
| 400 | `BadRequestHttpException` |
| 403 | `AccessDeniedHttpException` |
| 404 | `NotFoundHttpException` |
| 500 | `HttpException(500, $msg)` |
| Other | `HttpException($code, $msg)` |

Console context (`SelfupdateCommand`): `App::abort(500, $msg)` → `throw new \RuntimeException($msg)`.

### 2. `App::redirect()` → Injected Router Service

```php
// BEFORE
return App::redirect('@user/login');

// AFTER (controller with constructor DI)
public function __construct(
    private readonly mixed $router,
    // ...
) {}

return $this->router->redirect('@user/login');
```

### 3. `App::on/subscribe/trigger()` → Explicit Events Service

```php
// BEFORE (EventTrait instance calls)
$app->on('view.head', function ($event) { ... });
$app->subscribe(new ResponseListener());
$app->trigger('boot', [$this]);

// AFTER
$app->get('events')->on('view.head', function ($event) { ... });
$app->get('events')->subscribe(new ResponseListener());
$app->get('events')->trigger('boot', [$this]);
```

### 4. `App::user()/db()/cache()/...` → Constructor DI

```php
// BEFORE
$user = App::user();
$db = App::db();
$cache = App::cache();

// AFTER (controller/listener/helper)
public function __construct(
    private readonly mixed $user,
    private readonly mixed $db,
    private readonly mixed $cache,
) {}

$user = $this->user;
$db = $this->db;
$cache = $this->cache;
```

### 5. `App::getInstance()->get('x')` → Constructor DI

```php
// BEFORE (temporary bridge from 2.0.1c/d)
$module = App::getInstance()->get('module')->get('blog');

// AFTER
public function __construct(
    private readonly mixed $module,
) {}

$blog = $this->module->get('blog');
```

### 6. `$app->module('x')` Magic → `$app->get('module')->get('x')`

```php
// BEFORE (__call magic)
$site = $app->module('system/site');

// AFTER
$site = $app->get('module')->get('system/site');
```

### 7. `$app->config('x')` Magic → `$app->get('config')('x')`

```php
// BEFORE (__call magic)
$config = $app->config('system');

// AFTER
$config = $app->get('config')('system');
```

### 8. `$app->error(callback)` → ExceptionListenerWrapper

```php
// BEFORE (RouterTrait)
$app->error(function (HttpException $e) use ($app) {
    return $app->get('view')->render('views:system/error.php', compact('e'));
});

// AFTER
$app->get('events')->on('exception', new ExceptionListenerWrapper(
    function (HttpException $e) use ($app) {
        return $app->get('view')->render('views:system/error.php', compact('e'));
    }
), -10);
```

### 9. Intl Global Functions → IntlServiceLocator

```php
// BEFORE (functions.php)
use Pagekit\Application as App;

function __($id, ...) {
    return App::translator()->trans($id, ...);
}

// AFTER
use Pagekit\Intl\IntlServiceLocator;

function __($id, ...) {
    return IntlServiceLocator::getTranslator()->trans($id, ...);
}
```

`__()`, `_c()`, `_i()` global functions remain unchanged for extension authors — IntlServiceLocator is an internal implementation detail.

### 10. Models → ModelServiceLocator

```php
// BEFORE (Post.php)
$url = App::url()->get('@blog/id', ['id' => $this->id ?: 0], 'base');

// AFTER
$url = ModelServiceLocator::getUrl()->get('@blog/id', ['id' => $this->id ?: 0], 'base');
// TODO: Must be refactored in Step 2.1 (Static Analysis) — replace with DTO/presenter pattern
```

---

## Extension Migration Notes

Third-party extensions must update the following patterns:

### Required Changes

| Pattern | Replacement |
|---------|-------------|
| `App::user()`, `App::db()`, etc. | Constructor DI: inject the service, use `$this->service` |
| `App::abort(404, $msg)` | `throw new NotFoundHttpException($msg)` (import Symfony exception) |
| `App::abort(403, $msg)` | `throw new AccessDeniedHttpException($msg)` |
| `App::abort(400, $msg)` | `throw new BadRequestHttpException($msg)` |
| `App::redirect($url)` | Inject `router` service, call `$this->router->redirect($url)` |
| `App::on('event', $cb)` | `$app->get('events')->on('event', $cb)` |
| `App::subscribe($listener)` | `$app->get('events')->subscribe($listener)` |
| `App::trigger('event', $args)` | `$app->get('events')->trigger('event', $args)` |
| `App::getInstance()->get('x')` | Constructor DI or `$app->get('x')` in bootstrap context |
| `$app->module('name')` | `$app->get('module')->get('name')` |
| `$app->config('name')` | `$app->get('config')('name')` |
| `$app->service()` (any magic call) | `$app->get('service')` |

### Unchanged (no migration needed)

- `__($id, $params)`, `_c($id, $count, $params)`, `_i($format, $args)` — global translation functions are unchanged. IntlServiceLocator is internal.
- `$app->get('service')`, `$app->has('service')`, `$app->set('id', $value)` — PSR-11 methods from Stage 4 remain stable.

### Full Extension Migration Guide

**[PSR-11 Container Extension Migration Guide](step-2-0-1-extension-migration-guide.md)**

---

## Full File List

### Deleted Files (3)

| File | Lines Removed |
|------|---------------|
| `app/modules/application/src/Application/Traits/StaticTrait.php` | 83 |
| `app/modules/application/src/Application/Traits/EventTrait.php` | 59 |
| `app/modules/application/src/Application/Traits/RouterTrait.php` | 58 |

### Created Files (5)

| File | Lines Added |
|------|-------------|
| `app/system/modules/intl/src/IntlServiceLocator.php` | 39 |
| `app/system/modules/site/src/ModelServiceLocator.php` | 43 |
| `app/modules/kernel/src/Event/ExceptionListenerWrapper.php` | 59 |
| `app/system/modules/intl/src/Tests/IntlServiceLocatorTest.php` | 71 |
| `app/modules/application/src/Tests/EventDispatcherCompatibilityTest.php` | 30 |

### Modified Files — Application Core (8)

| File | Category | Changes |
|------|----------|---------|
| `app/modules/application/src/Application.php` | Trait removal | Removed 3 trait imports + `use` clause |
| `app/modules/application/src/Container.php` | Magic removal | Deleted `__call()`, removed `static::$instance` assignment |
| `app/modules/application/src/Application/Console/Application.php` | Magic call fix | Fixed `App::` reference |
| `app/modules/application/src/Module/Loader/ModuleLoader.php` | DI fix | Updated service access |
| `app/modules/application/src/Event/SymfonyEventDispatcherBridge.php` | Bridge fix | dispatch() now forwards events to Pagekit dispatcher |
| `app/modules/application/src/Tests/ContainerTest.php` | Test cleanup | Removed magic method tests (20 lines) |
| `app/modules/application/src/Tests/EventDispatcherCompatibilityTest.php` | New tests | Bridge dispatch verification |
| `app/modules/auth/index.php` | DI wiring | Added auth password alias |

### Modified Files — Core Modules (9)

| File | Category | Changes |
|------|----------|---------|
| `app/modules/kernel/index.php` | RouterTrait removal | `$app->redirect()` → `$app->get('router')->redirect()` |
| `app/modules/kernel/src/Event/ExceptionListenerWrapper.php` | New file | Typed exception filtering |
| `app/modules/routing/index.php` | EventTrait+RouterTrait removal | `$app->error/redirect()` → events/router services |
| `app/modules/debug/index.php` | EventTrait removal | `$app->on()` → `$app->get('events')->on()` |
| `app/modules/session/index.php` | EventTrait removal | `$app->subscribe()` → `$app->get('events')->subscribe()` |
| `app/modules/view/index.php` | EventTrait removal | `$app->on()` → `$app->get('events')->on()` |
| `app/modules/database/src/ORM/EntityManager.php` | Deferred | Added TODO marker for Step 2.1 |
| `app/system/config.php` | Version | Updated to 1.2.1 |
| `app/system/index.php` | Magic calls | `$app->module/config/subscribe/trigger()` → explicit service access |

### Modified Files — System Controllers (14)

| File | Patterns Migrated |
|------|-------------------|
| `app/system/src/Controller/AdminController.php` | `App::abort()` ×3, `App::redirect()` ×2 |
| `app/system/src/Controller/MigrationController.php` | `App::abort()` ×2, `App::redirect()` ×2 |
| `app/system/src/Controller/ValidatesRequestTrait.php` | `App::abort()` ×3, `App::getInstance()` ×2 |
| `app/system/modules/dashboard/src/Controller/DashboardController.php` | `App::getInstance()` ×1 |
| `app/system/modules/finder/src/Controller/FinderController.php` | `App::trigger()` ×2 |
| `app/system/modules/settings/src/Controller/SettingsController.php` | `App::getInstance()` ×1 |
| `app/system/modules/site/src/Controller/MenuApiController.php` | `App::abort()` ×1 |
| `app/system/modules/site/src/Controller/NodeApiController.php` | `App::abort()` ×4 |
| `app/system/modules/site/src/Controller/NodeController.php` | `App::abort()` ×4, `App::redirect()` ×1 |
| `app/system/modules/site/src/Controller/PageController.php` | `App::abort()` ×1 |
| `app/system/modules/user/src/Controller/AuthController.php` | `App::abort()` ×1, `App::redirect()` ×1 |
| `app/system/modules/user/src/Controller/ProfileController.php` | `App::abort()` ×3, `App::redirect()` ×1, `App::getInstance()` ×1 |
| `app/system/modules/user/src/Controller/RegistrationController.php` | `App::abort()` ×6, `App::redirect()` ×4, `App::getInstance()` ×1 |
| `app/system/modules/user/src/Controller/ResetPasswordController.php` | `App::abort()` ×6, `App::redirect()` ×4, `App::getInstance()` ×1 |

### Modified Files — System Controllers (continued) (4)

| File | Patterns Migrated |
|------|-------------------|
| `app/system/modules/user/src/Controller/RoleApiController.php` | `App::abort()` ×1 |
| `app/system/modules/user/src/Controller/UserApiController.php` | `App::abort()` ×9, `App::getInstance()` ×1 |
| `app/system/modules/user/src/Controller/UserController.php` | `App::abort()` ×1 |
| `app/system/modules/widget/src/Controller/WidgetApiController.php` | `App::abort()` ×3 |

### Modified Files — System Listeners & Helpers (8)

| File | Patterns Migrated |
|------|-------------------|
| `app/system/modules/captcha/src/CaptchaListener.php` | `App::abort()` ×2 |
| `app/system/modules/site/src/Event/MaintenanceListener.php` | `App::abort()` ×1 |
| `app/system/modules/user/src/Event/AccessListener.php` | `App::abort()` ×2 |
| `app/system/modules/cache/src/CacheModule.php` | `App::on()` ×1, `App::getInstance()` ×1 |
| `app/system/modules/content/src/ContentHelper.php` | `App::trigger()` ×1 |
| `app/system/modules/content/src/Plugin/MarkdownPlugin.php` | `App::markdown()` ×1 |
| `app/system/modules/dashboard/src/DashboardModule.php` | `App::getInstance()` ×2 |
| `app/system/modules/site/src/MenuHelper.php` | `App::getInstance()` ×2 |

### Modified Files — System Modules & Views (13)

| File | Patterns Migrated |
|------|-------------------|
| `app/system/modules/widget/src/Controller/WidgetController.php` | `App::abort()` ×1 |
| `app/system/modules/view/src/Asset/FileLocatorAsset.php` | `App::getInstance()` ×3 |
| `app/system/modules/user/src/UserModule.php` | `App::trigger()` |
| `app/system/modules/site/src/SiteModule.php` | `App::trigger()`, `$app->config()` |
| `app/system/src/SystemModule.php` | `$app->module()` |
| `app/system/src/Validator/Constraints/UniqueValidator.php` | `App::getInstance()` ×1 |
| `app/system/modules/site/widgets/menu.php` | `$app->view()` magic |
| `app/system/modules/user/views/login.php` | `$app->module()` magic |
| `app/system/modules/user/views/widget-login.php` | `$app->module()` magic |
| `app/system/modules/user/mails/approve.php` | `$app->module()` magic |
| `app/system/modules/user/mails/verification.php` | `$app->module()` magic |
| `app/system/modules/user/mails/welcome.php` | `$app->module()` magic |
| `app/system/modules/user/mails/reset.php` | `$app->module()` magic |

### Modified Files — System Index/Bootstrap (8)

| File | Patterns Migrated |
|------|-------------------|
| `app/system/modules/captcha/index.php` | `$app->subscribe()` |
| `app/system/modules/comment/index.php` | `$app->subscribe()` |
| `app/system/modules/content/index.php` | `$app->subscribe()` |
| `app/system/modules/dashboard/index.php` | Module wiring |
| `app/system/modules/editor/index.php` | `$app->module()` magic |
| `app/system/modules/intl/index.php` | IntlServiceLocator wiring |
| `app/system/modules/settings/index.php` | `$app->module()` magic |
| `app/system/modules/theme/index.php` | `$app->module()` magic |

### Modified Files — Intl Functions (2)

| File | Patterns Migrated |
|------|-------------------|
| `app/system/modules/intl/functions.php` | `App::translator()` ×3 → IntlServiceLocator |
| `app/system/modules/intl/functions-pagekit-namespace.php` | `App::translator()` ×2, `App::intl()` ×1 → IntlServiceLocator |

### Modified Files — Installer (11)

| File | Patterns Migrated |
|------|-------------------|
| `app/installer/index.php` | `$app->on/module/redirect()` → events/module/router services |
| `app/installer/install.php` | `App::getInstance()` ×1 removed |
| `app/installer/install-demo.php` | `App::getInstance()` ×1 removed |
| `app/installer/src/Installer.php` | Refactored to accept `$app` parameter |
| `app/installer/src/SelfUpdater.php` | `App::path()` → constructor parameter |
| `app/installer/src/Controller/InstallerController.php` | `App::getInstance()` ×1 |
| `app/installer/src/Controller/MarketplaceController.php` | `App::getInstance()` ×2 |
| `app/installer/src/Controller/PackageController.php` | `App::abort()` ×8, `App::debug/log/getInstance()` |
| `app/installer/src/Controller/UpdateController.php` | `App::abort()` ×2, `App::getInstance()` ×2 |
| `app/installer/src/Package/PackageFactory.php` | `App::getInstance()` ×1 |
| `app/installer/src/Package/PackageManager.php` | `App::getInstance()` ×9 → constructor `$app` |

### Modified Files — Installer (continued) (1)

| File | Patterns Migrated |
|------|-------------------|
| `app/installer/src/Package/PackageScripts.php` | `App::getInstance()` ×1 → parameter |

### Modified Files — Console (5)

| File | Patterns Migrated |
|------|-------------------|
| `app/console/app.php` | Bootstrap fix |
| `app/console/src/Commands/ArchiveCommand.php` | Magic call fix |
| `app/console/src/Commands/BuildCommand.php` | Magic call fix |
| `app/console/src/Commands/ExtensionTranslateCommand.php` | Magic call fix |
| `app/console/src/Commands/MigrationCommand.php` | Magic call fix |
| `app/console/src/Commands/SelfupdateCommand.php` | `App::abort()` ×2 → `RuntimeException` |

### Modified Files — Blog Package (8)

| File | Patterns Migrated |
|------|-------------------|
| `packages/pagekit/blog/index.php` | `$app->subscribe()`, service wiring for RouteListener/UrlResolver |
| `packages/pagekit/blog/src/Controller/BlogController.php` | `App::abort()` ×3, `App::redirect()` ×1, `App::user/db/message()` |
| `packages/pagekit/blog/src/Controller/CommentApiController.php` | `App::abort()` ×9, `App::user/request/content/db()` |
| `packages/pagekit/blog/src/Controller/PostApiController.php` | `App::abort()` ×3, `App::user/request/filter/db()` (~17 calls) |
| `packages/pagekit/blog/src/Controller/SiteController.php` | `App::abort()` ×2, `App::user/content/feed/url/response/db()` (~22 calls) |
| `packages/pagekit/blog/src/Event/RouteListener.php` | `App::router/routes/cache()` → constructor DI |
| `packages/pagekit/blog/src/Model/Post.php` | `App::module/user/url()` → ModelServiceLocator + param requirement |
| `packages/pagekit/blog/src/UrlResolver.php` | `App::abort/cache/module()` → constructor DI + typed exceptions |

### Modified Files — Blog Package (continued) (1)

| File | Patterns Migrated |
|------|-------------------|
| `packages/pagekit/blog/src/Model/PostModelTrait.php` | Removed App import |

### Modified Files — Theme-One Package (2)

| File | Patterns Migrated |
|------|-------------------|
| `packages/pagekit/theme-one/functions.php` | `App::view()` → injected view service |
| `packages/pagekit/theme-one/index.php` | Service wiring for functions.php |

### Modified Files — Site Model (2)

| File | Patterns Migrated |
|------|-------------------|
| `app/system/modules/site/src/Model/Node.php` | `App::getInstance()` ×2 → ModelServiceLocator |
| `app/system/modules/site/src/ModelServiceLocator.php` | New file — transitional locator |

---

## Bug Fixes (v1.2.1)

Post-merge bugfixes discovered during integration testing:

| Bug | Root Cause | Fix |
|-----|-----------|-----|
| **subscribe() multi-arg regression** | `EventDispatcher::subscribe()` accepts one subscriber; migrated calls passed multiple args (silently dropped) | Split into individual `subscribe()` calls in 6 module index.php files |
| **DashboardModule TypeError** | Non-nullable `$app` property without default caused TypeError when accessed before `main()` | Added `assertBooted()` guard + `LogicException` for missing widget ID |
| **PackageScripts null container** | 3 call sites omitted `$app` parameter, causing null container in script callbacks | Pass `$app` parameter from PackageManager to script runner |
| **PackageManager::getVersion() wrong file** | Read `composer.json` instead of `installed.json` for fallback lookup; also `->getName()` called on array | Fixed file path + array access |
| **systemApi factory crash** | Missing fallback for unregistered `system.api` service in DashboardModule | Added null fallback |
| **Console commands broken `__call()`** | MigrationCommand, BuildCommand, ArchiveCommand, ExtensionTranslateCommand still used `$app->service()` magic | Migrated to `$app->get('service')` |
| **ExceptionListenerWrapper TypeError** | `shouldRun()` parameter typed `\Exception` but receives `\Throwable` | Changed parameter type to `\Throwable` |
| **PackageFactory url silently null** | `load()` skipped setting package URL when `$url` was null | Used nullsafe operator with empty-string fallback |
| **CacheModule null app** | `clearCache()`/`doClearCache()` used `$this->app` without null guard | Added `assertBooted()` guard |
| **routing/index.php setResponse() extra args** | Passed 3 args to `setResponse()` instead of routing status into `redirect()` call | Fixed argument placement |

---

## Deferred Items

| Item | Current State | Target Step | Notes |
|------|--------------|-------------|-------|
| **EntityManager singleton** | `static::$instance = $this` in `EntityManager.php` | Step 2.1 (Static Analysis) | Deeply entangled with ORM static model methods (`ModelTrait::query()`). Tagged: `// TODO: Must be refactored in Step 2.1 (Static Analysis)` |
| **ModelServiceLocator** | Static locator for `url`/`user` in model serialization | Step 2.1 (Static Analysis) | Replace with DTO/presenter pattern. Tagged: `// TODO: Must be refactored in Step 2.1 (Static Analysis)` |

---

## Validation Results

**Date:** 2026-03-25
**Branch:** `cursor/psr-11-static-trait-removal-464b`

### 1. PHPUnit

```
Tests: 274, Assertions: 658, Warnings: 0, Skipped: 0
Result: OK (0 failures, 0 errors)
```

### 2. Zero Static/Magic Patterns

| Check | Result |
|-------|--------|
| `App::` static calls (excluding use/namespace/class) | 0 matches |
| `App::getInstance()` anywhere in app/ packages/ | 0 matches |
| `Container::__call()` method | Deleted |
| `Container::__callStatic()` (via StaticTrait) | Deleted |
| Trait files in `Application/Traits/` | Directory deleted |
| `$app->service()` magic dynamic calls | 0 matches |
| `TEMPORARY BRIDGE` markers in app/ packages/ | 0 matches |

### 3. CLI Validation

```
$ php pagekit setup
Pagekit setup completed.

$ php pagekit list
Pagekit 1.2.1 — all commands available
```

### 4. Container API — Final Clean State

```php
class Container implements \Psr\Container\ContainerInterface
{
    public function get(string $id): mixed;
    public function has(string $id): bool;
    public function set(string $id, mixed $value): void;
    public function factory(string $id, Closure $callable): void;
    public function extend(string $id, Closure $callable): void;
    public function raw(string $id): mixed;
    public function keys(): array;
    public function remove(string $id): void;
}
```

Zero magic methods. Zero static traits. Pure PSR-11.

---

## Migration Summary

| Metric | Value |
|--------|-------|
| **ROADMAP Step** | 2.0.1e |
| **Total files changed** | 106 |
| **Total insertions / deletions** | +964 / −887 |
| **`App::abort()` calls migrated** | ~80 |
| **`App::redirect()` calls migrated** | ~18 |
| **`App::on/subscribe/trigger()` calls migrated** | ~30 |
| **`App::user/db/cache/...` calls migrated** | ~90+ |
| **`App::getInstance()` bridges resolved** | ~38 |
| **`$app->service()` magic calls migrated** | ~30 |
| **Intl functions migrated** | 6 (3 + 3) |
| **Model static accesses purged** | 5 (Post) + 3 (Node) |
| **Traits deleted** | 3 (StaticTrait, EventTrait, RouterTrait) |
| **Container magic methods deleted** | 1 (`__call()`) |
| **New service locators** | 2 (IntlServiceLocator, ModelServiceLocator) |
| **New wrapper classes** | 1 (ExceptionListenerWrapper) |
| **New tests added** | 2 files (IntlServiceLocatorTest, EventDispatcherCompatibilityTest) |
| **Constructors added/modified** | ~45 |
| **Post-merge bugfixes** | 10 |
| **Commits** | 30 |
| **Version progression** | 1.1.8 → 1.2.0 → 1.2.1 |

---

## Commit History

```
07f3277b refactor(container): migrate app magic calls to PSR-11 get()
18347b17 refactor(container): migrate app event calls to explicit events service
94bedbe0 refactor(container): migrate app error/redirect to explicit service calls
85d883f7 refactor(container): delete Container::__call() magic method
40ef07da refactor(intl): create IntlServiceLocator, remove App:: from global functions
f9f20c13 refactor(system): replace App::abort() with typed Symfony HttpKernel exceptions
6c0d53ce refactor(installer,blog): replace App::abort() with typed exceptions
98ed31b4 refactor(controllers): replace App::redirect() with injected router service
a51daf9f refactor(system): replace remaining App:: static calls with DI
7101240e refactor(blog,theme-one): replace all App:: static calls with constructor DI (Step 10)
c1fc0ce3 refactor(blog,theme): replace all App:: static calls with constructor DI
d1f77d76 refactor(system): resolve App::getInstance() bridges in system area (Step 11)
7aaee4ea refactor(installer): resolve App::getInstance() bridges with constructor DI
22946d87 refactor(models): eliminate static App access from Post and Node models
8f458e5d refactor(events): fix SymfonyEventDispatcherBridge dispatch, defer EM singleton
882dcced refactor(container)!: delete StaticTrait, EventTrait, RouterTrait
c5d5912a refactor(container): final verification, fix remaining magic calls, add tests
539de4bc chore(release): bump version to 1.2.0
6490f5c4 fix(container): resolve 5 critical bugs from StaticTrait removal
96589296 chore(release): bump version to 1.2.1
e6c1956a fix(container): resolve 6 additional bugs from StaticTrait removal
a914b386 fix(site,console): add SiteModule assertBooted guard, fix SelfUpdater constructor call
1e0cd0a5 fix(container): support extend() on already-resolved services
137d5244 fix(kernel,dashboard): fix HttpException status code, remove hardcoded API key
c61a544c fix(routing,dashboard): fix JSON error handler, nullable getWidget, restore API key
ec0d9d95 fix(installer,routing,kernel): fix DataHelper call, scope JSON handler, widen callable
b9bef472 fix(intl,routing,container): fix intl service crash, use wrapper code, document extend
```
