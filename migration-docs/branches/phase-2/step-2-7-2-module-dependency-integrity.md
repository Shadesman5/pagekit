# Step 2.7.2 — Module Dependency Integrity

<!-- Branch doc for Roadmap Step 2.7.2.
     Path: migration-docs/branches/phase-2/step-2-7-2-module-dependency-integrity.md -->

**Branch:** `feature/module-dependency-integrity`
**ROADMAP Step:** 2.7.2 (Module Dependency Integrity)
**GitHub Issue:** [#268](https://github.com/Shadesman5/pagekit/issues/268)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-09-25 00:56
**Completed:** _TBD_

---

## 🎯 Overview

A module `require` that is missing or switched off is refused, and that module is not loaded. The enabled set is the extensions list plus the active theme, copied when `SystemModule::main` starts. Core modules stay loadable because they sit in the always-loaded closure of `system`, not because they appear in `extensions`.

---

## ✅ What Changed

### Fail-closed requirements and `requiredBy` (Checklist Step 1)

`resolveModules` throws when a `require` is not registered or is registered but disabled, and does not load that module. Until `setActivityPolicy` runs, a registered module still counts as active, so `load('system')` can walk core requirements. Afterwards, active means the enabled set or the always-loaded closure of the boot module. `requiredBy` lists direct dependers in registration order and is dropped on the next `register()`.

`Arr::pull` writes the packed list back through its by-ref parameter after `unset`. Rebinding the parameter would leave `Config::pull` storing the gapped keys.

`enableAction` pops the error and exception handlers it pushed. Putting the previous callable back with `set_error_handler` / `set_exception_handler` would leave those frames on the stack.

| File | Change |
|---|---|
| `app/modules/application/src/Module/UnsatisfiedRequirementException.php` (new) | English sentence names the depender and the requirement and says either "not registered" or "registered but disabled". `messageId()` is that sentence, for the panel to translate. |
| `app/modules/application/src/Module/ModuleManager.php` | `resolveModules` throws `UnsatisfiedRequirementException` or the existing circular-requirement message. `setActivityPolicy` / `isActive` / `requiredBy` / `assertRequirements`. `load()` of an unknown name still throws `Undefined module`. `assertRequirements` returns when the name is not registered. |
| `app/system/src/SystemModule.php` | `main()` calls `setActivityPolicy` with `extensions` plus a non-empty `site.theme` before `ExtensionLoader::load`. The list is the copy taken at the start of `main`. |
| `app/package/src/PackageManager.php` | `enable()` calls `assertRequirements` before any config write, lifecycle hook, or `package.enable`, and only when `Package::get('module')` is a string. |
| `app/package/src/Controller/PackageController.php` | `enableAction` translates `messageId()` into `error` even when debug is off. Any other failure, including a circular requirement, stays the generic enable error when debug is off. The cleanup closure calls `restore_error_handler()` and `restore_exception_handler()`. |
| `app/modules/application/src/Util/Arr.php` | `pull` assigns `array_values` through the by-ref parameter after `unset`. |

#### Tests (Checklist Step 1)

| File | Change |
|---|---|
| `tests/Unit/Module/ModuleRequirementTest.php` (new) | Fixture modules. Unregistered vs registered-but-disabled (the depender is not loaded), a registered requirement loads before a policy exists, `load` of the boot module still throws "not registered", a boot-module requirement stays active when it is absent from the enabled set, an active cycle throws the existing message and loads nothing, a cycle behind a disabled module is reported as disabled, `load` of an unknown name throws `Undefined module` while `assertRequirements` of one does not, `requiredBy` is direct dependers once in registration order, `register()` rebuilds the always-loaded closure, a `require` that is not a module name is refused. |
| `tests/Unit/Package/PackageEnableRequirementTest.php` (new) | `enable()` throws `UnsatisfiedRequirementException` or `Circular requirement "%s > %s" detected.` before the package changes, including when the module is already loaded and when the cycle sits behind a disabled module. A non-string `module` skips the walk. An unregistered module name does not throw `Undefined module`. |
| `tests/Unit/Package/PackageControllerEnableRequirementTest.php` (new) | The enable `error` names the depender and the requirement for both sentences with debug off. A circular requirement stays the generic enable error when debug is off and is the exception message when debug is on. |
| `tests/Unit/System/SystemModuleActivityTest.php` (new) | A theme set when `main()` starts satisfies a requirement that is absent from `extensions`. A name pushed onto the config service afterwards does not. A module `system` requires is active without being enabled. An enabled extension whose requirement is disabled is recorded and not loaded; `extensions` is left packed (`['pages']` at index 0). |
| `tests/Unit/Extension/ExtensionRequirementFailureTest.php` (new) | An already-enabled extension whose requirement is missing is auto-disabled and the sentence is stored. A theme whose requirement fails is recorded and not switched off. |

Gates: production verifier PASS; PHPUnit + PHPStan PASS; test-writer done; test-file verifier PASS; coverage PHPUnit + PHPStan PASS.

---

## 🧠 Key Decisions (Rationale)

- **No policy yet means registered modules are active.** Treating "absent from `extensions`" as disabled would fail `load('system')`, which walks core `require` before `SystemModule::main` can set the closure. An unregistered require still throws out of that load and is not an extension auto-disable. A registered-but-disabled require throws only after the policy is set, and that module is not loaded.
- **`enable()` is not `load()`.** `assertRequirements` returns when the root is not registered, so a package can be enabled without its module being in the registered set. `load()` of an unknown name still throws `Undefined module: $name`. A registered module throws before any config write, lifecycle hook, or `package.enable`.
- **A disabled module is not walked.** A cycle reachable only through one is reported as registered-but-disabled. Walking it would load it. A cycle among the module being activated and modules that are active still throws `Circular requirement "%s > %s" detected.`, and the disabled module stays out of the loaded set.
- **The sentence is translated in the controller.** The exception message is the English sentence with the names filled in. The resolver does not call `__()`, because the walk runs before the translator is booted. `enableAction` translates `messageId()` even when debug is off. Any other enable failure, including a circular requirement, stays the generic enable error when debug is off.
- **`requiredBy` is direct and cached.** Registration order, duplicates once, cache dropped on `register()`. The always-loaded closure (the boot module plus registered modules reachable through `require`) is rebuilt then. A module `system` requires is active after the policy is set even when it is absent from `extensions`.
- **The enabled set is a copy taken at the start of `main`.** `extensions` plus a non-empty `site.theme` — the list `ExtensionLoader` loads. Reading the config service on each check would see names `enable()` pushes onto that object later in the request. A theme set when `main()` starts satisfies a requirement even when it is absent from `extensions`.
- **The walk takes a module name.** `assertPackageRequirements(string)` skips a non-string `Package::get('module')`. The parameter stays `string` because `Package::get` is the generic container.
- **`Arr::pull` packs through the reference.** `$array = array_values($array)` after `unset`. `$array = &$packed` rebinds the parameter, so `Config::pull` would write the gapped array back. `Config::pull('extensions', $name)` on `['needs-off', 'pages']` leaves `['pages']` at index 0.
- **Handler cleanup pops the frame it pushed.** `restore_error_handler()` and `restore_exception_handler()`. Reinstalling the previous callable pushes another frame, and skipping that call when the previous handler is null leaves the new frame in place. After `enableAction()` returns, both stacks match the ones that were active when the action was entered.

---

## 💥 Breaking Changes (Extensions)

Enabling a package whose registered module requires something unregistered or registered-but-disabled fails before the package changes. The panel `error` names both modules even when debug is off. An already-enabled extension in that state is auto-disabled and the sentence is stored on the failure record. A theme is recorded and left on. There is no override.

`Config::pull` on a list (through `Arr::pull`) now returns a packed list. Pulling `needs-off` from `['needs-off', 'pages']` leaves `['pages']` at index 0.

---

## ⚠️ Risks & Rollout Notes

`load('system')` still fails the request when a core `require` is not registered. That throw is not an extension auto-disable.

An update calls `enable()` without going through `load()` again. The requirement walk in `enable()` is the one that refuses before config is written.

---

## 🔐 Security & Data Impact

A registered-but-disabled requirement is not loaded. The resolver does not recurse into it.

---

## 🛡️ No-Mercy Compliance

`resolveModules` no longer logs an unsatisfied `require` and continues. Nothing still loads that module, and there is no flag that asks it to.

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: `Arr::pull` writes the reindexed list back after `unset`. `enableAction` restores the previous error and exception handlers.

---

## 📋 Phase 1 Audit Closure

_TBD / None_

---

## 👤 Maintainer action

<!-- Human-only follow-ups the maintainer must do (ruleset flips, real Docker/Apache
     verification, secrets, etc.). Not ROADMAP deferrals — those go under Deferred. -->

_TBD / None_

---

## 📚 Deferred / Out-of-Scope

<!-- Future ROADMAP/PHASE work, explicit non-goals, bridges. Do NOT put maintainer
     Manual Work here — that belongs under Maintainer action above. -->

_TBD / None_

---

## 📌 Follow-on (ROADMAP)

_TBD / None_

---

## 🧊 Parked (unplanned)

<!-- Filled by the post-close review after Finalize: what the finished work left unowned,
     one bullet per finding with the ROADMAP step whose area it belongs to. Doc-writer leaves None. -->

_TBD / None_

---

## 🧹 Cleanup

<!-- Removed in passing (deleted files, dropped baseline/ignore entries, dead code). Doc-writer from
     the handover; the post-close review adds what the diff shows and the handover missed. -->

_TBD / None_

---

## 🛡️ Audit

<!-- No-Mercy leftovers of the shipped diff that have no owner (forward-debt tags, added baseline
     entries, ANOMALIES patterns), each with the ROADMAP step that resolves it. Post-close review. -->

_TBD / None_

---

## 🎁 Bonus

<!-- Work delivered beyond the ticket. Doc-writer from the handover; the post-close review adds
     what the diff shows and the handover missed. -->

_TBD / None_

---

## 🔍 Research

<!-- The verified facts behind each DECISION the post-close review raised — symbols, call chain,
     what each exit deletes or adds — so the maintainer can decide without re-reading the tree. -->

_TBD / None_

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/active/PROMPT_2_7_2_Module-Dependency-Integrity_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_7_2_Module-Dependency-Integrity.md`
- Predecessor: Step 2.7.1c — Runtime Composer Removal
- Successor: Step 2.7.3 — Static Module Registration

---

## 📊 <Step-specific appendix>

<!-- Narrative/structural notes only. Never a metrics table (coverage %, MSI, test counts): quality
     numbers are CI-owned — link the sticky quality-report comment + dashboard instead. -->

_TBD — remove this section if not applicable._
