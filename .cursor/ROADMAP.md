# **🗺️ Pagekit Modernization Roadmap & Rules**

> **Current Version**: 1.2.23
> **Current Step**: 2.1.9 (Test Coverage Expansion)

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

## **📊 TRACKING TABLE**

**Legend:**

- ✅ : Task implemented.
- 🛡️ : "No Mercy" Audit passed (Strict Types, No Shims, No Legacy).
- ⚠️ : Needs Audit-Fix (Legacy leftovers found).
- ⏳ : Future work.
- ⏸️ : Paused (Dependency in future step).

| ID     | Task Name                             | Status | Audit | Issue | PR      |
| :----- | :------------------------------------ | :----- | :---- | :---- | :------ |
| 1.1    | Mailer Migration                      | ✅     | 🛡️    | #119  | #17     |
| 1.2    | PHPUnit Update                        | ✅     | 🛡️    | #121  | #31     |
| 1.3    | Security Patches                      | ✅     | 🛡️    | #122  | #30     |
| 1.3.5  | ↳ Dependabot Updates                  | ✅     | 🛡️    | #123  | #32     |
| 1.4    | Safe Minor Updates                    | ✅     | 🛡️    | #124  | #53     |
| 1.5    | Doctrine DBAL 3.x                     | ✅     | 🛡️    | #125  | #54     |
| 1.6    | PSR-11 Container Compatibility        | ✅     | 🛡️    | #126  | #55     |
| 1.7    | Event System Compatibility            | ✅     | 🛡️    | #127  | #56     |
| 1.8    | Routing System Compatibility          | ✅     | 🛡️    | #128  | #57     |
| 1.9    | Symfony 6.4 LTS components            | ✅     | 🛡️    | #129  | #60-#61 |
| 1.10   | PSR-6 Cache                           | ✅     | 🛡️    | #130  | #62     |
| 1.10.5 | ↳ E2E Testing with Playwright         | ✅     | ⚠️    | #135  | #67     |
| 1.11   | ORM Modernization                     | ✅     | ⚠️    | #131  | #97     |
| 1.12   | DB Migration System                   | ✅     | 🛡️    | #132  | #107    |
| 1.13   | Validation Update                     | ✅     | 🛡️    | #133  | #108    |
| 1.13.5 | ↳ Template Security Hardening (CSP)   | ⏸️ 80% | ⚠️    | #136  | #110    |
| 1.14   | Doctrine Attributes                   | ✅     | 🛡️    | #134  | #111    |
| 2.0    | **Foundation Consolidation**          | ✅     | 🛡️    | #181  | #198    |
| 2.0.0  | ↳ Controller Attributes               | ✅     | 🛡️    | #142  | #111    |
| 2.0.1  | ↳ PSR-11 Container Modernization      | ✅     | 🛡️    | #145  | #174    |
| 2.0.1a | ↳ Container Core + Modules (S1+S2)    | ✅     | 🛡️    | #162  | #161    |
| 2.0.1b | ↳ DI Infrastructure                   | ✅     | 🛡️    | #163  | #167    |
| 2.0.1c | ↳ System/Installer/Console + DI       | ✅     | 🛡️    | #164  | #169    |
| 2.0.1d | ↳ Packages + ArrayAccess Removal      | ✅     | 🛡️    | #165  | #171    |
| 2.0.1e | ↳ StaticTrait Removal + DI Final      | ✅     | 🛡️    | #166  | #172    |
| 2.0.2  | ↳ Validator-Translator Integration    | ✅     | 🛡️    | #146  | #175    |
| 2.0.3  | ↳ Cache API Full Modernization        | ✅     | 🛡️    | #179  | #187    |
| 2.0.4  | ↳ Package/Migration System Redesign   | ✅     | 🛡️    | #180  | #189    |
| 2.0.5  | ↳ Composer & Autoload Hygiene         | ✅     | 🛡️    | #182  | #192    |
| 2.0.6  | ↳ Test Infrastructure Cleanup         | ✅     | 🛡️    | #183  | #193    |
| 2.0.7  | ↳ Event Dispatcher Bridge Removal     | ✅     | 🛡️    | #184  | #195    |
| 2.0.8  | ↳ Hotfix: `create_function()` in User | ✅     | 🛡️    | #185  | #197    |
| 2.1    | **Static Analysis & Code Quality**    | ⏳     | ⏳    | #147  | -       |
| 2.1.1  | ↳ Tooling-Setup & Baseline            | ✅     | ⏳    | #148  | #178    |
| 2.1.2  | ↳ CI/CD Integration & Quality Gates   | ✅     | 🛡️    | #149  | #199    |
| 2.1.3  | ↳ `strict_types` Migration            | ✅     | 🛡️    | #150  | #201    |
| 2.1.4  | ↳ PHPStan Level 5→6 (Return Types)    | ✅     | 🛡️    | #151  | #203    |
| 2.1.5  | ↳ PHPStan Level 6→7 (Null Safety)     | ✅     | 🛡️    | #152  | #210    |
| 2.1.6  | ↳ PHPStan Level 7→8 (Strict Typing)   | ✅     | 🛡️    | #153  | #212    |
| 2.1.7  | ↳ QueryBuilder API Standardization    | ✅     | 🛡️    | #154  | #215    |
| 2.1.8  | ↳ Infection Mutation Testing          | ✅     | 🛡️    | #155  | #216    |
| 2.1.9  | ↳ Test Coverage Expansion             | ⏳     | ⏳    | #156  | -       |
| 2.1.10 | ↳ Entity Presentation Layer (DTO)     | ⏳     | ⏳    | #204  | -       |
| 2.1.11 | ↳ EntityManager DI (remove singleton) | ⏳     | ⏳    | #205  | -       |
| 2.1.12 | ↳ Residual `mixed` narrowing          | ⏳     | ⏳    | -     | -       |
| 2.2    | CI/CD Pipeline                        | ⏳     | ⏳    | #157  | -       |
| 2.3    | Docker Production                     | ⏳     | ⏳    | #158  | -       |
| 2.4    | Build Tools                           | ⏳     | ⏳    | #159  | -       |
| 2.5    | Extension Safety System               | ⏳     | ⏳    | #160  | -       |
| 2.6    | Automated Update System               | ⏳     | ⏳    | -     | -       |
| 2.7    | Filesystem Write Resilience           | ⏳     | ⏳    | -     | -       |
| 3.1    | UIkit Update                          | ⏳     | ⏳    | -     | -       |
| 3.2    | Vue 2.7 Bridge                        | ⏳     | ⏳    | -     | -       |
| 3.2.1  | ↳ Template Pre-compilation (CSP)      | ⏳ 20% | ⏳    | -     | -       |
| 3.3    | TypeScript                            | ⏳     | ⏳    | -     | -       |
| 3.4    | Vue 3 Migration                       | ⏳     | ⏳    | -     | -       |
| 3.4.1  | ↳ vue-resource → axios                | ⏳     | ⏳    | -     | -       |
| 3.4.2  | ↳ vue-event-manager → mitt            | ⏳     | ⏳    | -     | -       |
| 3.4.3  | ↳ Vue 3 Core + @vue/compat            | ⏳     | ⏳    | -     | -       |
| 3.4.4  | ↳ Pinia State Management              | ⏳     | ⏳    | -     | -       |
| 3.4.5  | ↳ Deps (intl→Intl, lodash→native)     | ⏳     | ⏳    | -     | -       |
| 3.4.6  | ↳ Translation System Modernization    | ⏳     | ⏳    | -     | -       |
| 3.5    | Component Library                     | ⏳     | ⏳    | -     | -       |
| 3.6    | E2E selector strategy (data-testid)   | ⏳     | ⏳    | -     | -       |
| 4.1    | Basic Security                        | ⏳     | ⏳    | -     | -       |
| 4.2    | REST API v2                           | ⏳     | ⏳    | -     | -       |
| 4.3    | Performance Optimization              | ⏳     | ⏳    | -     | -       |
| 4.4    | Monitoring & Health Checks            | ⏳     | ⏳    | -     | -       |
| 4.5    | Rebranding: Pagekit → Kernkit         | ⏳     | ⏳    | -     | -       |
| 4.6    | Native Image Pipeline & Media Manager | ⏳     | ⏳    | -     | -       |
| 5.1    | Modern Block Editor                   | ⏳     | ⏳    | -     | -       |
| 5.2    | Advanced Security                     | ⏳     | ⏳    | -     | -       |
| 5.3    | Advanced Performance                  | ⏳     | ⏳    | -     | -       |
| 5.4    | Advanced CMS Features                 | ⏳     | ⏳    | -     | -       |
| 5.5    | Enterprise Features                   | ⏳     | ⏳    | -     | -       |
| 5.6    | Marketplace & Extensions              | ⏳     | ⏳    | -     | -       |

## **🛠️ TECHNICAL STACK REFERENCE**

- **PHP Version**: 8.2+ (Strict types mandatory)
- **Framework Components**: Symfony 6.4 (LTS)
- **Coding Standard**: PSR-12 / Symfony
- **Naming**: Use expressive, modern PHP naming (Constructor Property Promotion, etc.)
- **Error Format**: Standardized JSON {"error": true, "errors": {...}}
- **Rule**: If it's not modern, it's a bug.
