# PSR-11 Container Vollmodernisierung – Sub-Step 2.0.1a (Part 1): Container Core

**ROADMAP:** 2.0.1a (Part 1 of 2). Reference: `@ROADMAP.md`.
**Status:** ✅ COMPLETED — See `migration-docs/branches/PSR11_CONTAINER_STAGE1.md`

---

## CONTEXT

**Current State:**
- ✅ PSR-11 compatibility layer (Schritt 1.6): `getPsr11Adapter()`, `getService()`, `hasService()`
- ✅ Psr11Adapter wraps Container for PSR-11
- ❌ Container uses ArrayAccess (`$app['x']`) as primary API
- ❌ ~1000+ call sites use `$app['x']` or `App::x()` across codebase

**This Stage:** Refactor Container to implement `ContainerInterface` natively. **No call site changes.** ArrayAccess delegates to `get()` so existing code keeps working. Psr11Adapter is removed.

**Next Stages:**
- Stage 2: Migrate `app/modules/` call sites to `$app->get()`
- Stage 3: Migrate `app/system/`, `app/installer/`, `app/console/`
- Stage 4: Migrate `packages/`, remove ArrayAccess, final cleanup

---

## 0. SAFETY CHECKS (CRITICAL)

**Design principle:** Each stage keeps the system functional and testable. No intermediate "broken" state. After Stage 1, the system must work exactly as before – only internal implementation changes.

**Test environment:** All commands from **workspace root**. Console entrypoint: `php pagekit` (e.g. `php pagekit setup`, `php pagekit list`). PHPUnit: `./app/vendor/bin/phpunit` (Pagekit uses `app/vendor`, not root `vendor`). For HTTP checks, start the app first (e.g. `php pagekit start -s localhost:8080 --no-ansi &`), then run curl.

**AFTER EVERY LOGICAL CHANGE:**
```bash
php pagekit setup
# If HTTP checks required: ensure app is running (php pagekit start -s localhost:8080 --no-ansi &), then:
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/admin
./app/vendor/bin/phpunit
```
**IF ANY FAILS → STOP AND FIX!**

**Before starting:** `develop` up to date, Phase 1 (1.7–1.14) complete.

---

## 1. PREPARATION

1.1. Create branch from `develop` (agent chooses branch name)

1.2. Create `migration-docs/branches/PSR11_CONTAINER_STAGE1.md`

1.3. **Discovery** (document results):
```bash
rg "getPsr11Adapter|Psr11Adapter" app/ --type php -l
rg "getService|hasService" app/ --type php -c
```

---

## 2. CONTAINER CHANGES

### 2.1. Implement ContainerInterface in Container

**File:** `app/modules/application/src/Container.php`

- Add `implements \Psr\Container\ContainerInterface`
- Rename `getService(string $id)` → `get(string $id)` (PSR-11)
- Rename `hasService(string $id)` → `has(string $id)` (PSR-11)
- Ensure `get()` throws `NotFoundException` (implements `NotFoundExceptionInterface`)
- Ensure `ContainerException` implements `ContainerExceptionInterface`
- Keep: `factory()`, `extend()`, `raw()`, `keys()` (not in PSR-11, but needed)

**Important:** The resolution logic (closures, factories, raw values) lives in `get()`. `offsetGet` must delegate to `get()` – do NOT create a circular call (offsetGet → get → offsetGet). Move the current offsetGet resolution logic into get(), then make offsetGet simply `return $this->get((string) $name);`.

### 2.2. ArrayAccess → delegate to get()

**File:** `app/modules/application/src/Container.php`

- `offsetGet($name)`: `return $this->get((string) $name);`
- `offsetExists($name)`: `return $this->has((string) $name);`
- Keep `offsetSet` and `offsetUnset` for service registration (modules use `$app['x'] = fn`)

**Result:** All `$app['x']` calls work via `get()`. No behavior change for callers.

### 2.3. Remove Psr11Adapter

- Delete `app/modules/application/src/Container/Psr11Adapter.php`
- Remove `getPsr11Adapter()` from Container
- Search for `getPsr11Adapter()` usage – update to use Container directly (it now implements ContainerInterface)

### 2.4. Update StaticTrait

**File:** `app/modules/application/src/Application/Traits/StaticTrait.php`

- `case 'get':` → `return static::$instance->get($args[0] ?? '');`
- `case 'has':` → `return static::$instance->has($args[0] ?? '');`
- Default case (App::db(), App::cache(), etc.): keep delegating to `offsetGet($name)` which now calls `get()`. No change needed.

---

## 3. UPDATE TESTS

**File:** `app/modules/application/src/Tests/ContainerPsr11Test.php`

- Remove Psr11Adapter tests – Container is now PSR-11 directly
- Replace `getService()` with `get()`, `hasService()` with `has()`
- Add: `$this->assertInstanceOf(ContainerInterface::class, $container)`
- Ensure NotFoundException, ContainerException tests still pass

**File:** `app/modules/application/src/Tests/ContainerTest.php`

- Replace `getService`/`hasService` with `get`/`has`
- ArrayAccess tests: keep (they now delegate to get/has)

---

## 4. VALIDATION

- [ ] `php pagekit setup` succeeds
- [ ] Web + admin respond
- [ ] All PHPUnit tests pass
- [ ] No references to Psr11Adapter
- [ ] Container implements ContainerInterface
- [ ] ArrayAccess still works (delegates to get/has)

---

## 5. DOCUMENTATION

Update `PSR11_CONTAINER_STAGE1.md`:
- Summary of changes
- Before/after for Container
- Note: Stage 2 will migrate call sites from `$app['x']` to `$app->get('x')`

---

## SUCCESS CRITERIA

- Container implements `Psr\Container\ContainerInterface` directly
- Psr11Adapter removed
- `get()` and `has()` are the PSR-11 methods
- ArrayAccess delegates to get/has (temporary – removed in Stage 4)
- All tests pass, no regressions
