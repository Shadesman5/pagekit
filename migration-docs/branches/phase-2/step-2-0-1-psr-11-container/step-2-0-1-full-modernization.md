# PSR-11 Container Full Modernization — Complete Migration Summary

**ROADMAP Step:** 2.0.1
**Status:** ✅ Complete
**Version:** 1.1.6 → 1.2.1
**Parent Issue:** [#145](https://github.com/Shadesman5/pagekit/issues/145)

---

## Overview

The PSR-11 Container Vollmodernisierung (Step 2.0.1) was a five-stage refactoring that transformed Pagekit's service container from a legacy ArrayAccess/magic-method architecture into a pure PSR-11 `ContainerInterface` implementation with constructor dependency injection throughout. The migration eliminated `\ArrayAccess` from the Container class, deleted three Application traits (`StaticTrait`, `EventTrait`, `RouterTrait`), removed `Container::__call()` magic dispatch and `App::getInstance()` singleton bridges, and migrated ~940 call sites across ~200 files. The result is an explicit, type-safe container with zero magic methods, zero static proxies, and constructor DI as the standard service access pattern.

---

## Sub-Step Summary

| Step | Name | Branch Doc | PR | Issue | Key Deliverable |
|------|------|-----------|-----|-------|-----------------|
| 2.0.1a | Container Core + Modules | [step-2-0-1a-native-container-interface.md](step-2-0-1a-native-container-interface.md) / [step-2-0-1a-migration.md](step-2-0-1a-migration.md) / [step-2-0-1a-core-modules-call-sites.md](step-2-0-1a-core-modules-call-sites.md) | [#161](https://github.com/Shadesman5/pagekit/pull/161) | [#162](https://github.com/Shadesman5/pagekit/issues/162) | Native `ContainerInterface`, `get()`/`has()`, Psr11Adapter deleted, `app/modules/` ArrayAccess reads migrated |
| 2.0.1b | DI Infrastructure | [step-2-0-1b-di-infrastructure.md](step-2-0-1b-di-infrastructure.md) | [#167](https://github.com/Shadesman5/pagekit/pull/167) | [#163](https://github.com/Shadesman5/pagekit/issues/163) | `ControllerResolver` reflection-based constructor DI |
| 2.0.1c | System / Installer / Console | [step-2-0-1c-system-installer-console.md](step-2-0-1c-system-installer-console.md) | [#169](https://github.com/Shadesman5/pagekit/pull/169) | [#164](https://github.com/Shadesman5/pagekit/issues/164) | 493 call sites migrated, constructor DI for all controllers and listeners |
| 2.0.1d | Packages + ArrayAccess Removal | [step-2-0-1d-packages-arrayaccess-removal.md](step-2-0-1d-packages-arrayaccess-removal.md) | [#171](https://github.com/Shadesman5/pagekit/pull/171) | [#165](https://github.com/Shadesman5/pagekit/issues/165) | `set()` method added, all WRITEs migrated, `\ArrayAccess` deleted from Container |
| 2.0.1e | StaticTrait Removal + DI Final | [step-2-0-1e-statictrait-removal.md](step-2-0-1e-statictrait-removal.md) | [#172](https://github.com/Shadesman5/pagekit/pull/172) | [#166](https://github.com/Shadesman5/pagekit/issues/166) | All traits deleted, `__call()` removed, `App::getInstance()` eliminated, ~260 call sites migrated |

---

## Before / After Architecture Comparison

| Aspect | Before (pre-2.0.1) | After (post-2.0.1) |
|--------|---------------------|---------------------|
| **Container interface** | `\ArrayAccess` as primary API | Pure `Psr\Container\ContainerInterface` |
| **Service reads** | `$app['x']` (ArrayAccess) | `$app->get('x')` (PSR-11) |
| **Service writes** | `$app['x'] = $val` (ArrayAccess) | `$app->set('x', $val)` (explicit method) |
| **Existence checks** | `isset($app['x'])` | `$app->has('x')` |
| **Static proxy calls** | `App::db()`, `App::user()`, `App::module()` via `StaticTrait::__callStatic()` | Constructor DI: `$this->db`, `$this->user`, `$this->module` |
| **Instance magic calls** | `$app->service()` via `Container::__call()` | `$app->get('service')` |
| **HTTP exceptions** | `App::abort(404, $msg)` via `RouterTrait` | `throw new NotFoundHttpException($msg)` (Symfony) |
| **Redirects** | `App::redirect($url)` via `RouterTrait` | `$this->router->redirect($url)` (injected service) |
| **Event registration** | `$app->on()`, `$app->subscribe()`, `$app->trigger()` via `EventTrait` | `$app->get('events')->on()`, `->subscribe()`, `->trigger()` |
| **Error handlers** | `$app->error(callback)` via `RouterTrait` | `$app->get('events')->on('exception', new ExceptionListenerWrapper(callback))` |
| **Singleton access** | `App::getInstance()->get('x')` | Constructor DI or `$app->get('x')` in bootstrap context |
| **Application traits** | `StaticTrait`, `EventTrait`, `RouterTrait` (3 traits) | Deleted — zero traits |
| **Magic methods** | `__call()`, `__callStatic()`, `offsetGet()`, `offsetSet()`, `offsetExists()`, `offsetUnset()` | Zero magic methods on Container |
| **Intl global functions** | `App::translator()` via StaticTrait | `IntlServiceLocator::getTranslator()` (narrow static locator) |
| **Model service access** | `App::url()`, `App::user()` via StaticTrait | `ModelServiceLocator::getUrl()`, `::getUser()` (transitional, Step 2.1) |
| **PSR-11 compliance** | Via `Psr11Adapter` wrapper | Native `ContainerInterface` on `Container` class |
| **Exception types** | `\InvalidArgumentException` | `NotFoundException` implements `NotFoundExceptionInterface` |
| **Controller DI** | None — services accessed via static proxies | Reflection-based constructor injection via `ControllerResolver` |

---

## Container API Reference (Final)

After all five sub-steps, the `Container` class exposes exactly these public methods:

```php
class Container implements \Psr\Container\ContainerInterface
{
    /** PSR-11: Retrieve a service by ID. Throws NotFoundException if not registered. */
    public function get(string $id): mixed;

    /** PSR-11: Check whether a service is registered. */
    public function has(string $id): bool;

    /** Register a service definition (closure) or a scalar/object value. */
    public function set(string $id, mixed $value): void;

    /** Register a factory — each get() call returns a fresh instance. */
    public function factory(string $id, \Closure $callable): void;

    /** Extend an existing service definition with a decorator closure. */
    public function extend(string $id, \Closure $callable): void;

    /** Return the raw (unresolved) service definition. */
    public function raw(string $id): mixed;

    /** Return all registered service IDs. */
    public function keys(): array;

    /** Remove a service registration. */
    public function remove(string $id): void;
}
```

No `\ArrayAccess` methods. No `__call()`. No `__callStatic()`. No `offsetGet/Set/Exists/Unset`.

---

## Breaking Changes for Extensions (Consolidated)

All breaking changes across the five sub-steps, in order of introduction:

### Step 2.0.1a — PSR-11 Exceptions

| Change | Detail |
|--------|--------|
| `NotFoundException` replaces `\InvalidArgumentException` | Missing-service lookups now throw `Pagekit\Container\NotFoundException` (implements `Psr\Container\NotFoundExceptionInterface`) |
| `ContainerException` added | Resolution errors throw `Pagekit\Container\ContainerException` (implements `Psr\Container\ContainerExceptionInterface`) |
| `Psr11Adapter` deleted | Direct `$app->get()`/`$app->has()` replaces `$app->getPsr11Adapter()->get()` |
| `getService()` / `hasService()` removed | Use `get()` / `has()` directly |

### Step 2.0.1d — ArrayAccess Removal

| Change | Detail |
|--------|--------|
| `$app['x']` no longer works | Use `$app->get('x')` |
| `$app['x'] = $val` no longer works | Use `$app->set('x', $val)` |
| `isset($app['x'])` no longer works | Use `$app->has('x')` |
| `unset($app['x'])` no longer works | Use `$app->remove('x')` |
| `factory()` signature changed | Old: `$app['x'] = $app->factory(fn)` → New: `$app->factory('x', fn)` |

### Step 2.0.1e — Static/Magic Removal

| Change | Detail |
|--------|--------|
| `App::user()`, `App::db()`, etc. removed | Use constructor DI: `$this->user`, `$this->db` |
| `App::abort(code, msg)` removed | Throw Symfony exceptions: `NotFoundHttpException`, `AccessDeniedHttpException`, `BadRequestHttpException` |
| `App::redirect(url)` removed | Inject `router` service, call `$this->router->redirect(url)` |
| `App::on()` / `App::subscribe()` / `App::trigger()` removed | Use `$app->get('events')->on()` / `->subscribe()` / `->trigger()` |
| `App::getInstance()` removed | Use constructor DI or `$app->get('x')` in bootstrap context |
| `$app->service()` magic removed | Use `$app->get('service')` |
| `$app->module('name')` magic removed | Use `$app->get('module')->get('name')` |
| `$app->config('name')` magic removed | Use `$app->get('config')('name')` |
| `$app->error(callback)` removed | Use `$app->get('events')->on('exception', new ExceptionListenerWrapper(callback))` |
| `Container::__call()` deleted | No more dynamic method dispatch on container |
| `StaticTrait` / `EventTrait` / `RouterTrait` deleted | Application class no longer uses any traits |

---

## Extension Migration Guide

For a complete step-by-step guide on updating third-party extensions:

**[PSR-11 Container Extension Migration Guide](step-2-0-1-extension-migration-guide.md)**

Covers service access patterns, service registration, controller constructor injection, exception handling, and a quick migration checklist.

---

## Version Progression

| Version | Step | Milestone |
|---------|------|-----------|
| **1.1.6** | Start | Pre-migration baseline |
| **1.1.7** | 2.0.1a | Native PSR-11 `ContainerInterface`, `Psr11Adapter` deleted, `app/modules/` reads migrated |
| **1.1.8** | 2.0.1b–d | DI infrastructure, system/installer/console migrated, `set()` added, `\ArrayAccess` removed |
| **1.2.0** | 2.0.1e | StaticTrait/EventTrait/RouterTrait deleted, `__call()` removed, all static proxies eliminated |
| **1.2.1** | 2.0.1e (bugfixes) | 10 post-merge bugfixes (subscribe regression, null guards, console commands, ExceptionListenerWrapper) |

---

## Cumulative Statistics

| Metric | Value |
|--------|-------|
| **Sub-steps completed** | 5 (2.0.1a through 2.0.1e) |
| **Pull requests merged** | 5 (#161, #167, #169, #171, #172) |
| **Total call sites migrated** | ~940 |
| ↳ ArrayAccess reads (`$app['x']` → `$app->get('x')`) | ~250 |
| ↳ ArrayAccess writes (`$app['x'] =` → `$app->set('x', ...)`) | ~60 |
| ↳ `isset($app['x'])` → `$app->has('x')` | ~25 |
| ↳ `App::*()` static proxy calls → constructor DI | ~430 |
| ↳ `App::abort()` → typed Symfony exceptions | ~80 |
| ↳ `App::redirect()` → injected router | ~18 |
| ↳ `App::on/subscribe/trigger()` → events service | ~30 |
| ↳ `App::getInstance()` bridges → DI | ~38 |
| ↳ `$app->service()` magic calls → `$app->get()` | ~30 |
| **Total files changed** | ~200+ (across all 5 PRs, some files touched in multiple steps) |
| **Constructors added/modified** | ~85 |
| **Traits deleted** | 3 (StaticTrait, EventTrait, RouterTrait) |
| **Classes deleted** | 1 (Psr11Adapter) |
| **New utility classes** | 3 (IntlServiceLocator, ModelServiceLocator, ExceptionListenerWrapper) |
| **New test files** | 4 (ContainerPsr11Test, ControllerResolverTest, IntlServiceLocatorTest, EventDispatcherCompatibilityTest) |
| **Container magic methods removed** | 7 (`__call`, `__callStatic`, `offsetGet`, `offsetSet`, `offsetExists`, `offsetUnset`, `static::$instance`) |
| **Post-merge bugfixes (v1.2.1)** | 10 |
| **Final test count** | 274 tests, 658 assertions, 0 failures |
| **CLI validation** | `php pagekit setup` ✅, `php pagekit list` ✅ (Pagekit 1.2.1) |

### Deferred Items (tracked for Step 2.1)

| Item | Current State | Target |
|------|--------------|--------|
| `EntityManager` singleton (`static::$instance`) | Tagged `// TODO: Must be refactored in Step 2.1` | Step 2.1 (Static Analysis) |
| `ModelServiceLocator` transitional static | Tagged `// TODO: Must be refactored in Step 2.1` | Step 2.1 — replace with DTO/presenter pattern |
| `mixed` typing in ~25+ controller constructors | Tracked in Issues #151, #153 | Steps 2.1.4, 2.1.6 (PHPStan level progression) |
| Missing integration tests for DI wiring | Tracked in Issue #156 | Step 2.1.9 (Test Coverage Expansion) |
