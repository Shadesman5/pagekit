# Step 2.7.3 — Static Module Registration

<!-- Branch doc for Roadmap Step 2.7.3.
     Path: migration-docs/branches/phase-2/step-2-7-3-static-module-registration.md -->

**Branch:** `feature/static-module-registration`
**ROADMAP Step:** 2.7.3 (Static Module Registration)
**GitHub Issue:** [#266](https://github.com/Shadesman5/pagekit/issues/266)
**Pull Request:** [#306](https://github.com/Shadesman5/pagekit/pull/306)
**Status:** ✅ Complete
**Started:** 2026-09-26 22:29
**Completed:** 2026-09-27 09:16

---

## 🎯 Overview

Discovery reads `module.json` and does not execute `index.php`. `load()` applies the PSR-4 map from that record, includes `path/index.php` when the file exists, and writes `name`, `require`, `include`, `autoload`, `nodes`, and `path` back from the record. Defaults (`main`, `type`, `class`, `config`) are applied in that merge and are not stored at registration.

`PackageArchive` reads `name`, `autoload`, and `require` from the same file and does not open `index.php`. A missing `module.json` or a manifest exception is `ArchiveRefusedException` before anything is written. An `include` that does not stay inside the package is refused the same way.

An included manifest does not replace a name already registered. An include that leaves the module is not applied. A later caller-supplied manifest still replaces an earlier caller-supplied name, and `system`'s relative `modules/*/module.json` still registers a child whose name is free.

First-party modules carry those registration keys in `module.json`. Blog and theme-one still repeat `name` and `autoload` in `index.php`. `load()` writes those keys back from the registered record.

---

## ✅ What Changed

### Static discovery and load-time entry point (Checklist Step 1)

`register()` reads `module.json` and does not execute `index.php`. `load()` applies the PSR-4 map from the registered record, includes `path/index.php` when that file exists, then writes `name`, `require`, `include`, `autoload`, `nodes`, and `path` back from that record. Defaults (`main`, `type`, `class`, `config`) are applied in that merge and are not stored at registration.

A missing `name` or `""` is skipped, and `getRegistrationFailures()` stays empty. A present `name` that is not a string throws `ModuleManifestException` naming `name`, so a wrong JSON type stays a failure the archive refusal can name. A `require` element that is a string, including `""`, is kept in order. `requires()` and the always-loaded closure skip `""`. A non-string element is a registration failure for that path, and the name is not registered.

A non-array return from the entry point throws `RuntimeException` `Module "%s" entry point must return an array.` The module stays registered, `get($name)` is null, and the PSR-4 prefix added before the include stays. `includeEntry()` returns `mixed` because `include` yields the file's value or `1`. An `array` return type would turn that refusal into a `TypeError`.

Field readers keep native `string|array`, `array`, and scalar-or-structure parameters. PHPDoc names the iterable value `array<array-key, mixed>`. `{"include":1}`, `{"require":{}}`, `{"autoload":[]}`, and `{"nodes":[]}` are registration failures naming the field. A `nodes` object still stores nested scalars, nulls, and arrays.

`packages/pagekit/blog/index.php` and `packages/pagekit/theme-one/index.php` still return `name` and `autoload` beside `module.json`. `PackageArchive` reads `module.json` and does not open `index.php`. Those two trees are what the shipped-archive tests zip. Blog's `nodes` live only in `module.json`. Opening the zipped blog tree yields module `blog`, autoload `Pagekit\Blog\` => `src`, and `require` `[]`. Opening theme-one yields module `theme-one` and an empty autoload map. `load()` writes those keys back from the registered record.

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

### Archive reads `module.json` (Checklist Step 2)

`PackageArchive` reads `name`, `autoload`, and `require` through `ModuleManifest` and does not open `index.php`. A missing `module.json` or a `ModuleManifestException` is `ArchiveRefusedException`. Those sentences name `module.json`.

A `JsonException` previous keeps the existing `cannot be parsed` sentence and includes that JSON error, so a non-UTF-8 document never reaches a path quote. A document that is not an object says the archive's `module.json` is not a JSON object. The invalid field is read from the manifest exception's sentence. `include` is `gives no 'include' of non-empty strings` and `nodes` is `gives no 'nodes' object`. `name`, `autoload`, and `require` keep their own sentences.

The decoder exception is the refusal, so a bad `require` or `include` wins over an autoload path, and only the decoded map is checked. A repeated JSON key is the one `json_decode` keeps. An autoload key PHP stored as an int is cast to a string prefix and then checked as a folder. The decoder already accepted that map, so the key is not an autoload-field refusal. A path that is not a folder is still `not a folder`.

`PackageZip` always writes `module.json` (`name` is the composer basename, `autoload` is `{}`, `require` omitted) unless the caller replaces that entry. The fourth argument stays the `index.php` entry point. A default zip opens with `module()` equal to that basename, `autoload()` `[]`, and `require()` `[]`. A caller-supplied `module.json` of `{"name":"demo"}` is refused naming `autoload`, and the entry point is not consulted.

Archive fixtures describe `module.json`. Cases that only exercised PHP array syntax in `index.php` (spreads, computed keys, namespace returns, size and presence) are gone.

| File | Change |
|---|---|
| `app/package/src/Archive/PackageArchive.php` | Reads `module.json` through `ModuleManifest` and does not open `index.php`. A parse error, a non-object, or a bad field is `ArchiveRefusedException`. An int autoload key is a string prefix, then a folder check. |

#### Tests (Checklist Step 2)

| File | Change |
|---|---|
| `tests/Unit/Package/PackageZip.php` | Always writes `module.json` (`name`, empty `autoload`, `require` omitted) unless `$files` replaces that entry. The fourth argument stays the `index.php` entry point. |
| `tests/Unit/Package/PackageArchiveTest.php` | Fixtures describe `module.json`. A zip with registration fields only in `index.php` is refused naming `module.json`. A repeated autoload key keeps the path `json_decode` keeps. A bad `require` is named and does not mention a missing autoload folder in the same document. |
| `tests/Unit/Package/PackageArchiveRequirementTest.php` | Requirement fixtures put the registration fields in `module.json`. |
| `tests/Unit/Package/PackageUploadBoundaryTest.php` | Upload-boundary fixtures put the registration fields in `module.json`. |

Gates: production verifier FAIL (mixed parameters and integer autoload keys), production retry, production verifier FAIL (fixtures still wrote registration fields into `index.php`), production retry, production verifier PASS, production tester PASS, test-writer done, test verifier PASS, tester PASS.

### Review (Bugbot + Security) + E2E (Checklist Step 3)

No production or test files changed. Bugbot and the Security review found nothing to correct. Step 3 left no open decision.

Gates: Bugbot clean (no bugs); Security clean (no findings); E2E PASS. No fix-loops.

### Include confinement (Finalize)

A security review on the prior head reported a HIGH: an include could replace a name already registered, and a pattern could leave the module. The fix keeps an include inside the module that declared it.

An include pass leaves names already stored. Within that pass the last manifest for a free name wins, and only that manifest's `include` is followed. A pattern that leaves the module (absolute, empty base, `..`, or a glob that matches a parent segment) is not applied and does not stop the sweep. A glob match whose `realpath` is outside the including module is not registered. `system`'s relative `modules/*/module.json` still registers a free child. A later caller-supplied manifest still replaces an earlier caller-supplied name. `PackageArchive` refuses an include that does not stay inside the package before anything is written, and before the autoload folder check.

| File | Change |
|---|---|
| `app/modules/kernel/src/Module/ModuleManager.php` | Include passes protect names already stored. A leaving pattern is not globbed. A match whose `realpath` is outside the module is not registered. |
| `app/modules/kernel/src/Module/ModuleManifest.php` | `includeStaysInModule()` refuses an empty pattern, NUL, an absolute or drive path, a `..` segment, and a glob segment that matches `..`. |
| `app/package/src/Archive/PackageArchive.php` | An `include` that fails that check is `ArchiveRefusedException` naming the pattern, before a write. |
| `tests/Unit/Module/ModuleManifestTest.php`, `tests/Unit/Module/ModuleRegistrationTest.php`, `tests/Unit/Package/PackageArchiveTest.php` | Cover the include-confinement invariants. |

Gates: production verifier PASS; PHPUnit + PHPStan PASS; E2E PASS. Bugbot clean. The security review on `4221729f` completed with conclusion success and posted no new finding.

---

## 🧠 Key Decisions (Rationale)

- **A non-string `name` is a failure; a missing name or `""` is not.** Returning null for every non-string name was rejected: a wrong JSON type has to be a failure the archive refusal can name. `{}` and `{"name":""}` are skipped with `getRegistrationFailures()` empty. `{"name":42}` is stored under that path and the name is not registered.
- **A string `require` element is kept, including `""`.** A non-string element is a registration failure and that module is not registered. `requires()` and the always-loaded closure skip `""`.
- **A non-array entry point throws from `load()`, not as a `TypeError`.** `includeEntry()` returns `mixed` because `include` yields the file's value or `1`. The message is `Module "%s" entry point must return an array.` The module stays registered, `get($name)` is null, and the PSR-4 prefix added before the include stays.
- **Field readers keep native parameter types.** `mixed` was rejected. A wrong type for `require`, `include`, `autoload`, or `nodes` is a registration failure naming the field. A `nodes` object still stores nested scalars, nulls, and arrays.
- **The manifest exception's sentence is the archive refusal.** A property on the exception was rejected. A `JsonException` previous keeps `cannot be parsed` and includes the JSON error, so a non-UTF-8 document never reaches a path quote. A non-object says the archive's `module.json` is not a JSON object. `include` and `nodes` have their own sentences. `name`, `autoload`, and `require` keep theirs, with the filename `module.json`.
- **The decoder exception wins, and only the decoded map is checked.** Walking folders or raw text first was rejected. A bad `require` or `include` is named, and a missing autoload folder in the same document is not. A repeated JSON key is the one `json_decode` keeps. An autoload key PHP stored as an int is cast to a string prefix and then checked as a folder. It is not an autoload-field refusal.
- **`PackageZip` always writes `module.json`.** The fourth argument stays the `index.php` entry point. Parsing that entry point into `module.json` was rejected. A caller-supplied `module.json` replaces the default entry. A default zip opens with `module()` equal to the composer basename, `autoload()` `[]`, and `require()` `[]`.
- **Blog and theme-one still repeat `name` and `autoload` in `index.php`.** Those literals stayed while the archive still parsed `index.php`, because the shipped-archive tests zip those two trees. The archive now reads `module.json` and does not open `index.php`. The literals are still in the two entry points. `load()` writes the keys back from the registered record.
- **An included manifest does not replace a name already registered.** First-wins on every include was rejected: package globs run before `system`, and would keep `system/view`. Dropping `include` under `packages/` was rejected: a later caller-supplied manifest still replaces an earlier one and still registers its free children. Within one include pass the last free name wins, and only that include is followed. A later pass does not replace that child.
- **A pattern that leaves the module is not globbed, and `register()` does not throw.** An absolute path, an empty base, NUL, or a `..` segment — including a glob segment that matches `..` (`.*`, `..*`, `.?`, `.[.]`) — resolves to the base. A segment that does not start with `.` cannot match `..`, so `*` stays. Returning the original path was rejected. The next caller-supplied module still registers, and the pattern adds no failure row. A glob match is registered only when its `realpath` stays inside the including module, so a symlink whose target is outside occupies no name.
- **The archive refuses an include that does not stay inside the package before anything is written.** The sentence names the pattern. A pattern with no matching entry is still accepted. Reading nested names at open was rejected: `modules/*/module.json` is valid with no children, and a nested `name` of `system` is stopped when the include is applied. `include` of `""` stays the non-empty-strings sentence. The check runs before the autoload folder check.

---

## 💥 Breaking Changes (Extensions)

An extension or theme archive must include `module.json` at the package root. `name` is the part of the Composer name after the slash. `autoload` must be present (`{}` when there is no map) and each value must be a folder in the archive. `require` omitted means none. The archive check does not open `index.php`. Registration fields that exist only in `index.php` do not register the module and do not satisfy the archive check.

On boot, `register()` reads `module.json` and does not execute `index.php`. `load()` runs `index.php` when that file exists, then restores `name`, `require`, `include`, `autoload`, `nodes`, and `path` from the registered record.

An `include` that does not stay inside the package is refused. An included manifest does not replace a module the caller already registered. `system`'s `modules/*/module.json` still registers children whose names are free.

---

## ⚠️ Risks & Rollout Notes

- A zip whose `name` and `autoload` live only in `index.php` is refused, and that package does not register.
- A module that is loaded still executes `index.php`. The extension fault barrier still covers that window. Discovery of a package that is only on disk does not execute it.
- Blog and theme-one still contain `name` and `autoload` in `index.php`. Discovery and the archive read `module.json`.
- A package `include` cannot replace `system` or any name the boot already registered. A relative include still registers a child whose name is free.

---

## 🔐 Security & Data Impact

`register()` does not include the entry point. A throw or a side effect in `index.php` does not run at discovery.

Size is checked before the bytes are decoded. Over 1 MiB is a registration failure, and an archive refusal, and the document is not decoded.

A path segment that starts with `.` is not registered, including a path passed directly, so a retired `packages/<vendor>/.<name>-<hex>` tree is not a second module.

Invalid JSON, a non-object, or a bad field is stored under that path and the sweep continues. The archive refuses the same document before a write.

The entry point of a module that is being loaded still runs. There is no process sandbox.

An included manifest does not replace a name already registered. An include that leaves the module (absolute, empty base, `..`, or a glob that matches a parent segment) is not applied and does not stop the sweep. A glob match whose `realpath` is outside the module, including through a symlink, is not registered. `PackageArchive` refuses an include that does not stay inside the package before anything is written.

---

## 🛡️ No-Mercy Compliance

`register()` reads `module.json` through `ModuleManifest` and does not include `index.php`. There is no fallback to the entry point and no second decoder. The Step 2.7.3 TODO and the include `try` are gone. `PackageArchive` uses that same reader and no longer parses PHP. `nikic/php-parser` stays for the console visitor and the import-edge test. An include that leaves the module is not applied; there is no second path that still registers it.

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

| Gate | Result |
|---|---|
| CI — PHP Tests | ✅ success — [run 36307598183](https://github.com/Shadesman5/pagekit/actions/runs/36307598183) on `4221729f` |
| CI — Frontend | ✅ success — [run 36307598108](https://github.com/Shadesman5/pagekit/actions/runs/36307598108) |
| CI — Infection | ✅ success — [run 36307598137](https://github.com/Shadesman5/pagekit/actions/runs/36307598137) |
| CI — e2e-smoke, e2e-merge | skipped by repository policy |
| Pull request | [#306](https://github.com/Shadesman5/pagekit/pull/306) |
| Coverage gap pass | ran — `tests/Unit/Module/ModuleLoadTest.php`, `tests/Unit/Module/ModuleManifestTest.php`, `tests/Unit/Module/ModuleRegistrationTest.php`, `tests/Unit/Package/PackageArchiveTest.php` |
| Cursor Bugbot (PR) | ✅ clean |
| Cursor Security Reviewer (PR) | findings fixed — review on `4221729f` success, no new finding |
| E2E | PASS |

**CI head:** `4221729fd02731b7d6293f9d6e307a6114e9f75e`

**Metrics (CI-owned):** [PR #306 quality-report comment](https://github.com/Shadesman5/pagekit/pull/306#issuecomment-5852718031) · [Quality Dashboard](https://Shadesman5.github.io/pagekit/quality/)

**Notable deviations:** Step 1: none. Step 2: production verifier FAIL (mixed parameters and integer autoload keys), then FAIL (fixtures still wrote registration fields into `index.php`), then PASS. Step 3: Bugbot clean; Security clean; E2E PASS; no fix-loops. Finalize: Security HIGH on `ModuleManager.php` include last-wins. Production fix, verifier PASS, PHPUnit+PHPStan PASS, E2E PASS. Tests cover the Step 3 invariants. CI green on `4221729f`; `e2e-smoke` and `e2e-merge` skipped by repository policy; coverage-gap pass ran; Bugbot clean; the security review on this head posted no new finding.

---

## 📋 Phase 1 Audit Closure

None.

---

## 👤 Maintainer action

<!-- Human-only follow-ups the maintainer must do (ruleset flips, real Docker/Apache
     verification, secrets, etc.). Not ROADMAP deferrals — those go under Deferred. -->

None.

---

## 📚 Deferred / Out-of-Scope

<!-- Future ROADMAP/PHASE work, explicit non-goals, bridges. Do NOT put maintainer
     Manual Work here — that belongs under Maintainer action above. -->

- **Step 2.8** — the published package contract, author tooling, and upload-ZIP shape require this `module.json` beside `index.php`.
- **Step 5.0** — a tier change rewrites that module's `module.json`. `system`'s `require` list lives there.
- **Non-goals:** `vendor/` at the repo root (2.7.4); prebuilt JS/CSS (2.8); marketplace (5.6); process sandbox of enabled PHP; rebuilding the admin package list from `composer.json` (`title`, `version`, `type`).
- **Bridges:** none.

---

## 📌 Follow-on (ROADMAP)

- 2.7.4 — Standard Composer Layout (`vendor/` at the repo root)
- 2.8 — Extension Packaging & Prebuilt Assets
- 5.0 — a tier change rewrites `module.json`

---

## 🧊 Parked (unplanned)

<!-- Filled by the post-close review after Finalize: what the finished work left unowned,
     one bullet per finding with the ROADMAP step whose area it belongs to. Doc-writer leaves None. -->

- **Blog and theme-one still return registration keys.** `packages/pagekit/blog/index.php` returns `name` and `autoload`; `packages/pagekit/theme-one/index.php` returns the same. `ModuleManager::load()` writes those keys back from the registered `module.json`, and `PackageArchive` does not open `index.php`, so the literals do not register the module and are not what the archive check reads. → **2.8**
- **An archive without `index.php` still installs.** `PackageArchive::open` does not check that the entry point exists. `ModuleManager::load()` merges the defaults with the registered record and does not include a file, so the module is `Pagekit\Module\Module` with `type` `module`. Recorded default: refuse a missing `index.php` without reading it. The other exit is to state that a manifest-only package loads that way. → **2.8**
- **A key outside the registration record is ignored.** `ModuleManifest::fields` copies `name`, `require`, `include`, `autoload`, and `nodes` only. `type`, `main`, routes, events, and `config` in `module.json` are not a failure and do not reach `load()`. `include`, when present, has to be a non-empty string or a list of non-empty strings; `""` is a `ModuleManifestException`. → **2.8**
- **The upload still describes a PHP array.** `PackageArchive::fieldMessage()` says `name` is `'name' => '%module%' as a string literal` and that `autoload` and `require` are an `array of string literals`. `ModuleManifest::decode` accepts `autoload` only as a JSON object, so `[]` is refused with that `autoload` sentence. Recorded default: those three sentences name the JSON shape. The other exit is to leave them and rely on the contract text. → **2.8**

---

## 🧹 Cleanup

<!-- Removed in passing (deleted files, dropped baseline/ignore entries, dead code). Doc-writer from
     the handover; the post-close review adds what the diff shows and the handover missed. -->

None.

---

## 🛡️ Audit

<!-- No-Mercy leftovers of the shipped diff that have no owner (forward-debt tags, added baseline
     entries, ANOMALIES patterns), each with the ROADMAP step that resolves it. Post-close review. -->

None.

---

## 🎁 Bonus

<!-- Work delivered beyond the ticket. Doc-writer from the handover; the post-close review adds
     what the diff shows and the handover missed. -->

- README's archive checklist names `module.json` (`name`, `autoload`, `require`, `include`) and says an `include` that leaves the package is refused. The archive check does not open `index.php`.

---

## 🔍 Research

<!-- The verified facts behind each DECISION the post-close review raised — symbols, call chain,
     what each exit deletes or adds — so the maintainer can decide without re-reading the tree. -->

- **Missing entry point.** `PackageArchive::manifest` reads `module.json` through `ModuleManifest::decode` and never looks up `ModuleManifest::ENTRY`. `PackageArchiveTest::testOpenDoesNotRequireTheEntryPoint` opens a zip that has no `index.php`. `ModuleManager::load` builds `$path.'/'.ModuleManifest::ENTRY`, includes it only when `is_file` is true, then writes `name`, `require`, `include`, `autoload`, `nodes`, and `path` back from the registered record. With no file, the stored module is `array_replace` of `$defaults` (`main` null, `type` `module`, `class` `Pagekit\Module\Module`, `config` `[]`) and that record. `ModuleLoader::load` then constructs `Pagekit\Module\Module` and `Module::main` returns without calling a closure. Refusing the missing file is a presence check in `PackageArchive::open` before `extractTo`, still without reading the file. Leaving it means a `pagekit-extension` or `pagekit-theme` archive enables as that default module.
- **Refusal wording.** `PackageArchive::refusedManifest` reads the field out of `Module manifest field "<field>" is invalid.` and `fieldMessage` picks the sentence. `name` keeps `'name' => '%module%' as a string literal`. `autoload` and `require` keep `array of string literals`. `include` is `gives no 'include' of non-empty strings` and `nodes` is `gives no 'nodes' object`. `ModuleManifest::fields` accepts `autoload` only as a JSON object; `[]` throws naming `autoload`, and `{}` is the empty map. Rewriting the three sentences changes the `__()` msgid. Leaving them means the upload text still names the PHP shape the decoder refuses.

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/done/PROMPT_2_7_3_Static-Module-Registration_plan.md`
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_7_3_Static-Module-Registration.md`
- Predecessor: Step 2.7.2 — Module Dependency Integrity
- Successor: Step 2.7.4 — Standard Composer Layout (`vendor/` at the repo root)
