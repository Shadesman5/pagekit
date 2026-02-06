# PSR-11 Container Vollmodernisierung – Stage 3: System Modules, Installer, Console

## CONTEXT

**Previous Work (Stage 1 + 2) Completed:**
- ✅ Container implements ContainerInterface, get()/has() native
- ✅ Psr11Adapter removed
- ✅ All `app/modules/` call sites migrated to `$app->get()` / `App::get()`
- ⏳ ArrayAccess still present (delegates to get/has)

**This Stage:** Migrate call sites in `app/system/`, `app/installer/`, `app/console/` from `$app['x']` and `App::x()` to `$app->get('x')` and `App::get('x')`.

**Next Stage:** Stage 4 = Packages, remove ArrayAccess, final cleanup.

---

## AGGRESSIVE MODERNIZATION RULES

1. **NO COMPATIBILITY LAYERS** – Use get() only.
2. **NO ADAPTERS** – Update all call sites directly.
3. **BREAKING CHANGES ALLOWED INTERNALLY** – Public HTTP/API unchanged.
4. **DELETE OVER WRAP** – Remove old patterns.
5. **LEGACY HACKS MUST BE MARKED** – Use `// TODO: Must be refactored later` if unavoidable.
6. **HONEST COMMENTS** – No hidden backward compatibility.

---

## 0. SAFETY CHECKS (CRITICAL)

**Design principle:** System remains functional. Same as Stage 2 – we migrate call sites, ArrayAccess still works for any unmigrated code.

**AFTER EVERY LOGICAL CHANGE:**
```bash
php pagekit setup
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/admin
./app/vendor/bin/phpunit
npx playwright test tests/e2e/specs/01-setup/installation.spec.js
```
**IF ANY FAILS → STOP AND FIX!**

**Before starting:** Stage 1 + 2 merged into `develop`. Branch from `develop`.

---

## 1. PREPARATION

1.1. Create branch from `develop` (agent chooses branch name)

1.2. Create `migration-docs/branches/PSR11_CONTAINER_STAGE3.md`

1.3. **Discovery:**
```bash
# app/system/
rg '\$app\[['\''"]\w+' app/system/ --type php -n
rg 'App::(db|cache|config|module|request|router|events|kernel|url|view|mailer|auth|user|translator|csrf|session|cookie|filter|log|info|finder|theme|widget|menu|node|locator|get|has)' app/system/ --type php -n

# app/installer/
rg '\$app\[['\''"]\w+' app/installer/ --type php -n
rg 'App::' app/installer/ --type php -n

# app/console/
rg '\$app\[['\''"]\w+' app/console/ --type php -n
rg 'App::' app/console/ --type php -n
```

Document: file list, occurrence counts. Create checklist.

---

## 2. MIGRATION RULES

Same as Stage 2:
- `$app['x']` → `$app->get('x')`
- `isset($app['x'])` → `$app->has('x')`
- `App::db()`, `App::cache()`, etc. → `App::get('db')`, `App::get('cache')`
- `App::module('name')` → `App::get('module')->get('name')`
- Service registration: `$app['x'] = fn` stays for now (Stage 4)

---

## 3. MIGRATION ORDER

1. **app/system/** – System modules (dashboard, cache, finder, info, mail, settings, site, theme, user, widget, etc.)
2. **app/installer/** – Installer controllers, views, helpers
3. **app/console/** – Console commands

After each area: run safety checks. Installer and console are critical – test `php pagekit setup` and `php pagekit list`.

---

## 4. INSTALLER-SPECIFIC

Installer runs before config exists. Verify:
- `App::get('config')` during install – StaticTrait has dummy config fallback
- No crashes when config not yet created
- Run fresh install E2E: `npx playwright test tests/e2e/specs/01-setup/installation.spec.js`

---

## 5. VALIDATION

- [ ] No `$app['x']` in app/system/, app/installer/, app/console/ (except registration)
- [ ] No `App::x()` shortcuts (use `App::get('x')`)
- [ ] `php pagekit setup` works
- [ ] `php pagekit list` works
- [ ] Fresh install E2E passes
- [ ] All PHPUnit tests pass

---

## 6. DOCUMENTATION

Update `PSR11_CONTAINER_STAGE3.md`:
- Changed files
- Installer/console-specific notes
- Notes for Stage 4

---

## SUCCESS CRITERIA

- All app/system/, app/installer/, app/console/ call sites use get()
- Installer works (fresh install)
- Console works
- All tests pass
