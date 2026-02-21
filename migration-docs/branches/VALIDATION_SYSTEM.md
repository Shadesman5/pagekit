# Symfony Validator Integration (Step 1.13)

## Overview

This document describes the integration of Symfony Validator 7.4 with PHP 8 Attributes support into Pagekit CMS. Both Validation and ORM now use PHP 8 Attributes (Step 1.14 completed).

## Migration Phases

### Phase 1 (Completed)
- Symfony Validator 7.4 installed and configured
- `ValidatorServiceProvider` created and registered
- `ValidatesRequestTrait` created for controller validation
- Custom `Unique` constraint implemented
- User module fully migrated (User, Role entities and controllers)

### Phase 2 (Completed)
- Validation messages file created (`app/system/languages/en_US/validators.php`)
- All hardcoded messages replaced with message keys
- Site module migrated (Node, Page entities and controllers)
- Widget module migrated (Widget entity and controller)
- User module messages updated to use message keys

### Phase 3 (Completed)
- Comment base module migrated (abstract Comment class)
- Blog package migrated (Post, Comment entities and controllers)
- All extension packages now use Symfony Validator

---

## ⚠️ Known Gap: Translation Integration Missing

**Status**: NOT YET IMPLEMENTED — Tracked as Step 2.0.2 (Validator-Translator Integration)

The Symfony Validator currently returns **raw message keys** (e.g. `validation.user.username_required`) without translation. The `ValidatorServiceProvider` does not connect to Pagekit's Translator.

### What's broken

1. `ValidatorServiceProvider` creates a standalone validator without `setTranslator()` / `setTranslationDomain()`
2. `ValidatesRequestTrait` returns raw keys in JSON responses — users see keys, not human-readable messages
3. The `validators.php` file is loaded by `IntlModule::loadLocale()` as domain `validators`, but nothing queries it

### Required fix (Phase 2 implementation)

```php
// ValidatorServiceProvider — connect to Pagekit's Translator
$app['validator'] = function ($app): ValidatorInterface {
    $builder = Validation::createValidatorBuilder();
    $builder->enableAttributeMapping();
    $builder->setTranslator($app['translator']);
    $builder->setTranslationDomain('validators');
    return $builder->getValidator();
};
```

With this fix, `$violation->getMessage()` returns the **translated string** automatically. No changes needed in `ValidatesRequestTrait`.

### File rename required

The translation file must follow Symfony conventions:
- **Current (wrong):** `validation.php` → domain `validation`
- **Target (correct):** `validators.php` → domain `validators`

Files to rename:
- `app/system/languages/en_US/validation.php` → `validators.php`
- `packages/pagekit/blog/languages/en_US/validation.php` → `validators.php`

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
    // TODO: Connect to Translator — see "Known Gap" section above
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
`app/system/languages/en_US/validators.php`

> **Note:** File is currently named `validation.php` — rename to `validators.php` required (Symfony standard domain name).

Centralized validation messages for all entities. Messages use key-pattern approach:

```php
// In validators.php (Symfony domain: "validators")
'validation.user.username_required' => 'Username is required.',

// In entity
#[Assert\NotBlank(message: 'validation.user.username_required')]
public ?string $username = '';
```

### Key-Pattern Convention

Pattern: `validation.{module}.{field}_{constraint}`

This differs from Pagekit's traditional `messages` domain which uses English strings as keys (`'Save' => 'Speichern'`). The key-pattern approach is the modern standard because:
- Keys are stable identifiers (changing the English text doesn't break translations)
- Self-documenting: the key tells you module, field, and constraint type
- Symfony recommends key-patterns for the `validators` domain

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

All validation messages are stored in `app/system/languages/en_US/validators.php`.

> **Note:** File is currently named `validation.php` — rename pending.

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

2. Add message keys to `validators.php`:
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

Validation errors are returned as JSON:

```json
{
  "error": true,
  "message": "First translated error message for display",
  "errors": {
    "username": ["Username is required.", "Username must be at least 3 characters."],
    "email": ["Email is invalid."]
  }
}
```

HTTP Status Code: **400 Bad Request**

> **Note:** Currently returns raw message keys instead of translated strings until the Translator integration is implemented. See "Known Gap" section.

---

## 8. Translation Architecture

### How it will work (after integration)

```
Entity Attribute                    Symfony Validator              Translator (IntlModule)
#[Assert\NotBlank(                  ──► resolves key via ──►      validators.php domain
  message: 'validation.x.y')]           setTranslator()            'validation.x.y' => 'Human text'
                                                                    ▼
                                    ◄── returns translated  ◄──   Locale-specific file
                                        string                     de_DE/validators.php
```

### Domain separation

| Domain | File | Key Style | Purpose |
|--------|------|-----------|---------|
| `messages` | `messages.php` | English strings (`'Save'`) | UI labels, general strings (legacy pattern) |
| `validators` | `validators.php` | Key-pattern (`'validation.user.x'`) | Validation error messages (modern pattern) |

Both domains are loaded automatically by `IntlModule::loadLocale()` — it scans all `*.php` files in the locale directory and registers each as a domain based on filename.

### `ExtensionTranslateCommand` gap

The `./pagekit extension:translate` command currently does NOT extract message keys from `#[Assert\...]` attributes. It only scans `__()`, `_c()`, `trans`, and `transChoice` calls. This needs to be extended in Phase 2 to also parse PHP 8 Attribute `message:` parameters.

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
- app/system/languages/en_US/validators.php (currently named validation.php — rename pending)
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

### Phase 3

```
Modified:
- app/system/languages/en_US/validators.php (added Blog/Comment messages; currently named validation.php)
- app/system/modules/comment/src/Model/Comment.php (base Comment entity)
- packages/pagekit/blog/src/Model/Post.php
- packages/pagekit/blog/src/Model/Comment.php
- packages/pagekit/blog/src/Controller/PostApiController.php
- packages/pagekit/blog/src/Controller/CommentApiController.php
```

---

## 10. Aggressive Modernization Rules Applied

| Rule | Application |
|------|-------------|
| **#1 NO COMPATIBILITY LAYERS** | No shim classes created; all controllers updated directly |
| **#2 NO ADAPTERS** | All manual validation calls replaced in the same commit |
| **#3 BREAKING CHANGES ALLOWED** | Internal API changed (validate() method removed) |
| **#4 DELETE OVER WRAP** | Old `validate()` method and manual validation completely removed |
| **#5 MANDATORY FLAGGING** | ORM annotations were migrated in Step 1.14 (completed) |

---

## 11. Open Items

1. **Rename `validation.php` → `validators.php`** (Symfony standard domain)
   - `app/system/languages/en_US/validation.php`
   - `packages/pagekit/blog/languages/en_US/validation.php`

2. **Connect Validator to Translator** in `ValidatorServiceProvider`
   - Add `$builder->setTranslator($app['translator'])`
   - Add `$builder->setTranslationDomain('validators')`

3. **Extend `ExtensionTranslateCommand`** to extract `#[Assert\...]` message keys

4. **Create locale files** for other languages (e.g. `de_DE/validators.php`).
   After Open Item #3 is done, `./pagekit extension:translate` will generate `validators.pot` (template).
   Translators then create the actual `xx_XX/validators.php` files from that template (manually or via poEdit/Weblate).

5. **Custom Constraints**: Consider adding more custom constraints:
   - `#[StrongPassword]` - Password strength validation
   - `#[ValidSlug]` - URL slug validation
