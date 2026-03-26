# PSR-11 Container Vollmodernisierung – Overview

> **ROADMAP Step:** 2.0.1 (Sub-Steps a–e)
> **Note:** This migration is split into **5 sub-steps**. Each sub-step has its own prompt. Execute sub-steps **sequentially** – each builds on the previous.

---

## Execution (Orchestrator Workflow)

Use the **Task Invocation Template** (`.cursor/PROMPT_TASK_INVOCATION_TEMPLATE.md`). Rule: `.cursor/rules/orchestrator-subagent-workflow.mdc`.

- **One invocation per sub-step.** Do not invoke this overview file; invoke the sub-step file.
- **Order:**
  `@PSR-11-Container-Stage1-Core.md` + `@PSR-11-Container-Stage2-CoreModules.md` (2.0.1a, done) →
  `@PSR-11-Container-DI-Infrastructure.md` (2.0.1b) →
  `@PSR-11-Container-Stage3-SystemInstallerConsole.md` (2.0.1c) →
  `@PSR-11-Container-Stage4-PackagesFinal.md` (2.0.1d) →
  `@PSR-11-Container-StaticTrait-Removal.md` (2.0.1e)
- **Per sub-step:** Architect produces scope/checklist; Refactorer → Verifier → Tester run per logical step; one commit per step.

---

## Sub-Steps Overview

| Sub-Step | ROADMAP | Prompt | Scope | Result |
|----------|---------|--------|-------|--------|
| **2.0.1a** | ✅ Done | [Stage1](PSR-11-Container-Stage1-Core.md) + [Stage2](PSR-11-Container-Stage2-CoreModules.md) | Container Core + app/modules/ call sites | Container implements ContainerInterface, Psr11Adapter removed, app/modules/ uses $app->get() |
| **2.0.1b** | ⏳ | [DI Infrastructure](PSR-11-Container-DI-Infrastructure.md) | ControllerResolver, listener pattern | Controllers + listeners support constructor injection |
| **2.0.1c** | ⏳ | [Stage3 corrected](PSR-11-Container-Stage3-SystemInstallerConsole.md) | app/system/, app/installer/, app/console/ | All call sites migrated: ArrayAccess → get(), App::x() → constructor DI |
| **2.0.1d** | ⏳ | [Stage4](PSR-11-Container-Stage4-PackagesFinal.md) | packages/ + Final Cleanup | ArrayAccess removed, set() for registration, Extension Migration Guide |
| **2.0.1e** | ⏳ | [StaticTrait Removal](PSR-11-Container-StaticTrait-Removal.md) | Models + StaticTrait + __call | Repository pattern, StaticTrait deleted, zero App:: static calls |

---

## Why This Structure?

### Original Problem: `__callStatic` / PSR-11 Collision

When Container got `get()` and `has()` as PSR-11 instance methods (Stage 1), `App::get('x')` stopped working via `__callStatic` — PHP doesn't trigger `__callStatic` when a method with that name exists as an instance method. This forced a workaround (`App::getInstance()->get('x')`) at 8 call sites.

The original Stage 3 migration rules incorrectly specified `App::db() → App::get('db')` which would cause fatal errors. Instead of patching around this collision, we solve it properly:

1. **2.0.1b** adds DI infrastructure so controllers/listeners CAN use constructor injection
2. **2.0.1c** migrates call sites directly to DI (no intermediate `App::getInstance()->get()` step)
3. **2.0.1e** removes StaticTrait entirely — no more magic, no more collision

### Dependency Chain

```
2.0.1a (done) ──→ 2.0.1b (DI infrastructure required before controllers can use DI)
                      │
                      ▼
                   2.0.1c (app/system/ migration uses DI from 2.0.1b)
                      │
                      ▼
                   2.0.1d (packages/ + ArrayAccess removal)
                      │
                      ▼
                   2.0.1e (StaticTrait removal — last step, needs ALL call sites clean)
```

---

## Component DI Analysis (Reference)

| Component Type | Instantiation | DI Feasible? | Migration Sub-Step |
|---------------|--------------|-------------|-------------------|
| Controllers (~25 files, ~200 calls) | `new $class()` in ControllerResolver | Yes, after 2.0.1b | 2.0.1c |
| Event Listeners (~7 files, ~50 calls) | `new Listener()` in index.php | Yes, `$app` available | 2.0.1c |
| Console Commands (~2 files, ~3 calls) | `new $class()` + setContainer() | Yes | 2.0.1c |
| Models (~5 files, ~10 calls) | ORM-managed (EntityManager) | No — needs repositories | 2.0.1e |
| Module index.php (0 App:: calls) | Have `$app` instance | Already clean | 2.0.1a (done) |

---

## Execution Order

1. ✅ **2.0.1a** (Stage 1+2) → Merged
2. Execute **2.0.1b** → PR → Merge into develop
3. Execute **2.0.1c** → PR → Merge into develop
4. Execute **2.0.1d** → PR → Merge into develop
5. Execute **2.0.1e** → PR → Merge into develop

After 2.0.1e: Container is fully PSR-11, zero static access, StaticTrait deleted, proper DI everywhere.

---

## Branch Strategy

Each sub-step creates its own branch (name chosen by agent). After merging a sub-step into `develop`, the next sub-step starts from `develop`.
