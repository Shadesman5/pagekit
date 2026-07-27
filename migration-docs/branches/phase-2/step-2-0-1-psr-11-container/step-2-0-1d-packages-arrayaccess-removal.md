# PSR-11 Container Stage 4: Packages & Final ArrayAccess Removal

**ROADMAP Step:** 2.0.1d
**Branch:** `cursor/psr-11-container-final-packages-73f8`
**Previous Stage:** [PSR-11 Container Stage 3](step-2-0-1c-system-installer-console.md)
**Status:** Complete

---

## Objective

Complete the PSR-11 container migration by:

1. Adding an explicit `set()` method to `Container` for service registration
2. Migrating all `$app['x'] = ...` WRITE patterns (deferred from Stages 1–3) to `$app->set('x', ...)`
3. Migrating remaining `$app['x']` READ patterns in `packages/` to `$app->get('x')`
4. Adding constructor injection to blog package controllers
5. Removing `\ArrayAccess` from `Container` entirely
6. Updating `ContainerTest` to reflect the new API
7. Publishing an extension migration guide

---

## What Changed

### Container API

The `Container` class (`app/modules/application/src/Container.php`) no longer implements `\ArrayAccess`. The four ArrayAccess methods (`offsetGet`, `offsetSet`, `offsetExists`, `offsetUnset`) have been deleted. All service access now uses explicit PSR-11 methods plus a new `set()` method:

| Operation | Old (ArrayAccess) | New (PSR-11 + `set()`) |
|-----------|-------------------|------------------------|
| Read | `$app['x']` | `$app->get('x')` |
| Write | `$app['x'] = $val` | `$app->set('x', $val)` |
| Exists | `isset($app['x'])` | `$app->has('x')` |
| Delete | `unset($app['x'])` | *(removed, not supported)* |

### `set()` Method Signature

```php
public function set(string $id, mixed $value): void
```

- Stores a service definition (closure) or a scalar/object value.
- Throws `\RuntimeException` if the service has already been resolved (same guard as the old `offsetSet()`).
- `factory()` and `extend()` internally delegate to `set()`.

### Container Implements

```php
class Container implements \Psr\Container\ContainerInterface
```

`\ArrayAccess` is no longer in the implements clause. The `#[\ReturnTypeWillChange]` attributes have been removed.

---

## Migration Scope

### Service Registration (WRITE) — `$app['x'] = ...` to `$app->set('x', ...)`

All 24 WRITE patterns deferred from Stage 3 have been migrated:

| Area | Files | Writes Migrated |
|------|-------|-----------------|
| `app/modules/` (core) | 17 | ~35 |
| `app/system/` (system + sub-modules) | 11 | ~20 |
| `app/installer/` + `app/console/` | 4 | ~4 |
| **Total** | **32** | **~59** |

### Package READ Migrations — `$app['x']` to `$app->get('x')`

| File | Reads Migrated |
|------|----------------|
| `packages/pagekit/blog/scripts.php` | 4 |
| `packages/pagekit/blog/index.php` | 1 |
| **Total** | **5** |

### Package `isset()` Migrations — `isset($app['x'])` to `$app->has('x')`

| File | isset Migrated |
|------|----------------|
| `packages/pagekit/blog/scripts.php` | 1 |

### Controller Constructor Injection (Blog Package)

| Controller | Services Injected | Remaining Static Calls |
|------------|-------------------|------------------------|
| `BlogController` | `module` | `App::user()` (deferred) |
| `PostApiController` | `module` | `App::user()`, `App::request()`, `App::filter()` (deferred) |
| `CommentApiController` | `module` | `App::user()` (deferred) |
| `SiteController` | `module` | *(none)* |

### Application.php Internal Migration

| Pattern | Count |
|---------|-------|
| `$this['x'] = ...` to `$this->set('x', ...)` | 2 |
| `$this['x']->method()` to `$this->get('x')->method()` | 2 |

---

## ArrayAccess Removal Verification

After Step 11 (ArrayAccess removal), the following verification queries return zero results:

| Check | Command | Result |
|-------|---------|--------|
| No `$app['x']` anywhere | `rg '\$app\[' app/ packages/ --type php` | 0 matches |
| No `isset($app['x'])` | `rg 'isset\(\$app\[' app/ packages/ --type php` | 0 matches |
| No `$this['x']` in Container/Application | `rg '\$this\[' app/modules/application/src/ --type php` | 0 matches |
| No `ArrayAccess` in Container | `rg 'ArrayAccess' app/modules/application/src/Container.php` | 0 matches |

---

## ContainerTest Updates

`app/modules/application/src/Tests/ContainerTest.php` was rewritten to use the new API:

| Old Test Method | New Test Method |
|-----------------|-----------------|
| `testArrayAccessImplementation()` | `testSetAndGetMethods()` |
| `testOffsetGetThrowsExceptionForUndefined` | `testGetThrowsNotFoundException` |
| `testOffsetSetThrowsExceptionWhenOverriding` | `testSetThrowsExceptionWhenOverriding` |
| `$this->container['x'] = ...` | `$this->container->set('x', ...)` |
| `$this->container['x']` | `$this->container->get('x')` |
| `isset($this->container['x'])` | `$this->container->has('x')` |

New test added: `testSetMethod()` — covers scalar values, closures, and factory override prevention.

---

## Extension Migration Guide

A comprehensive migration guide for third-party extensions has been published:

**[PSR-11 Container Extension Migration Guide](step-2-0-1-extension-migration-guide.md)**

Covers:
- Service access changes (`$app['x']` to `$app->get('x')`)
- Service registration changes (`$app['x'] = ...` to `$app->set('x', ...)`)
- Controller constructor injection patterns
- Exception type changes (PSR-11 `NotFoundExceptionInterface`, `ContainerExceptionInterface`)
- Quick migration checklist

---

## Deferred Items (Step 2.0.1e)

The following patterns remain in the codebase and are tracked for the next migration step:

### StaticTrait Removal

All `App::*()` static proxy calls via `StaticTrait` will be removed. These currently delegate through `__call()` on the Container:

- `App::user()`, `App::db()`, `App::cache()`, `App::router()`, `App::request()`
- `App::url()`, `App::content()`, `App::feed()`, `App::response()`, `App::filter()`
- `App::abort()`, `App::redirect()`, `App::on()`, `App::trigger()`, `App::message()`
- `App::module('x')` shorthand

Tagged: `// TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)`

### `__call()` Magic Removal

The `Container::__call()` method that enables `$app->serviceName()` magic dispatch will be removed once StaticTrait is gone.

Tagged: `// TODO: Remove __call() magic in Step 2.0.1e (StaticTrait Removal)`

### Repository Pattern for Models

Models (`Post.php`, `Node.php`) currently use `App::getInstance()->get('module')->get('blog')` as a temporary bridge because they cannot use constructor injection.

Tagged: `// TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e`

Target resolution: introduce a Repository pattern so models access data through injected repositories instead of reaching into the container.

### Summary of Deferred Counts

| Pattern | Approximate Count | Target |
|---------|-------------------|--------|
| `App::*()` static proxy calls | ~400+ | Step 2.0.1e |
| `App::getInstance()->get()` bridges | ~20 | Step 2.0.1e |
| `__call()` magic on Container | 1 method | Step 2.0.1e |
| `App::translator()` in intl functions | 5 | Step 2.0.1e |

---

## Migration Summary

| Metric | Value |
|--------|-------|
| **ROADMAP Step** | 2.0.1d |
| **ArrayAccess removed from Container** | Yes |
| **`set()` method added** | Yes |
| **WRITE patterns migrated** | ~59 |
| **Package READ patterns migrated** | 5 |
| **Controllers with new DI** | 4 (blog package) |
| **Tests updated** | ContainerTest fully rewritten |
| **Extension guide published** | Yes |
| **Zero `$app['x']` remaining** | Verified |
