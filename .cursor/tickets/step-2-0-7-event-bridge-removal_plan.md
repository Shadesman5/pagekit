## ARCHITECT OUTPUT

- **Current Step (ROADMAP):** 2.0.7 (Event Dispatcher Bridge Removal)
- **GitHub Issue:** #184
- **Prerequisite (already merged on this branch):** 2.0.6 (Test Infrastructure Cleanup)

### Scope

**In-scope (this ticket):**

- Delete the unused Symfony↔Pagekit event-dispatcher compatibility bridge
- Delete its dedicated unit test
- Remove the `symfony.event_dispatcher` service registration and its `use` import
- Remove the now-stale `phpstan-baseline.neon` entries that point at the two deleted files
- Verify zero remaining references in `app/` and `packages/` and that PHPUnit + PHPStan + `php pagekit list` are green

**Files in scope:**

- `app/modules/application/src/Event/SymfonyEventDispatcherBridge.php` (DELETE)
- `app/modules/application/src/Tests/EventDispatcherCompatibilityTest.php` (DELETE)
- `app/modules/application/index.php` (EDIT — remove service registration; no other lines)
- `phpstan-baseline.neon` (EDIT — drop the two ignore blocks tied to deleted files)

**Explicitly out of scope (deferred — flag with ROADMAP IDs only):**

| Concern | Tracked in |
|---|---|
| Rename `GetResponseEvent` in `app/modules/auth/` (confusing Symfony-5 name) | Step 2.1.6 (PHPStan Level 7→8 — Strict Typing) |
| `ExceptionListenerWrapper` adapter pattern in kernel | Step 2.1.6 (PHPStan Level 7→8 — Strict Typing) |
| Console `execute()` return types (`: int`) | Step 2.1.4 (PHPStan Level 5→6 — Return Types) |
| Pagekit's own `EventDispatcher`, `PrefixEventDispatcher`, `Event`, `EventInterface` API surface (string events, `ArrayAccess`, module-manifest events) | Phase 2.1 / Phase 5 — kept by design (Aggressive Rule 3: stable platform API, NOT a compatibility layer) |
| Existing PHPStan baseline noise on `EventDispatcher.php`, `PrefixEventDispatcher.php`, `TraceableEventDispatcher.php` | Steps 2.1.4 / 2.1.5 / 2.1.6 |
| Any documentation in `migration-docs/audits/`, `migration-docs/branches/`, `migration-docs/pull-requests/`, `migration-docs/testing/`, `CHANGELOG-NEW.md` | Historical record — do **not** rewrite history; only the orchestrator-level finalize step writes the new branch doc + CHANGELOG-NEW entry |

If any leftover annotation is unavoidable, use ROADMAP-ID tags only. None are expected for this ticket — this is a pure deletion.

### Pre-conditions

- Branch is up to date with `develop` and Step 2.0.6 (PR #193) is merged.
- `./app/vendor/bin/phpunit` is green on the current tree.
- PHPStan baseline is green on the current tree (i.e. errors == ignored count).
- `tmp/{logs,cache,temp,packages}` and `storage/` exist and are writable (per `AGENTS.md`).
- `rg "symfony.event_dispatcher" app/ packages/ --glob "*.php"` returns **only** `app/modules/application/index.php`.
- `rg "SymfonyEventDispatcherBridge" app/ packages/ --glob "*.php"` returns **only**:
  - `app/modules/application/src/Event/SymfonyEventDispatcherBridge.php` (the class itself)
  - `app/modules/application/src/Tests/EventDispatcherCompatibilityTest.php` (the test)
  - `app/modules/application/index.php` (the registration)
- `rg "Symfony\\\\Component\\\\EventDispatcher\\\\EventDispatcherInterface" app/ packages/ --glob "*.php"` returns **only** the bridge + the test.

If any of these pre-conditions fail, **STOP** and escalate — there is a hidden consumer that must be migrated to Pagekit's `$app->get('events')` first, and that migration is the real Step 2.0.7 scope.

### Files to change

**Edit:**

- `app/modules/application/index.php`
  - Remove the `$app->set('symfony.event_dispatcher', function ($app) { … });` block (currently lines 21–23).
  - There is no top-level `use Pagekit\Event\SymfonyEventDispatcherBridge;` import — the registration uses the FQCN inline (`new \Pagekit\Event\SymfonyEventDispatcherBridge(...)`). Verify this; if a `use` was added later, remove it too.
  - Leave the rest of the file untouched (do **not** retype, reformat, or reorder other `set()` calls — Rule 4: no drive-by edits).

- `phpstan-baseline.neon`
  - Remove the ignore block at lines ~375–379 (path `app/modules/application/src/Event/SymfonyEventDispatcherBridge.php`, identifier `function.alreadyNarrowedType`, count 2).
  - Remove the ignore block at lines ~399–403 (path `app/modules/application/src/Tests/EventDispatcherCompatibilityTest.php`, identifier `method.impossibleType`, count 1).
  - Keep all other entries unchanged.

### Files to delete

- `app/modules/application/src/Event/SymfonyEventDispatcherBridge.php`
- `app/modules/application/src/Tests/EventDispatcherCompatibilityTest.php`

Both are removed via `git rm` (Rule 4: DELETE OVER WRAP — no `@deprecated`, no comment-out, no rename to `*.php.legacy`).

### Refactorer Checklist

Each item is one Conventional Commit. Per-step gate after every item: PHPUnit + PHPStan. The orchestrator commits between items.

1. **Pre-flight: confirm zero consumers**
   - Run the three `rg` commands from the Pre-conditions section verbatim and capture the output.
   - Expected results match exactly the lists in Pre-conditions. If any extra hit appears (other than the four expected files), STOP and escalate to Architect — that consumer must be migrated to `$app->get('events')` before deletion.
   - Also run `rg "EventDispatcherCompatibilityTest" app/ packages/` → expect zero matches outside the test file itself.
   - No code changes in this step. The commit (if any) is a no-op; record findings only in the next commit's body if useful. Otherwise skip the commit.

2. **`refactor(events): remove SymfonyEventDispatcherBridge service registration`**
   - Edit `app/modules/application/index.php`:
     - Delete the `$app->set('symfony.event_dispatcher', function ($app) { return new \Pagekit\Event\SymfonyEventDispatcherBridge($app->get('events')); });` block (lines 21–23 plus the surrounding blank line so the file stays clean).
     - If a top-level `use Pagekit\Event\SymfonyEventDispatcherBridge;` exists, remove it.
   - Verify: `rg "symfony.event_dispatcher" app/ packages/ --glob "*.php"` → zero matches.
   - Per-step gate: `./app/vendor/bin/phpunit` and `./app/vendor/bin/phpstan analyse --no-progress` (or whatever PHPStan command the project uses). The bridge file still exists but is now unreferenced from runtime — both must be green.

3. **`refactor(events): delete SymfonyEventDispatcherBridge and its compatibility test`**
   - `git rm app/modules/application/src/Event/SymfonyEventDispatcherBridge.php`
   - `git rm app/modules/application/src/Tests/EventDispatcherCompatibilityTest.php`
   - Verify: `rg "SymfonyEventDispatcherBridge" app/ packages/ --glob "*.php"` → zero matches.
   - Verify: `rg "Symfony\\\\Component\\\\EventDispatcher\\\\EventDispatcherInterface" app/ packages/ --glob "*.php"` → zero matches.
   - Per-step gate: PHPUnit must be green (the deleted test was the only consumer of the bridge). PHPStan will report the two newly-stale baseline ignore blocks as **errors** (`Ignored error … was not matched in reported errors`); that is expected and is fixed in step 4 — leave it for now if blocking, or temporarily run PHPStan with `--allow-empty-baseline`-equivalent flag only to confirm no NEW errors appeared. If the project has zero tolerance for stale baseline entries, fold step 4 into this commit instead.

4. **`chore(phpstan): drop baseline entries for removed event-bridge files`**
   - Edit `phpstan-baseline.neon`:
     - Remove the ignore block for `app/modules/application/src/Event/SymfonyEventDispatcherBridge.php` (lines ~375–379).
     - Remove the ignore block for `app/modules/application/src/Tests/EventDispatcherCompatibilityTest.php` (lines ~399–403).
   - Verify: `rg "SymfonyEventDispatcherBridge|EventDispatcherCompatibilityTest" phpstan-baseline.neon` → zero matches.
   - Per-step gate: PHPUnit + PHPStan. PHPStan must now be fully green (errors == ignored == 0 net new).

5. **Final consolidated audit (mirrors PROMPT §7 + §8 success criteria)**
   - `rg "SymfonyEventDispatcherBridge|symfony.event_dispatcher" app/ packages/` → zero hits.
   - `rg "Symfony\\\\Component\\\\EventDispatcher\\\\EventDispatcherInterface" app/ packages/ --glob "*.php"` → zero hits.
   - `rg "EventDispatcherCompatibilityTest" app/ packages/` → zero hits.
   - `./app/vendor/bin/phpunit` — green (the suite drops by exactly the deleted test methods, no other regressions).
   - PHPStan baseline — green (errors == ignored, no stale path warnings).
   - `php pagekit list` — boots without referencing the removed service.
   - Playwright E2E (installation, login, dashboard) — green (final-run only; not per-step). Chromium-only per `AGENTS.md`.
   - No new commit unless an audit finding requires one — this step is verification only.

### Verifier Acceptance Criteria (No-Mercy compliance)

- **Rule 1 (No Compatibility Layers):** `SymfonyEventDispatcherBridge.php` deleted; no replacement bridge, shim, adapter, facade, or "Pagekit-to-Symfony" helper introduced anywhere.
- **Rule 2 (No Adapters):** No new wrapper class, trait, interface, or static helper added; the deletion is enacted by removing call sites, not by replacing them.
- **Rule 3 (Breaking Changes Allowed Internally):** The removed `symfony.event_dispatcher` service has zero production consumers (verified in step 1). System remains functional after each commit.
- **Rule 4 (Delete Over Wrap):** Files are removed via `git rm`; no `@deprecated` markers, no commented-out code, no `*.legacy` files, no soft-archival.
- **Rule 5 (Mandatory Flagging):** No new `// TODO` markers introduced. Existing `// TODO: Step 2.0.5 – SymfonyEventDispatcherBridge::dispatch() should forward to Pagekit's trigger()` line in `migration-docs/audits/2026/02/phase1/AUDIT_REPORT_2026-02-06.md:174` is **historical audit content** and stays untouched (not source code).
- **PHP 8.2+ hygiene:** No regressions to typed properties, return types, or constructor property promotion in any touched file. (Only `index.php` and `phpstan-baseline.neon` are edited; `index.php` has no class to retype.)
- **No WordPress/Laravel artifacts** introduced.
- **Diff scope:** Diff touches exactly four paths — `app/modules/application/index.php`, `app/modules/application/src/Event/SymfonyEventDispatcherBridge.php` (D), `app/modules/application/src/Tests/EventDispatcherCompatibilityTest.php` (D), `phpstan-baseline.neon`. Anything else is a drive-by edit and must be rejected.
- **ROADMAP traceability:** Each commit message references Step 2.0.7 / Issue #184 in the body where appropriate.

### Tester Acceptance Criteria

- `./app/vendor/bin/phpunit` — all green; total test count decreases by exactly the methods removed with `EventDispatcherCompatibilityTest` (8 according to PR_SYMFONY_EVENT_COMPATIBILITY.md, plus any added in PSR-11 work). No other tests start failing.
- PHPStan — green (errors == ignored; no stale path warnings; no new errors). Baseline drops exactly the two ignore blocks for the removed files.
- `php pagekit setup` — runs cleanly on a fresh `config.php`-less workspace (or already-set-up workspace; idempotent). No stack trace mentioning `symfony.event_dispatcher` or `SymfonyEventDispatcherBridge`.
- `php pagekit list` — exits 0; lists commands without errors.
- Playwright E2E (chromium-only, per `AGENTS.md`): the three smoke specs (installation, login, dashboard) pass.
- `rg "SymfonyEventDispatcherBridge|symfony.event_dispatcher" app/ packages/` returns zero.

### Risks & Mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| A hidden consumer of `symfony.event_dispatcher` exists in an extension or in `packages/` that the audit missed | Very Low | Step 1 ripgrep audit. If found → STOP, escalate, the consumer must be migrated to `$app->get('events')` and that migration becomes the real Step 2.0.7 scope. |
| PHPStan fails due to stale baseline entries pointing at deleted files | High (expected) | Step 4 removes the two stale ignore blocks as part of the same ticket. |
| PHPUnit suite count drops more than expected (deleted test pulls in unrelated tests) | Very Low | The compat test is self-contained (`Pagekit\Tests\EventDispatcherCompatibilityTest` + a local `TestSymfonySubscriber` class in the same file). No cross-test fixtures. |
| Future Symfony component (e.g. HttpKernel integration) needs a Symfony dispatcher | Deferred — out of scope | The decision (per PROMPT §1.2 and PHASE_2 Step 2.0.7 rationale) is that Pagekit keeps its own dispatcher; if such a component is later introduced, it will be wired against `$app->get('events')` directly via a thin `Symfony\Contracts\EventDispatcher\EventDispatcherInterface` re-implementation on `Pagekit\Event\EventDispatcher` itself, **not** via a separate bridge class. Tracked in Phase 4.x if it ever materializes. |
| `index.php` edit accidentally removes adjacent service registrations | Low | StrReplace with sufficient context; verifier confirms diff scope (exactly 1 `set()` call removed). |
| Existing `// TODO: Step 2.0.5 – SymfonyEventDispatcherBridge::dispatch() should forward...` markers exist in source code | Audited: zero source-code matches; only `migration-docs/audits/.../AUDIT_REPORT_2026-02-06.md:174` (historical) and `.cursor/tickets/PSR-11-Container/...` (historical ticket). Both are docs and stay untouched. | None needed. |

### Out-of-scope flagging conventions to use in code

This ticket is a pure deletion; no in-code TODOs are expected. **If** an unforeseen leftover hack must be flagged, use exactly these formats (per `.cursor/ROADMAP.md` Rule 5):

- Out-of-scope legacy: `// TODO: Must be refactored in Step 2.1.4 (PHPStan Level 5→6 — Return Types)` — for the auth/console/audit-debt items listed in the Out-of-scope table.
- Temporary bridge: `// TODO: TEMPORARY BRIDGE - To be removed in Step X.Y` — **not expected** for this ticket; if introduced, the verifier must reject the diff (Rule 1).
- Audit fix tag: `// TODO: AUDIT FIX Step 2.0.7 (Event Bridge Removal)` — fallback for any non-trivial fix surfaced during this step.
- Backward compat tag: `// TODO: BACKWARD COMPATIBILITY - Must be refactored later` — **not expected**; reject if introduced.

## TESTING STRATEGY

- **Per step:** PHPUnit + PHPStan (mandatory after every checklist step).
- **Final run (after all steps):** PHPUnit + PHPStan + `php pagekit setup` + `php pagekit list` + Playwright E2E (installation, login, dashboard).
