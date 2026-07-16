# Step 2.1.12 — Residual `mixed` narrowing

**Branch:** `feature/residual-mixed-narrowing`
**ROADMAP Step:** 2.1.12 (Residual `mixed` narrowing)
**GitHub Issue:** [#217](https://github.com/Shadesman5/pagekit/issues/217)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-07-16 01:13
**Completed:** _TBD_

---

## 🎯 Overview

_TBD_

---

## ✅ What Changed

### CaptchaListener `verifyToken()` → `string` (Checklist Step 1)

| File | Change |
|---|---|
| `app/system/modules/captcha/src/CaptchaListener.php` | `verifyToken()` params `mixed` → `string`; `onRequest()` call site uses `$request->request->getString('gRecaptchaResponse')` + `(string)` cast of `recaptcha_secret`. `post()` untouched (baseline `curl_setopt_array` entry must stay matched). |

### Tests (Step 1)

| File | Change |
|---|---|
| `app/system/modules/captcha/src/Tests/CaptchaListenerTest.php` | **New.** `onRequest()` coverage via anonymous subclass overriding `post()` (no network): disabled no-op; missing token/secret → BadRequest; success/failure JSON responses. |
| `app/system/modules/captcha/src/Tests/bootstrap.php` | **New.** `__()` stub + `require_once` of `CaptchaListener.php` — `Pagekit\Captcha\` is module-autoload only (not Composer PSR-4). |

### NodeController `SiteModule` DI + `site` service (Checklist Step 2)

| File | Change |
|---|---|
| `app/system/modules/site/src/SiteModule.php` | `main()` registers `$app->set('site', $this)` for constructor DI by parameter name. |
| `app/system/modules/site/src/Controller/NodeController.php` | Dropped `ModuleManager` + `protected mixed $site`; promoted `private readonly SiteModule $site`. Null-guards: `getTypes() ?? []`; `getType($node->type ?? '')`. |
| `app/system/modules/site/src/Tests/NodeControllerTest.php` | Constructor call site passes `SiteModule` mock directly (no `ModuleManager`); docblock updated. |

### Tests (Step 2)

| File | Change |
|---|---|
| `app/system/modules/site/src/Tests/SiteModuleTest.php` | Asserts `main()` registers `site` resolving to the module instance (injected container only). |
| `app/system/modules/site/src/Tests/NodeControllerTest.php` | `testIndexActionReturnsEmptyTypesWhenGetTypesIsNull` — null `getTypes()` → empty `types` list. |

---

## 🧠 Key Decisions (Rationale)

- **Controller DI is by parameter name, not type-hint.** `ControllerResolver::instantiateController()` resolves constructor args via `$container->has($paramName)`. The task prompt's claim that the container resolves module classes by type-hint is wrong — `MigrationController`'s `SystemModule $system` works only because `SystemModule::main()` runs `$app->set('system', $this)`. Checklist Step 2 therefore registers `$app->set('site', $this)` in `SiteModule::main()` (mirror of `system`; no `site` service exists today; no container-id collision with menu/event `'site'` strings).
- **Captcha module tests need an explicit `require_once`.** First coverage-tester run failed (`Class "Pagekit\Captcha\CaptchaListener" not found`) because the new bootstrap stubbed `__()` only. Fixed by requiring the production class — same pattern as comment/widget/blog module Tests bootstraps.
- **Typed `$site` surfaces a second nullability gap.** Plan called out `getTypes() ?? []` only. After injection, PHPStan also flagged `getType($node->type)` (`string|null` → `string`). Fixed with `$node->type ?? ''` (empty string → existing "Type not found" path).

---

## ⚠️ Breaking Changes (Extensions)

_TBD_

---

## ⚠️ Risks & Rollout Notes

_TBD / None_

---

## 🔐 Security & Data Impact

_TBD / None_

---

## 🛡️ No-Mercy Compliance

_TBD_

---

## ✅ Verification (links only)

- CI run: _TBD_
- Notable deviations: Step 1 coverage tester failed once on missing `CaptchaListener` autoload in the new captcha Tests bootstrap; retry PASS after `require_once` (716 tests, PHPStan clean). Production gate was green on first pass. Step 2 production tester failed once — PHPStan `argument.type` at `NodeController.php:86` (`getType()` expects `string`, `$node->type` is `string|null`); retry PASS after `$node->type ?? ''` (716 tests, PHPStan clean). Coverage gate: 718 tests, PHPStan clean.

---

## 📋 Phase 1 Audit Closure

_TBD_

---

## 📚 Deferred / Out-of-Scope

None. Deferred/Bridges are empty. Task-prompt §3 remaining `mixed` (docblock array shapes, `PropertyTrait::__get/__set`, filter/loader/PSR-11 `get()` contracts, `PregReplaceFilter::filter()` return, `ExceptionListener::$controller` / `WrappedListener::$listener` callables) is legitimate permanent non-goal — not future work. No `PHASE_*` amendment required.

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/active/PROMPT_2_1_12_Residual-Mixed-Narrowing_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_12_Residual-Mixed-Narrowing.md`
- Predecessor: Step 2.1.11 — EntityManager DI (remove singleton)
- Successor: Step 2.1.13 — TinyMCE Security Patch (~5.10.9)

---

## 📊 <Step-specific appendix>

None.
