# PSR-11 Container Stage 1: Native ContainerInterface

**ROADMAP Step:** 2.0.1 (Stage 1)
**Branch:** `cursor/psr-11-container-core-71d2`
**Status:** In Progress

---

## Objective

Refactor `Pagekit\Container` to implement `Psr\Container\ContainerInterface` natively, eliminating the `Psr11Adapter` wrapper. ArrayAccess is kept but delegates to `get()`/`has()` so existing `$app['x']` call sites continue working without changes.

## Discovery

### Files with Psr11Adapter references:
- `app/modules/application/src/Container.php` (import + getPsr11Adapter method)
- `app/modules/application/src/Container/Psr11Adapter.php` (the adapter itself)
- `app/modules/application/src/Tests/ContainerPsr11Test.php` (tests using adapter)

### Files with getService/hasService references:
- `app/modules/application/src/Container.php` (method definitions)
- `app/modules/application/src/Container/Psr11Adapter.php` (delegates to getService/hasService)
- `app/modules/application/src/Application/Traits/StaticTrait.php` (calls hasService)
- `app/modules/application/src/Tests/ContainerPsr11Test.php` (test calls)

## Changes

### Container.php
- [ ] Add `implements \Psr\Container\ContainerInterface`
- [ ] Move resolution logic from `offsetGet()` into `get()`
- [ ] `offsetGet()` delegates to `get()`
- [ ] `offsetExists()` delegates to `has()`
- [ ] Remove `getService()`, `hasService()`, `getPsr11Adapter()`
- [ ] Remove `Psr11Adapter` import

### StaticTrait.php
- [ ] `case 'has':` → `static::$instance->has(...)`
- [ ] `case 'get':` → `static::$instance->get(...)`

### Psr11Adapter.php
- [ ] Delete file entirely

### Tests
- [ ] Update ContainerPsr11Test: remove adapter tests, use get/has directly
- [ ] Update ContainerTest: ensure ArrayAccess delegation works

## Safety Notes

- `NotFoundException extends \InvalidArgumentException` - so existing `catch(\InvalidArgumentException)` blocks still work
- `ContainerException extends \RuntimeException` - wraps service creation errors
- ArrayAccess kept as backward compatibility (removed in Stage 4)
