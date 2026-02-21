# Symfony Validator Migration - Phase 2 Discovery

## Overview

This document lists all entities and controllers that need to be migrated to use Symfony Validator in Phase 2.

## Discovered Entities Requiring Validation

### Already Migrated (Phase 1)

| Entity | Location | Status |
|--------|----------|--------|
| `User` | `app/system/modules/user/src/Model/User.php` | ✅ DONE |
| `Role` | `app/system/modules/user/src/Model/Role.php` | ✅ DONE |

### To Be Migrated (Phase 2)

| Entity | Location | Priority | Validation Needed |
|--------|----------|----------|-------------------|
| `Node` | `app/system/modules/site/src/Model/Node.php` | HIGH | slug, title, link, status |
| `Page` | `app/system/modules/site/src/Model/Page.php` | MEDIUM | title |
| `Widget` | `app/system/modules/widget/src/Model/Widget.php` | HIGH | title, type, status |

## Controllers with Manual Validation

### Already Migrated (Phase 1)

| Controller | Location | Status |
|------------|----------|--------|
| `UserApiController` | `app/system/modules/user/src/Controller/` | ✅ DONE |
| `RegistrationController` | `app/system/modules/user/src/Controller/` | ✅ DONE |
| `ProfileController` | `app/system/modules/user/src/Controller/` | ✅ DONE |

### To Be Migrated (Phase 2)

| Controller | Location | Manual Validation Found |
|------------|----------|------------------------|
| `NodeApiController` | `app/system/modules/site/src/Controller/` | `App::abort(400, 'Invalid slug')`, `App::abort(400, 'Link is required')` |
| `WidgetApiController` | `app/system/modules/widget/src/Controller/` | `App::abort(400, 'Widget title empty')` |
| `MenuApiController` | `app/system/modules/site/src/Controller/` | `App::abort(400, 'Invalid id')` - Business logic, not entity validation |
| `RoleApiController` | `app/system/modules/user/src/Controller/` | No manual validation, but should use `ValidatesRequestTrait` for Role entity |

## Hardcoded Validation Messages Found

### Site Module - NodeApiController

```php
// Line 76
App::abort(400, __('Invalid slug.'));

// Line 82
App::abort(400, __('Link is required. Please specify a valid URL or route.'));

// Line 103
App::abort(400, __('Invalid type.')); // Business logic, not entity validation

// Line 211
App::abort(400, __('Invalid node type.')); // Business logic, not entity validation
```

### Widget Module - WidgetApiController

```php
// Line 112
App::abort(400, 'Widget title empty.'); // Note: Not using __() function!
```

### Site Module - MenuApiController

```php
// Line 61
App::abort(400, __('Invalid id.')); // Business logic for menu slugification
```

## Migration Priority Order

1. **Widget Module** (Simple, single entity)
   - Entity: `Widget.php`
   - Controller: `WidgetApiController.php`
   
2. **Site Module** (More complex, 2 entities)
   - Entity: `Node.php`, `Page.php`
   - Controller: `NodeApiController.php`
   
3. **User Module - RoleApiController** (Add ValidatesRequestTrait)
   - Controller: `RoleApiController.php`

## Validation Rules to Implement

### Node Entity

| Property | Constraints | Message Key |
|----------|-------------|-------------|
| `slug` | NotBlank, Regex(/^[a-z0-9\-_]+$/) | `validation.node.slug_required`, `validation.node.slug_invalid` |
| `title` | NotBlank, Length(max: 255) | `validation.node.title_required`, `validation.node.title_max_length` |
| `link` | Length(max: 500) | `validation.node.link_max_length` |
| `type` | NotBlank | `validation.node.type_required` |
| `status` | Choice(0, 1) | `validation.node.status_invalid` |

### Page Entity

| Property | Constraints | Message Key |
|----------|-------------|-------------|
| `title` | NotBlank, Length(max: 255) | `validation.page.title_required`, `validation.page.title_max_length` |

### Widget Entity

| Property | Constraints | Message Key |
|----------|-------------|-------------|
| `title` | NotBlank, Length(max: 255) | `validation.widget.title_required`, `validation.widget.title_max_length` |
| `type` | NotBlank | `validation.widget.type_required` |
| `status` | Choice(0, 1) | `validation.widget.status_invalid` |

## Notes

### Business Logic vs Entity Validation

Some validation in controllers is **business logic**, not entity validation:

1. **MenuApiController** - `Invalid id` check is for slugification, not entity validation
2. **NodeApiController** - `Invalid type` and `Invalid node type` are checking if the node type is protected or not allowed as frontpage

These should **remain in controllers** as they involve business rules, not simple field validation.

### ORM Annotations

All ORM annotations (`@Entity`, `@Column`, `@Id`) must be preserved and marked with:

```php
// TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
```

### Translation Integration

Message keys are stored in `app/system/languages/en_US/validators.php` (Symfony standard domain `validators`).

> **Note:** File is currently named `validation.php` — rename to `validators.php` pending.

The Translator integration (connecting Symfony Validator to Pagekit's `IntlModule` Translator via `setTranslator()` + `setTranslationDomain('validators')`) is tracked as a Phase 2 task. See `migration-docs/branches/VALIDATION_SYSTEM.md` for details.
