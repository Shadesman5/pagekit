# Symfony Validator Integration (Step 1.13 - Hybrid Mode)

## Overview

This document describes the integration of Symfony Validator 7.4 with PHP 8 Attributes support into the Pagekit CMS. The implementation follows a **hybrid approach**:

- **Validation**: Uses PHP 8 Attributes (`#[Assert\...]`)
- **ORM**: Still uses Doctrine Annotations (`@Entity`, `@Column`) - Will be migrated in Step 1.14

## Changes Made

### 1. Dependencies Added

```json
{
  "require": {
    "symfony/validator": "^7.4"
  }
}
```

### 2. New Files Created

#### ValidatorServiceProvider
`app/system/src/ValidatorServiceProvider.php`

Registers the Symfony Validator as a service in the Pagekit application container:

```php
$app['validator'] = function ($app): ValidatorInterface {
    $builder = Validation::createValidatorBuilder();
    $builder->enableAttributeMapping();
    return $builder->getValidator();
};
```

#### ValidatesRequestTrait
`app/system/src/Controller/ValidatesRequestTrait.php`

Provides standardized validation methods for controllers:

- `validate($object)`: Returns `JsonResponse` on failure, `null` on success
- `validateOrFail($object)`: Throws `Exception` on failure
- `validationErrorResponse($violations)`: Creates structured JSON error response

#### Custom Constraints
`app/system/src/Validator/Constraints/`

- `Unique.php`: Attribute-based constraint for database uniqueness checks
- `UniqueValidator.php`: Validator implementation using Pagekit's QueryBuilder (DBAL 3.x)

### 3. Modified Files

#### User Entity
`app/system/modules/user/src/Model/User.php`

**BEFORE** (Manual validation):
```php
public function validate(): bool
{
    if (empty($this->name)) {
        throw new Exception(__('Name required.'));
    }
    // ... more manual checks ...
}
```

**AFTER** (Symfony Validator Attributes):
```php
#[Assert\NotBlank(message: 'Name is required.')]
#[Assert\Length(max: 255)]
public ?string $name = null;

#[Assert\NotBlank(message: 'Username is required.')]
#[Assert\Length(min: 3, max: 255)]
#[Assert\Regex(pattern: '/^[a-zA-Z0-9._\-]+$/')]
#[PagekitAssert\Unique(table: '@system_user', column: 'username')]
public ?string $username = '';

#[Assert\NotBlank(message: 'Email is required.')]
#[Assert\Email(message: 'Email is invalid.')]
#[PagekitAssert\Unique(table: '@system_user', column: 'email')]
public ?string $email = '';
```

**NOTE**: The old `validate()` method was **REMOVED** per Rule #4 (DELETE OVER WRAP).

#### Role Entity
`app/system/modules/user/src/Model/Role.php`

Added validation attributes:
- `name`: `#[Assert\NotBlank]`, `#[Assert\Length(min: 2, max: 255)]`
- `priority`: `#[Assert\PositiveOrZero]`

#### Controllers Updated

All controllers now use `ValidatesRequestTrait`:

1. **UserApiController** (`app/system/modules/user/src/Controller/UserApiController.php`)
   - Added `use ValidatesRequestTrait;`
   - Replaced `$user->validate()` with `$this->validateOrFail($user)`
   - Added `declare(strict_types=1)`

2. **RegistrationController** (`app/system/modules/user/src/Controller/RegistrationController.php`)
   - Added `use ValidatesRequestTrait;`
   - Replaced `$user->validate()` with `$this->validateOrFail($user)`
   - Added `declare(strict_types=1)`

3. **ProfileController** (`app/system/modules/user/src/Controller/ProfileController.php`)
   - Added `use ValidatesRequestTrait;`
   - Replaced `$user->validate()` with `$this->validateOrFail($user)`
   - Added `declare(strict_types=1)`

### 4. Service Registration

The validator service is registered in `app/system/index.php` during the `boot` event:

```php
'boot' => function ($event, $app) {
    \Pagekit\System\ValidatorServiceProvider::register($app);
    // ...
}
```

## Validation Constraints Used

### Symfony Built-in Constraints

| Constraint | Used On | Purpose |
|------------|---------|---------|
| `#[Assert\NotBlank]` | User.username, User.email, User.name, User.password, Role.name | Required field validation |
| `#[Assert\Email]` | User.email | Email format validation |
| `#[Assert\Length]` | User.username, User.name, Role.name | Min/max length validation |
| `#[Assert\Regex]` | User.username | Pattern validation |
| `#[Assert\Url]` | User.url | URL format validation |
| `#[Assert\Choice]` | User.status | Enum validation |
| `#[Assert\PositiveOrZero]` | Role.priority | Numeric validation |

### Custom Constraints

| Constraint | Used On | Purpose |
|------------|---------|---------|
| `#[PagekitAssert\Unique]` | User.username, User.email | Database uniqueness check |

## Validation Groups

The `registration` validation group is used for password validation during user registration:

```php
#[Assert\NotBlank(message: 'Password is required.', groups: ['registration'])]
public ?string $password = '';
```

## Error Response Format

Validation errors are returned as JSON with the following structure:

```json
{
  "error": true,
  "message": "First error message for display",
  "errors": {
    "username": ["Username is required.", "Username must be at least 3 characters."],
    "email": ["Email is invalid."]
  }
}
```

HTTP Status Code: **400 Bad Request**

## Usage Guide

### In Controllers

```php
use Pagekit\System\Controller\ValidatesRequestTrait;

class MyController
{
    use ValidatesRequestTrait;

    public function saveAction()
    {
        $entity = new MyEntity();
        // ... populate entity ...

        // Option 1: Return JSON response on failure
        if ($errorResponse = $this->validate($entity)) {
            return $errorResponse;
        }

        // Option 2: Throw exception on failure (for try/catch handling)
        $this->validateOrFail($entity);

        // Validation passed, continue with save
        $entity->save();
    }
}
```

### Adding Validation to New Entities

1. Import the Symfony Validator constraints:
   ```php
   use Symfony\Component\Validator\Constraints as Assert;
   use Pagekit\System\Validator\Constraints as PagekitAssert;
   ```

2. Add attributes to properties:
   ```php
   #[Assert\NotBlank(message: 'Field is required.')]
   #[Assert\Length(min: 3, max: 100)]
   public ?string $myField = '';
   ```

3. For database uniqueness:
   ```php
   #[PagekitAssert\Unique(
       table: '@my_table',
       column: 'my_column',
       message: 'Value already exists.'
   )]
   public ?string $uniqueField = '';
   ```

## Hybrid Mode Notes

### Why Hybrid?

The ORM still uses Doctrine Annotations (`doctrine/annotations` package) because:
- Step 1.14 (ORM Attributes Migration) is pending
- Entity mapping relies on `@Entity`, `@Column`, `@Id` annotations
- Both can coexist on the same class

### ORM Annotations (Temporary)

All ORM annotations are marked with TODO comments:

```php
/** @Column */
// TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
```

### When Step 1.14 is Complete

After ORM Attributes migration:
1. Replace `@Entity`, `@Column`, `@Id` annotations with `#[ORM\Entity]`, `#[ORM\Column]`, `#[ORM\Id]`
2. Remove `// TODO: Must be refactored in Step 1.14` comments
3. Update `doctrine/annotations` usage

## Migration from Manual Validation

### Old Pattern (REMOVED)

```php
// In Entity
public function validate(): bool
{
    if (empty($this->name)) {
        throw new Exception(__('Name required.'));
    }
    return true;
}

// In Controller
$user->validate();
$user->save();
```

### New Pattern (CURRENT)

```php
// In Entity - just attributes, no validate() method
#[Assert\NotBlank(message: 'Name is required.')]
public ?string $name = null;

// In Controller
use ValidatesRequestTrait;
// ...
$this->validateOrFail($user);
$user->save();
```

## Aggressive Modernization Rules Applied

| Rule | Application |
|------|-------------|
| **#1 NO COMPATIBILITY LAYERS** | No shim classes created; all controllers updated directly |
| **#2 NO ADAPTERS** | All `$user->validate()` calls replaced in the same commit |
| **#3 BREAKING CHANGES ALLOWED** | Internal API changed (validate() method removed) |
| **#4 DELETE OVER WRAP** | Old `validate()` method completely removed, not wrapped |
| **#5 MANDATORY FLAGGING** | All ORM annotations marked with `// TODO: Must be refactored in Step 1.14` |

## Files Changed

```
Modified:
- composer.json (added symfony/validator)
- app/system/index.php (registered validator service)
- app/system/modules/user/src/Model/User.php (added validation attributes, removed validate())
- app/system/modules/user/src/Model/Role.php (added validation attributes)
- app/system/modules/user/src/Controller/UserApiController.php (use ValidatesRequestTrait)
- app/system/modules/user/src/Controller/RegistrationController.php (use ValidatesRequestTrait)
- app/system/modules/user/src/Controller/ProfileController.php (use ValidatesRequestTrait)

Created:
- app/system/src/ValidatorServiceProvider.php
- app/system/src/Controller/ValidatesRequestTrait.php
- app/system/src/Validator/Constraints/Unique.php
- app/system/src/Validator/Constraints/UniqueValidator.php
- migration-docs/branches/VALIDATION_SYSTEM.md (this file)
```

## Testing

### Manual Tests

1. **Page Load Test**: Access `/admin` - should redirect to login
2. **Invalid Data Test**: Submit registration with empty fields - should return validation errors
3. **Valid Data Test**: Submit valid registration - should succeed
4. **ORM Test**: Load users via `User::find(1)` - should work correctly

### Automated Tests

TODO: Add unit tests for:
- `ValidatorServiceProvider` registration
- `ValidatesRequestTrait` methods
- `Unique` constraint validator
- User entity validation

## Rollback Plan

If issues occur:

1. Revert the branch
2. Remove `symfony/validator` from `composer.json`
3. Run `composer update`
4. Verify system works

## Next Steps

1. **Step 1.14**: Migrate ORM from Annotations to Attributes
   - Replace `@Entity` with `#[ORM\Entity]`
   - Replace `@Column` with `#[ORM\Column]`
   - Remove `doctrine/annotations` dependency
   - Remove TODO comments

2. Add validation to other entities as needed

3. Consider adding more custom constraints:
   - `#[UniqueEmail]` - Email uniqueness across multiple tables
   - `#[StrongPassword]` - Password strength validation
   - `#[ValidSlug]` - URL slug validation
