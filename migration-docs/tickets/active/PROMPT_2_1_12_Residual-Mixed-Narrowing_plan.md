# Ticket: Residual `mixed` narrowing (typed properties & signatures) — Step 2.1.12

## ARCHITECT OUTPUT

- **Current Step (ROADMAP):** 2.1.12
- **Scope:**
  - `app/system/modules/captcha/src/CaptchaListener.php` — `verifyToken()` params `mixed` → `string`; call site via typed request accessor + `(string)` config cast
  - `app/system/modules/site/src/Controller/NodeController.php` — `protected mixed $site` → promoted `private readonly SiteModule $site`; drop `ModuleManager`
  - `app/system/modules/site/src/SiteModule.php` — register `site` container service in `main()` (required for constructor DI, see Checklist Step 2)
  - `app/system/modules/site/src/Tests/NodeControllerTest.php` — constructor call-site update (drop ModuleManager mock, pass SiteModule mock directly)
  - `app/system/src/Model/DataModelTrait.php` — `public mixed $data` → `?array` with `@var array<string, mixed>|null`; null-guard in `get()`
  - Test-writer additions: `app/system/modules/captcha/src/Tests/` (new `CaptchaListenerTest.php` + `bootstrap.php`), `app/system/modules/site/src/Tests/SiteModuleTest.php` (new `site` service assertion)
- **Deferred:** None. The task prompt's §3 list (docblock array shapes, `PropertyTrait::__get/__set`, filter/loader/PSR-11 `get()` contracts, `PregReplaceFilter::filter()` return, `ExceptionListener::$controller` / `WrappedListener::$listener` callable properties) is **legitimate `mixed`** — a permanent non-goal, not future work. No `PHASE_*` amendment required.
- **Bridges:** None. All call sites are updated in-step; no compatibility layers.
- **Checklist:**
  1. **CaptchaListener: `verifyToken()` params → `string`.** In `app/system/modules/captcha/src/CaptchaListener.php`: change `verifyToken(mixed $gRecaptchaResponse, mixed $secret): ?string` → `verifyToken(string $gRecaptchaResponse, string $secret): ?string`. Update the call site in `onRequest()` to `$this->verifyToken($request->request->getString('gRecaptchaResponse'), (string) $this->captchaModule->config('recaptcha_secret'))`. Bag confirmed: the Vue interceptor (`app/interceptor.js`) writes the token into the JSON body and `Pagekit\Kernel\Event\JsonResponseListener` (`'request'` priority 130) replaces `$request->request` with the decoded JSON before the captcha listener runs (`'request'` priority −100), so the POST bag (`$request->request`) is correct. Behaviour preserved: empty string is falsy, so the `if ($gRecaptchaResponse && $secret)` unconfigured path is unchanged. Do **not** touch `post()` — its `phpstan-baseline.neon` entry (`curl_setopt_array` argument shape) must stay matched (`reportUnmatchedIgnoredErrors` defaults to on).
  2. **NodeController: `$site` → constructor-injected `SiteModule`; register `site` service.** ⚠️ The task prompt's claim "the container resolves module classes by type-hint" is **wrong**: `Pagekit\Kernel\Controller\ControllerResolver::instantiateController()` resolves constructor args **by parameter name** (`$container->has($paramName)`). `MigrationController`'s `SystemModule $system` only works because `SystemModule::main()` runs `$app->set('system', $this)`. No `site` service exists today, so:
     (a) In `SiteModule::main()` (`app/system/modules/site/src/SiteModule.php`) add `$app->set('site', $this);` (mirror of the `system` registration; no container-id collision — the `'site'` strings in `index.php` files are menu entries / event names, not services).
     (b) In `NodeController`: delete `protected mixed $site;` and the `$this->site = $this->module->get('system/site');` constructor-body assignment; add promoted `private readonly SiteModule $site` (import `Pagekit\Site\SiteModule`); drop the now-unused `ModuleManager $module` param and its import (its only use was that lookup — verified).
     (c) In `indexAction()` guard the nullable return: `array_values($this->site->getTypes() ?? [])` — `SiteModule::getTypes(): ?array` becomes visible to PHPStan once `$site` is typed (previously hidden behind `mixed`); happy path identical, and the former `array_values(null)` TypeError path becomes a safe empty list.
     (d) Update `NodeControllerTest::createController()` (call-site of the changed constructor): remove the `ModuleManager` mock indirection and pass the existing `SiteModule` mock directly; adjust the class docblock sentence about "resolved via a mocked ModuleManager".
  3. **DataModelTrait: `$data` → `?array`; ticket-wide sweep.** In `app/system/src/Model/DataModelTrait.php`: `public mixed $data = null;` → `public ?array $data = null;` with `/** @var array<string, mixed>|null */`. In `get()`, pass `$this->data ?? []` to `Arr::get()` (property may be `null` pre-hydration: `Repository::create()` loads with `convert: false` and no `data` key keeps the `null` default; DB hydration always uses `convert: true` — `EntityManager::hydrateOne()`/`hydrateAll()` — through `JsonArrayType::convertToPHPValue()`, which always returns an array). `set()`'s null-guard stays as-is. Affected entities (`Widget`, `User`, `Page`, `Node`) need no change; the only external write is `app/system/modules/widget/index.php:154`, which always assigns an array. Finally, run the sweep: `rg -n "verifyToken\(mixed|protected mixed \$site|public mixed \$data" app/` must return nothing, and PHPStan must pass with no new or unmatched baseline entries.

## EXECUTION STATE

<!-- Machine-readable progress index for the Orchestrator/Conductor. Mirrors the Checklist 1:1
     (same numbers + short titles). Size hint per step: S = small/atomic, M = medium,
     L = large or loop-risk. A step orchestrator flips its box to [x] in the SAME commit as that step's code + tests (after full step PASS incl. test-writer when applicable) -->

- [x] Step 1 (M) — CaptchaListener `verifyToken()` → `string` params + typed call site
- [x] Step 2 (M) — NodeController `SiteModule` constructor DI + `site` service registration
- [ ] Step 3 (S) — DataModelTrait `$data` → `?array` + final `mixed` sweep

## TESTING STRATEGY

- **Per step (production gate):** Refactorer → Verifier → Tester (PHPUnit + PHPStan) — production code must be green before any new tests are written
- **Per step (coverage — inline light):** test-writer → Verifier (test files only) → Tester (PHPUnit + PHPStan) — **skip** when the step changes no production PHP under `app/` or `packages/` (docs/config/ROADMAP-only steps); mark those steps `test-writer: skip` here
- **Per step notes:**
  - **Step 1:** test-writer adds `app/system/modules/captcha/src/Tests/CaptchaListenerTest.php` plus a `bootstrap.php` with an `__()` stub (pattern: `app/system/modules/mail/src/Tests/bootstrap.php`; PHPUnit auto-discovers `app/system/modules/*/src/Tests`). Exercise `onRequest()` through an anonymous subclass overriding `post()` — **no network calls**. Cases: captcha disabled → no-op; missing token/secret → `BadRequestHttpException` ('reCaptcha not probably configured.'); `{"success":true}` → passes; `{"success":false}` → `BadRequestHttpException` ('Invalid reCaptcha.'). Mock `Module` (config values), `Auth`, `Router`; use a real `RequestStack` with a pushed `Request` whose POST bag carries the token and whose attributes set `_captcha_verify`.
  - **Step 2:** the existing `NodeControllerTest` is updated by the **refactorer** (constructor call site, part of 2d). test-writer adds a `SiteModuleTest` case asserting `main()` registers the `site` container service resolving to the module instance (same style as its existing `nodeRepository` assertions).
  - **Step 3:** `test-writer: skip` — no observable contract change; `$data` behaviour is covered indirectly by existing entity/repository tests.
- **E2E (Execute — last checklist step only):** when ticking Step 3 completes every `## EXECUTION STATE` box, the Orchestrator delegates `"final E2E run"` to Tester (3 Playwright specs, local PASS/FAIL). **Not** gated on PR/CI — see `.cursor/agents/tester.md` § End-of-ticket E2E. Focus areas: captcha-guarded form submit (registration/login), admin site page create/edit (`NodeController`), any `data`-backed entity save/load (widget/user settings).
- **Finalize:** Orchestrator opens PR → waits on PHP Quality CI (`gh run watch`) → Bugbot → version/CHANGELOG/ROADMAP. GitHub Issue: #217 (`Closes #217` in PR metadata).
