# Step 2.0.7: Event Dispatcher Bridge Removal

**ROADMAP:** 2.0.7 — Foundation Consolidation.  
**GitHub Issue:** #184.
**Prerequisite:** Step 2.0.6 (Test Infrastructure Cleanup) merged on your branch.

**Also read:** `.cursor/ROADMAP.md` (5 aggressive rules), `migration-docs/TODO/PHASE_2_MODERNISING.md` (Step 2.0.7 section).

---

## 1. CONTEXT

### 1.1 Why this step exists

Steps 1.7 and 1.9 introduced `SymfonyEventDispatcherBridge` to provide Symfony `EventDispatcherInterface` compatibility alongside Pagekit's own Event Dispatcher. Phase 1 audit reveals:

- **Zero production consumers** of the `symfony.event_dispatcher` service
- The bridge is **dead code** — no service, controller, or listener requests it
- It violates Rule 1 (No Compatibility Layers) and Rule 4 (Delete over Wrap)

### 1.2 Decision: Keep Pagekit's Dispatcher

Pagekit's Event Dispatcher is **not** a compatibility layer — it is the core event system with ~147 call sites and unique features:

- String-based events with extra arguments (`trigger('boot', [$app])`)
- Module manifest `events` array (declarative listener registration)
- `PrefixEventDispatcher` for modular namespaces
- `Event` with `ArrayAccess` for parameter passing

Replacing it with Symfony's dispatcher would require a Phase-level effort (comparable to Step 2.0.1) with no benefit for extension developers. The Pagekit API (`on`/`trigger`/`subscribe`) is simpler and well-documented.

### 1.3 Scope

This is a **minimal** cleanup: delete 2 files, update 1 file. No changes to Pagekit's dispatcher itself.

---

## 2. SAFETY & VERIFICATION

**Workspace root.** PHPUnit: `./app/vendor/bin/phpunit`. Console: `php pagekit list`.

**After the changes:**

```bash
./app/vendor/bin/phpunit
php pagekit list
```

---

## 3. PRE-CHECK: VERIFY ZERO CONSUMERS

Before deleting anything, confirm no code depends on the bridge:

```bash
rg "symfony.event_dispatcher" app/ packages/ --glob "*.php"
rg "SymfonyEventDispatcherBridge" app/ packages/ --glob "*.php"
rg "Symfony\\\\Component\\\\EventDispatcher\\\\EventDispatcherInterface" app/ packages/ --glob "*.php"
```

**Expected results:**
- `symfony.event_dispatcher` → only in `app/modules/application/index.php` (registration)
- `SymfonyEventDispatcherBridge` → only in `app/modules/application/index.php` (import) + the bridge file itself + the test file
- Symfony `EventDispatcherInterface` → only in bridge + test

If ANY other file references these, **investigate before deleting**. That consumer must be migrated to use Pagekit's `$app->get('events')` dispatcher directly.

---

## 4. DELETE BRIDGE CLASS

**Delete:** `app/modules/application/src/Event/SymfonyEventDispatcherBridge.php`

This class implements `Symfony\Component\EventDispatcher\EventDispatcherInterface` and maps all calls to Pagekit's dispatcher. It has zero consumers.

---

## 5. DELETE COMPATIBILITY TEST

**Delete:** `app/modules/application/src/Tests/EventDispatcherCompatibilityTest.php`

This test only verifies that the bridge correctly maps Symfony API to Pagekit API. With the bridge gone, the test has no purpose.

---

## 6. REMOVE SERVICE REGISTRATION

**File:** `app/modules/application/index.php`

Find and remove the `symfony.event_dispatcher` service registration. It looks approximately like:

```php
$app->set('symfony.event_dispatcher', function ($app) {
    return new SymfonyEventDispatcherBridge($app->get('events'));
});
```

Also remove the corresponding `use` import for `SymfonyEventDispatcherBridge` at the top of the file.

---

## 7. FINAL VERIFICATION

```bash
# No references should remain
rg "SymfonyEventDispatcherBridge|symfony.event_dispatcher" app/ packages/

# Tests pass
./app/vendor/bin/phpunit

# Console works
php pagekit list
```

---

## 8. SUCCESS CRITERIA

- [ ] `SymfonyEventDispatcherBridge.php` deleted
- [ ] `EventDispatcherCompatibilityTest.php` deleted
- [ ] `symfony.event_dispatcher` service removed from `application/index.php`
- [ ] Zero references to bridge or service in `app/` and `packages/`
- [ ] `./app/vendor/bin/phpunit` green
- [ ] `php pagekit list` OK

---

## 9. OUT OF SCOPE (flagged for later)

| Item | Target Step |
|------|-------------|
| Rename `GetResponseEvent` in `app/modules/auth/` (confusing Symfony-5 name) | 2.1.x |
| `ExceptionListenerWrapper` adapter pattern in kernel | 2.1.x |
| Console `execute()` return types (`: int`) | 2.1.4 |

---

**End of prompt.**
