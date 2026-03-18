# Deferred Bugbot Findings — Step 2.0.1c

Issues reported by Bugbot during PR #169 review that are outside the scope
of Step 2.0.1c and should be addressed in later ROADMAP steps.

---

## 1. Missing Test Coverage for Backend Changes

**Bugbot Rule:** 6.1 (Require tests for backend changes)
**Severity:** Non-blocking
**Target Step:** 2.1.9 (Test Coverage Expansion)

The PR modifies many files in `app/system/src/`, `app/installer/src/`, and
`app/system/modules/*/src/` (constructor signatures, DI patterns, service
resolution) but introduces no corresponding tests.

**Affected areas:**
- All controllers migrated to constructor injection (25 files)
- All listeners migrated to constructor injection (7 files)
- Installer controllers and PackageManager
- CaptchaListener, DashboardController, InstallerController

**Rationale for deferral:** Step 2.0.1c is a mechanical migration (static
calls → injection). Tests verifying the DI wiring would be integration tests
requiring a running container. The existing PHPUnit suite (267 tests) passes,
confirming no regressions. Dedicated test coverage expansion is planned for
Step 2.1.9.

---

## 2. `mixed` Typing in Constructor-Injected Properties

**Bugbot Rule:** 2.2 / 2.3 (Typed properties, Return types)
**Severity:** Non-blocking
**Target Step:** 2.1.4 (PHPStan Level 5→6, Return Types) / 2.1.6 (Level 7→8, Strict Typing)

Multiple controllers use `mixed` for constructor-injected service properties
instead of their actual types. Example from `DashboardController`:

```php
public function __construct(
    private readonly mixed $module,    // actually ModuleManager
    private readonly mixed $request,   // actually Request
    private readonly mixed $response,  // actually Response
    private readonly mixed $version,   // actually string
) {}
```

This pattern exists across all 25+ migrated controllers.

**Rationale for deferral:** The `mixed` typing was a pragmatic choice during
Stage 3 to avoid coupling controllers to concrete container service types that
may change in Step 2.0.1d/2.0.1e. Once the container modernization is complete
and PHPStan is enforced (Steps 2.1.4–2.1.6), all `mixed` types should be
replaced with proper types. The `ControllerResolver` resolves services by
parameter name from the container, so type hints are not used for resolution.
