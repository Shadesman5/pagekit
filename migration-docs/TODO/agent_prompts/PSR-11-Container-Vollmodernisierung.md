# PSR-11 Container Full Modernization – Overview

> **Note:** This migration is split into **4 stages**. Each stage has its own prompt. Execute stages **sequentially** – Stage 2 builds on Stage 1, etc.

---

## Execution (Orchestrator Workflow)

Use the **Task Invocation Template** (`.cursor/PROMPT_TASK_INVOCATION_TEMPLATE.md`). Rule: `.cursor/rules/orchestrator-subagent-workflow.mdc`.

- **One invocation per stage.** Do not invoke this overview file; invoke the stage file.
- **Order:**  
  `@PSR-11-Container-Stage1-Core.md` → wait for completion (all steps committed) →  
  then `@PSR-11-Container-Stage2-CoreModules.md` → … → Stage 3 → Stage 4.
- **Per stage:** Architect produces scope/checklist from the stage prompt; Refactorer → Verifier → Tester run per logical step; one commit per step. ROADMAP step: **2.0.5** (Stage N in tracking).

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

**Important:** The system remains functional after each stage. There is no intermediate phase where functionality can only be tested at the end. Safety checks (setup, curl, phpunit) must pass after each stage. Apply rules from `@ROADMAP.md` (THE 5 AGGRESSIVE RULES) and `.cursor/rules/pagekit-context.mdc`.

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
