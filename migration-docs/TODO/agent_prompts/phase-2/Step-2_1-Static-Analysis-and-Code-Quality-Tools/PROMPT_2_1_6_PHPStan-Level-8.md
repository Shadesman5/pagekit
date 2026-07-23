# Step 2.1.6: PHPStan Level 7→8 (Strict Typing)

**ROADMAP:** 2.1.6. GitHub Issue: #153. Reference: `@ROADMAP.md`.

---

## CONTEXT

- **Depends on:** Step 2.1.5 (PHPStan Level 7 — property types and null safety)
- **Risk:** Medium — may require architecture decisions (interfaces, generics)
- **What Level 8 checks:** No `mixed` without explicit operations, strict call checks, type-safe comparisons.

**Goal:** Eliminate `mixed` where avoidable, introduce template parameters for generic collections where appropriate, and pass PHPStan Level 8.

---

## 0. SAFETY CHECKS (CRITICAL)

**AFTER EVERY BATCH:**
```bash
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse
```
**IF ANY FAILS → STOP AND FIX!**

**Before starting:** Verify: Branch is Up-to-Date with `develop`. (Step 2.1.5 merged).

---

## 1. PREPARATION

### 1.1. Bump PHPStan level

```neon
parameters:
    level: 8
```

### 1.2. Discover errors

Level 8 errors are typically:
- **"Parameter has no type"** (function params)
- **"Cannot call method on mixed"** → narrow the type
- **"Cannot access property on mixed"**
- **"Binary operation on mixed"**

---

## 2. ELIMINATE `mixed`

### 2.1. Narrow types where possible

```php
// BEFORE:
public function process($data) { ... }

// AFTER:
public function process(array $data): array { ... }
```

### 2.2. Justified `mixed` exceptions

Some uses of `mixed` are correct and unavoidable:
- **PSR-11 Container** `get()` returns `mixed` — this is correct per specification
- **Event/config data** — dynamic plugin data is genuinely mixed
- **Template rendering** — view variables are mixed by design

Document justified exceptions with PHPDoc:

```php
/** @return mixed Genuinely unknown type — plugin API return value */
```

### 2.3. PHPDoc generics for collections

Where arrays have known structure:

```php
/** @var array<string, mixed> */
private array $config;

/** @return array<int, User> */
public function getUsers(): array { ... }
```

---

## 3. KNOWN INTERFACE REFACTORS (from 2.1.1 review)

These were identified during the Step 2.1.1 code review and must be resolved in this step:

### 3.1. MailerInterface split

**Problem:** `Pagekit\Mail\MailerInterface` conflates two roles — the Mailer itself and mail plugins both implement it. The Mailer has no-op `beforeSend()`/`afterSend()` just to satisfy the interface.

**Fix:** Split into `MailerInterface` (send, create, registerPlugin) and `MailPluginInterface` (beforeSend, afterSend). Update `ImpersonatePlugin` to implement `MailPluginInterface`, update `registerPlugin()` parameter type.

**Files:** `Mailer.php`, `MailerInterface.php` (new: `MailPluginInterface.php`), `ImpersonatePlugin.php`, `Message.php`, `MessageInterface.php`

### 3.2. EntityManager singleton removal

**Problem:** `EntityManager` stores `static::$instance = $this` in constructor and provides `getInstance()`. This bypasses DI and will be flagged by Level 8 strict typing.

**Fix:** Remove `static $instance` property and `getInstance()`. Update all call sites to use injected `EntityManager` via container.

**Files:** `EntityManager.php`, all files calling `EntityManager::getInstance()`

### 3.3. FileLocatorAsset static service locator

**Problem:** `FileLocatorAsset` uses `static mixed $file` and `static mixed $locator` set via `setServices()` — same anti-pattern as EntityManager singleton. Properties are untyped.

**Fix:** Replace static properties with constructor injection (requires asset factory changes). Type `$file` as `Filesystem`, `$locator` as `ResourceLocator`.

**Files:** `FileLocatorAsset.php`, `view/index.php`

### 3.4. ResponseListener mixed $url

**Problem:** `ResponseListener::$url` is `mixed` but used as callable `($this->url)($path)`.

**Fix:** Type-narrow to `callable` or the correct URL generator interface.

**Files:** `ResponseListener.php`

### 3.5. General architect decision points

If the Refactorer encounters additional issues:
- A method returning `mixed` that's used by multiple callers with different expectations → Escalate to Architect
- An interface method that needs a generic type parameter → Architect decides if generics are worth the complexity
- A significant refactor needed to narrow a type → Architect scopes it

---

## 4. LEVEL 9 — DO NOT USE

**❌ Level 9 is explicitly excluded.** It's too strict for a CMS with dynamic extension APIs. Level 8 is the target ceiling.

---

## AUDIT FINDINGS (Phase 1 Review)

The following architectural/typing issues were identified during the Phase 1 codebase audit:

- `IntlServiceLocator` — static service locator holding `ContainerInterface`; replace with proper DI chains
- `ModelServiceLocator` — **type-narrow only** in this step: give `getUrl()` / `getUser()` / `getModule()` concrete return types instead of `mixed` (do NOT remove the locator or change its call sites). The full removal + DTO/presenter refactor is **Step 2.1.10** (Entity Presentation Layer, GitHub #204).
- `PackageController` — uses `ContainerInterface $app` as God-DI object; inject specific services instead
- `#[AllowDynamicProperties]` on `Node` and `Widget` models — remove attribute and fix dynamic property usage via typed properties
- ORM `Metadata`, `Relation`, `PropertyTrait` — incomplete typing throughout (mixed params, missing return types)
- `NodeModelTrait` — static request-scoped cache array (`static ?array $nodes`); replace with proper PSR-6 caching or explicit cache layer
- `UrlGeneratorInterface` (Routing) — naming collision with Symfony's interface; rename to e.g. `LinkReferenceType` and move `LINK_URL` constant
- `GetResponseEvent` (Auth) — misleading Symfony-5 naming; rename to e.g. `AuthResponseEvent`

---

## ADDITIONAL SCOPE — Static service-locator / setter-DI cleanups (repo TODO inventory §2)

Routed from the repo TODO inventory (`migration-docs/TODO/PHP-TODOs-without-roadmap-step-assignment.md`, §§1–2) and mirrored in `PHASE_2_MODERNISING.md` Step 2.1.6 + GitHub #153. These carry canonical `Step 2.1.6` TODO comments in the source.

- `app/modules/routing/src/Event/AliasListener.php` (~line 49) — remove the dead inline-query-string parser: the `strpos($aliasName, '?')` block plus the dependent `strtok($alias->getName(), '?')` clause in the `array_filter` (line 39). Zero callers use the `?param=value` suffix in alias names; the `$defaults` parameter of `Routes::alias()` replaced it. Do NOT touch `Router::generate('route?foo=bar', ...)` — that is a separate, actively used mechanism. (From Step 2.1.3 closure review.)
- `app/modules/application/src/Application/Console/Application.php` (~line 51) — convert console commands from setter injection (`Command::setContainer()` in `add()`) to constructor DI / a command factory.
- `packages/pagekit/blog/src/UrlResolver.php` (~line 25) — replace the `private static` cache/module setters with proper DI. **Blocker:** the Router instantiates resolvers via `new $class` without DI, so the static locator cannot be removed in isolation — the routing factory (`ParamsResolver` bootstrap) must support DI first (align with Step 1.8 routing architecture). The `mixed $cache` typing portion was already completed in Step 2.0.3.
- `packages/pagekit/theme-one/functions.php` (~line 8) — replace the `ThemeOneHelpers` static `UrlProvider` (global state at the theme boundary) with proper DI once template helper functions support injection; type all properties.

---

## SUCCESS CRITERIA

- PHPStan Level 8 passes
- No baseline entries (or minimal, documented, justified exceptions)
- `mixed` only used where genuinely unavoidable (documented)
- No architecture regressions
- All PHPUnit tests pass

---

## VALIDATION CHECKLIST

- [ ] `phpstan.neon` level set to 8
- [ ] `mixed` eliminated where avoidable
- [ ] Justified `mixed` uses documented
- [ ] Generic PHPDoc annotations where useful
- [ ] `MailerInterface` split into Mailer + Plugin interfaces
- [ ] `EntityManager` singleton pattern removed
- [ ] `FileLocatorAsset` static service locator replaced with DI
- [ ] `ResponseListener::$url` typed (no `mixed`)
- [ ] `./app/vendor/bin/phpstan analyse` passes at Level 8
- [ ] All PHPUnit tests pass
- [ ] Baseline updated (zero or documented exceptions only)
