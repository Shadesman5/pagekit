# Pull Request: Complete PHP 8 Attributes Migration (ORM + Routing)

**Branch:** `cursor/orm-attributes-migration-6f53`  
**Target:** `develop`  
**Version:** 1.0.48 → **1.1.0** (Major Feature Release)

---

## Summary

Complete migration from Doctrine Annotations to PHP 8 Attributes for both ORM and Routing components. This is a **breaking change** for extensions using the old annotation syntax.

---

## Changes Overview

### Version Bump
- `app/system/config.php`: 1.0.48 → 1.1.0
- `composer.json`: 1.0.48 → 1.1.0

### ORM Attributes Migration

**New Attribute Classes** (`app/modules/database/src/ORM/Attribute/`):
| Attribute | Purpose |
|-----------|---------|
| `Entity` | Class-level table mapping |
| `MappedSuperclass` | Abstract entity mapping |
| `Column` | Property to column mapping |
| `Id` | Primary key marker |
| `BelongsTo` | Many-to-one relation |
| `HasOne` | One-to-one relation |
| `HasMany` | One-to-many relation |
| `ManyToMany` | Many-to-many relation |
| `OrderBy` | Default ordering for relations |
| `Saving`, `Saved`, `Creating`, `Created`, `Updating`, `Updated`, `Deleting`, `Deleted`, `Init` | Lifecycle events |

**Migrated Entities:**
- `User`, `Role`, `Page`, `Node`, `Widget`, `Comment` (base), `Post` (blog), `Comment` (blog)

**Migrated Traits:**
- `AccessModelTrait`, `UserModelTrait`, `RoleModelTrait`, `NodeModelTrait`, `DataModelTrait`, `CommentModelTrait`, `PostModelTrait`

### Routing Attributes Migration

**New Attribute Classes:**
| Attribute | Location | Purpose |
|-----------|----------|---------|
| `Route` | `app/modules/routing/src/Attribute/` | URL path, methods, requirements |
| `Request` | `app/modules/routing/src/Attribute/` | Parameter mapping from HTTP request |
| `Access` | `app/system/modules/user/src/Attribute/` | Permission and admin access control |
| `Captcha` | `app/system/modules/captcha/src/Attribute/` | reCAPTCHA verification |

**Migrated Controllers (31 total):**

| Module | Controllers |
|--------|-------------|
| User | `UserController`, `UserApiController`, `AuthController`, `ProfileController`, `RegistrationController`, `ResetPasswordController`, `RoleApiController` |
| Site | `NodeController`, `NodeApiController`, `PageApiController`, `MenuApiController` |
| Widget | `WidgetController`, `WidgetApiController` |
| System | `AdminController`, `MigrationController`, `SettingsController` |
| Installer | `PackageController`, `MarketplaceController`, `UpdateController` |
| Blog | `BlogController`, `SiteController`, `PostApiController`, `CommentApiController` |
| Other | `MailController`, `InfoController`, `IntlController`, `IntlApiController`, `FinderController`, `StorageController`, `CacheController`, `DashboardController` |

**Updated Listeners:**
- `ConfigureRouteListener` → reads `#[Request]` attributes
- `AccessListener` → reads `#[Access]` attributes
- `CaptchaListener` → reads `#[Captcha]` attributes

### Deleted Files

**ORM Annotations (20 files):**
```
app/modules/database/src/ORM/Annotation/
├── BelongsTo.php
├── Column.php
├── Created.php
├── Creating.php
├── Deleted.php
├── Deleting.php
├── Entity.php
├── HasMany.php
├── HasOne.php
├── Id.php
├── Init.php
├── ManyToMany.php
├── MappedSuperclass.php
├── OrderBy.php
├── Saved.php
├── Saving.php
├── Updated.php
└── Updating.php
app/modules/database/src/ORM/Loader/AnnotationLoader.php
```

**Routing Annotations (5 files):**
```
app/modules/routing/src/Annotation/Route.php
app/modules/routing/src/Annotation/Request.php
app/modules/routing/src/Loader/AnnotationLoader.php
app/system/modules/user/src/Annotation/Access.php
app/system/modules/captcha/src/Annotation/Captcha.php
```

### Dependency Changes

**Removed:**
- `doctrine/annotations` - No longer required

---

## Bug Fixes During Migration

| Issue | File | Fix |
|-------|------|-----|
| `Undefined array key 0` | `ORM/Loader/AttributeLoader.php` | Check array not empty before accessing `[0]` |
| String assigned to array property | `ORM/Relation/HasMany.php` | Added `parseOrderBy()` helper + null coalescing |
| HTTP status code "0" is not valid | `Controller/ExceptionController.php` | Validate status code (100-599) with 500 default |
| Non-scalar value in InputBag | `Request/ParamFetcherListener.php` | Use `->all()[$name]` instead of `->get($name)` |
| Missing `$data` argument | `CommentApiController.php` | Rename parameter to match `#[Request]` key |
| Type mismatch (int/string) | `ConfigureRouteListener.php` | Use correct `['value' => ..., 'options' => []]` format |

---

## Migration Guide for Extensions

### ORM Annotations → Attributes

**Before:**
```php
use Pagekit\Database\ORM\Annotation as ORM;

/**
 * @ORM\Entity(tableClass="@my_table")
 */
class MyEntity {
    /**
     * @ORM\Column(type="integer")
     * @ORM\Id
     */
    public ?int $id = null;
    
    /**
     * @ORM\BelongsTo(targetEntity="User", keyFrom="user_id")
     */
    public ?User $user = null;
}
```

**After:**
```php
use Pagekit\Database\ORM\Attribute as ORM;

#[ORM\Entity(tableClass: '@my_table')]
class MyEntity {
    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    public ?int $id = null;
    
    #[ORM\BelongsTo(targetEntity: User::class, keyFrom: 'user_id')]
    public ?User $user = null;
}
```

### Controller Annotations → Attributes

**Before:**
```php
/**
 * @Access(admin=true)
 * @Route("/api/items", name="@api/items")
 */
class MyController {
    /**
     * @Access("my.permission")
     * @Request({"id": "int", "data": "array"})
     * @Route("/{id}", methods="POST")
     */
    public function saveAction(int $id, array $data) {}
}
```

**After:**
```php
use Pagekit\Routing\Attribute\Route;
use Pagekit\Routing\Attribute\Request;
use Pagekit\User\Attribute\Access;

#[Access(admin: true)]
#[Route('/api/items', name: '@api/items')]
class MyController {
    #[Access('my.permission')]
    #[Request(['id' => 'int', 'data' => 'array'])]
    #[Route('/{id}', methods: 'POST')]
    public function saveAction(int $id, array $data) {}
}
```

---

## Testing Checklist

- [x] `php pagekit setup` - Installation works
- [x] Admin login - Authentication works
- [x] Admin dashboard - Loads correctly
- [x] User management - CRUD operations work
- [x] Page/Node management - Routing works
- [x] Widget management - Relations work
- [x] Blog posts/comments - Package migrations work
- [x] No PHP errors in logs

---

## Labels

- `breaking-change`
- `backend`
- `database`
- `enhancement`

---

## Reviewers

- @Shadesman5
