# **🗺️ Pagekit Modernization Roadmap & Rules**

## **💀 THE 5 AGGRESSIVE RULES (NO MERCY)**

1. **NO COMPATIBILITY LAYERS**
   - Do not create "Shim" classes or wrappers to support old calling patterns.
   - Break the internal API immediately if it conflicts with modern standards.
2. **NO ADAPTERS**
   - If a method signature changes, update all usages in the codebase.
   - Never create intermediate adapters to "bridge" old and new code within the same scope.
3. **BREAKING CHANGES ALLOWED**
   - Internal API breakage is encouraged for cleaner, stricter PHP 8.2+ code.
   - Refactor over preserve.
4. **DELETE OVER WRAP**
   - Legacy code must be physically deleted from the file.
   - Do not comment out old code; use Git history for reference.
5. **MANDATORY FLAGGING & AUDIT DEBT (Scope Boundaries)**
   Every legacy remnant must have a // TODO: Step X.Y tag.
   - If code outside the current step's scope must remain legacy, tag it:  
     // TODO: Must be refactored in Step X.Y (Name)
   - If a temporary bridge is strictly required for system stability:  
     // TODO: TEMPORARY BRIDGE - To be removed in Step X.Y
   - If a "completed" step fails the "No Mercy" audit, it must be flagged with
     // TODO: AUDIT FIX Step X.Y.
   - Dynamic Roadmap: Agents are encouraged to insert sub-steps (e.g., 2.0.5b)
     if they identify missing links for a clean modernization.

## **📊 TRACKING TABLE (PHASE 2: FOUNDATION)**

**Legend:**

- ✅ : Task implemented.
- 🛡️ : "No Mercy" Audit passed (Strict Types, No Shims, No Legacy).
- ⚠️ : Needs Audit-Fix (Legacy leftovers found).
- ⏳ : Future work.
- ⏸️ : Paused (Dependency in future step).

| ID     | Task Name                         | Status | Audit | PR      | Responsibility |
| :----- | :-------------------------------- | :----- | :---- | :------ | :------------- |
| 1.1    | Mailer Migration                  | ✅     | 🛡️    | #17     | Audit Passed   |
| 1.2    | PHPUnit Update                    | ✅     | 🛡️    | #31     | Audit Passed   |
| 1.3    | Security Patches                  | ✅     | 🛡️    | #30     | Audit Passed   |
| 1.3.5  | Dependabot Updates                | ✅     | 🛡️    | #32     | Audit Passed   |
| 1.4    | Safe Minor Updates                | ✅     | 🛡️    | #53     | Audit Passed   |
| 1.5    | Doctrine DBAL 3.x                 | ✅     | 🛡️    | #54     | Audit Passed   |
| 1.6    | PSR-11 Container Compatibility    | ✅     | 🛡️    | #55     | Audit Passed   |
| 1.7    | Event System Compatibility        | ✅     | 🛡️    | #56     | Audit Passed   |
| 1.8    | Routing System Compatibility      | ✅     | 🛡️    | #57     | Audit Passed   |
| 1.9    | Symfony 6.4 LTS components        | ✅     | 🛡️    | #60-#61 | Audit Passed   |
| 1.10   | PSR-6 Cache                       | ✅     | 🛡️    | #62     | Audit Passed   |
| 1.10.5 | E2E Testing with Playwright       | ✅     | 🛡️    | #67     | Audit Passed   |
| 1.11   | ORM Modernization                 | ✅     | 🛡️    | #97     | Audit Passed   |
| 1.12   | DB Migration System               | ✅     | 🛡️    | #107    | Audit Passed   |
| 1.13   | Validation Update                 | ✅     | 🛡️    | #108    | Audit Passed   |
| 1.13.5 | Template Security Hardening (CSP) | ⏸️ 80% | 🛡️    | #110    | Audit Passed   |
| 1.14   | Doctrine Attributes               | ✅     | 🛡️    | #111    | Audit Passed   |
| 2.0    | Controller Attributes             | ✅     | ⚠️    | #111    | Needs Audit    |
| 2.0.5  | PSR-11 Container Modernising      | ⏳     | ⏳    | -       | **Next Step**  |
| 2.1    | Static Analysis & Code Quality    | ⏳     | ⏳    | -       | Phase 2        |
| 2.1.5  | QueryBuilder API Standardization  | ⏳     | ⏳    | -       | Phase 2        |
| 2.2    | CI/CD Pipeline                    | ⏳     | ⏳    | -       | Phase 2        |
| 2.3    | Docker Production                 | ⏳     | ⏳    | -       | Phase 2        |
| 2.4    | Build Tools                       | ⏳     | ⏳    | -       | Phase 2        |
| 2.5    | Extension Safety System           | ⏳     | ⏳    | -       | Phase 2        |
| 3.1    | UIkit Update                      | ⏳     | ⏳    | -       | Phase 3        |
| 3.2    | Vue 2.7 Bridge                    | ⏳     | ⏳    | -       | Phase 3        |
| 3.2.5  | Template Pre-compilation (CSP)    | ⏳ 20% | ⏳    | -       | Phase 3        |
| 3.3    | TypeScript                        | ⏳     | ⏳    | -       | Phase 3        |
| 3.4    | Vue 3 Migration                   | ⏳     | ⏳    | -       | Phase 3        |
| 3.5    | Component Library                 | ⏳     | ⏳    | -       | Phase 3        |
| 4.1    | Basic Security                    | ⏳     | ⏳    | -       | Phase 4        |
| 4.2    | REST API v2                       | ⏳     | ⏳    | -       | Phase 4        |
| 4.3    | Performance Optimization          | ⏳     | ⏳    | -       | Phase 4        |
| 4.4    | Monitoring & Health Checks        | ⏳     | ⏳    | -       | Phase 4        |
| 5.1    | Modern Block Editor               | ⏳     | ⏳    | -       | Phase 5        |
| 5.2    | Advanced Security                 | ⏳     | ⏳    | -       | Phase 5        |
| 5.3    | Advanced Performance              | ⏳     | ⏳    | -       | Phase 5        |
| 5.4    | Advanced CMS Features             | ⏳     | ⏳    | -       | Phase 5        |
| 5.5    | Enterprise Features               | ⏳     | ⏳    | -       | Phase 5        |
| 5.6    | Marketplace & Extensions          | ⏳     | ⏳    | -       | Phase 5        |

## **🛠️ TECHNICAL STACK REFERENCE**

- **PHP Version**: 8.2+ (Strict types mandatory)
- **Framework Components**: Symfony 6.4 (LTS)
- **Coding Standard**: PSR-12 / Symfony
- **Naming**: Use expressive, modern PHP naming (Constructor Property Promotion, etc.)
- **Error Format**: Standardized JSON {"error": true, "errors": {...}}
- **Rule**: If it's not modern, it's a bug.
