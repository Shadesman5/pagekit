# Validation System Testing Guide

## Overview

This guide covers all manual and automated tests for the Symfony Validator integration (Step 1.13).

## ✅ Manual Testing Checklist

### 1. User Module Validation Tests

#### 1.1 User Registration (`/admin/user` → Add User)

**Required Fields:**
- [ ] **Username** (min 3 chars, max 255, alphanumeric + dots/underscores/hyphens)
  - ❌ Test: Empty username → Should show: "Username is required"
  - ❌ Test: 2 characters → Should show: "Username must be at least 3 characters"
  - ❌ Test: 256+ characters → Should show: "Username cannot exceed 255 characters"
  - ❌ Test: Special chars (e.g., `user@name`, `user name`, `user#name`) → Should show: "Username is invalid. Only letters, numbers, dots, underscores and hyphens are allowed"
  - ❌ Test: Duplicate username → Should show: "Username is not available"

- [ ] **Email** (required, valid format, unique)
  - ❌ Test: Empty email → Should show: "Email is required"
  - ❌ Test: Invalid format (e.g., `notanemail`, `user@`, `@domain.com`) → Should show: "Email is invalid"
  - ❌ Test: Duplicate email → Should show: "Email is not available"

- [ ] **Name** (required, max 255 chars)
  - ❌ Test: Empty name → Should show: "Name is required"
  - ❌ Test: 256+ characters → Should show max length error

- [ ] **Password** (required on registration only)
  - ❌ Test: Empty password on new user → Should show: "Password is required"
  - ✅ Test: Empty password on existing user → Should NOT validate (password unchanged)

#### 1.2 User Profile Update (`/admin/user/edit/{id}`)

**Update Validation:**
- [ ] **Username uniqueness** - Try to change to existing username → Should show: "Username is not available"
- [ ] **Email uniqueness** - Try to change to existing email → Should show: "Email is not available"
- [ ] **Password optional** - Empty password should NOT trigger validation (only on registration)

#### 1.3 User Registration (Public `/registration`)

- [ ] Same tests as 1.1 (Required fields, formats, uniqueness)
- [ ] Password required on registration

---

### 2. Role Module Validation Tests

#### 2.1 Role Creation/Update (`/admin/user/role`)

- [ ] **Name** (required, max length)
  - ❌ Test: Empty name → Should show: "Role name is required"
  - ❌ Test: 256+ characters → Should show max length error

- [ ] **Priority** (non-negative number)
  - ❌ Test: Negative number (e.g., -1) → Should show: "Priority must be a non-negative number"
  - ✅ Test: Zero → Should work
  - ✅ Test: Positive number → Should work

---

### 3. Site Module Validation Tests

#### 3.1 Node Creation/Update (`/admin/site/page` → Add/Edit Page)

- [ ] **Title** (required, max 255 chars)
  - ❌ Test: Empty title → Should show: "Title is required"
  - ❌ Test: 256+ characters → Should show max length error

- [ ] **Slug** (required, regex pattern `/^[a-z0-9\-_]+$/`, max 255)
  - ❌ Test: Empty slug (generated from title if empty) → If title also empty, should fail
  - ❌ Test: Invalid chars (e.g., `My Page`, `page@123`, `page#test`) → Should show: "Invalid slug. Only lowercase letters, numbers, hyphens and underscores are allowed"
  - ❌ Test: Uppercase letters (e.g., `MyPage`) → Should show invalid slug error
  - ❌ Test: 256+ characters → Should show max length error
  - ✅ Test: Valid slug (e.g., `my-page`, `page_123`, `test-page-1`) → Should work

- [ ] **Link** (max 500 chars, optional)
  - ❌ Test: 501+ characters → Should show max length error
  - ✅ Test: Valid URL/route → Should work

- [ ] **Type** (required)
  - ❌ Test: Empty type → Should show: "Type is required"

- [ ] **Status** (Choice: 0 or 1)
  - ❌ Test: Invalid status (e.g., 2, -1) → Should show: "Status is invalid"
  - ✅ Test: Status 0 (inactive) → Should work
  - ✅ Test: Status 1 (active) → Should work

#### 3.2 Page Entity (part of Node)

- [ ] **Page Title** (required, max 255)
  - ❌ Test: Empty title → Should show: "Page title is required"
  - ❌ Test: 256+ characters → Should show max length error

---

### 4. Widget Module Validation Tests

#### 4.1 Widget Creation/Update (`/admin/site/widget` → Add/Edit Widget)

- [ ] **Title** (required, max 255 chars)
  - ❌ Test: Empty title → Should show: "Widget title is required"
  - ❌ Test: 256+ characters → Should show max length error

- [ ] **Type** (required)
  - ❌ Test: Empty type → Should show: "Widget type is required"

- [ ] **Status** (Choice: 0 or 1)
  - ❌ Test: Invalid status (e.g., 2, -1) → Should show: "Status is invalid"
  - ✅ Test: Status 0 (inactive) → Should work
  - ✅ Test: Status 1 (active) → Should work

---

### 5. Error Response Format Tests

#### 5.1 JSON Error Structure

For ALL validation failures, verify:
- [ ] HTTP Status Code: **400 Bad Request**
- [ ] Response format:
  ```json
  {
    "error": true,
    "message": "First error message for display",
    "errors": {
      "field_name": ["Error message 1", "Error message 2"],
      "another_field": ["Error message"]
    }
  }
  ```
- [ ] Errors are grouped by field name
- [ ] Multiple errors per field are shown as array

#### 5.2 Frontend Integration

**⚠️ Current State (Dual Validation System):**

- [ ] **Client-Side Validation (Vue.js / vee-validate)**
  - ✅ Errors appear below form fields BEFORE submit
  - ✅ Error messages are translated
  - ✅ Form submission is blocked when validation fails
  - ✅ Error styling (red borders, error text) is applied
  - ✅ **Example**: `v-input` with `:rules="{required: true, regex: /^[a-zA-Z0-9._\-]+$/}"`

- [ ] **Backend Validation (Symfony Validator)**
  - ⚠️ **Currently**: Backend errors shown as generic notification (not field-specific)
  - ⚠️ **TODO**: Backend validation errors should be displayed as structured field errors
  - ✅ Validation happens AFTER submit (server-side)
  - ✅ Catches edge cases that client-side validation might miss
  - ⚠️ **Note**: `validateOrFail()` throws exception with first error message only

**Current Frontend Error Handling:**
```javascript
// Example from user-edit.js
}, function (res) {
    this.$notify(res.data, 'danger');  // ← Generic notification only
})
```

**Future Improvement Needed:**
- Display backend validation errors per field (like client-side validation)
- Use `response.data.errors` object to show errors below respective form fields
- This will be addressed in Vue 3 migration (Phase 3 — Step 3.4+)

---

## 🧪 Unit Testing Recommendations

### Why Unit Tests?

**YES, Unit Tests are highly recommended** for validation because:

1. **Automated Verification** - Catch regressions automatically
2. **Fast Feedback** - Run tests in seconds vs. manual testing (minutes)
3. **Edge Case Coverage** - Test boundary conditions easily (min/max lengths, special chars)
4. **Documentation** - Tests serve as living documentation of validation rules
5. **CI/CD Integration** - Prevent broken code from reaching production

### Recommended Test Structure

#### Test File Locations

```
tests/unit/Validator/
├── UniqueValidatorTest.php        # Test custom Unique constraint
├── UserValidationTest.php         # Test User entity validation
├── RoleValidationTest.php         # Test Role entity validation
├── NodeValidationTest.php         # Test Node entity validation
├── PageValidationTest.php         # Test Page entity validation
└── WidgetValidationTest.php       # Test Widget entity validation
```

#### Example Test Structure

```php
<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Validator;

use Pagekit\User\Model\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class UserValidationTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        // Get validator from app container
        $app = \Pagekit\Application::getInstance();
        $this->validator = $app['validator'];
    }

    public function testUsernameRequired(): void
    {
        $user = new User();
        $user->email = 'test@example.com';
        $user->name = 'Test User';

        $violations = $this->validator->validate($user);

        $this->assertGreaterThan(0, count($violations));
        $this->assertStringContainsString('username', (string) $violations[0]->getPropertyPath());
        $this->assertStringContainsString('required', $violations[0]->getMessage());
    }

    public function testUsernameMinLength(): void
    {
        $user = new User();
        $user->username = 'ab'; // 2 chars - too short
        $user->email = 'test@example.com';
        $user->name = 'Test User';

        $violations = $this->validator->validate($user);

        $this->assertGreaterThan(0, count($violations));
        // Check for min length violation
    }

    public function testUsernameRegex(): void
    {
        $user = new User();
        $user->username = 'user@name'; // Invalid chars
        $user->email = 'test@example.com';
        $user->name = 'Test User';

        $violations = $this->validator->validate($user);

        $this->assertGreaterThan(0, count($violations));
        // Check for regex violation
    }

    public function testEmailFormat(): void
    {
        $user = new User();
        $user->username = 'testuser';
        $user->email = 'notanemail'; // Invalid format
        $user->name = 'Test User';

        $violations = $this->validator->validate($user);

        $this->assertGreaterThan(0, count($violations));
        // Check for email format violation
    }

    // ... more tests for each constraint
}
```

### Test Coverage Checklist

#### User Entity Tests
- [ ] Username required
- [ ] Username min length (3 chars)
- [ ] Username max length (255 chars)
- [ ] Username regex pattern
- [ ] Username uniqueness (new user)
- [ ] Username uniqueness (update - exclude self)
- [ ] Email required
- [ ] Email format validation
- [ ] Email uniqueness (new user)
- [ ] Email uniqueness (update - exclude self)
- [ ] Name required
- [ ] Name max length (255 chars)
- [ ] Password required (registration group only)
- [ ] Password optional (update, no registration group)
- [ ] Status choice validation (0 or 1)

#### Role Entity Tests
- [ ] Name required
- [ ] Name max length
- [ ] Priority non-negative (PositiveOrZero)

#### Node Entity Tests
- [ ] Slug required
- [ ] Slug regex pattern
- [ ] Slug max length
- [ ] Title required
- [ ] Title max length
- [ ] Link max length (optional)
- [ ] Type required
- [ ] Status choice (0 or 1)
- [ ] Priority non-negative
- [ ] Parent ID non-negative

#### Page Entity Tests
- [ ] Title required
- [ ] Title max length

#### Widget Entity Tests
- [ ] Title required
- [ ] Title max length
- [ ] Type required
- [ ] Status choice (0 or 1)

#### UniqueValidator Tests
- [ ] Unique constraint works for new records
- [ ] Unique constraint excludes current record on update
- [ ] Unique constraint uses correct table and column
- [ ] Unique constraint handles null/empty values correctly

---

## 🚀 Quick Test Commands

### Manual Testing via Browser

1. **User Registration:**
   ```
   http://localhost:8000/admin/user
   → Click "Add User"
   → Try saving with empty/invalid data
   ```

2. **Node Creation:**
   ```
   http://localhost:8000/admin/site/page
   → Click "Add Page"
   → Try saving with empty/invalid data
   ```

3. **Widget Creation:**
   ```
   http://localhost:8000/admin/site/widget
   → Click "Add Widget"
   → Try saving with empty/invalid data
   ```

### API Testing via curl (if API endpoints are accessible)

```bash
# Test User validation
curl -X POST http://localhost:8000/api/user \
  -H "Content-Type: application/json" \
  -d '{"user": {"username": "", "email": "invalid"}}'
# Should return 400 with validation errors

# Test Node validation
curl -X POST http://localhost:8000/api/site/node \
  -H "Content-Type: application/json" \
  -d '{"node": {"title": "", "slug": "INVALID SLUG"}}'
# Should return 400 with validation errors
```

---

## 📊 Test Priority

### High Priority (Must Test)
1. ✅ **User Registration** - Critical security feature
2. ✅ **Username/Email Uniqueness** - Prevents duplicate accounts
3. ✅ **Node Slug Validation** - Prevents invalid URLs
4. ✅ **Required Fields** - Basic functionality

### Medium Priority (Should Test)
1. ⚠️ **Length Constraints** - Data integrity
2. ⚠️ **Regex Patterns** - Format validation
3. ⚠️ **Choice Validation** (Status, etc.) - Enum validation

### Low Priority (Nice to Have)
1. ℹ️ **Update Context** - Unique constraint excludes self
2. ℹ️ **Validation Groups** - Password on registration only

---

## 🎯 Success Criteria

**All tests pass if:**
- ✅ Invalid data returns 400 Bad Request
- ✅ Error messages are clear and helpful
- ✅ Error format matches expected JSON structure
- ✅ Frontend displays errors correctly
- ✅ Valid data saves successfully
- ✅ No PHP errors in logs
- ✅ No JavaScript errors in browser console

---

## 📝 Notes

- **Translation**: Currently using message keys (not translated yet) - translation integration will come later
- **Frontend Validation**: 
  - ✅ **Client-Side Validation (Vue.js / vee-validate)**: Still active and working - validates BEFORE submit, shows field-specific errors
  - ⚠️ **Backend Validation (Symfony Validator)**: Validates AFTER submit, currently shows only generic notifications (not field-specific errors)
  - 📌 **Dual System**: Both validations work in parallel:
    1. Client-side catches most errors before submit (better UX)
    2. Backend validation catches edge cases and ensures data integrity (security)
  - 🔮 **Future**: Backend validation errors will be displayed as structured field errors in Vue 3 migration (Phase 3 — Step 3.4+)
- **Business Logic**: Some validation (like "Invalid type" for protected nodes) is business logic, not entity validation - these remain in controllers
