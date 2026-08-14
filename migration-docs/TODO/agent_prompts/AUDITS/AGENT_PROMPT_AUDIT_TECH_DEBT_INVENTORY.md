# Task: Technical Debt & Modernization Audit — Debt Inventory

<!-- conductor-mode: plan --> <!-- V2 Conductor reads this marker: audit/report task = Step-0 gate only (Plan phase; no Execute/Finalize). -->

Audit the Pagekit codebase and produce a prioritized **Technical Debt Inventory**: a structured report
of everything that is still legacy / non-modern / unclean, with a clear recommendation per item. The
most important part of the report is the **Decision-Critical Shortlist** — items that must be decided
**before** more is built on top, because deferring them would mean throwing work away later.

This is a **read-only** audit: investigate and report only. Do **not** modify application code.

---

## Rules

- **Read-only:** change no application code. The only file you create is the audit report.
- **Language:** English.
- **Standards (your yardstick):** `.cursor/rules/pagekit.mdc`, `.cursor/rules/php.mdc`,
  `.cursor/ROADMAP.md` (step IDs), `migration-docs/TODO/MODERNISATION_STRATEGY.md` (Pagekit DNA).
- **Already-handled work:** cross-check `.cursor/ROADMAP.md` (steps marked `✅`/`🛡️`) and `CHANGELOG-NEW.md`;
  link to those instead of re-reporting solved items.
- **Evidence:** every finding cites at least one `path:line` plus the search/observation that found it.
- **Tooling:** PHPUnit is `./app/vendor/bin/phpunit` (custom vendor-dir). Do **not** run the full E2E suite.
- **Secrets:** never echo or commit a secret value; if you find a hardcoded secret, record it as a
  **Critical** finding without reproducing the value.

---

## Deliverable

- **Report:** `migration-docs/audits/{YYYY}/{MM}/AUDIT_REPORT_TECH_DEBT_INVENTORY_{YYYY-MM-DD}.md`
- **Commit message:** `docs(audit): technical debt inventory {date}`
- Do **not** edit ROADMAP / PHASE files here — propose any changes *inside the report* (§7).

---

## Definition of Modern (measure every finding against this)

A unit of code is **modern / best-practice** for this project when it satisfies all of these; each
violation is a candidate finding:

1. **Dependency Injection only** — constructor injection via the PSR-11 container. No static service
   locators, no singletons, no setter-DI, no `new $ServiceClass()` for things that should be services.
2. **No layer/coupling violations** — the **core must not know about a specific module** (e.g. core code
   referencing a module's config key). Modules depend on core, never the reverse.
3. **No deprecated APIs** — no `@deprecated` Symfony/PHP APIs, no copied clones of deprecated framework
   classes; use the current idiom (e.g. `CompiledUrlMatcher`/`CompiledUrlGenerator`).
4. **No compatibility layers / adapters / shims / bridges** — except items *explicitly* flagged
   `TEMPORARY BRIDGE … Step X.Y` that are already scheduled.
5. **Strict typing** — `declare(strict_types=1)`, typed properties + return types, PHPStan **Level 8**
   clean (no *new* baseline entries), `mixed` only with a justified docblock.
6. **PSR compliance** — PSR-11 (container), PSR-6 (cache), PSR-3 (logging), PSR-4 (autoload), etc.
7. **Robust, explicit behavior** — e.g. cache invalidation must be explicit/versioned, **not** inferred
   from file mtimes; error handling must not surface as uncaught 500s.
8. **Separation of concerns** — small, testable units; clear boundaries; no god-objects / god-DI.
9. **Tested & documented** — unit/E2E coverage where it matters; intent documented.
10. **Stable, documented extension/public API** — the surface external developers touch is consistent,
    typed, documented, and intentional.

**Do NOT false-positive these:**

- **Stable platform API names** kept by design (e.g. `$date`, `$number`, `$currency`, `$http`) are a
  *clean modern reimplementation*, **not** a compatibility layer (ROADMAP Rule 3). Not debt.
- **Pagekit DNA** (`MODERNISATION_STRATEGY.md`): lightweight, modular, no bloat — "we are NOT building
  WordPress 2.0". **Over-engineering / unnecessary enterprise abstractions are also debt** — flag them.
- The **frontend** (Vue 2.6, vue-resource, lodash, vue-nestable, vue-intl, UIkit 3.5) is *intentionally*
  in maintenance mode and fully mapped in `PHASE_3_MODERNISING.md`. Do **not** re-inventory it in depth;
  only flag **NEW / untracked** frontend debt not already covered by Phase 3.

---

## Start here: a known debt cluster (use as the archetype)

The routing/cache subsystem already shows a representative cluster of debt. Use it as the pattern of
what to look for, then expand across all domains:

- **Module → core coupling:** core `Router` cache keying knows about a module config key
  (`blog.permalink`) — `app/modules/routing/src/Router.php`, `packages/pagekit/blog/src/Event/RouteListener.php`.
- **DI gap / static bridge:** routing resolvers are created via `new $class()` without the container, so
  `UrlResolver` uses static `setCache()/setModule()` (`packages/pagekit/blog/src/UrlResolver.php`).
- **Deprecated framework clone:** `app/modules/routing/src/Matcher/Dumper/PhpMatcherDumper.php` clones
  Symfony's `@deprecated since 4.3` dumper.
- **Fragile cache invalidation:** route cache freshness is derived from controller-file mtimes
  (`Router::getCache` + `Routes::createRoute`) — config changes don't bump it.
- **Uncaught-error fragility:** a corrupted/raced cache file produced an HTTP 500 (the pattern — narrow
  `catch`, non-atomic writes — may exist elsewhere).

Find every comparable cluster and classify each by decision-urgency (see rubric below).

---

## Scope — audit domains (the Architect investigates each)

For each domain: cross-check ROADMAP status first; deep-dive **untracked / inaccurately-scoped** debt,
and merely confirm-and-link debt that is already tracked.

- [ ] **D1 — Routing, URL generation, cache & path resolution** (primary hotspot). Router/matcher/generator,
      dumpers, resolvers, alias/node mounting, `UrlProvider`, `Locator`/`Filesystem` path handling,
      cache invalidation model.
- [ ] **D2 — Dependency Injection & service architecture.** Static service locators (`ModelServiceLocator`,
      `*::setX()` static bridges), singletons (`EntityManager`), setter-DI remnants, `new $class` for
      services, backend globals.
- [ ] **D3 — ORM & database layer.** EntityManager/Metadata/Relations, QueryBuilder API consistency,
      N+1 patterns, typing.
- [ ] **D4 — Module / extension system & boot.** `index.php` boot closures, `scripts.php`, boot-time
      coupling, fault isolation.
- [ ] **D5 — Legacy idioms & anti-patterns.** WordPress/Laravel-isms, magic methods / dynamic properties,
      global functions, `@deprecated` usages, other deprecated Symfony/PHP APIs.
- [ ] **D6 — Error handling, logging & resilience.** Narrow `catch` blocks that can surface as 500s,
      non-atomic file writes, inconsistent error/JSON formats, logging that depends on the DB.
- [ ] **D7 — Public / Extension API surface & DX.** What external developers touch: consistency, typing,
      docs, stability; where the API leaks internals.
- [ ] **D8 — Configuration & state management.** Config flow, global state, per-request mutable state
      injected into shared services.
- [ ] **D9 — Tests, types & static analysis.** PHPStan baseline suppressions by area, weak/uncovered
      areas, `mixed` hotspots.
- [ ] **D10 — Flag/TODO reconciliation.** Collect EVERY in-code flag (`TODO`/`HACK`/`FIXME`/`BRIDGE`/
      `BACKWARD COMPATIBILITY`/`Must be refactored`) and confirm each maps to a ROADMAP step; surface
      orphans (no step) and stale flags (step already done).

**Out of scope:** implementing fixes; deep frontend re-inventory (Phase 3 owns it — only NEW debt);
re-auditing `✅/🛡️` steps beyond a confirm-and-link.

---

## Methodology

1. **Baseline first** (for report §2): PHP version, PHPStan level + suppressed counts, test totals,
   ROADMAP `Current Step`.
2. **Breadth via systematic search** (ripgrep / Grep). **Search hygiene — apply from the first query:**
   - **Scope to PHP by default** (`rg -t php …`, or Grep with `type: "php"`). The frontend (Vue 2.6, JS,
     LESS, UIkit) is intentionally out of scope, so PHP-scoping blocks that noise before it starts. Widen
     beyond PHP **only** when you deliberately hunt **NEW / untracked** frontend debt (see scope note above).
   - **Cap noisy output.** For high-frequency tokens (e.g. `TODO`, `->config\(`), get counts/locations
     first with `rg -c` / `rg -l` (or pipe `| head -n 50`). The goal is to identify the *pattern and its
     spread*, not to read every hit — this protects the context window.
   Suggested smell queries (extend as needed; record the exact query next to each finding):
   - DI / statics: `new static\(`, `::getInstance`, `public static function set[A-Z]`, `ServiceLocator`,
     `\bnew\s+[A-Z]\w*Resolver\(`
   - Deprecated: `@deprecated`, `Doctrine\\Common\\Annotations`, deprecated Symfony class names.
   - Coupling: core files referencing module names/config keys (e.g. `->config\('blog`).
   - Legacy idioms: `__get`, `__set`, `__call`, `AllowDynamicProperties`, `create_function`,
     WordPress/Laravel tokens (`add_action`, `WP_`, `dd\(`, `collect\(`, `Str::`).
   - Fragility: `catch \(\\Error`, non-atomic `file_put_contents`, `filemtime`.
   - Flags: `TODO`, `HACK`, `FIXME`, `BRIDGE`, `BACKWARD COMPATIBILITY`, `Must be refactored`.
3. **Confirm against ROADMAP/CHANGELOG** for every finding: already tracked? which step/flag?
4. **Evidence standard:** prefer 1–3 representative `path:line` hits + a count over exhaustive dumps.
5. **Respect Pagekit DNA:** recommend the *simplest modern* solution; flag any over-engineering as debt.

---

## Severity, decision-urgency & disposition

**Severity:** **Critical** (active correctness/security/data-loss or already causing bugs) ·
**High** (architecture-shaping debt that blocks/complicates future steps) ·
**Medium** (clear non-modern pattern, localized effort) · **Low** (cosmetic/minor).

**Decision-urgency (the key axis):**

- **🔴 DECIDE-NOW (foundation-shaping / now-or-throwaway)** — a choice that, if deferred, means code built
  on top is likely thrown away or expensively reworked (DI model, cache architecture, extension-API shape,
  routing internals). These go on the Shortlist (§5).
- **🟡 SCHEDULE** — real debt; assign to an existing or new ROADMAP step; not blocking.
- **🟢 OPPORTUNISTIC** — fix when the area is next touched.

**Disposition (per finding):** map to an **existing** ROADMAP step | **propose a new** sub-step (suggested
ID + rationale) | **accept** (document why it's fine / intentional platform API).

---

## Report structure

Write to `migration-docs/audits/{YYYY}/{MM}/AUDIT_REPORT_TECH_DEBT_INVENTORY_{YYYY-MM-DD}.md`:

1. **Header** — date, PHP version, standards, source-of-truth, ROADMAP `Current Step`.
2. **Current-State Baseline** — run and record:
   ```bash
   grep -c "message:" phpstan-baseline.neon                              # baseline blocks
   grep -oP 'count:\s*\K[0-9]+' phpstan-baseline.neon | awk '{s+=$1} END{print s+0}'  # suppressed errors (awk: portable, bc is not always installed)
   ./app/vendor/bin/phpunit --colors=never 2>&1 | grep -E '^(OK \(|Tests:)'  # unit test total (green: "OK (N tests…)"; issues/failures: "Tests: N…")
   ```
   plus PHP version and ROADMAP current step. (No full E2E run.)
3. **Executive Summary** — overall health; counts by severity and by decision-urgency; 3–7 key takeaways.
4. **Definition-of-Modern Scorecard** — per domain D1–D10: RAG status (🟢/🟡/🔴) + one-line justification.
5. **🔴 Decision-Critical Shortlist (now-or-throwaway)** — the headline section. Per item: *the decision to
   make*, *why it's foundation-shaping*, *options + trade-offs*, *recommended option*, *what gets thrown
   away if deferred*, *which future steps depend on it*.
6. **Master Debt Inventory (table)** — one row per finding:
   `ID | Domain | Finding | Evidence (path:line) | Severity | Decision-urgency | Best-practice target | Effort (S/M/L) | Already tracked? (step/flag) | Disposition`.
7. **Per-domain deep-dives (D1–D10)** — narrative + grouped evidence + recommendations.
8. **Proposed ROADMAP changes** — concrete proposals (new sub-steps with suggested IDs, or scope edits to
   existing steps). Proposals only — do not edit ROADMAP/PHASE files in this run.
9. **Orphan/stale flag reconciliation** — table: every in-code flag → mapped step | orphan | stale.
10. **Appendix** — methodology + the exact ripgrep/Grep queries used (for reproducibility).

---

## Success criteria

- All domains D1–D10 covered (each deep-dived or confirmed-and-linked to an existing step).
- Every finding has hard evidence (`path:line` + query) and a defensible severity + decision-urgency.
- A clear **Decision-Critical Shortlist** the user can act on immediately.
- Each finding is dispositioned (existing step | proposed new sub-step | accept).
- Orphan/stale flags reconciled.
- **No application code changed** — only the report written.
- Recommendations respect Pagekit DNA (no bloat, no over-engineering, no WordPress-ism).
