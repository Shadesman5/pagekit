# Step 2.7.3 — Static Module Registration

<!-- Branch doc for Roadmap Step 2.7.3.
     Path: migration-docs/branches/phase-2/step-2-7-3-static-module-registration.md -->

**Branch:** `feature/static-module-registration`
**ROADMAP Step:** 2.7.3 (Static Module Registration)
**GitHub Issue:** [#266](https://github.com/Shadesman5/pagekit/issues/266)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-09-26 22:29
**Completed:** _TBD_

---

## 🎯 Overview

_TBD_

---

## ✅ What Changed

### Static discovery and load-time entry point (Checklist Step 1)

`register()` reads `module.json` and does not execute `index.php`. `load()` applies the PSR-4 map from the registered record, includes `path/index.php` when that file exists, then writes `name`, `require`, `include`, `autoload`, `nodes`, and `path` back from that record. Defaults (`main`, `type`, `class`, `config`) are applied in that merge and are not stored at registration.

A missing `name` or `""` is skipped, and `getRegistrationFailures()` stays empty. A present `name` that is not a string throws `ModuleManifestException` naming `name`, so a wrong JSON type stays a failure the archive refusal can name. A `require` element that is a string, including `""`, is kept in order. `requires()` and the always-loaded closure skip `""`. A non-string element is a registration failure for that path, and the name is not registered.

A non-array return from the entry point throws `RuntimeException` `Module "%s" entry point must return an array.` The module stays registered, `get($name)` is null, and the PSR-4 prefix added before the include stays. `includeEntry()` returns `mixed` because `include` yields the file's value or `1`. An `array` return type would turn that refusal into a `TypeError`.

Field readers keep native `string|array`, `array`, and scalar-or-structure parameters. PHPDoc names the iterable value `array<array-key, mixed>`. `{"include":1}`, `{"require":{}}`, `{"autoload":[]}`, and `{"nodes":[]}` are registration failures naming the field. A `nodes` object still stores nested scalars, nulls, and arrays.

`packages/pagekit/blog/index.php` and `packages/pagekit/theme-one/index.php` keep `name` and `autoload` as PHP literals beside `module.json`. `PackageArchive` still parses `index.php`, and those two trees are what the shipped-archive tests zip. Blog's `nodes` live only in `module.json`. Opening the zipped blog tree still yields module `blog`, autoload `Pagekit\Blog\` => `src`, and `require` `[]`. Opening theme-one still yields module `theme-one` and an empty autoload map. `load()` writes those keys back from the registered record.

| File | Change |
|---|---|
| `app/modules/kernel/src/Module/ModuleManifest.php` (new) | `decode()` reads one JSON object. `FILE` is `module.json`, `ENTRY` is `index.php`, `MAX_BYTES` is 1 MiB. Unknown keys are dropped. |
| `app/modules/kernel/src/Module/ModuleManifestException.php` (new) | `RuntimeException` for invalid JSON, a non-object, or a field of the wrong type. |
| `app/modules/kernel/src/Module/ModuleManager.php` | `register()` checks size before reading, skips a path segment that starts with `.`, stores a manifest exception under that path, and does not include the entry point. `load()` merges defaults, the registered record, and the entry-point array, then writes the registration keys back. |
| `app/system/app.php`, `app/console/app.php` | Register `packages/*/*/module.json`, `app/modules/*/module.json`, `app/package/module.json`, `app/installer/module.json`, and `app/system/module.json`. Console also registers `app/console/module.json`. |
| `app/installer/app.php` | Same core globs. Still does not glob `packages/`. |
| First-party `module.json` under `app/modules`, `app/system`, `app/system/modules`, `app/package`, `app/installer`, `app/console`, `packages/pagekit` (new) | `name`, plus `require`, `include`, `autoload`, and `nodes` where the entry point had them. `system`'s `include` is `modules/*/module.json`. |
| First-party `index.php` in those trees, except blog and theme-one | Those registration keys removed. `main`, events, routes, config, and the other load-time keys stay. |
| `packages/pagekit/blog/index.php` | `nodes` removed. `name` and `autoload` stay. |
| `packages/pagekit/theme-one/index.php` | Unchanged. `name` and `autoload` stay beside the new `module.json`. |

#### Tests (Checklist Step 1)

| File | Change |
|---|---|
| `tests/Unit/Module/ModuleManifestTest.php` (new) | A missing or blank `name` is skipped. A non-string `name` names the field. `require` keeps `""`. A wrong type for `require`, `include`, `autoload`, or `nodes` names that field. A `nodes` object keeps nested values. |
| `tests/Unit/Module/ModuleLoadTest.php` (new) | Defaults fill omitted keys and keep keys the entry point sets. The registered record wins. A preLoader appended from `main()` sees a later entry point only. The autoload map is applied at `load()`. A string return or a throw leaves `get($name)` null, the module registered, and the prefix in place. A missing entry point still loads. `load()` does not register. |
| `tests/Unit/Module/ModuleRegistrationTest.php` | An entry point that throws or writes a marker still registers and does not run. Invalid JSON and a wrong field type are failures and do not stop the next module. A document with no `name` is skipped. A path segment that starts with `.` is not registered. |
| `tests/Unit/Module/ModuleRequirementTest.php` | A blank `require` element is kept, and both `requires()` and the always-loaded closure skip it. An entry-point `require` is overwritten from the registered record. |
| `tests/Unit/Package/PackageModuleBoundaryTest.php` | Each boot registers `app/package/module.json`. The installer boot does not register `blog` or `theme-one`. Only the console boot registers `console`. The package manifest's registration keys are read from `module.json`. |
| `tests/Unit/Module/ModuleImportEdgeTest.php`, `app/modules/kernel/src/Tests/KernelFoundationOwnershipTest.php`, and the extension, package, console, system, and snapshot tests that read `name`, `require`, or `autoload` | Those fields are read from `module.json`. Fixtures under `tests/fixtures/modules` gained a `module.json`, and the registration keys left their `index.php`. `UploadedPackageRoundTripTest` registers `module.json` on the extracted tree. |

Gates: production verifier PASS; production tester PASS; test-writer done; test-files verifier PASS; coverage tester PASS. No deviations.

---

## 🧠 Key Decisions (Rationale)

_TBD / None_

---

## 💥 Breaking Changes (Extensions)

_TBD / None_

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

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: _TBD / None_

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

- Ticket: `migration-docs/tickets/active/<task-slug>_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/.../PROMPT_X_Y_Z_....md`
- Predecessor: Step X.Y.(Z−1) — _TBD_
- Successor: Step X.Y.(Z+1) — _TBD_

---

## 📊 <Step-specific appendix>

<!-- Narrative/structural notes only. Never a metrics table (coverage %, MSI, test counts): quality
     numbers are CI-owned — link the sticky quality-report comment + dashboard instead. -->

_TBD — remove this section if not applicable._
