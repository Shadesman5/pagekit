# Symfony Validator Integration (Step 1.13 - Hybrid Mode)

## Overview

This document describes the integration of Symfony Validator 7.4 with PHP 8 Attributes support into the Pagekit CMS. The implementation follows a **hybrid approach**:

- **Validation**: Uses PHP 8 Attributes (`#[Assert\...]`)
- **ORM**: Still uses Doctrine Annotations (`@Entity`, `@Column`) - Will be migrated in Step 1.14

## Migration Phases

### Phase 1 (Completed)
- Symfony Validator 7.4 installed and configured
- `ValidatorServiceProvider` created and registered
- `ValidatesRequestTrait` created for controller validation
- Custom `Unique` constraint implemented
- User module fully migrated (User, Role entities and controllers)

### Phase 2 (Completed)
- Validation messages file created (`app/system/languages/en_US/validation.php`)
- All hardcoded messages replaced with message keys
- Site module migrated (Node, Page entities and controllers)
- Widget module migrated (Widget entity and controller)
- User module messages updated to use message keys

### Phase 3 (Completed)
- Comment base module migrated (abstract Comment class)
- Blog package migrated (Post, Comment entities and controllers)
- All extension packages now use Symfony Validator

---

## 1. Dependencies

```json
{
  "require": {
    "symfony/validator": "^7.4"
  }
}
```

## 2. Infrastructure Files

### ValidatorServiceProvider
`app/system/src/ValidatorServiceProvider.php`

Registers the Symfony Validator as a service in the Pagekit application container:

```php
$app['validator'] = function ($app): ValidatorInterface {
    $builder = Validation::createValidatorBuilder();
    $builder->enableAttributeMapping();
    return $builder->getValidator();
};
```

### ValidatesRequestTrait
`app/system/src/Controller/ValidatesRequestTrait.php`

Provides standardized validation methods for controllers:

- `validate($object)`: Returns `JsonResponse` on failure, `null` on success
- `validateOrFail($object)`: Throws `Exception` on failure
- `validationErrorResponse($violations)`: Creates structured JSON error response

### Custom Constraints
`app/system/src/Validator/Constraints/`

- `Unique.php`: Attribute-based constraint for database uniqueness checks
- `UniqueValidator.php`: Validator implementation using Pagekit's QueryBuilder (DBAL 3.x)

### Validation Messages File
`app/system/languages/en_US/validation.php`

Centralized validation messages for all entities. Messages are referenced by key in entity attributes:

```php
// In validation.php
'validation.user.username_required' => 'Username is required.',

// In entity
#[Assert\NotBlank(message: 'validation.user.username_required')]
public ?string $username = '';
```

---

## 3. Migrated Modules

### User Module (Phase 1 + 2)

**Entities:**
- `User.php` - Full validation with Unique constraints
- `Role.php` - Name and priority validation

**Controllers:**
- `UserApiController.php` - Uses ValidatesRequestTrait
- `RegistrationController.php` - Uses ValidatesRequestTrait
- `ProfileController.php` - Uses ValidatesRequestTrait
- `RoleApiController.php` - Uses ValidatesRequestTrait (Phase 2)

### Site Module (Phase 2)

**Entities:**
- `Node.php` - Slug, title, link, type, status validation
- `Page.php` - Title validation

**Controllers:**
- `NodeApiController.php` - Uses ValidatesRequestTrait

### Widget Module (Phase 2)

**Entities:**
- `Widget.php` - Title, type, status validation

**Controllers:**
- `WidgetApiController.php` - Uses ValidatesRequestTrait

### Comment Module - Base (Phase 3)

**Entities:**
- `Comment.php` (abstract) - Author, content, status validation

### Blog Package (Phase 3)

**Entities:**
- `Post.php` - Title, slug, status, user_id validation
- `Comment.php` - Email, url, post_id validation (extends base Comment)

**Controllers:**
- `PostApiController.php` - Uses ValidatesRequestTrait
- `CommentApiController.php` - Uses ValidatesRequestTrait

---

## 4. Validation Constraints Reference

### Symfony Built-in Constraints

| Constraint | Used On | Purpose |
|------------|---------|---------|
| `#[Assert\NotBlank]` | User.username, User.email, User.name, User.password, Role.name, Node.slug, Node.title, Node.type, Page.title, Widget.title, Widget.type, Comment.author, Comment.content, Post.title, Post.slug, Post.user_id, BlogComment.post_id | Required field validation |
| `#[Assert\Email]` | User.email, BlogComment.email | Email format validation |
| `#[Assert\Length]` | User.username, User.name, Role.name, Node.slug, Node.title, Node.link, Page.title, Widget.title, Comment.author, Post.title, Post.slug | Min/max length validation |
| `#[Assert\Regex]` | User.username, Node.slug, Post.slug | Pattern validation |
| `#[Assert\Url]` | User.url, BlogComment.url | URL format validation |
| `#[Assert\Choice]` | User.status, Node.status, Widget.status, Comment.status, Post.status | Enum validation |
| `#[Assert\PositiveOrZero]` | Role.priority, Node.priority, Node.parent_id, Post.comment_count | Numeric validation |
| `#[Assert\Positive]` | Post.user_id, BlogComment.post_id | Positive integer validation |

### Custom Constraints

| Constraint | Used On | Purpose |
|------------|---------|---------|
| `#[PagekitAssert\Unique]` | User.username, User.email | Database uniqueness check |

---

## 5. Message Keys Reference

All validation messages are stored in `app/system/languages/en_US/validation.php`.

### User Module Messages

| Key | Message |
|-----|---------|
| `validation.user.username_required` | Username is required. |
| `validation.user.username_min_length` | Username must be at least {{ limit }} characters. |
| `validation.user.username_max_length` | Username cannot exceed {{ limit }} characters. |
| `validation.user.username_invalid` | Username is invalid. Only letters, numbers, dots, underscores and hyphens are allowed. |
| `validation.user.username_not_available` | Username is not available. |
| `validation.user.email_required` | Email is required. |
| `validation.user.email_invalid` | Email is invalid. |
| `validation.user.email_not_available` | Email is not available. |
| `validation.user.name_required` | Name is required. |
| `validation.user.password_required` | Password is required. |
| `validation.role.name_required` | Role name is required. |
| `validation.role.priority_invalid` | Priority must be a non-negative number. |

### Site Module Messages

| Key | Message |
|-----|---------|
| `validation.node.slug_required` | Slug is required. |
| `validation.node.slug_invalid` | Invalid slug. Only lowercase letters, numbers, hyphens and underscores are allowed. |
| `validation.node.title_required` | Title is required. |
| `validation.node.type_required` | Node type is required. |
| `validation.page.title_required` | Page title is required. |

### Widget Module Messages

| Key | Message |
|-----|---------|
| `validation.widget.title_required` | Widget title is required. |
| `validation.widget.type_required` | Widget type is required. |

---

## 6. Usage Guide

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

1. Import the constraints:
   ```php
   use Symfony\Component\Validator\Constraints as Assert;
   use Pagekit\System\Validator\Constraints as PagekitAssert;
   ```

2. Add message keys to `validation.php`:
   ```php
   'validation.myentity.field_required' => 'Field is required.',
   ```

3. Add attributes to properties:
   ```php
   #[Assert\NotBlank(message: 'validation.myentity.field_required')]
   #[Assert\Length(min: 3, max: 100)]
   public ?string $myField = '';
   ```

4. For database uniqueness:
   ```php
   #[PagekitAssert\Unique(
       table: '@my_table',
       column: 'my_column',
       message: 'validation.myentity.field_not_available'
   )]
   public ?string $uniqueField = '';
   ```

---

## 7. Error Response Format

Validation errors are returned as JSON with the following structure:

```json
{
  "error": true,
  "message": "First error message for display",
  "errors": {
    "username": ["validation.user.username_required", "validation.user.username_min_length"],
    "email": ["validation.user.email_invalid"]
  }
}
```

HTTP Status Code: **400 Bad Request**

---

## 8. Hybrid Mode Notes

### Why Hybrid?

The ORM still uses Doctrine Annotations (`doctrine/annotations` package) because:
- Step 1.14 (ORM Attributes Migration) is pending
- Entity mapping relies on `@Entity`, `@Column`, `@Id` annotations
- Both can coexist on the same class

### ORM Annotations (Temporary)

All ORM annotations are marked with TODO comments:

```php
/**
 * @Column
 */
// TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
#[Assert\NotBlank(message: 'validation.entity.field_required')]
public ?string $field = null;
```

---

## 9. Files Changed

### Phase 1

```
Created:
- app/system/src/ValidatorServiceProvider.php
- app/system/src/Controller/ValidatesRequestTrait.php
- app/system/src/Validator/Constraints/Unique.php
- app/system/src/Validator/Constraints/UniqueValidator.php

Modified:
- composer.json (added symfony/validator)
- app/system/index.php (registered validator service)
- app/system/modules/user/src/Model/User.php
- app/system/modules/user/src/Model/Role.php
- app/system/modules/user/src/Controller/UserApiController.php
- app/system/modules/user/src/Controller/RegistrationController.php
- app/system/modules/user/src/Controller/ProfileController.php
```

### Phase 2

```
Created:
- app/system/languages/en_US/validation.php
- migration-docs/branches/VALIDATION_PHASE2_DISCOVERY.md

Modified:
- app/system/modules/user/src/Model/User.php (updated to use message keys)
- app/system/modules/user/src/Model/Role.php (updated to use message keys)
- app/system/modules/user/src/Controller/RoleApiController.php (added ValidatesRequestTrait)
- app/system/modules/site/src/Model/Node.php (added validation attributes)
- app/system/modules/site/src/Model/Page.php (added validation attributes)
- app/system/modules/site/src/Controller/NodeApiController.php (added ValidatesRequestTrait)
- app/system/modules/widget/src/Model/Widget.php (added validation attributes)
- app/system/modules/widget/src/Controller/WidgetApiController.php (added ValidatesRequestTrait)
```

---

## 10. Aggressive Modernization Rules Applied

| Rule | Application |
|------|-------------|
| **#1 NO COMPATIBILITY LAYERS** | No shim classes created; all controllers updated directly |
| **#2 NO ADAPTERS** | All manual validation calls replaced in the same commit |
| **#3 BREAKING CHANGES ALLOWED** | Internal API changed (validate() method removed) |
| **#4 DELETE OVER WRAP** | Old `validate()` method and manual validation completely removed |
| **#5 MANDATORY FLAGGING** | All ORM annotations marked with `// TODO: Must be refactored in Step 1.14` |

---

## 11. Next Steps

1. **Step 1.14**: Migrate ORM from Annotations to Attributes
   - Replace `@Entity` with `#[ORM\Entity]`
   - Replace `@Column` with `#[ORM\Column]`
   - Remove `doctrine/annotations` dependency
   - Remove TODO comments

2. **Translation Integration**: Integrate message keys with `__()` function or Symfony Translator

3. **Additional Entities**: Add validation to any remaining entities as needed

4. **Custom Constraints**: Consider adding more custom constraints:
   - `#[StrongPassword]` - Password strength validation
   - `#[ValidSlug]` - URL slug validation
