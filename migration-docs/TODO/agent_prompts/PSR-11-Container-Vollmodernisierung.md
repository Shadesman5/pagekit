# PSR-11 Container Full Modernization – Overview

> **Note:** This migration is split into **4 stages**. Each stage has its own prompt. Execute stages **sequentially** – Stage 2 builds on Stage 1, etc.

---

## Stages Overview

| Stage | Prompt | Scope | Result |
|-------|--------|-------|--------|
| **1** | [PSR-11-Container-Stage1-Core.md](PSR-11-Container-Stage1-Core.md) | Container Core | Container implements ContainerInterface, Psr11Adapter removed, ArrayAccess delegates to get() |
| **2** | [PSR-11-Container-Stage2-CoreModules.md](PSR-11-Container-Stage2-CoreModules.md) | app/modules/ | All call sites in core modules use $app->get() |
| **3** | [PSR-11-Container-Stage3-SystemInstallerConsole.md](PSR-11-Container-Stage3-SystemInstallerConsole.md) | app/system/, app/installer/, app/console/ | All call sites migrated |
| **4** | [PSR-11-Container-Stage4-PackagesFinal.md](PSR-11-Container-Stage4-PackagesFinal.md) | packages/ + Final Cleanup | ArrayAccess removed, set() for registration, Extension Migration Guide |

---

## Why Staged Approach?

- **~1000+ Call Sites** – One large PR would be error-prone and hard to review
- **Minimize Risk** – Each stage is testable and reversible
- **Clear Boundaries** – Each stage has a defined scope
- **Dependencies** – Stage 1 must be complete before call sites can be migrated

**Important:** The system remains functional after each stage. There is no intermediate phase where functionality can only be tested at the end. Safety checks (setup, curl, phpunit) must pass after each stage.

---

## AGGRESSIVE MODERNIZATION RULES (for all stages)

1. **NO COMPATIBILITY LAYERS** – No parallel old/new APIs
2. **NO ADAPTERS** – Update all call sites directly, no wrappers
3. **BREAKING CHANGES ALLOWED INTERNALLY** – Public HTTP/API must stay the same
4. **DELETE OVER WRAP** – Remove old logic, do not wrap it
5. **LEGACY HACKS MUST BE MARKED** – `// TODO: Must be refactored later`
6. **HONEST COMMENTS** – No hidden backward compatibility

---

## Execution Order

1. Execute **Stage 1** → PR → Merge into develop
2. Execute **Stage 2** → PR → Merge into develop
3. Execute **Stage 3** → PR → Merge into develop
4. Execute **Stage 4** → PR → Merge into develop

After Stage 4: Container is fully PSR-11, no legacy patterns remaining.

---

## Branch Strategy

The Remote Agent creates its own branch per stage (name chosen by the agent). After merging a stage into `develop`, the next stage starts from `develop`.
