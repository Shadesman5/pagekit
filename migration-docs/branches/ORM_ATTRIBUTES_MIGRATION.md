# ORM Attributes Migration (Step 1.14 - FINAL STEP)

## Overview

This document describes the migration from Doctrine Annotations to PHP 8 Attributes for ORM mapping.
This is the **FINAL STEP** of Phase 1 Core Modernization.

## Migration Date

- **Started**: 2026-01-26
- **Branch**: `cursor/orm-attributes-migration-6f53`

## What Changed

### Before
- ORM mapping used Doctrine Annotations (`@Entity`, `@Column`, `@Id`, etc.)
- Required `doctrine/annotations` package
- Used `AnnotationLoader` with `SimpleAnnotationReader`

### After
- ORM mapping uses PHP 8 Attributes (`#[ORM\Entity]`, `#[ORM\Column]`, `#[ORM\Id]`, etc.)
- Removed `doctrine/annotations` package
- Uses `AttributeLoader` with native PHP Reflection API

## Created Attribute Classes

Location: `app/modules/database/src/ORM/Attribute/`

### Interface
- `Attribute.php` - Base interface for all ORM Attributes

### Class Attributes
- `Entity.php` - Marks a class as an ORM entity
- `MappedSuperclass.php` - Marks a class as a mapped superclass

### Property Attributes
- `Column.php` - Maps a property to a database column
- `Id.php` - Marks a property as the primary key
- `BelongsTo.php` - Defines a belongs-to relationship
- `HasOne.php` - Defines a has-one relationship
- `HasMany.php` - Defines a has-many relationship
- `ManyToMany.php` - Defines a many-to-many relationship
- `OrderBy.php` - Defines ordering for relationships

### Method Attributes (Lifecycle Events)
- `Saving.php` - Called before entity is saved
- `Saved.php` - Called after entity is saved
- `Updating.php` - Called before entity is updated
- `Updated.php` - Called after entity is updated
- `Deleting.php` - Called before entity is deleted
- `Deleted.php` - Called after entity is deleted
- `Creating.php` - Called before entity is created
- `Created.php` - Called after entity is created
- `Init.php` - Called when entity is initialized

## Migrated Entities

### System Modules
1. `User.php` (`app/system/modules/user/src/Model/User.php`)
2. `Role.php` (`app/system/modules/user/src/Model/Role.php`)
3. `Page.php` (`app/system/modules/site/src/Model/Page.php`)
4. `Node.php` (`app/system/modules/site/src/Model/Node.php`)
5. `Comment.php` (`app/system/modules/comment/src/Model/Comment.php`)
6. `Widget.php` (`app/system/modules/widget/src/Model/Widget.php`)

### Traits with ORM Attributes
7. `AccessModelTrait.php` (`app/system/modules/user/src/Model/AccessModelTrait.php`)

### Traits with Lifecycle Event Attributes
8. `UserModelTrait.php` (`app/system/modules/user/src/Model/UserModelTrait.php`)
9. `RoleModelTrait.php` (`app/system/modules/user/src/Model/RoleModelTrait.php`)

### Blog Package
10. `Post.php` (`packages/pagekit/blog/src/Model/Post.php`)
11. `Comment.php` (`packages/pagekit/blog/src/Model/Comment.php`)

## Migration Pattern

### Before (Annotations)
```php
/**
 * @Entity(tableClass="@system_user")
 */
class User
{
    /**
     * @Column(type="integer") @Id
     */
    public ?int $id = null;

    /**
     * @Column
     */
    public ?string $username = '';
}
```

### After (Attributes)
```php
use Pagekit\Database\ORM\Attribute as ORM;

#[ORM\Entity(tableClass: '@system_user')]
class User
{
    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    public ?int $id = null;

    #[ORM\Column]
    public ?string $username = '';
}
```

## Common Attribute Patterns

| Annotation | Attribute |
|------------|-----------|
| `@Entity(tableClass="@table")` | `#[ORM\Entity(tableClass: '@table')]` |
| `@Column(type="integer")` | `#[ORM\Column(type: 'integer')]` |
| `@Column` | `#[ORM\Column]` |
| `@Id` | `#[ORM\Id]` |
| `@MappedSuperclass` | `#[ORM\MappedSuperclass]` |
| `@BelongsTo(targetEntity="...", keyFrom="...")` | `#[ORM\BelongsTo(targetEntity: '...', keyFrom: '...')]` |
| `@HasMany(targetEntity="...")` | `#[ORM\HasMany(targetEntity: '...')]` |
| `@OrderBy({"field" = "ASC"})` | `#[ORM\OrderBy(value: 'field ASC')]` |
| `@Saving` | `#[ORM\Saving]` |

## Removed Files

### Annotation Classes (Deleted)
- `app/modules/database/src/ORM/Annotation/Annotation.php`
- `app/modules/database/src/ORM/Annotation/BelongsTo.php`
- `app/modules/database/src/ORM/Annotation/Column.php`
- `app/modules/database/src/ORM/Annotation/Created.php`
- `app/modules/database/src/ORM/Annotation/Creating.php`
- `app/modules/database/src/ORM/Annotation/Deleted.php`
- `app/modules/database/src/ORM/Annotation/Deleting.php`
- `app/modules/database/src/ORM/Annotation/Entity.php`
- `app/modules/database/src/ORM/Annotation/HasMany.php`
- `app/modules/database/src/ORM/Annotation/HasOne.php`
- `app/modules/database/src/ORM/Annotation/Id.php`
- `app/modules/database/src/ORM/Annotation/Init.php`
- `app/modules/database/src/ORM/Annotation/ManyToMany.php`
- `app/modules/database/src/ORM/Annotation/MappedSuperclass.php`
- `app/modules/database/src/ORM/Annotation/OrderBy.php`
- `app/modules/database/src/ORM/Annotation/Saved.php`
- `app/modules/database/src/ORM/Annotation/Saving.php`
- `app/modules/database/src/ORM/Annotation/Updated.php`
- `app/modules/database/src/ORM/Annotation/Updating.php`

### Loader (Deleted)
- `app/modules/database/src/ORM/Loader/AnnotationLoader.php`

### Dependencies (Removed)
- `doctrine/annotations` removed from `composer.json`

## AttributeLoader Implementation

The new `AttributeLoader` uses PHP's native Reflection API:

```php
class AttributeLoader implements LoaderInterface
{
    public function load(\ReflectionClass $class, array $config = []): array
    {
        // Uses $class->getAttributes() to read PHP 8 attributes
        // Uses $property->getAttributes() for property attributes
        // Uses $method->getAttributes() for method attributes
    }
}
```

## Breaking Changes

None for public API. Internal changes:
- `AnnotationLoader` replaced by `AttributeLoader`
- All annotation imports removed

## Testing

After migration, verify:
1. `php pagekit setup` - Console works
2. `curl http://localhost:8000` - Web loads
3. Entity loading: `User::find(1)` works
4. Relations work: `$user->roles` works
5. CRUD operations work

## Phase 1 Complete

This step completes Phase 1 Core Modernization:
- ✅ Symfony 6.4 components
- ✅ PHP 8.2+ strict types
- ✅ PSR-11 Container
- ✅ PSR-6 Cache
- ✅ Doctrine DBAL 3.x
- ✅ Symfony Validator with PHP 8 Attributes
- ✅ ORM with PHP 8 Attributes
- ✅ No more Doctrine Annotations
