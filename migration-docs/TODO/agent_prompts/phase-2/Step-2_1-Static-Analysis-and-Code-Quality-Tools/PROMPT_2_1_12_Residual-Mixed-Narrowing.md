# Step 2.1.12: Residual `mixed` narrowing (typed properties & signatures)

<!-- conductor-mode: full -->

**ROADMAP:** 2.1.12. GitHub Issue: #217. Reference: `@ROADMAP.md`, `PHASE_2_MODERNISING.md` §2.1.12.

---

## CONTEXT

- **Depends on:** Current `develop` (PHPStan Level 8 landed; EntityManager DI / repository migration already reshaped `NodeController`'s constructor — plan against that shape).
- **Risk:** Low — signature/property narrowing plus one small call-site change; no behaviour change.
- **Scope:** This is **not** a blanket "remove all `mixed`" pass (there is no PHPStan Level 9 step planned). Only the three genuinely narrowable cases below are in scope; the legitimate `mixed` listed under §3 stays.

**Goal:** Narrow the last few avoidable `mixed` occurrences left after the Level-8 typing sweep to honest, concrete types — purely developer-facing type accuracy (IDE/PHPStan-friendly).

---

## 0. SAFETY CHECKS (CRITICAL)

**Before starting — verify all of these:**

1. **Branch up-to-date with `develop`** (includes Level-8 typing and EntityManager DI — `NodeController` already injects `NodeRepository` / `roleRepository`; only the `$site` / `ModuleManager` narrowing remains here).
2. **Full suite green:**
   ```bash
   ./app/vendor/bin/phpunit
   ./app/vendor/bin/phpstan analyse
   ```

**After every change:**

```bash
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse
```

**IF ANY FAILS → STOP AND FIX!**

---

## 1. DISCOVERY

Confirm the three sites are still present at planning time (line numbers may have drifted):

```bash
rg -n "verifyToken\(mixed" app/system/modules/captcha/
rg -n "protected mixed \$site" app/system/modules/site/src/Controller/NodeController.php
rg -n "public mixed \$data" app/system/src/Model/DataModelTrait.php
```

---

## 2. NARROWINGS

### 2.1. `CaptchaListener::verifyToken()` — `mixed` params → `string`

**File:** `app/system/modules/captcha/src/CaptchaListener.php`

- Signature (`~:146`): `verifyToken(mixed $gRecaptchaResponse, mixed $secret): ?string` → `verifyToken(string $gRecaptchaResponse, string $secret): ?string`
- Call site (`~:141`): feed real strings via Symfony 6.4 typed accessors instead of `$request->get(...)` (which may return array/null) and the `mixed` module config:
  - `$request->get('gRecaptchaResponse')` → `$request->request->getString('gRecaptchaResponse')` (confirm the token arrives in the `request`/POST bag; adjust the bag if the interceptor sends it elsewhere)
  - `$this->captchaModule->config('recaptcha_secret')` → `(string) $this->captchaModule->config('recaptcha_secret')` (`Module::config()` returns `mixed`)
- Behaviour preserved: `verifyToken()` guards `if ($gRecaptchaResponse && $secret)`; an empty string is falsy, so the empty/unconfigured path is unchanged.

### 2.2. `NodeController::$site` — `mixed` → `SiteModule` (constructor DI)

**File:** `app/system/modules/site/src/Controller/NodeController.php`

- `protected mixed $site;` → `private readonly SiteModule $site` injected via the constructor (add `use Pagekit\Site\SiteModule;`).
- Remove the `$this->site = $this->module->get('system/site');` assignment (`~:31`).
- `ModuleManager $module` is used **only** for that lookup — drop it from the constructor once `$site` is injected (verify no other `$this->module` use remains).
- Precedent: `app/system/src/Controller/MigrationController.php` already injects a concrete module (`private readonly SystemModule $system`), so the container resolves module classes by type-hint.
- `$this->site` is used as `->getTypes()` / `->getType()` / `->config()` — all `SiteModule` methods.

### 2.3. `DataModelTrait::$data` — `mixed` → `?array`

**File:** `app/system/src/Model/DataModelTrait.php`

- `public mixed $data = null;` → `public ?array $data = null;` with `/** @var array<string, mixed>|null */`.
- **Why `?array` (nullable), not `array`:** the column is `#[ORM\Column(type: 'json')]`; the DBAL `json` type is globally overridden to the array-safe `JsonArrayType` (`app/modules/database/index.php:100`; no `json_array` alias). `JsonArrayType::convertToPHPValue()` always returns an array (null → `[]`), so a **hydrated** entity's `$data` is always an array — but a freshly `new`/`create()`d entity keeps the `null` default until `set()` is called. Hence the property stays nullable.
- **Keep the null-guard in `get()`:** `Arr::get()` is typed `array $array`, and `$data` may be `null` (pre-hydration). Keep `(array) $this->data` (or switch to `$this->data ?? []`) — no compatibility shim beyond this documented guard.

---

## 3. OUT OF SCOPE — deliberately kept `mixed`

Do **not** touch these (legitimate `mixed`, reviewed):

- Docblock array shapes, magic-method proxies (`PropertyTrait::__get/__set`), filter/loader/PSR-11 `get()` contracts, polymorphic `preg_replace` returns.
- `PregReplaceFilter::filter()` return (honest polymorphic return).
- `ExceptionListener::$controller`, `WrappedListener::$listener` (PHP forbids `callable` as a native property type → `mixed` + `@var callable…` is the idiomatic pattern).
- `Node::getUrl()` / presenter URL reference typing is already on the presenter path — not here.

---

## 4. TESTING (step-specific)

**PHPUnit — default:** pure type/property narrowing needs **no new test** if behaviour is unchanged.

**Add or adjust tests only where an observable contract changes:**

- **`CaptchaListener::verifyToken(string, string)`** — no PHPUnit coverage exists today. Add a focused unit test for the typed request-accessor path and config cast; mock HTTP/request dependencies, no kernel boot.
- **`NodeController` → constructor `SiteModule` DI** — optional unit test (precedent: `MenuApiControllerTest` — direct `new Controller(...)`, no kernel).
- **`DataModelTrait::$data` → `?array`** — no dedicated test needed; covered indirectly by entities using the trait.

**E2E focus (final Execute step):** captcha-guarded form submit; site page create/edit (`NodeController`); any `data`-backed entity save/load.

---

## SUCCESS CRITERIA

- `CaptchaListener::verifyToken()` params typed `string`; call site uses typed request accessor + `(string)` config cast
- `NodeController::$site` typed `SiteModule` via constructor DI; `ModuleManager` dropped if unused
- `DataModelTrait::$data` typed `?array` with `@var array<string, mixed>|null`; `get()` null-guard retained
- `rg "verifyToken\(mixed|protected mixed \$site|public mixed \$data"` returns nothing
- No behaviour change; `./app/vendor/bin/phpunit` + `./app/vendor/bin/phpstan analyse` pass (no new baseline entries)
