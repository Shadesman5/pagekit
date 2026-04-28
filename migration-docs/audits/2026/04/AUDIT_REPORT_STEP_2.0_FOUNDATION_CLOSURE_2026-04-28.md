# Audit Report: Step 2.0 — Foundation Consolidation Closure & Gap Audit

**Date**: 2026-04-28
**Branch**: `cursor/step-2-0-foundation-closure`
**Source of Truth**: `.cursor/ROADMAP.md`, `migration-docs/TODO/PHASE_2_MODERNISING.md`
**PHP Version**: 8.3.x
**Standards**: Pagekit Modernization Rules — 5 Aggressive Rules (NO compatibility layers, NO adapters, DELETE OVER WRAP, MANDATORY FLAGGING, PHP 8.2+).
**Parent Issue**: #181
**Prior partial audit (covers 2.0 → 2.0.2 only)**: `migration-docs/audits/2026/03/AUDIT_REPORT_STEP_2.0-2.0.2_2026-03-27.md`

> **Status: WORK IN PROGRESS — Skeleton scaffold.**
> This file is the §4 section skeleton produced in Checklist Step 1 of the
> `PROMPT_2_0_Foundation-Consolidation-Closure` ticket. Subsequent checklist
> steps (2 → 17) populate every `_TBD_` placeholder with evidence collected
> from `develop` HEAD. The final Executive Summary and Closure Verdict are
> written last (Checklist Step 17).

---

## §4.1 Executive Summary

_TBD — single paragraph summary of the closure-and-gap audit. Cross-checks every
claim made by Steps 2.0.0–2.0.8 against `develop` HEAD; identifies any
Foundation-Consolidation debt that was promised, deferred, missed, or surfaced
during 2.0.x execution but never landed and never got its own ticket; routes
each gap to either a new 2.0.X sub-step (X ≥ 9), an existing future step
(2.1.6 / 2.1.9 / 2.5 / …), or "informational only"._

### Closure Verdict (10-row summary table)

`2.0.1a–e` is collapsed here for readability; per-sub-step evidence blocks in
§4.2 list each of `2.0.1a`, `2.0.1b`, `2.0.1c`, `2.0.1d`, `2.0.1e` individually.

| ID         | Sub-step                                       | Audit ground-truth | New sub-step needed? |
|------------|------------------------------------------------|--------------------|----------------------|
| 2.0.0      | Controller Attributes                          | _TBD_              | _TBD_                |
| 2.0.1      | PSR-11 Container Modernization                 | _TBD_              | _TBD_                |
| 2.0.1a–e   | Container sub-stages (Core / DI / System / Packages / StaticTrait) | _TBD_ | _TBD_      |
| 2.0.2      | Validator-Translator Integration               | _TBD_              | _TBD_                |
| 2.0.3      | Cache API Full Modernization                   | _TBD_              | _TBD_                |
| 2.0.4      | Package / Migration System Redesign            | _TBD_              | _TBD_                |
| 2.0.5      | Composer & Autoload Hygiene                    | _TBD_              | _TBD_                |
| 2.0.6      | Test Infrastructure Cleanup                    | _TBD_              | _TBD_                |
| 2.0.7      | Event Dispatcher Bridge Removal                | _TBD_              | _TBD_                |
| 2.0.8      | `User::hasAccess()` Hotfix                     | _TBD_              | _TBD_                |

**Legend:** 🛡️ = audit ground-truth confirms scope, deletions and Phase 1
closure claims hold on `develop` HEAD ; ⚠️ = partial / drift detected
(documented in §4.4 Gap List) ; ❌ = scope claim contradicted by `develop`.

---

## §4.2 Per-Sub-Step Evidence Blocks

Each block ≤ 20 lines, structured as:

- **Scope verified** (✅ / ❌ + ripgrep evidence).
- **Phase 1 closure claims verified** (✅ / ⚠️ partial / ❌).
- **No-Mercy spot-check** (✅ / list of suspect hits classified per §3.3).
- **Deferred items still tracked** (✅ / list of orphaned items routed in §4.4).

---

### §4.2.0 Step 2.0.0 — Controller Attributes

**ROADMAP Status**: ✅ | **Issue**: #142 | **PR**: #111 | **Audit**: _TBD_

- Scope verified: _TBD_ (`rg -n "@Route\(" app/ packages/ --glob "*.php" --glob "!*Test.php"` → expect 0 hits; `rg -n "#\[Route\(" app/ packages/ --glob "*.php"` → expect attribute usage alive).
- Phase 1 closure claims verified: none directly (2.0.0 has no `Closes Phase 1 audit:` line).
- No-Mercy spot-check: _TBD_ (zero `@deprecated` markers introduced in controller layer by this step).
- Deferred items still tracked: _TBD_.

---

### §4.2.1 Step 2.0.1 — PSR-11 Container Modernization (umbrella)

**ROADMAP Status**: ✅ | **Issue**: #145 | **PR**: #174 (audit) | **Audit**: _TBD_

- Scope verified: _TBD_ (`rg -n "Psr11Adapter|StaticTrait|class_alias.*Container" app/ packages/ --glob "*.php"` → expect 0 hits; `Container` natively `implements ContainerInterface` from `Psr\Container`).
- Phase 1 closure claims verified: `Closes Phase 1 audit: Step 1.6` — confirm ROADMAP row 1.6 = 🛡️ and prior audit (`AUDIT_REPORT_STEP_2.0-2.0.2_2026-03-27.md`) corroborates.
- No-Mercy spot-check: _TBD_.
- Deferred items still tracked: _TBD_.

---

### §4.2.1a Step 2.0.1a — Container Core + Modules

**ROADMAP Status**: ✅ | **Issue**: #162 | **PR**: #161 | **Audit**: _TBD_

- Scope verified: _TBD_ (`Container` directly implements `Psr\Container\ContainerInterface`; `get()`/`has()`/`set()` PSR-11-compliant).
- Phase 1 closure claims verified: rolled up under 2.0.1.
- No-Mercy spot-check: _TBD_.
- Deferred items still tracked: _TBD_.

---

### §4.2.1b Step 2.0.1b — DI Infrastructure

**ROADMAP Status**: ✅ | **Issue**: #163 | **PR**: #167 | **Audit**: _TBD_

- Scope verified: _TBD_ (`ControllerResolver` resolves typed constructor parameters from container; no service-locator pattern in controller resolution).
- Phase 1 closure claims verified: rolled up under 2.0.1.
- No-Mercy spot-check: _TBD_.
- Deferred items still tracked: _TBD_.

---

### §4.2.1c Step 2.0.1c — System / Installer / Console + DI

**ROADMAP Status**: ✅ | **Issue**: #164 | **PR**: #169 | **Audit**: _TBD_

- Scope verified: _TBD_ (System + Installer + Console controllers migrated; zero `$app['service']` array-access patterns).
- Phase 1 closure claims verified: rolled up under 2.0.1.
- No-Mercy spot-check: _TBD_.
- Deferred items still tracked: _TBD_.

---

### §4.2.1d Step 2.0.1d — Packages + ArrayAccess Removal

**ROADMAP Status**: ✅ | **Issue**: #165 | **PR**: #171 | **Audit**: _TBD_

- Scope verified: _TBD_ (`ArrayAccess` removed from `Container`; `offsetGet`/`offsetSet`/`offsetExists`/`offsetUnset` deleted; zero `$app['key']` bracket access in codebase).
- Phase 1 closure claims verified: rolled up under 2.0.1.
- No-Mercy spot-check: _TBD_.
- Deferred items still tracked: _TBD_.

---

### §4.2.1e Step 2.0.1e — StaticTrait Removal + DI Final

**ROADMAP Status**: ✅ | **Issue**: #166 | **PR**: #172 | **Audit**: _TBD_

- Scope verified: _TBD_ (`StaticTrait`, `EventTrait`, `RouterTrait` physically deleted; zero `App::` static calls; zero `__call`/`__callStatic` on Container/Application).
- Phase 1 closure claims verified: rolled up under 2.0.1.
- No-Mercy spot-check: _TBD_.
- Deferred items still tracked: _TBD_.

---

### §4.2.2 Step 2.0.2 — Validator-Translator Integration

**ROADMAP Status**: ✅ | **Issue**: #146 | **PR**: #175 | **Audit**: _TBD_

- Scope verified: _TBD_ (`validators.php` exists per locale (system + blog); `validation.php` deleted; `ValidatorServiceProvider` calls `setTranslator()` + `setTranslationDomain('validators')`).
- Phase 1 closure claims verified: confirm prior audit cell flipped 1.13 → 🛡️.
- No-Mercy spot-check: _TBD_ (confirm `MenuApiController` manual-validation finding is **already** listed under 2.1.9 PHASE_2 — route check, no action in this PR).
- Deferred items still tracked: _TBD_.

---

### §4.2.3 Step 2.0.3 — Cache API Full Modernization

**ROADMAP Status**: ✅ | **Issue**: #179 | **PR**: #187 | **Audit**: _TBD_

- Scope verified: _TBD_ (`rg -n "Pagekit\\\\Cache\\\\CacheInterface|Psr6Adapter" app/ packages/ --glob "*.php"` → expect 0 hits; zero `fetch()` / `flushAll()` calls on cache pools; `LoginAttemptListener`, `UrlResolver`, `RouteListener`, `blog/scripts.php` consumers use PSR-6 API).
- Phase 1 closure claims verified: confirm 1.10 stays 🛡️.
- No-Mercy spot-check: _TBD_ (verify `composer.lock` + `yarn.lock` committed — lockfile-versioning sub-task that shipped early in 2.0.3 PR #187).
- Deferred items still tracked: _TBD_.

---

### §4.2.4 Step 2.0.4 — Package / Migration System Redesign

**ROADMAP Status**: ✅ | **Issue**: #180 | **PR**: #189 | **Audit**: _TBD_

- Scope verified: _TBD_ (`DatabaseHandler::createTable()` deleted (`rg -n "function createTable" app/modules/auth/`); blog migration renamed to timestamp format (`rg -n "Version001_CreateBlogTables|Version[0-9]{14}.*Blog" app/`); `MigrationServiceTest` un-skipped (no `markTestSkipped`); `MigrationService::getConfigPath()` deleted; login check + update wizard execute Doctrine Migrations before `scripts->update()`).
- Phase 1 closure claims verified: confirm 1.12 stays 🛡️.
- No-Mercy spot-check: _TBD_ (flag any audit-findings sub-bullet still untouched as a Gap).
- Deferred items still tracked: _TBD_.

---

### §4.2.5 Step 2.0.5 — Composer & Autoload Hygiene

**ROADMAP Status**: ✅ | **Issue**: #182 | **PR**: #192 | **Audit**: _TBD_

- Scope verified: _TBD_ (`composer.json`: dead PSR-4 mappings `Pagekit\Theme\` and `Pagekit\Package\` removed; unused deps removed (`symfony/framework-bundle`, `symfony/twig-bridge`, `symfony/yaml`, `symfony/process`, `paragonie/sodium_compat`, `doctrine/data-fixtures`); `symfony/validator` aligned to `^6.4`; `paragonie/random-lib` resolved (replaced or loosened); `composer validate` clean and `composer.lock` consistent).
- Phase 1 closure claims verified: confirm 1.4 → 🛡️.
- No-Mercy spot-check: _TBD_.
- Deferred items still tracked: _TBD_.

---

### §4.2.6 Step 2.0.6 — Test Infrastructure Cleanup

**ROADMAP Status**: ✅ | **Issue**: #183 | **PR**: #193 | **Audit**: _TBD_

- Scope verified: _TBD_ (zero module-level `phpunit.xml.dist` (`rg --files app/modules/ -g "phpunit.xml.dist"`); `tests/Unit` casing fixed; `@dataProvider` / `@group` migrated to `#[DataProvider]` / `#[Group]`; `Doctrine\Common\Cache\ArrayCache` import removed from `ConfigManagerTest`; `RoutesLoader::addController()` no longer silently swallows `InvalidArgumentException`).
- Phase 1 closure claims verified: confirm 1.2 + 1.8 → 🛡️.
- No-Mercy spot-check: _TBD_.
- Deferred items still tracked: _TBD_.

---

### §4.2.7 Step 2.0.7 — Event Dispatcher Bridge Removal

**ROADMAP Status**: ✅ | **Issue**: #184 | **PR**: #195 | **Audit**: _TBD_

- Scope verified: _TBD_ (`rg -n "SymfonyEventDispatcherBridge|symfony\\.event_dispatcher|EventDispatcherCompatibilityTest" app/ packages/` → expect 0 hits; PHPStan baseline does not list any of those identifiers).
- Phase 1 closure claims verified: confirm 1.7 → 🛡️ (1.9 already 🛡️).
- No-Mercy spot-check: _TBD_ (cross-check `GetResponseEvent` rename deferral is tracked in 2.1.6 PHASE_2 — if not, add it there as a routed gap in §4.4).
- Deferred items still tracked: _TBD_.

---

### §4.2.8 Step 2.0.8 — `User::hasAccess()` Hotfix

**ROADMAP Status**: ✅ | **Issue**: #185 | **PR**: #197 | **Audit**: _TBD_

- Scope verified: _TBD_ (`rg -n "create_function" app/ packages/ --glob "*.php"` → expect 0 hits; PHPStan baseline entry `function.notFound: create_function` removed; parser handles `&&` / `||` / `!` AND single-character `&` / `|`).
- Phase 1 closure claims verified: confirm 1.11 still ⚠️ (only partial closure — `EntityManager` singleton etc. carry to 2.1.6).
- No-Mercy spot-check: _TBD_ (cross-check `evaluateBooleanExpression` → `PermissionExpressionEvaluator` deferral is referenced in 2.5's PHASE_2 section; if missing, add it there as a routed gap in §4.4).
- Deferred items still tracked: _TBD_.

---

## §4.3 Cross-Cutting Verification (ripgrep sweeps)

Run the four ripgrep sweeps from §3.3 of the prompt verbatim, plus the
targeted sweeps from §3.5. Classify every hit as **OK / by design**,
**OK / tagged with valid future ROADMAP ID**, or **GAP**. GAPs feed §4.4.

### §4.3.1 Rule 1 / 2 / 4 sweep — `Bridge|Adapter|Compat|Shim|Legacy|Wrapper`

```bash
rg -n "Bridge|Adapter|Compat|Shim|Legacy|Wrapper" app/ packages/ --glob "*.php" --glob "!*Test.php"
```

| File:Line | Match | Classification | Notes / Routing |
|-----------|-------|----------------|-----------------|
| _TBD_     | _TBD_ | _TBD_          | _TBD_           |

---

### §4.3.2 Rule 4 sweep — `@deprecated`

```bash
rg -n "@deprecated" app/ packages/ --glob "*.php"
```

| File:Line | Match | Classification | Notes / Routing |
|-----------|-------|----------------|-----------------|
| _TBD_     | _TBD_ | _TBD_          | _TBD_           |

---

### §4.3.3 Rule 4 sweep — `class_alias`

```bash
rg -n "class_alias" app/ packages/ --glob "*.php"
```

| File:Line | Match | Classification | Notes / Routing |
|-----------|-------|----------------|-----------------|
| _TBD_     | _TBD_ | _TBD_          | _TBD_           |

---

### §4.3.4 Rule 5 sweep — debt markers (PHP, JS, Vue, LESS)

```bash
rg -n "TEMPORARY BRIDGE|AUDIT FIX|BACKWARD COMPATIBILITY|Must be refactored later" \
   app/ packages/ --glob "*.php" --glob "*.js" --glob "*.vue" --glob "*.less"
```

| File:Line | Match | Classification | Notes / Routing |
|-----------|-------|----------------|-----------------|
| _TBD_     | _TBD_ | _TBD_          | _TBD_           |

---

### §4.3.5 Targeted sweeps (per §3.5 of the prompt)

| Sweep | Command | Hit count | Classification | Routing |
|-------|---------|-----------|----------------|---------|
| Stale Vue-migration TODOs | `rg -n "Refactor in Phase 3 \\(Vue Migration\\)" app/ packages/ --glob "*.php" --glob "*.js" --glob "*.vue"` | _TBD_ | _TBD_ | _TBD_ |
| Module `phpunit.xml.dist` | `rg --files app/modules/ -g "phpunit.xml.dist"` | _TBD_ | _TBD_ | _TBD_ |
| Module `composer.json` autoload entries | `rg -n "psr-4|psr-0" app/modules/*/composer.json packages/*/composer.json` | _TBD_ | _TBD_ | _TBD_ |
| Pagekit own `CacheInterface` / `Psr6Adapter` | `rg -n "Pagekit\\\\Cache\\\\CacheInterface\|Psr6Adapter" app/ packages/ --glob "*.php"` | _TBD_ | _TBD_ | _TBD_ |
| `SymfonyEventDispatcherBridge` / `symfony.event_dispatcher` | `rg -n "SymfonyEventDispatcherBridge\|symfony\\.event_dispatcher" app/ packages/` | _TBD_ | _TBD_ | _TBD_ |
| `create_function` | `rg -n "create_function" app/ packages/ --glob "*.php"` | _TBD_ | _TBD_ | _TBD_ |

---

## §4.4 Gap List

Collated from §4.2 (per-sub-step) and §4.3 (cross-cutting). Every gap gets a
disposition. `Disposition` ∈ {`new sub-step 2.0.X`, `route to existing step`,
`informational only`}. For routed gaps, name the existing step (most likely
`2.1.6`, `2.1.9`, `2.5`). For new sub-step gaps, assign the next free
integer ≥ 9. The Architect's call which gaps are **must-fix-before-2.1**
(block 2.0 closure) vs. non-blocking (allow ✅ / 🛡️ closure with deferred
sub-steps) is documented in §4.8 Closure Verdict.

| # | Gap (one line) | Disposition | Target | Blocking? |
|---|----------------|-------------|--------|-----------|
| _TBD_ | _TBD_ | _TBD_ | _TBD_ | _TBD_ |

> If the audit detects **zero** gaps, this section MUST contain the explicit
> phrase **"no gaps detected"** below the table header (per §8 of the
> task prompt — Definition of Done).

---

## §4.5 New Sub-Step Proposals

For every gap with disposition `new sub-step 2.0.X`, record the full skeleton
(cross-linked to the agent-prompt file under
`migration-docs/TODO/agent_prompts/Step-2_0-Foundation-Consolidation/`).

If zero new sub-steps are proposed, this section reads: **"No new sub-steps proposed."**

### §4.5.1 Step 2.0.{X} — _TBD title_

- **Goal**: _TBD_
- **Prerequisite**: _TBD_
- **Priority**: _TBD_
- **Closes Phase 1 audit**: _TBD_ (or `none`)
- **GitHub issue**: _TBD_ (linked as sub-issue of #181)
- **Agent prompt**: `migration-docs/TODO/agent_prompts/Step-2_0-Foundation-Consolidation/PROMPT_2_0_{X}_{Slug}.md`
- **Affected files**: _TBD_
- **Risk**: _TBD_
- **Blocking 2.1 entry?**: _TBD_

_(repeat for each new sub-step; numbering: next free integer ≥ 9)._

---

## §4.6 PHASE_2 / ROADMAP / Issue Updates (exact diffs proposed in this PR)

Documentation drift findings (per §3.6 of the prompt) **and** the diffs for
new sub-step paper deliverables (per §4.5) are recorded here.

### §4.6.1 Documentation drift sweep findings

| Document | Drift detected? | Notes |
|----------|-----------------|-------|
| `.cursor/ROADMAP.md` (rows 2.0.0–2.0.8 statuses, audit cells, issue + PR linked) | _TBD_ | _TBD_ |
| `migration-docs/TODO/PHASE_2_MODERNISING.md` (every 2.0.x section: `Closes Phase 1 audit:`, `Agent Prompt:` path, `Audit findings (Phase 1 review):` if applicable) | _TBD_ | _TBD_ |
| `migration-docs/branches/` (branch doc per sub-step) | _TBD_ | _TBD_ |
| `README.md` (cache / migrations / event-dispatcher / PHPUnit module configs references) | _TBD_ | _TBD_ |
| `AGENTS.md` (service mappings + pitfalls touched by 2.0.x) | _TBD_ | _TBD_ |
| `CHANGELOG-NEW.md` (every 2.0.x entry coherent; version chain `1.2.5 → 1.2.13` complete) | _TBD_ | _TBD_ |

### §4.6.2 ROADMAP diff (this PR)

```diff
_TBD — exact lines added / changed in .cursor/ROADMAP.md.
```

### §4.6.3 PHASE_2 diff (this PR)

```diff
_TBD — exact lines added / changed in migration-docs/TODO/PHASE_2_MODERNISING.md
       (new 2.0.X sub-section bodies + appended "Audit findings" bullets on
       routed-gap target steps).
```

### §4.6.4 New agent-prompt skeletons (this PR)

| Path | Sub-step | Status |
|------|----------|--------|
| _TBD_ | _TBD_ | new |

### §4.6.5 GitHub issues opened (this PR)

| Issue | Title | Labels | Milestone | Parent |
|-------|-------|--------|-----------|--------|
| _TBD_ | _TBD_ | _TBD_ | Phase 2: Developer Experience | #181 |

---

## §4.7 Final Test Summary

Pre-flight baseline (Checklist Step 1, captured against `develop` HEAD before
any audit-machinery changes) and final-gate runs (Checklist Step 22, on the
closure branch with all docs/skeletons in place).

| Gate | Pre-flight (Step 1) | Final (Step 22) |
|------|---------------------|-----------------|
| `./app/vendor/bin/phpunit` | _TBD_ | _TBD_ |
| `./app/vendor/bin/phpstan analyse --no-progress --memory-limit=512M` | _TBD_ | _TBD_ |
| `php pagekit list` | _TBD_ | _TBD_ |
| `php pagekit setup` | _TBD_ | _TBD_ |
| Playwright E2E (chromium-only): `installation`, `authentication`, `dashboard` | n/a | _TBD_ |

### §4.7.1 PHPUnit raw output (truncated)

```
_TBD_
```

### §4.7.2 PHPStan raw output (truncated)

```
_TBD_
```

### §4.7.3 `php pagekit list` raw output (truncated)

```
_TBD_
```

### §4.7.4 `php pagekit setup` raw output (truncated)

```
_TBD_
```

### §4.7.5 Playwright raw output (truncated; final run only)

```
_TBD_
```

---

## §4.8 Closure Verdict

_TBD — pick exactly one of:_

- ✅ **Step 2.0 can close in this PR.** New sub-steps (if any) are
  non-blocking and may land later. ROADMAP row `2.0` flips to `✅` / `🛡️`,
  `Current Step` header pointer advances to `2.1.2` (2.1.1 is already done).
- ⚠️ **Step 2.0 stays `⏳` / `⏳`** until the following must-fix-before-2.1
  new sub-steps land: _TBD list_. ROADMAP `Current Step` pointer advances
  to the **first** new sub-step in that list (e.g. `2.0.9`). Each blocking
  sub-step's PHASE_2 section names "must land before Step 2.1.x" on its
  `Prerequisite` line.

### Decision rationale

_TBD — short paragraph explaining why each blocking gap (if any) blocks 2.1
entry and why each non-blocking gap can ship later. References the §4.4 Gap
List rows by number. Closure is conditional on the §4.7 final-gate matrix
being all-green._

---

**End of audit report skeleton.**
