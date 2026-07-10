# Step 2.1.12: Residual `mixed` narrowing (typed properties & signatures)

<!-- conductor-mode: full -->

**ROADMAP:** 2.1.12. GitHub Issue: #217. Reference: `@ROADMAP.md`.

---

## CONTEXT

- **Depends on:** Step 2.1.6 (PHPStan Level 8) — these are the residual narrowable candidates left after the L8 sweep.
- **Risk:** Low — signature/property narrowing plus one small call-site change; no behaviour change.
- **Scope:** This is **not** a blanket "remove all `mixed`" pass (there is no PHPStan Level 9 step planned). Only the three genuinely narrowable cases below are in scope; the legitimate `mixed` listed under §3 stays.

**Goal:** Narrow the last few avoidable `mixed` occurrences from the Step 2.1.6 `mixed` audit to honest, concrete types — purely developer-facing type accuracy (IDE/PHPStan-friendly).

---

## 0. SAFETY CHECKS (CRITICAL)

**Before starting — verify all of these:**

1. **Branch up-to-date with `develop`** (Step 2.1.6 merged).
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

Confirm the three sites are still present and unchanged at planning time (line numbers may have drifted):

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
- **Why `?array` (nullable), not `array`:** the column is `#[ORM\Column(type: 'json')]`; the DBAL `json` type is globally overridden to the array-safe `JsonArrayType` (`app/modules/database/index.php:100`; the former `json_array` alias was removed in Step 2.1.7). `JsonArrayType::convertToPHPValue()` always returns an array (null → `[]`), so a **hydrated** entity's `$data` is always an array — but a freshly `new`/`create()`d entity keeps the `null` default until `set()` is called. Hence the property stays nullable.
- **Keep the null-guard in `get()`:** `Arr::get()` is typed `array $array`, and `$data` may be `null` (pre-hydration). Keep `(array) $this->data` (or switch to `$this->data ?? []`) — it is **not** redundant.

---

## 3. OUT OF SCOPE — deliberately kept `mixed`

Do **not** touch these (legitimate `mixed`, reviewed):

- Docblock array shapes, magic-method proxies (`PropertyTrait::__get/__set`), filter/loader/PSR-11 `get()` contracts.
- `PregReplaceFilter::filter()` return (honest polymorphic return).
- `ExceptionListener::$controller`, `WrappedListener::$listener` (PHP forbids `callable` as a native property type → `mixed` + `@var callable…` is the idiomatic pattern).
- `Node::getUrl()`'s `mixed $referenceType` → `int|string` is handled in **Step 2.1.10** (relocated there as a presentation concern), not here.

---

## 4. TESTING

- `./app/vendor/bin/phpunit` — all green (captcha listener, node controller, model-trait-backed entities).
- `./app/vendor/bin/phpstan analyse` — no new baseline entries; the three narrowed sites drop their `mixed`.
- Add/adjust a focused test only where the narrowing changes an observable contract (e.g. captcha typed-accessor path); pure type narrowing needs no new test.
- Playwright E2E (3 specs) at Final Test: captcha-guarded form submit, site page create/edit (NodeController), any `data`-backed entity save/load.

---

## 5. AGGRESSIVE MODERNIZATION RULES

- **DELETE OVER WRAP** — narrow types directly; no `mixed` + runtime type-juggling shim.
- **NO NEW BRIDGES** — no compatibility casts beyond the documented null-guard in `get()`.
- **INTERNAL BREAKING CHANGES ALLOWED** — `verifyToken()` / `NodeController` signatures may change; update call sites in the same PR.

---

## AUDIT FINDINGS (Phase 1 Review / Step 2.1.6 `mixed` audit — scoped to this step)

- `CaptchaListener::verifyToken(mixed, mixed)` — narrowable to `string` via typed request accessors + config cast.
- `NodeController::$site` (`mixed`) — concrete `SiteModule` via constructor DI.
- `DataModelTrait::$data` (`mixed`) — `?array` (JSON column is array-safe; nullable pre-hydration).

---

## SUCCESS CRITERIA

- The three sites no longer declare `mixed`; types are concrete (`string`, `SiteModule`, `?array`).
- No behaviour change; existing tests pass.
- PHPStan passes with no new baseline entries.
- Playwright E2E (3 specs) pass at Final Test.

---

## VALIDATION CHECKLIST

_Acceptance bar — not the full checklist._

- [ ] `CaptchaListener::verifyToken()` params typed `string`; call site uses typed request accessor + `(string)` config cast
- [ ] `NodeController::$site` typed `SiteModule` via constructor DI; `ModuleManager` dropped if unused
- [ ] `DataModelTrait::$data` typed `?array` with `@var array<string, mixed>|null`; `get()` null-guard retained
- [ ] `rg "verifyToken\(mixed|protected mixed \$site|public mixed \$data"` returns nothing
- [ ] `./app/vendor/bin/phpunit` passes
- [ ] `./app/vendor/bin/phpstan analyse` passes (no new baseline entries)
- [ ] Playwright E2E (3 specs) pass
