# Static Analysis & Code Quality Tools – Overview

> **ROADMAP Step:** 2.1 (Sub-Steps 2.1.1–2.1.9), Parent Issue #147
> **Note:** This migration is split into **9 sub-steps**. Each sub-step has its own prompt. Execute sub-steps in the dependency order below.

---

## Execution (Orchestrator Workflow)

Use the **Task Invocation Template** (`.cursor/PROMPT_TASK_INVOCATION_TEMPLATE.md`). Rule: `.cursor/rules/orchestrator-subagent-workflow.mdc`.

- **One invocation per sub-step.** Do not invoke this overview file; invoke the sub-step file.
- **Order (dependency chain):**

```
2.1.1 (Tooling-Setup) ──→ 2.1.2 (CI/CD Quality Gates)
                               │
                               ├──→ 2.1.3 (strict_types) ──→ 2.1.4 (Level 6) ──→ 2.1.5 (Level 7) ──→ 2.1.6 (Level 8) ──→ 2.1.8 (Infection)
                               │
                               ├──→ 2.1.7 (QueryBuilder)  [parallel to 2.1.3]
                               │
                               └──→ 2.1.9 (Coverage)      [ongoing, parallel]
```

- **Per sub-step:** Architect produces scope/checklist; Refactorer → Verifier → Tester per logical step; one commit per step.

---

## Sub-Steps Overview

| Sub-Step | ROADMAP | Issue | Prompt | Scope | Depends On |
|----------|---------|-------|--------|-------|------------|
| **2.1.1** | ⏳ | #148 | [Tooling-Setup](PROMPT_2_1_1_Tooling-Setup-Baseline.md) | PHPStan install, phpstan.neon, baseline, PSR-12 format | 1.14 |
| **2.1.2** | ⏳ | #149 | [CI/CD](PROMPT_2_1_2_CI-CD-Quality-Gates.md) | GitHub Actions workflows, required checks | 2.1.1 |
| **2.1.3** | ⏳ | #150 | [strict_types](PROMPT_2_1_3_Strict-Types-Migration.md) | `declare(strict_types=1)` in all PHP files | 2.1.2 |
| **2.1.4** | ⏳ | #151 | [Level 5→6](PROMPT_2_1_4_PHPStan-Level-6.md) | Return types on all methods | 2.1.3 |
| **2.1.5** | ⏳ | #152 | [Level 6→7](PROMPT_2_1_5_PHPStan-Level-7.md) | Property types, null safety | 2.1.4 |
| **2.1.6** | ⏳ | #153 | [Level 7→8](PROMPT_2_1_6_PHPStan-Level-8.md) | Eliminate mixed, generics | 2.1.5 |
| **2.1.7** | ⏳ | #154 | [QueryBuilder](PROMPT_2_1_7_QueryBuilder-API.md) | execute() → executeQuery()/executeStatement() | 2.1.2 |
| **2.1.8** | ⏳ | #155 | [Infection](PROMPT_2_1_8_Infection-Mutation-Testing.md) | Mutation testing for critical modules | 2.1.6 + coverage |
| **2.1.9** | ⏳ | #156 | [Coverage](PROMPT_2_1_9_Test-Coverage-Expansion.md) | Systematic coverage increase | 2.1.2 (ongoing) |

---

## Current Codebase State (March 2026)

| Metric | Current | Target |
|--------|---------|--------|
| PHP files (excl. vendor) | ~790 | All modernized |
| Files with `strict_types` | ~137 (~17%) | 100% |
| PHPStan | Not installed | Level 8 |
| PHP-CS-Fixer config | `@PSR2` (`.php-cs-fixer.php`) | `@PSR12` |
| CI/CD for PHP quality | None (only project automation) | Full pipeline |
| Infection | Not installed | 80%+ (critical modules) |
| Test files | ~44 | 80%+ Core coverage |
| `roave/security-advisories` | Not installed | Installed |

---

## Branch Strategy

Each sub-step creates its own branch. After merging into `develop`, the next sub-step starts from `develop`. Steps 2.1.3, 2.1.7, and 2.1.9 can run in parallel after 2.1.2 is merged.

---

## Important Caveats

- **Vendor directory is `app/vendor/`**, not `vendor/`. Binaries go to `app/vendor/bin/`.
- **PHP-CS-Fixer** exists as `.php-cs-fixer.php` config but is NOT in `composer.json` as a dev dependency yet.
- The `executeQuery()` method in Pagekit's QueryBuilder is currently **protected**, not public.
- **~790 PHP files** need `strict_types` — this is NOT a trivial style change, it changes runtime behavior.
