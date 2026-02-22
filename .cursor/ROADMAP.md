# **🗺️ Pagekit Modernization Roadmap & Rules**

## **💀 THE 5 AGGRESSIVE RULES (NO MERCY)**

1. **NO COMPATIBILITY LAYERS**
   - Do not create "Shim" classes or wrappers to support old calling patterns.
   - Break the internal API immediately if it conflicts with modern standards.
2. **NO ADAPTERS**
   - If a method signature changes, update all usages in the codebase.
   - Never create intermediate adapters to "bridge" old and new code within the same scope.
3. **BREAKING CHANGES ALLOWED INTERNALLY**
   - Internal API breakage is encouraged for cleaner, stricter PHP 8.2+ code.
   - Refactor over preserve.
   - The system must remain **functional after each step** (all tests green).
   - Internal API endpoints (`/api/...`) may change as long as both frontend and backend
     are updated together in the same step (no external consumers exist yet).
   - **Platform API names** (e.g. `$date`, `$number`, `$http`) may be kept when they represent
     a stable developer-facing API for extensions — this is NOT a compatibility layer,
     it's a clean modern reimplementation under the same function signature.
   - The **real public API** (versioned, documented, JWT-authenticated) comes in Step 4.2.
4. **DELETE OVER WRAP**
   - Legacy code must be physically deleted from the file.
   - Do not comment out old code; use Git history for reference.
5. **MANDATORY FLAGGING & AUDIT DEBT (Scope Boundaries)**
   Every legacy remnant must have a TODO with a ROADMAP step or a clear label. Tag formats:
   - Out-of-scope legacy: `// TODO: Must be refactored in Step X.Y (Name)`
   - Temporary bridge: `// TODO: TEMPORARY BRIDGE - To be removed in Step X.Y`
   - Backward compatibility: `// TODO: BACKWARD COMPATIBILITY - Must be refactored later`
   - Agents may add sub-steps (e.g. 2.0.5b) in ROADMAP if a step is missing for clean modernization.

## **📊 TRACKING TABLE (PHASE 2: FOUNDATION)**

**Legend:**

- ✅ : Task implemented.
- 🛡️ : "No Mercy" Audit passed (Strict Types, No Shims, No Legacy).
- ⚠️ : Needs Audit-Fix (Legacy leftovers found).
- ⏳ : Future work.
- ⏸️ : Paused (Dependency in future step).

| ID     | Task Name                             | Status | Audit | Issue | PR      | Responsibility   |
| :----- | :------------------------------------ | :----- | :---- | :---- | :------ | :--------------- |
| 1.1    | Mailer Migration                      | ✅     | 🛡️    | #119  | #17     | Audit Passed     |
| 1.2    | PHPUnit Update                        | ✅     | 🛡️    | #121  | #31     | Audit Passed     |
| 1.3    | Security Patches                      | ✅     | 🛡️    | #122  | #30     | Audit Passed     |
| 1.3.5  | ↳ Dependabot Updates                  | ✅     | 🛡️    | #123  | #32     | Audit Passed     |
| 1.4    | Safe Minor Updates                    | ✅     | 🛡️    | #124  | #53     | Audit Passed     |
| 1.5    | Doctrine DBAL 3.x                     | ✅     | 🛡️    | #125  | #54     | Audit Passed     |
| 1.6    | PSR-11 Container Compatibility        | ✅     | 🛡️    | #126  | #55     | Audit Passed     |
| 1.7    | Event System Compatibility            | ✅     | 🛡️    | #127  | #56     | Audit Passed     |
| 1.8    | Routing System Compatibility          | ✅     | 🛡️    | #128  | #57     | Audit Passed     |
| 1.9    | Symfony 6.4 LTS components            | ✅     | 🛡️    | #129  | #60-#61 | Audit Passed     |
| 1.10   | PSR-6 Cache                           | ✅     | 🛡️    | #130  | #62     | Audit Passed     |
| 1.10.5 | ↳ E2E Testing with Playwright         | ✅     | 🛡️    | #135  | #67     | Audit Passed     |
| 1.11   | ORM Modernization                     | ✅     | 🛡️    | #131  | #97     | Audit Passed     |
| 1.12   | DB Migration System                   | ✅     | 🛡️    | #132  | #107    | Audit Passed     |
| 1.13   | Validation Update                     | ✅     | 🛡️    | #133  | #108    | Audit Passed     |
| 1.13.5 | ↳ Template Security Hardening (CSP)   | ⏸️ 80% | 🛡️    | #136  | #110    | Audit Passed     |
| 1.14   | Doctrine Attributes                   | ✅     | 🛡️    | #134  | #111    | Audit Passed     |
| 2.0    | Controller Attributes                 | ✅     | ⚠️    | #142  | #111    | Needs Audit      |
| 2.0.1  | ↳ PSR-11 Container Vollmodernisierung | ⏳     | ⏳    | #145  | -       | **Current Step** |
| 2.0.1a | ↳ Container Core + Modules (S1+S2)    | ✅     | ⏳    | #162  | #161    | Done             |
| 2.0.1b | ↳ DI Infrastructure                   | ⏳     | ⏳    | #163  | -       | Next Sub-Step    |
| 2.0.1c | ↳ System/Installer/Console + DI       | ⏳     | ⏳    | #164  | -       | Sub-Step         |
| 2.0.1d | ↳ Packages + ArrayAccess Removal      | ⏳     | ⏳    | #165  | -       | Sub-Step         |
| 2.0.1e | ↳ StaticTrait Removal + DI Final      | ⏳     | ⏳    | #166  | -       | Sub-Step         |
| 2.0.2  | ↳ Validator-Translator Integration    | ⏳     | ⏳    | #146  | -       | Phase 2          |
| 2.1    | Static Analysis & Code Quality        | ⏳     | ⏳    | #147  | -       | Phase 2          |
| 2.1.1  | ↳ Tooling-Setup & Baseline            | ⏳     | ⏳    | #148  | -       | Phase 2          |
| 2.1.2  | ↳ CI/CD Integration & Quality Gates   | ⏳     | ⏳    | #149  | -       | Phase 2          |
| 2.1.3  | ↳ `strict_types` Migration            | ⏳     | ⏳    | #150  | -       | Phase 2          |
| 2.1.4  | ↳ PHPStan Level 5→6 (Return Types)    | ⏳     | ⏳    | #151  | -       | Phase 2          |
| 2.1.5  | ↳ PHPStan Level 6→7 (Null Safety)     | ⏳     | ⏳    | #152  | -       | Phase 2          |
| 2.1.6  | ↳ PHPStan Level 7→8 (Strict Typing)   | ⏳     | ⏳    | #153  | -       | Phase 2          |
| 2.1.7  | ↳ QueryBuilder API Standardization    | ⏳     | ⏳    | #154  | -       | Phase 2          |
| 2.1.8  | ↳ Infection Mutation Testing          | ⏳     | ⏳    | #155  | -       | Phase 2          |
| 2.1.9  | ↳ Test Coverage Expansion             | ⏳     | ⏳    | #156  | -       | Phase 2          |
| 2.2    | CI/CD Pipeline                        | ⏳     | ⏳    | #157  | -       | Phase 2          |
| 2.3    | Docker Production                     | ⏳     | ⏳    | #158  | -       | Phase 2          |
| 2.4    | Build Tools                           | ⏳     | ⏳    | #159  | -       | Phase 2          |
| 2.5    | Extension Safety System               | ⏳     | ⏳    | #160  | -       | Phase 2          |
| 3.1    | UIkit Update                          | ⏳     | ⏳    | -     | -       | Phase 3          |
| 3.2    | Vue 2.7 Bridge                        | ⏳     | ⏳    | -     | -       | Phase 3          |
| 3.2.1  | ↳ Template Pre-compilation (CSP)      | ⏳ 20% | ⏳    | -     | -       | Phase 3          |
| 3.3    | TypeScript                            | ⏳     | ⏳    | -     | -       | Phase 3          |
| 3.4    | Vue 3 Migration                       | ⏳     | ⏳    | -     | -       | Phase 3          |
| 3.4.1  | ↳ vue-resource → axios                | ⏳     | ⏳    | -     | -       | Phase 3          |
| 3.4.2  | ↳ vue-event-manager → mitt            | ⏳     | ⏳    | -     | -       | Phase 3          |
| 3.4.3  | ↳ Vue 3 Core + @vue/compat            | ⏳     | ⏳    | -     | -       | Phase 3          |
| 3.4.4  | ↳ Pinia State Management              | ⏳     | ⏳    | -     | -       | Phase 3          |
| 3.4.5  | ↳ Deps (intl→Intl, lodash→native)     | ⏳     | ⏳    | -     | -       | Phase 3          |
| 3.4.6  | ↳ Translation System Modernization    | ⏳     | ⏳    | -     | -       | Phase 3          |
| 3.5    | Component Library                     | ⏳     | ⏳    | -     | -       | Phase 3          |
| 4.1    | Basic Security                        | ⏳     | ⏳    | -     | -       | Phase 4          |
| 4.2    | REST API v2                           | ⏳     | ⏳    | -     | -       | Phase 4          |
| 4.3    | Performance Optimization              | ⏳     | ⏳    | -     | -       | Phase 4          |
| 4.4    | Monitoring & Health Checks            | ⏳     | ⏳    | -     | -       | Phase 4          |
| 5.1    | Modern Block Editor                   | ⏳     | ⏳    | -     | -       | Phase 5          |
| 5.2    | Advanced Security                     | ⏳     | ⏳    | -     | -       | Phase 5          |
| 5.3    | Advanced Performance                  | ⏳     | ⏳    | -     | -       | Phase 5          |
| 5.4    | Advanced CMS Features                 | ⏳     | ⏳    | -     | -       | Phase 5          |
| 5.5    | Enterprise Features                   | ⏳     | ⏳    | -     | -       | Phase 5          |
| 5.6    | Marketplace & Extensions              | ⏳     | ⏳    | -     | -       | Phase 5          |

## **🛠️ TECHNICAL STACK REFERENCE**

- **PHP Version**: 8.2+ (Strict types mandatory)
- **Framework Components**: Symfony 6.4 (LTS)
- **Coding Standard**: PSR-12 / Symfony
- **Naming**: Use expressive, modern PHP naming (Constructor Property Promotion, etc.)
- **Error Format**: Standardized JSON {"error": true, "errors": {...}}
- **Rule**: If it's not modern, it's a bug.
