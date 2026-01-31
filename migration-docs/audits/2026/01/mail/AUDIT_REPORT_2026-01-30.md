# Audit Report: Mail Module
**Date**: 2026-01-30  
**Target**: `app/system/modules/mail/`  
**Standards**: Pagekit Modernization 2025/2026

## Executive Summary

- [x] **Needs cleanup** - Multiple issues require attention
- Key findings count: **23 issues**
- Recommended actions (prioritized):
  1. **[CRITICAL]** Fix API inconsistency in `RegistrationController.php` - broken method calls
  2. **[HIGH]** Fix attachment/embed implementation in `Message.php`
  3. **[HIGH]** Add `declare(strict_types=1)` to all PHP files
  4. **[HIGH]** Add type declarations to untyped properties
  5. **[MEDIUM]** Remove deprecated `__call` magic method from `Message.php`
  6. **[MEDIUM]** Fix test expectations and controller tests
  7. **[LOW]** Update documentation paths

---

## 1. Compatibility Layers & Legacy Code

| File | Line | Pattern | Recommendation |
|------|------|---------|-----------------|
| `src/Message.php` | 154-161 | `@deprecated` + `__call` magic method | **REMOVE** - Per "DELETE OVER WRAP" rule, this magic method provides hidden compatibility. Remove and update all call sites. |

**Analysis:**
- The `__call` method in `Message.php` allows calling non-existent methods, which masks API misuse errors
- This contradicts the "NO COMPATIBILITY LAYERS" rule
- Only 1 compatibility layer found - overall the migration is clean

**Note:** No `// TODO: BACKWARD COMPATIBILITY` markers were found - the code doesn't properly mark this legacy pattern.

---

## 2. PHP 8.2+ Compliance

### 2.1 Missing `declare(strict_types=1)`

| File | Issue | Fix |
|------|-------|-----|
| `index.php` | Missing strict types declaration | Add `declare(strict_types=1);` |
| `src/Mailer.php` | Missing strict types declaration | Add `declare(strict_types=1);` |
| `src/Message.php` | Missing strict types declaration | Add `declare(strict_types=1);` |
| `src/MailerInterface.php` | Missing strict types declaration | Add `declare(strict_types=1);` |
| `src/MessageInterface.php` | Missing strict types declaration | Add `declare(strict_types=1);` |
| `src/Plugin/ImpersonatePlugin.php` | Missing strict types declaration | Add `declare(strict_types=1);` |
| All test files | Missing strict types declaration | Add `declare(strict_types=1);` |

**Only `src/Controller/MailController.php` has `declare(strict_types=1)`.**

### 2.2 Untyped Properties

| File | Line | Issue | Fix |
|------|------|-------|-----|
| `src/Mailer.php` | 18 | `protected $transport;` | `protected TransportInterface $transport;` |
| `src/Mailer.php` | 23 | `protected $plugins = [];` | `protected array $plugins = [];` |
| `src/Plugin/ImpersonatePlugin.php` | 14 | `protected $address;` | `protected ?string $address;` |
| `src/Plugin/ImpersonatePlugin.php` | 19 | `protected $name;` | `protected ?string $name;` |
| `src/Tests/MailerTest.php` | 15 | `protected $transport = null;` | `protected ?TransportInterface $transport = null;` |

### 2.3 Missing Return Types

| File | Method | Issue | Fix |
|------|--------|-------|-----|
| `src/Mailer.php` | `create()` | No return type | Add `: Email` |
| `src/Mailer.php` | `registerPlugin()` | No return type | Add `: self` |
| `src/MailerInterface.php` | `beforeSend()` | No return type | Add `: void` |
| `src/MailerInterface.php` | `afterSend()` | No return type | Add `: void` |
| `src/Plugin/ImpersonatePlugin.php` | `beforeSend()` | No return type | Add `: void` |
| `src/Plugin/ImpersonatePlugin.php` | `afterSend()` | No return type | Add `: void` |

### 2.4 Constructor Property Promotion Missing

| File | Recommendation |
|------|----------------|
| `src/Mailer.php` | Convert to `public function __construct(protected TransportInterface $transport)` |
| `src/Plugin/ImpersonatePlugin.php` | Convert to `public function __construct(protected ?string $address = null, protected ?string $name = null)` |

---

## 3. Technology Violations

| File | Violation | Status |
|------|-----------|--------|
| All files | SwiftMailer references | ✅ **NONE FOUND** - Migration complete |
| All files | WordPress functions | ✅ **NONE FOUND** |
| All files | Laravel facades (dd, collect, Str::) | ✅ **NONE FOUND** |

**Positive findings:**
- Symfony Mailer is properly integrated
- PSR-compliant interfaces used
- No legacy mail library remnants

---

## 4. API Consistency Issues

### 4.1 CRITICAL: Broken Call Sites in RegistrationController

| Caller | Issue | Fix |
|--------|-------|-----|
| `app/system/modules/user/src/Controller/RegistrationController.php:155-158` | Uses `setTo()`, `setSubject()`, `setBody()` which don't exist on `Symfony\Component\Mime\Email` | Change to `to()`, `subject()`, `html()`/`text()` |
| `app/system/modules/user/src/Controller/RegistrationController.php:168-172` | Same issue | Same fix |
| `app/system/modules/user/src/Controller/RegistrationController.php:183-187` | Same issue | Same fix |
| `app/system/modules/user/src/Controller/RegistrationController.php:155,169,184` | Calls `->send()` on `Email` object which has no `send()` method | Use `App::mailer()->send($mail)` instead |

**Root Cause:**
- `Mailer::create()` returns `Symfony\Component\Mime\Email`
- But `RegistrationController` calls methods that only exist on the old API or `Message` class
- The `ResetPasswordController.php` uses the correct API (`to()`, `subject()`, `html()`, `App::mailer()->send($mail)`)

**Impact:** User registration emails are completely broken!

### 4.2 Message.php Implementation Errors

| Method | Issue | Fix |
|--------|-------|-----|
| `attachFile()` | Uses `File` object with `addPart()`, but `addPart()` requires `DataPart` | Use `DataPart::fromPath($file, $name, $mime)` |
| `embedFile()` | Calls `getPreparedHeaders()` on `File` which doesn't exist | Use `DataPart::fromPath()` and proper Content-ID header handling |
| `embedData()` | Calls `setHeaderBody()` with wrong argument count | Fix to `setHeaderBody('Id', 'Content-ID', '<'.$contentId.'>')` |

---

## 5. Documentation Discrepancies

| Doc | Claim | Reality |
|-----|-------|---------|
| `MAIL_MIGRATION.md:25` | File path: `app/modules/mail/src/Mailer.php` | Actual: `app/system/modules/mail/src/Mailer.php` |
| `MAIL_MIGRATION.md:34` | File path: `app/modules/mail/src/Message.php` | Actual: `app/system/modules/mail/src/Message.php` |
| `MAIL_MIGRATION.md:32` | "Added backward compatibility layer" | Only `__call` magic method exists, marked as `@deprecated` but not properly documented as compatibility layer |
| `MAIL_MIGRATION.md:97` | Test command: `app/modules/mail/src/Tests/` | Actual: `app/system/modules/mail/src/Tests/` |
| `MAIL_MIGRATION.md:105-106` | "OK (42 tests, 156 assertions)" | Current: 52 tests, 15 errors, only 74 assertions passing |
| `MAIL_MIGRATION.md:110-112` | "10 tests failing" | Current: 15 errors + 1 warning |
| `MAIL_MIGRATION.md:120` | "None for end users. The API remains compatible." | **FALSE** - `RegistrationController` is completely broken |

### Additional Documentation Review

**MAIL_MIGRATION_ANALYSIS.md:**
- Documents performance improvements (60% faster, 75% smaller headers)
- Confirms successful migration benefits
- No discrepancies found

**MAIL_SENDMAIL_FIX.md:**
- Documents Windows/Mailpit sendmail path fix (already implemented in `index.php:63-72`)
- Documents SMTP validation fix (already implemented in `MailController.php:30-32`)
- Both fixes are correctly implemented in code

---

## 6. Test Status

### Current Results
```
Tests: 52 | Pass: 31 | Errors: 15 | Warnings: 1 | Skipped: 5 | PHPUnit Deprecations: 7
```

### Failing Tests by Category

**Controller Tests (requires Application context):**
- `MailControllerTest::testSmtpActionWithInvalidCredentials`
- `MailControllerTest::testSmtpActionReturnsCorrectStructure`
- `MailControllerTest::testSmtpActionWithMissingParameters`
- `MailControllerTest::testSmtpActionWithPartialParameters`
- `SendmailTransportTest::testSmtpActionWithEmptyOptions`
- `SendmailTransportTest::testSmtpActionWithOnlyHost`

**Message Attachment/Embed Tests:**
- `MailIntegrationTest::testEmailWithAttachment` - TypeError: File vs DataPart
- `MailIntegrationTest::testEmailWithEmbeddedContent` - Undefined method
- `MessageTest::testAttachFile` - TypeError: File vs DataPart
- `MessageTest::testEmbedFile` - Undefined method getPreparedHeaders
- `MessageTest::testEmbedFileWithCustomCid` - Same
- `MessageTest::testEmbedData` - ArgumentCountError
- `MessageTest::testGetPartsIncludesEmbeded` - ArgumentCountError

**SMTP Connection Tests:**
- `MailerTest::testTestSmtpConnectionWithNullTransport` - Exception thrown when none expected
- `MailerTest::testTestSmtpConnectionWithInvalidParameters` - Returns exception instead of error string

### Gaps
- No integration tests with actual Application context
- No tests for `RegistrationController` mail sending
- No tests verifying `ResetPasswordController` mail API usage

### Regression Risks
- **HIGH**: Fixing `Message.php` attachment methods may affect any code using these features
- **HIGH**: Removing `__call` magic method will expose hidden API misuse
- **MEDIUM**: Adding strict types may reveal type mismatches in callers

---

## 7. Prioritized Action List

### Critical (Must fix immediately)
1. **[CRITICAL]** Fix `RegistrationController.php` broken mail calls:
   - Replace `setTo()` → `to()`
   - Replace `setSubject()` → `subject()`
   - Replace `setBody(..., 'text/html')` → `html(...)`
   - Replace `$mail->send()` → `App::mailer()->send($mail)`

2. **[CRITICAL]** Fix `Message.php` attachment/embed implementation:
   - `attachFile()`: Use `DataPart::fromPath()` instead of `new File()`
   - `embedFile()`: Same fix + proper Content-ID handling
   - `embedData()`: Fix `setHeaderBody()` argument count

### High Priority
3. **[HIGH]** Add `declare(strict_types=1);` to all PHP files (12 files)

4. **[HIGH]** Add type declarations:
   - `Mailer.php`: Type properties `$transport`, `$plugins`
   - `ImpersonatePlugin.php`: Type properties `$address`, `$name`
   - `MailerInterface.php`: Add return types
   - `ImpersonatePlugin.php`: Add return types

5. **[HIGH]** Update constructor to use property promotion in `Mailer.php` and `ImpersonatePlugin.php`

### Medium Priority
6. **[MEDIUM]** Remove `__call` magic method from `Message.php` - per "DELETE OVER WRAP" rule

7. **[MEDIUM]** Fix controller tests to work without full Application context, or mark as integration tests

8. **[MEDIUM]** Update `MAIL_MIGRATION.md`:
   - Fix all path references from `app/modules/mail/` to `app/system/modules/mail/`
   - Update test counts and status
   - Document actual breaking changes

### Low Priority
9. **[LOW]** Add missing return type to `Mailer::create()` method

10. **[LOW]** Mark `__call` with proper `// TODO: BACKWARD COMPATIBILITY - Must be refactored later` before removal

---

## 8. Summary of Compliance

| Area | Status | Score |
|------|--------|-------|
| No SwiftMailer remnants | ✅ Compliant | 100% |
| No WordPress/Laravel code | ✅ Compliant | 100% |
| No compatibility layers | ⚠️ Partial (1 `__call` method) | 90% |
| PHP 8.2+ strict types | ❌ Non-compliant (11/12 files missing) | 8% |
| PHP 8.2+ typed properties | ⚠️ Partial (5 untyped) | 70% |
| PHP 8.2+ return types | ⚠️ Partial (6 missing) | 75% |
| API consistency | ❌ Critical issues | 40% |
| Documentation accuracy | ❌ Multiple errors | 30% |
| Test coverage | ⚠️ 15 failing | 60% |

**Overall Compliance: ~55%** - Significant cleanup needed.

---

## Appendix: Quick Fix Commands

```bash
# Check for remaining issues
grep -rn "setTo\|setSubject\|setBody" app/system/modules/user/src/ --include="*.php"

# Run mail tests after fixes
./app/vendor/bin/phpunit app/system/modules/mail/src/Tests/ --testdox

# Check strict_types compliance
grep -rL "declare(strict_types=1)" app/system/modules/mail/src/*.php app/system/modules/mail/src/**/*.php
```
