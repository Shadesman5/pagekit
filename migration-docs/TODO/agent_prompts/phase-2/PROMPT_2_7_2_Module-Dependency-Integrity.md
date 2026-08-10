# Step 2.7.2: Module Dependency Integrity

<!-- conductor-mode: full -->

**ROADMAP:** 2.7.2. GitHub Issue: #268. Reference: `@ROADMAP.md`, `PHASE_2_MODERNISING.md` §2.7.2.

---

## CONTEXT

- **Land after:** Step 2.7 (#160) — fail-closed activation and disable/uninstall pre-flight reuse the auto-disable / admin-notice seam. If 2.7 has not merged, STOP and sequence correctly.
- **Provides:** an honest bidirectional dependency graph and a pre-flight answer for destructive package ops. Sub-Extension operator activation (5.0) must not ship while unsatisfied requirements can still be skipped silently. Prefer landing **before** Static Module Registration (2.7.3) so the graph hardens against today's registration model first.
- **Risk:** Low–Medium. One resolver behaviour change plus a read-only graph. Fail-closed can strand an installation whose manifests were already inconsistent — failures must name the missing module.
- **Goal:** Activation never leaves a half-wired application because a `require` was skipped; disable/uninstall never run blind; "what depends on this?" is answerable without scanning ad hoc at call time.
- **Why:** `ModuleManager::resolveModules()` silently skips an unregistered `require` entry. `load()` throws only for a directly requested unknown name. `PackageManager::disable()` performs no dependency check. That is tolerable while `require` lists are maintained in core code; it becomes a fault source the moment operators activate and deactivate modules themselves (5.0) or third-party packages declare dependencies (2.8 / 5.6).

### Current state (verified 2026-08-10 — confirm in Discovery, then build; do not rediscover blindly)

**Resolver — silent skip.**

- `ModuleManager::resolveModules()` (`app/modules/application/src/Module/ModuleManager.php:197-218`):
  - Circular: if `$required` is in `$unresolved` → `\RuntimeException` with `Circular requirement "%s > %s"` (`:205-207`).
  - Present: if `$required` is in `$this->registered` → recurse (`:209-210`).
  - **Absent: if not registered → no log, no throw, continue.** The depender still enters `$resolved` (`:216`).
- `ModuleManager::load()` (`:85-118`) — unknown **directly requested** name → `\RuntimeException("Undefined module: $name")` (`:95-97`). That is fail-loud for the top-level name only; it does not fix the silent skip inside `resolveModules()`.

**Boot still loads extensions/theme through SystemModule.**

- `SystemModule::main()` merges `extensions` + `site.theme` and loads each (post-2.7: behind `\Throwable` barrier + auto-disable). A missing dependency that was silently skipped surfaces later as a missing service — or, after 2.7, as an opaque load failure that does not name the missing `require`.

**Activation registry is two keys.**

- Enabled extensions: `system` config `extensions` (array). Theme: `site.theme`.
- Written by `PackageManager::enable()` / `disable()` (`push` / `pull` on `extensions`; `set`/`remove` on `site.theme`). Persisted via `ConfigManager` to `@system_config`.
- A disable check that reads **only** `extensions` will happily disable a module the **active theme** requires and break the frontend. Both keys are dependents.

**PackageManager::disable() — no graph check.**

- `app/installer/src/Package/PackageManager.php:271-288` — scripts → event → pull from `extensions`. Zero dependency validation.
- `PackageController::disableAction()` / uninstall UI likewise have no pre-flight.

**Manifest `require` examples.**

- Core: `app/system/index.php` and module manifests under `app/modules/*/index.php` declare `require` arrays (e.g. routing → `kernel`, `filter`).
- First-party packages: `packages/pagekit/blog/index.php` and `packages/pagekit/theme-one/index.php` currently have **no** `require` key — the graph must still be correct for core, and ready for packages that will declare dependencies under 2.8 / 5.6.

**Owned tables / settings — not declared.**

- Table ownership is implicit (ORM `tableClass`, migrations). Config ownership is implicit (`@system_config` keys by module name). Pre-flight "data risk" must derive from what exists (migrations registry, known config keys, node types) without inventing a second metadata system — or declare a minimal optional manifest field and migrate first-party packages deliberately. Do not block the graph on a perfect ownership schema.

**Admin UI.**

- Extensions/themes lists show name, toggle, version, folder, settings, permissions, uninstall — **no** dependency column, **no** "required by" warning before disable/uninstall.
- Step 2.7 admin notice seam (durable disable record → message) is the channel for fail-closed activation failures that auto-disable or refuse enable — reuse it; do not invent a second flash path.

**Tests.**

- No dedicated PHPUnit coverage for `resolveModules()` silent-skip or disable pre-flight today. `PackageLifecycleWiringTest` covers node lifecycle only.

---

## PRINCIPLES (hold across every checklist step)

- **Fail closed, name the missing module.** An unsatisfied `require` must refuse activation or disable the depender with an explicit reason — never a silent skip.
- **Bidirectional.** `require` (forward) and `requiredBy` (reverse) are both first-class; destructive ops query reverse.
- **Theme is a dependent.** `site.theme` counts like an enabled extension when asking "who needs this module?".
- **Reuse 2.7 notice / disable seams.** Do not add a parallel messaging or auto-disable mechanism.
- **Pre-flight is one query.** Admin UI and API consume the same result: blockers, orphans, data-risk hints.
- **Do not implement 5.0 cleanup here.** No install-reason bookkeeping, no automatic orphan removal — only the information and the refuse/block seams those features will need.
- **Do not rewrite discovery.** Static manifests are 2.7.3; this step works with today's registered module arrays.
- **Rule 3** — tree stays green after every checklist step; required CI job names stay stable.

---

## 0. SAFETY CHECKS

Before starting:

1. Branch up-to-date with `develop`.
2. Confirm **Step 2.7 has landed** (barrier + admin notice seam available).
3. Baseline green:

```bash
php -v
composer install --no-interaction --prefer-dist --no-progress
./app/vendor/bin/phpunit
./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M
pnpm install --frozen-lockfile && pnpm build
```

**IF ANY FAILS → STOP AND FIX** (or report the pre-existing failure) before changing the resolver.

---

## 1. DISCOVERY

```bash
rg -n 'resolveModules|Circular requirement|Undefined module' app/modules/application/src/Module/ --glob '!app/vendor/**'
rg -n "'require'" app/modules/*/index.php app/system/index.php packages/pagekit/*/index.php
rg -n 'function disable|pull\('\''extensions|site\.theme' app/installer/src/Package/ app/system/src/ --glob '!app/vendor/**'
rg -n 'extensions\.php|disableAction|uninstallAction|package-manager' app/installer/ --glob '!app/vendor/**'
rg -n 'MessageBag|auto-disable|view\.messages' app/system/ app/modules/session/ --glob '!app/vendor/**'
```

Resolve before writing code:

- **Fail-closed policy on boot vs. on enable.** When an already-enabled module has an unsatisfied `require` after upgrade: refuse boot of that module + auto-disable via 2.7, or hard-fail the request? Prefer surviving admin (2.7 principle) — state the choice.
- **Inactive vs. unregistered.** A `require` on a registered-but-disabled module is different from a missing package — both must be explicit in errors.
- **`requiredBy` derivation** — computed from registered manifests at boot or on demand; caching rules; invalidation when packages are enabled/disabled.
- **Circular detection at validation time** — keep boot detection; add the same check when enabling / validating a package so operators see it before activation.
- **Data-risk signal** — minimal honest heuristic (has migrations? has config rows? has node types?) without a full ownership schema if that would bloat the step.
- **UI/API shape** — one pre-flight DTO/array for disable and uninstall; where the extensions Vue surfaces blockers.
- **Interaction with 2.7.1** — if snapshots land first or later: pre-flight should still run before destructive stages; do not own snapshot creation here.

---

## 2. WORK

Architect: decompose into ordered, individually-green checklist steps. Suggested order:

1. **Fail closed in `resolveModules()`** — unregistered / unresolvable `require` throws (or returns a structured failure the loader handles) naming the missing module; no silent skip. Boot path stays admin-safe via 2.7 barrier.
2. **Activation-time validation** — enable / validate refuses unsatisfied or circular requirements with the same naming.
3. **Reverse index `requiredBy`** — derived API used by PackageManager and controllers.
4. **Theme-as-dependent** — disable/uninstall of anything `site.theme` requires is blocked or warned per the chosen policy (block is safer for "must not break frontend").
5. **Pre-flight for disable/uninstall** — single query: active dependents (blockers), would-be orphans, data-risk hints; wire into `PackageManager` + controller before mutation.
6. **Admin UX** — show blockers in the extensions/themes UI; disable/uninstall cannot ignore a blocking dependent without an explicit, tested escape hatch (prefer no escape hatch).
7. **Mandatory final `(XL)` step** — Review (Bugbot + Security) + E2E.

Sizing hints: (1) and (5) carry behaviour risk; (6) is the UX fix-loop; (2)–(4) are focused once the resolver contract exists.

### Notes per group

- **(1) Behaviour change:** existing installs with inconsistent manifests will start failing loudly — that is intended. Error text must be actionable (which module, which missing require).
- **(4) Theme:** treating only `extensions` is a design bug, not an edge case.
- **(6) Vue 2.7** — match installer module patterns; no Vue 3-only syntax.

---

## 3. OUT OF SCOPE

- **Snapshots, three-stage uninstall, retention, restore UX** → Step 2.7.1.
- **Static module registration / discovery without PHP for inactive packages** → Step 2.7.3.
- **Automatic removal of orphaned dependencies and install-reason bookkeeping** → Step 5.0.
- **Fault barrier, dumpers, static bridge removal** → Step 2.7 (consume; do not redo).
- **Package author contract / prebuilt assets** → Step 2.8.
- **Marketplace** → Step 5.6.

---

## 4. TESTING

- **Per checklist step (Conductor Tester):** PHPUnit + PHPStan PASS/FAIL.
- **`test-writer` applies** — this area starts near zero for the resolver/graph; write tests from scratch.
- **Minimum coverage:** silent-skip is gone (unregistered require fails with named module); circular still detected; enable refused on unsatisfied require; `requiredBy` lists a depender; disable blocked when an enabled extension or the active theme requires the target; pre-flight payload shape stable for UI/API.
- **Fixture modules** under test doubles / temp package manifests — do not corrupt first-party packages to test.
- **Frontend:** lint/prettier if Vue touched.
- **E2E (final `(XL)`):** 3 `@ci` specs; admin still boots if a dependency failure auto-disables via 2.7.

---

## SUCCESS CRITERIA

- `resolveModules()` no longer skips unregistered `require` entries; failures name the missing module.
- Enable/validate surfaces circular and unsatisfied requirements before activation completes.
- `requiredBy` answers "what depends on this?" from registered manifests.
- Disable/uninstall pre-flight reports blockers (including `site.theme`), orphans, and a data-risk hint; blocking dependents prevent the mutation.
- Admin UI shows the pre-flight result; no silent disable of a module the active theme requires.
- Reuses the Step 2.7 admin notice / auto-disable seam for boot-time fail-closed cases — no second messaging system.
- No `Step 2.7.2` references in code or tests; PHPUnit + PHPStan green with no new baseline entries.

---

## NOTES FOR THE ARCHITECT

- **The silent skip is the bug.** Replacing it with a log line and continuing is not fail-closed.
- **Strand risk is real.** An upgraded site with a typo'd `require` will start refusing that module — make the message the feature.
- **Keep the graph read-mostly.** Prefer deriving `requiredBy` over storing a second writable index that can drift.
- **2.7.3 will change how modules become `registered`.** Keep the integrity API against "registered + enabled" abstractions so discovery can move without reopening fail-closed rules.
- **Do not build orphan auto-delete.** Information only; 5.0 owns removal policy and must call 2.7.1 for any delete.
- One ticket / one PR; Conventional Commits; version bump once at Finalize — never inside checklist steps.
