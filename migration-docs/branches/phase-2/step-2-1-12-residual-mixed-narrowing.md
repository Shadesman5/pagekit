# Step 2.1.12 — Residual `mixed` narrowing

**Branch:** `feature/residual-mixed-narrowing`
**ROADMAP Step:** 2.1.12 (Residual `mixed` narrowing)
**GitHub Issue:** [#217](https://github.com/Shadesman5/pagekit/issues/217)
**Pull Request:** [#227](https://github.com/Shadesman5/pagekit/pull/227) (merged)
**Status:** ✅ Complete — Merged
**Started:** 2026-07-16 01:13
**Completed:** 2026-07-16 02:45
**Issue #217:** closed — all Tasks + Acceptance Criteria checked

---

## 🎯 Overview

Narrows the last three avoidable `mixed` sites to concrete types — no behaviour
change; IDE/PHPStan clarity only. `CaptchaListener::verifyToken()` takes
`string` params with a typed request accessor at the call site;
`NodeController` injects `SiteModule` via constructor DI (with a new `site`
container registration); `DataModelTrait::$data` is `?array` with an
`array<int|string, mixed>|null` docblock.

Legitimate remaining `mixed` (docblock shapes, magic accessors, PSR-11/filter
contracts, callable properties) is a permanent non-goal.

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

### DataModelTrait `$data` → `?array` (Checklist Step 3)

| File | Change |
|---|---|
| `app/system/src/Model/DataModelTrait.php` | Property `$data` typed `?array` with `@var array<int\|string, mixed>\|null` (not `array<string, mixed>` — see Key Decisions). |

### Tests (Step 3)

None (test-writer skip per TESTING STRATEGY).

---

## 🧠 Key Decisions (Rationale)

- **Controller DI is by parameter name, not type-hint.** `ControllerResolver::instantiateController()` resolves constructor args via `$container->has($paramName)`. The task prompt's claim that the container resolves module classes by type-hint is wrong — `MigrationController`'s `SystemModule $system` works only because `SystemModule::main()` runs `$app->set('system', $this)`. Checklist Step 2 therefore registers `$app->set('site', $this)` in `SiteModule::main()` (mirror of `system`; no `site` service exists today; no container-id collision with menu/event `'site'` strings).
- **Captcha module tests need an explicit `require_once`.** First coverage-tester run failed (`Class "Pagekit\Captcha\CaptchaListener" not found`) because the new bootstrap stubbed `__()` only. Fixed by requiring the production class — same pattern as comment/widget/blog module Tests bootstraps.
- **Typed `$site` surfaces a second nullability gap.** Plan called out `getTypes() ?? []` only. After injection, PHPStan also flagged `getType($node->type)` (`string|null` → `string`). Fixed with `$node->type ?? ''` (empty string → existing "Type not found" path).
- **`$data` docblock must allow int keys.** First Step 3 production tester failed: narrowing to `@var array<string, mixed>|null` conflicted with `Arr::set($this->data, …)` typing the by-ref array as `array<int|string, mixed>` (`assign.propertyType` ×5 on Post/Node/Page/User/Widget). Widened to `array<int|string, mixed>|null`.

---

## ⚠️ Breaking Changes (Extensions)

**Constructor / signature narrowing (internal).** Extensions that subclass or
manually instantiate these types must match the new shapes:

- `CaptchaListener::verifyToken(string $gRecaptchaResponse, string $secret)` —
  callers passing non-string values will TypeError under PHP 8.
- `NodeController` — constructor takes `SiteModule $site` (no `ModuleManager`);
  the `site` container service must exist (registered in `SiteModule::main()`).
- `DataModelTrait::$data` — typed `?array`; assigning a non-array/non-null value
  TypeErrors.

No schema, route, or public HTTP API changes.

---

## ⚠️ Risks & Rollout Notes

None. Behaviour preserved (empty-string captcha path, null `getTypes()` → empty
list, null `$data` pre-hydration). Core and bundled packages updated in-step;
third-party subclasses of the three sites above need a one-line signature fix.

---

## 🔐 Security & Data Impact

None. Captcha verification flow unchanged (typed accessors only); no auth,
schema, or storage changes.

---

## 🛡️ No-Mercy Compliance

| Rule | How satisfied |
|---|---|
| **1 — No compatibility layers** | Old `mixed` signatures deleted; no dual-typed overloads. |
| **2 — No adapters** | Call sites updated in-step (`getString`, `(string)` cast, `SiteModule` DI); no wrappers. |
| **3 — Breaking changes allowed internally** | Constructor/`verifyToken`/`$data` shapes narrowed; platform helpers untouched. |
| **4 — Delete over wrap** | `ModuleManager` lookup and `protected mixed $site` removed, not shimmed. |
| **5 — Flagging & audit debt** | No new bridges/TODOs; remaining `mixed` documented as permanent non-goal (Deferred). |

---

## ✅ Verification (links only)

| Gate | Result |
|---|---|
| CI — PHP Quality | ✅ success |
| Coverage gap pass | skipped (no codecov comment within ~5 min after CI green) |
| Cursor Bugbot | ✅ clean |
| E2E | ✅ PASS |
| Finalize fix-loop | none |

**CI run:** https://github.com/Shadesman5/pagekit/actions/runs/29467007309

**Notable deviations:** Step 1 coverage tester failed once on missing `CaptchaListener` autoload in the new captcha Tests bootstrap; retry PASS after `require_once` (716 tests, PHPStan clean). Production gate was green on first pass. Step 2 production tester failed once — PHPStan `argument.type` at `NodeController.php:86` (`getType()` expects `string`, `$node->type` is `string|null`); retry PASS after `$node->type ?? ''` (716 tests, PHPStan clean). Coverage gate: 718 tests, PHPStan clean. Step 3 production tester failed once — PHPStan `assign.propertyType` ×5 at `DataModelTrait.php:40` (`Arr::set` by-ref `array<int|string, mixed>` vs `array<string, mixed>|null`); retry PASS after docblock widen to `array<int|string, mixed>|null` (718 tests, PHPStan clean). Coverage skipped; Final E2E PASS (PHPUnit/PHPStan/smoke/E2E install+auth+dashboard).

---

## 📋 Phase 1 Audit Closure

None.

---

## 📚 Deferred / Out-of-Scope

None. Deferred/Bridges are empty. Task-prompt §3 remaining `mixed` (docblock array shapes, `PropertyTrait::__get/__set`, filter/loader/PSR-11 `get()` contracts, `PregReplaceFilter::filter()` return, `ExceptionListener::$controller` / `WrappedListener::$listener` callables) is legitimate permanent non-goal — not future work. No `PHASE_*` amendment required.

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/done/PROMPT_2_1_12_Residual-Mixed-Narrowing_plan.md` (archive after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/Step-2_1-Static-Analysis-and-Code-Quality-Tools/PROMPT_2_1_12_Residual-Mixed-Narrowing.md`
- Predecessor: Step 2.1.11 — EntityManager DI (remove singleton)
- Successor: Step 2.1.13 — TinyMCE Security Patch (~5.10.9)

---

## 📊 <Step-specific appendix>

None.
