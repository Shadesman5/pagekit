# Remote Agent Prompt: Codebase Audit & Verification Against Modernization Standards

=================================================================================
PURPOSE: Comprehensive analysis and verification of migrated/legacy code
=================================================================================

This prompt is designed for **Remote Agent** / **Background Agent** execution.
It performs a systematic audit of specified code areas against Pagekit's current
modernization standards (2025/2026). Use it to verify completed migrations, identify
remaining compatibility layers, and ensure compliance with aggressive modernization rules.

**Context**: Earlier migrations (e.g. Mail 2024) were done without the current
radical modernization rules. This prompt enables re-audit and cleanup.

=================================================================================
REFERENCE: Current Standards (from .cursor/rules/pagekit-context.mdc)
=================================================================================

## CRITICAL CONSTRAINTS (STRICT)
1. NO WORDPRESS CODE – Never use WordPress functions or assume wp_* schema
2. NO LARAVEL FACADES – Use Symfony Components, not dd(), collect(), Str::
3. STRICT TYPING (PHP 8.2+) – Typed properties, return types, constructor promotion

## AGGRESSIVE MODERNIZATION RULES
1. **NO COMPATIBILITY LAYERS** – Do not keep old & new behavior in parallel
2. **NO ADAPTERS** – Update all call sites instead of adding wrappers
3. **BREAKING CHANGES ALLOWED INTERNALLY** – Public HTTP/API must stay same
4. **DELETE OVER WRAP** – If old logic conflicts with security model, delete it
5. **LEGACY HACKS MUST BE MARKED** – Use `// TODO: Must be refactored later`
6. **HONEST COMMENTS** – Mark backward compatibility with:
   `// TODO: BACKWARD COMPATIBILITY - Must be refactored later`

=================================================================================
TASK: Run Full Audit
=================================================================================

**Input**: Target area (module name, path, or "full codebase")
**Output**: Structured audit report (see OUTPUT FORMAT below)

**Default target if none specified**: `app/system/modules/mail/` (Mail migration reference)

=================================================================================
PHASE 1: Compatibility Layer Detection
=================================================================================

1.1 Search for explicit compatibility patterns:
   ```bash
   grep -rn "backward compatibility\|compatibility layer\|@deprecated\|__call\|__get\|__set" <TARGET_PATH> --include="*.php"
   grep -rn "TODO:.*BACKWARD\|TODO:.*compat" <TARGET_PATH> --include="*.php"
   ```

1.2 Search for adapter/wrapper patterns:
   ```bash
   grep -rn "Adapter\|Wrapper\|Legacy" <TARGET_PATH> --include="*.php"
   grep -rn "extends.*implements" <TARGET_PATH> --include="*.php"
   ```

1.3 Check for dual code paths (old + new):
   - Methods that branch on "if (old_format)" vs "else (new_format)"
   - Config keys that support both legacy and new formats
   - Request handling that supports multiple input formats without clear migration path

1.4 Document each finding:
   - File:Line
   - Pattern type (compatibility layer / adapter / deprecated / dual path)
   - Recommendation (remove / mark TODO / refactor)

=================================================================================
PHASE 2: PHP 8.2+ Compliance
=================================================================================

2.1 Typed properties:
   - All class properties MUST have explicit types
   - Flag: `protected $var;` without type

2.2 Return types:
   - All public/protected methods MUST have return types
   - Flag: Methods without `: void`, `: ?array`, `: string`, etc.

2.3 Parameter types:
   - All parameters MUST be typed
   - Nullable: Use `?Type $param = null` not `Type $param = null`

2.4 Constructor Property Promotion:
   - Prefer `public function __construct(public string $name)` over separate property + assign

2.5 Strict mode:
   - Verify `declare(strict_types=1);` at top of all PHP files

=================================================================================
PHASE 3: Technology Stack Violations
=================================================================================

3.1 Forbidden patterns:
   - `swiftmailer`, `Swift_*` (any remaining references)
   - `wp_*`, `add_action`, `do_shortcode` (WordPress)
   - `dd()`, `collect()`, `Str::` (Laravel, unless polyfilled)

3.2 Required patterns:
   - Symfony components for mail, routing, events, etc.
   - `dump()` for debugging (not var_dump)
   - PSR-11 container usage

3.3 Frontend (if applicable):
   - Vue 2.6 patterns only (no Composition API, no <script setup>)
   - No complex mixins that block Vue 3 migration

=================================================================================
PHASE 4: API Consistency & Call Site Analysis
=================================================================================

4.1 For the target module, find ALL call sites:
   - Who calls `create()`, `send()`, or other public APIs?
   - Are return types consistent? (e.g. `create()` returns Email vs Message)

4.2 Check for mismatched usage:
   - Caller expects Method A but receives object with Method B
   - Example: `$mail->send()` on object that has no `send()` (Symfony Email vs Pagekit Message)

4.3 Document integration points:
   - Controllers, extensions, event listeners that use the module

=================================================================================
PHASE 5: Documentation vs. Reality
=================================================================================

5.1 Compare migration docs (e.g. MAIL_MIGRATION.md) with actual code:
   - Does doc say "Added backward compatibility layer" but code has none?
   - Does doc say "42 tests" but current count differs?
   - Are file paths correct? (app/modules/mail vs app/system/modules/mail)

5.2 Flag outdated documentation:
   - List discrepancies
   - Suggest doc updates

=================================================================================
PHASE 6: Test Coverage & Regression Risks
=================================================================================

6.1 Run existing tests:
   ```bash
   ./vendor/bin/phpunit <TARGET_PATH>/src/Tests/
   ```

6.2 Document:
   - Pass/fail count
   - Any skipped or failing tests
   - Gaps (areas without tests)

6.3 Identify regression risks:
   - Code removed in cleanup could break untested paths
   - Suggest tests to add before refactoring

=================================================================================
OUTPUT FORMAT: Audit Report
=================================================================================

Generate a markdown report with this structure:

```markdown
# Audit Report: [Module/Area Name]
**Date**: [YYYY-MM-DD]
**Target**: [path]
**Standards**: Pagekit Modernization 2025/2026

## Executive Summary
- [ ] Compliant / [ ] Needs cleanup / [ ] Critical issues
- Key findings count
- Recommended actions (prioritized)

## 1. Compatibility Layers & Legacy Code
| File | Line | Pattern | Recommendation |
|------|------|---------|-----------------|
| ... | ... | ... | ... |

## 2. PHP 8.2+ Compliance
| File | Issue | Fix |
|------|-------|-----|
| ... | ... | ... |

## 3. Technology Violations
| File | Violation | Action |
|------|-----------|--------|
| ... | ... | ... |

## 4. API Consistency Issues
| Caller | Issue | Fix |
|--------|-------|-----|
| ... | ... | ... |

## 5. Documentation Discrepancies
| Doc | Claim | Reality |
|-----|-------|---------|
| ... | ... | ... |

## 6. Test Status
- Total: X | Pass: Y | Fail: Z
- Gaps: [list]
- Regression risks: [list]

## 7. Prioritized Action List
1. [High] ...
2. [Medium] ...
3. [Low] ...
```

=================================================================================
EXAMPLE: Mail Module Quick Audit Commands
=================================================================================

```bash
# Compatibility layer scan
grep -rn "backward\|compat\|deprecated\|__call" app/system/modules/mail/ --include="*.php"

# Typing check
grep -rn "protected \$" app/system/modules/mail/src/ --include="*.php" | grep -v "protected [a-zA-Z]* \$"

# SwiftMailer remnants
grep -rn "swift\|Swift" app/system/modules/mail/ --include="*.php"

# Run tests
./vendor/bin/phpunit app/system/modules/mail/src/Tests/
```

=================================================================================
USAGE INSTRUCTIONS FOR REMOTE AGENT
=================================================================================

1. **Single module audit**: "Run AGENT_PROMPT_AUDIT_AND_VERIFICATION for app/system/modules/mail/"
2. **Full backend audit**: "Run AGENT_PROMPT_AUDIT_AND_VERIFICATION for app/system/modules/ app/modules/"
3. **Post-cleanup verification**: After applying fixes, re-run audit to confirm compliance

4. **Output**: Save report to `migration-docs/audits/2026/01/<module>/AUDIT_REPORT_<date>.md`

5. **Follow-up**: Use report's "Prioritized Action List" to create focused refactoring tasks

=================================================================================
VERSION HISTORY
=================================================================================

- 2026-01: Initial version. Replaces lost Mail migration prompt. Aligned with
  aggressive modernization rules (NO COMPATIBILITY LAYERS, DELETE OVER WRAP).
  Suitable for Cursor Background Agent / Remote Agent execution.
