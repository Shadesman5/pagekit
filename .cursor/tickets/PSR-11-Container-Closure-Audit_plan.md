## ARCHITECT OUTPUT
- **Current Step (ROADMAP):** 2.0.1 (Parent) — Closure audit for sub-steps 2.0.1a–e
- **Scope:** `app/modules/application/src/` (Container, Application), `app/system/modules/*/src/Controller/`, `app/system/modules/*/src/Event/`, `app/system/modules/*/src/Model/`, `packages/*/src/Controller/`, `packages/*/src/Event/`, `packages/*/src/Model/`, `app/modules/kernel/src/`, `migration-docs/branches/PSR-11-Container/`, `.cursor/ROADMAP.md`
- **Deferred:** None — this is the final closure. All 2.0.1 work must be complete before marking audit passed.
- **Bridges:** ZERO `TEMPORARY BRIDGE` markers must remain. Any found are blockers to resolve inline.

- **Checklist:**
  1. **Safety: verify branch state** — confirm on branch from `develop` with all 5 PRs (#161, #167, #169, #171, #172) merged. Run `git log --oneline -10`.
  2. **Acceptance 1.1: PSR-11 native** — `rg 'implements.*ContainerInterface' app/modules/application/src/Container.php`; verify `get()`, `has()`, `set()` are public, non-magic.
  3. **Acceptance 1.2: No ArrayAccess** — verify Container does NOT implement ArrayAccess; `rg '\$app\[' app/ packages/ --type php` returns zero.
  4. **Acceptance 1.3: No StaticTrait/EventTrait/RouterTrait** — confirm `app/modules/application/src/Application/Traits/` does not exist; Application.php has no `use StaticTrait|EventTrait|RouterTrait`.
  5. **Acceptance 1.4: Zero `App::` static calls** — `rg 'App::' app/ packages/ --type php` excluding use/namespace/docblock/comment lines returns zero.
  6. **Acceptance 1.5: Zero magic methods** — `rg '__call\(' app/modules/application/src/Container.php` returns zero; no `__callStatic`, no `static::$instance`.
  7. **Acceptance 1.6: Controllers use constructor DI** — list all `*Controller` classes, verify zero `App::` / `App::getInstance()` in controller files.
  8. **Acceptance 1.7: Listeners use constructor DI** — list all `*Listener` classes, verify zero `App::` / `App::getInstance()` in listener files.
  9. **Acceptance 1.8: Models use repository pattern** — verify zero `App::` in model files; verify repository classes exist.
  10. **Acceptance 1.9: Migration guide published** — verify `migration-docs/branches/PSR-11-Container/` contains: `PSR11_CONTAINER_FULL_MODERNIZATION.md`, `PSR11_CONTAINER_STATICTRAIT_REMOVAL.md`, `PSR11_CONTAINER_EXTENSION_MIGRATION.md`.
  11. **Acceptance 1.10: PHPUnit green** — `./app/vendor/bin/phpunit` must pass; `php pagekit list` must work.
  12. **Audit Rule 1: No Compat Layers** — `rg -i '(shim|compat|legacy|wrapper|bridge)' app/modules/application/src/ --type php` returns zero functional hits; `Psr11Adapter.php` must not exist.
  13. **Audit Rule 2: No Adapters** — `rg 'class.*Adapter' app/modules/application/src/ app/modules/kernel/src/ --type php` returns zero.
  14. **Audit Rule 3: Breaking + Functional** — no `offsetGet|offsetSet|offsetExists|offsetUnset` in Container.php; tests green (from step 11).
  15. **Audit Rule 4: Delete Over Wrap** — confirm StaticTrait.php, EventTrait.php, RouterTrait.php, Psr11Adapter.php are physically deleted.
  16. **Audit Rule 5: Flagging & Debt** — `rg 'TEMPORARY BRIDGE' app/ packages/ --type php` = zero; `rg 'TODO.*2\.0\.1[a-e]' app/ packages/ --type php` = zero (stale markers); document any valid future-step TODOs.
  17. **Fix: Stale TODOs** — if stale `TODO` markers referencing 2.0.1a–e found, delete them (work is done) or fix the regression.
  18. **Fix: TEMPORARY BRIDGE remnants** — if any remain, replace with proper modern pattern and delete the marker.
  19. **Fix: Leftover legacy patterns** — any `App::`, `__call`, `ArrayAccess` usage is regression; fix with constructor DI / `$app->get()`.
  20. **Fix: Missing docs** — if `PSR11_CONTAINER_FULL_MODERNIZATION.md` is incomplete (missing architecture changes, breaking changes for extensions, before/after examples), complete it.
  21. **ROADMAP update** — set 2.0.1 row to `✅ | 🛡️ | #145 | #174 | Audit Passed`; set 2.0.1a–e audit column to `🛡️`; if all clear, advance 2.0.2 to `**Current Step**`. Use `⚠️` if deferred findings exist.
  22. **Commit** — if code fixes: `audit(container): resolve PSR-11 audit debt (ROADMAP 2.0.1)`; ROADMAP only: `docs(roadmap): mark PSR-11 Container 2.0.1 as audit passed`. Push.
  23. **GitHub Issue #145 comment** — post audit results checklist via `gh issue comment 145`. Do NOT close the issue.
  24. **E2E Playwright (final)** — start dev server `php -S localhost:8080 index.php`; run `installation.spec.js`, `authentication.spec.js`, `dashboard.spec.js` sequentially; all must pass. If failure is migration-caused, fix + re-run. If pre-existing, document as known issue.
