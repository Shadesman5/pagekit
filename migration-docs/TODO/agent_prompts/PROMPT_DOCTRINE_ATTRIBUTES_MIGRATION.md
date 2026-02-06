## TASK: Doctrine Annotations to PHP 8 Attributes Migration (Step 1.14 - FINAL STEP!)

### CONTEXT:

This is the **FINAL STEP** of Phase 1 Core Modernization. We are migrating from Doctrine Annotations to PHP 8 Attributes for ORM mapping.

**CRITICAL CONTEXT**: The system currently uses:
- ✅ PHP 8 Attributes for **Validation** (Symfony Validator - Step 1.13 completed)
- ❌ Doctrine Annotations for **ORM** (`@Entity`, `@Column`, `@Id`) - **THIS STEP MIGRATES THIS**

**Current State**:

- ✅ Symfony 6.4 components integrated
- ✅ PHP 8.2+ with strict types
- ✅ Symfony Validator with PHP 8 Attributes (Step 1.13)
- ✅ PSR-11 Container
- ✅ PSR-6 Cache System
- ✅ Doctrine DBAL 3.x
- ❌ ORM still uses `doctrine/annotations` package
- ❌ `AnnotationLoader` uses `SimpleAnnotationReader`
- ✅ All infrastructure prerequisites (Steps 1.1-1.13.5) are completed

**REFERENCE RULES**:

- Follow `pagekit-context.mdc` (Strict types, No WordPress, No Laravel)
- Follow `pagekit-files.mdc` (PHP 8.2+ standards)

---

### 💀 AGGRESSIVE MODERNIZATION RULES (NO MERCY FOR LEGACY):

1. **NO COMPATIBILITY LAYERS**: Do not create "Shim" classes or wrappers just to support old annotation patterns. Delete annotations completely.
2. **NO ADAPTERS**: If a loader signature changes, update all usages immediately. Do not create adapters.
3. **BREAKING CHANGES ALLOWED**: It is explicitly allowed to break the internal API if it leads to cleaner, stricter PHP 8 code.
4. **DELETE OVER WRAP**: If old annotation logic conflicts with the new Attribute system, DELETE the old logic. Do not try to merge/wrap it.
5. **MANDATORY FLAGGING**: If a legacy fallback/workaround is absolutely unavoidable (e.g. to prevent a fatal crash), you MUST mark it with `// TODO: Must be refactored`. Silent workarounds are FORBIDDEN.

---

### 0. 🛑 SAFETY CHECKS (CRITICAL):

**AFTER EVERY SINGLE CHANGE:**

- Run: `php pagekit setup` (console must work)
- Test web: `curl http://localhost:8000` (MUST return 200, not 500!)
- Test admin: `curl http://localhost:8000/admin` (must load and redirect to http://localhost:8000/admin/login)
- Test entity loading: Create a test script that loads `User::find(1)` - must work!
- **IF ANY TEST FAILS → STOP AND FIX BEFORE CONTINUING!**

**Before starting:**

- Verify `doctrine/annotations` is in `composer.json` (it will be removed)
- Verify all entities have TODO comments marking ORM annotations (from Step 1.13)
- Check that `AnnotationLoader` exists in `app/modules/database/src/ORM/Loader/AnnotationLoader.php`
- Verify all infrastructure steps (1.1-1.13.5) are completed

---

### 1. PREPARATION:

- Create new branch from `develop`
- Create `migration-docs/branches/ORM_ATTRIBUTES_MIGRATION.md` to document changes
- Analyze current annotation usage:
  - Check all entities in `app/system/modules/*/src/Model/`
  - Check all annotation classes in `app/modules/database/src/ORM/Annotation/`
  - Document all annotation patterns found
  - Count total entities to migrate (should be ~7: User, Role, Page, Node, Comment, Widget, AccessModelTrait)

---

### 2. CREATE ATTRIBUTE CLASSES:

**2.1. Create Attribute Directory:**

Create `app/modules/database/src/ORM/Attribute/` directory.

**2.2. Create Base Attribute Interface:**

Create `app/modules/database/src/ORM/Attribute/Attribute.php`:

```php
<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Attribute;

/**
 * Base interface for all ORM Attributes.
 */
interface Attribute
{
}
```

**2.3. Create Entity Attribute:**

Create `app/modules/database/src/ORM/Attribute/Entity.php`:

```php
<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Entity implements Attribute
{
    public function __construct(
        public string $tableClass = '',
        public string $eventPrefix = ''
    ) {
    }
}
```

**2.4. Create Column Attribute:**

Create `app/modules/database/src/ORM/Attribute/Column.php`:

```php
<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Column implements Attribute
{
    public function __construct(
        public string $name = '',
        public string|int|null $type = 'string'
    ) {
    }
}
```

**2.5. Create Id Attribute:**

Create `app/modules/database/src/ORM/Attribute/Id.php`:

```php
<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Id implements Attribute
{
}
```

**2.6. Create MappedSuperclass Attribute:**

Create `app/modules/database/src/ORM/Attribute/MappedSuperclass.php`:

```php
<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class MappedSuperclass implements Attribute
{
}
```

**2.7. Create Relation Attributes:**

Create `app/modules/database/src/ORM/Attribute/BelongsTo.php`:

```php
<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class BelongsTo implements Attribute
{
    public function __construct(
        public string $targetEntity,
        public string $keyFrom = '',
        public string $keyTo = ''
    ) {
    }
}
```

Create `app/modules/database/src/ORM/Attribute/HasOne.php`:

```php
<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class HasOne implements Attribute
{
    public function __construct(
        public string $targetEntity,
        public string $keyFrom = '',
        public string $keyTo = ''
    ) {
    }
}
```

Create `app/modules/database/src/ORM/Attribute/HasMany.php`:

```php
<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class HasMany implements Attribute
{
    public function __construct(
        public string $targetEntity,
        public string $keyFrom = '',
        public string $keyTo = ''
    ) {
    }
}
```

Create `app/modules/database/src/ORM/Attribute/ManyToMany.php`:

```php
<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class ManyToMany implements Attribute
{
    public function __construct(
        public string $targetEntity,
        public string $keyFrom = '',
        public string $keyTo = '',
        public string $keyThroughFrom = '',
        public string $keyThroughTo = '',
        public string $tableThrough = ''
    ) {
    }
}
```

**2.8. Create OrderBy Attribute:**

Create `app/modules/database/src/ORM/Attribute/OrderBy.php`:

```php
<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class OrderBy implements Attribute
{
    public function __construct(
        public string $value
    ) {
    }
}
```

**2.9. Create Event Attributes:**

Create event attributes for lifecycle hooks:

`app/modules/database/src/ORM/Attribute/Saving.php`:
```php
<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Saving implements Attribute
{
}
```

Create similar files for: `Saved.php`, `Updating.php`, `Updated.php`, `Deleting.php`, `Deleted.php`, `Creating.php`, `Created.php`, `Init.php`

**Pattern for all event attributes:**
```php
<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class [EventName] implements Attribute
{
}
```

**VERIFY**: After creating all attributes, test that they can be imported and used.

---

### 3. CREATE ATTRIBUTE LOADER:

**3.1. Create AttributeLoader:**

Create `app/modules/database/src/ORM/Loader/AttributeLoader.php`:

```php
<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Loader;

use Pagekit\Database\ORM\Attribute\Attribute;
use Pagekit\Database\ORM\Attribute\BelongsTo;
use Pagekit\Database\ORM\Attribute\Column;
use Pagekit\Database\ORM\Attribute\Entity;
use Pagekit\Database\ORM\Attribute\HasMany;
use Pagekit\Database\ORM\Attribute\HasOne;
use Pagekit\Database\ORM\Attribute\Id;
use Pagekit\Database\ORM\Attribute\ManyToMany;
use Pagekit\Database\ORM\Attribute\MappedSuperclass;
use Pagekit\Database\ORM\Attribute\OrderBy;

class AttributeLoader implements LoaderInterface
{
    /**
     * {@inheritdoc}
     */
    public function load(\ReflectionClass $class, array $config = []): array
    {
        // Get Entity or MappedSuperclass attribute
        $entityAttr = $this->getClassAttribute($class, Entity::class);
        $mappedSuperclassAttr = $this->getClassAttribute($class, MappedSuperclass::class);

        if ($entityAttr) {
            $config['table'] = $entityAttr->tableClass ?: strtolower($class->getShortName());
            $config['eventPrefix'] = $entityAttr->eventPrefix;
        } elseif ($mappedSuperclassAttr) {
            $config['isMappedSuperclass'] = true;
        } else {
            throw new \Exception(sprintf('No #[Entity] or #[MappedSuperclass] attribute found for class %s', $class->getName()));
        }

        // Process properties
        foreach ($class->getProperties() as $property) {
            $name = $property->getName();

            if (!$property->isPrivate() && (isset($config['isMappedSuperclass']) || isset($config['fields'][$name]['inherited']) || isset($config['relations'][$name]['inherited']))) {
                continue;
            }

            // Check for Column attribute
            $columnAttr = $this->getPropertyAttribute($property, Column::class);
            if ($columnAttr) {
                $field = ['name' => $name];

                if (isset($config['fields'][$name])) {
                    throw new \Exception(sprintf('Duplicate field mapping detected, "%s" already exists.', $name));
                }

                if ($columnAttr->type) {
                    $field['type'] = (string) $columnAttr->type;
                }

                if ($columnAttr->name) {
                    $field['column'] = $columnAttr->name;
                }

                // Check for Id attribute
                if ($this->getPropertyAttribute($property, Id::class)) {
                    $field['id'] = true;
                }

                $config['fields'][$name] = $field;
            } else {
                // Check for relation attributes
                $belongsToAttr = $this->getPropertyAttribute($property, BelongsTo::class);
                $hasOneAttr = $this->getPropertyAttribute($property, HasOne::class);
                $hasManyAttr = $this->getPropertyAttribute($property, HasMany::class);
                $manyToManyAttr = $this->getPropertyAttribute($property, ManyToMany::class);

                $relationAttr = $belongsToAttr ?: $hasOneAttr ?: $hasManyAttr ?: $manyToManyAttr;
                $relationType = null;

                if ($belongsToAttr) {
                    $relationType = 'BelongsTo';
                    $relationData = [
                        'targetEntity' => $belongsToAttr->targetEntity,
                        'keyFrom' => $belongsToAttr->keyFrom,
                        'keyTo' => $belongsToAttr->keyTo,
                    ];
                } elseif ($hasOneAttr) {
                    $relationType = 'HasOne';
                    $relationData = [
                        'targetEntity' => $hasOneAttr->targetEntity,
                        'keyFrom' => $hasOneAttr->keyFrom,
                        'keyTo' => $hasOneAttr->keyTo,
                    ];
                } elseif ($hasManyAttr) {
                    $relationType = 'HasMany';
                    $relationData = [
                        'targetEntity' => $hasManyAttr->targetEntity,
                        'keyFrom' => $hasManyAttr->keyFrom,
                        'keyTo' => $hasManyAttr->keyTo,
                    ];
                } elseif ($manyToManyAttr) {
                    $relationType = 'ManyToMany';
                    $relationData = [
                        'targetEntity' => $manyToManyAttr->targetEntity,
                        'keyFrom' => $manyToManyAttr->keyFrom,
                        'keyTo' => $manyToManyAttr->keyTo,
                        'keyThroughFrom' => $manyToManyAttr->keyThroughFrom,
                        'keyThroughTo' => $manyToManyAttr->keyThroughTo,
                        'tableThrough' => $manyToManyAttr->tableThrough,
                    ];
                }

                if ($relationAttr) {
                    if (isset($config['fields'][$name]) || isset($config['relations'][$name])) {
                        throw new \Exception(sprintf('Duplicate relation mapping detected, "%s" already exists.', $name));
                    }

                    // Check for OrderBy attribute
                    $orderByAttr = $this->getPropertyAttribute($property, OrderBy::class);
                    if ($orderByAttr) {
                        $relationData['orderBy'] = $orderByAttr->value;
                    }

                    $config['relations'][$name] = array_merge(
                        ['name' => $name, 'type' => $relationType],
                        $relationData
                    );
                }
            }
        }

        // Process methods for event attributes
        foreach ($class->getMethods() as $method) {
            $name = $method->getName();

            if (!$method->isPublic() || $method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            // Check for event attributes: Saving, Saved, Updating, Updated, Deleting, Deleted, Created, Creating, Init
            $eventAttributes = [
                'Saving', 'Saved', 'Updating', 'Updated',
                'Deleting', 'Deleted', 'Created', 'Creating', 'Init'
            ];

            foreach ($eventAttributes as $eventName) {
                $eventClass = "Pagekit\\Database\\ORM\\Attribute\\{$eventName}";
                if ($this->getMethodAttribute($method, $eventClass)) {
                    $config['events'][lcfirst($eventName)][] = $name;
                    break;
                }
            }
        }

        return $config;
    }

    /**
     * {@inheritdoc}
     */
    public function isTransient(\ReflectionClass $class): bool
    {
        $entityAttr = $this->getClassAttribute($class, Entity::class);
        $mappedSuperclassAttr = $this->getClassAttribute($class, MappedSuperclass::class);

        return !$entityAttr && !$mappedSuperclassAttr;
    }

    /**
     * Gets a class attribute.
     */
    protected function getClassAttribute(\ReflectionClass $class, string $attributeClass): ?object
    {
        $attributes = $class->getAttributes($attributeClass, \ReflectionAttribute::IS_INSTANCEOF);
        return $attributes[0]?->newInstance();
    }

    /**
     * Gets a property attribute.
     */
    protected function getPropertyAttribute(\ReflectionProperty $property, string $attributeClass): ?object
    {
        $attributes = $property->getAttributes($attributeClass, \ReflectionAttribute::IS_INSTANCEOF);
        return $attributes[0]?->newInstance();
    }

    /**
     * Gets a method attribute.
     */
    protected function getMethodAttribute(\ReflectionMethod $method, string $attributeClass): ?object
    {
        $attributes = $method->getAttributes($attributeClass, \ReflectionAttribute::IS_INSTANCEOF);
        return $attributes[0]?->newInstance();
    }
}
```

**3.2. Update Database Module Registration:**

In `app/modules/database/index.php`, replace `AnnotationLoader` with `AttributeLoader`:

```php
use Pagekit\Database\ORM\Loader\AttributeLoader;

// ... in 'db.metas' service:
$manager = new MetadataManager($app['db'], $app['db.events']);
$manager->setLoader(new AttributeLoader); // Changed from AnnotationLoader
$manager->setCache($app['cache.phpfile']);
```

**VERIFY**: After creating AttributeLoader, test that the system still loads (even with annotations still present).

---

### 4. MIGRATE ENTITIES (Rule #4: DELETE OVER WRAP):

**4.1. Migration Pattern:**

For each entity, follow this pattern:

1. **ADD** PHP 8 Attributes above class/properties
2. **REMOVE** all `/** @Entity */`, `/** @Column */`, `/** @Id */` annotations
3. **REMOVE** all `// TODO: Must be refactored in Step 1.14` comments
4. **REMOVE** all PHPDoc blocks that only contained annotations

**Example Migration (User.php):**

**BEFORE:**
```php
/**
 * @Entity(tableClass="@system_user")
 */
class User implements UserInterface, \JsonSerializable
{
    /**
     * @Column(type="integer") @Id
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public ?int $id = null;

    /**
     * @Column
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\NotBlank(message: 'validation.user.username_required')]
    public ?string $username = '';
}
```

**AFTER:**
```php
use Pagekit\Database\ORM\Attribute as ORM;

#[ORM\Entity(tableClass: '@system_user')]
class User implements UserInterface, \JsonSerializable
{
    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    public ?int $id = null;

    #[ORM\Column]
    #[Assert\NotBlank(message: 'validation.user.username_required')]
    public ?string $username = '';
}
```

**4.2. Target Entities (Priority Order):**

1. **User.php** (`app/system/modules/user/src/Model/User.php`) - Priority 1
2. **Role.php** (`app/system/modules/user/src/Model/Role.php`) - Priority 2
3. **Page.php** (`app/system/modules/site/src/Model/Page.php`) - Priority 3
4. **Node.php** (`app/system/modules/site/src/Model/Node.php`) - Priority 4
5. **Comment.php** (`app/system/modules/comment/src/Model/Comment.php`) - Priority 5
6. **Widget.php** (`app/system/modules/widget/src/Model/Widget.php`) - Priority 6
7. **AccessModelTrait.php** (`app/system/modules/user/src/Model/AccessModelTrait.php`) - Priority 7 (if it has annotations)

**4.3. Migration Rules:**

- **Rule #4 (DELETE OVER WRAP)**: Remove ALL annotations immediately. Do not keep them for compatibility.
- **Rule #1 (NO COMPATIBILITY LAYERS)**: Do not create any wrapper that supports both annotations and attributes.
- **Rule #2 (NO ADAPTERS)**: Update all entities in the same commit. Do not create adapters.

**4.4. Common Annotation Patterns:**

- `@Entity(tableClass="@system_user")` → `#[ORM\Entity(tableClass: '@system_user')]`
- `@Column(type="integer")` → `#[ORM\Column(type: 'integer')]`
- `@Column` → `#[ORM\Column]`
- `@Id` → `#[ORM\Id]`
- `@BelongsTo(targetEntity="...", keyFrom="...", keyTo="...")` → `#[ORM\BelongsTo(targetEntity: '...', keyFrom: '...', keyTo: '...')]`
- `@HasMany(targetEntity="...")` → `#[ORM\HasMany(targetEntity: '...')]`
- `@OrderBy(value="id ASC")` → `#[ORM\OrderBy(value: 'id ASC')]`

**4.5. Test After Each Entity:**

After migrating each entity:
- Test: `php pagekit setup`
- Test: Load entity in console: `User::find(1)`
- Test: Web interface loads
- **IF ANY TEST FAILS → STOP AND FIX BEFORE CONTINUING!**

---

### 5. REMOVE ANNOTATIONS (Rule #4: DELETE OVER WRAP):

**5.1. Remove Annotation Classes:**

Delete the entire `app/modules/database/src/ORM/Annotation/` directory:

```bash
# All annotation classes should be deleted:
- Annotation.php
- BelongsTo.php
- Column.php
- Created.php
- Creating.php
- Deleted.php
- Deleting.php
- Entity.php
- HasMany.php
- HasOne.php
- Id.php
- Init.php
- ManyToMany.php
- MappedSuperclass.php
- OrderBy.php
- Saved.php
- Saving.php
- Updated.php
- Updating.php
```

**5.2. Remove AnnotationLoader:**

Delete `app/modules/database/src/ORM/Loader/AnnotationLoader.php` (replaced by AttributeLoader).

**5.3. Remove doctrine/annotations Package:**

In `composer.json`, remove:
```json
"doctrine/annotations": "~1.14",
```

Then run:
```bash
composer update doctrine/annotations --no-interaction
composer remove doctrine/annotations --no-interaction
```

**5.4. Remove AnnotationRegistry (if exists):**

Check `app/autoload.php` or `index.php` for any `AnnotationRegistry::registerLoader()` calls and remove them.

**5.5. Remove Annotation Imports:**

Search for any remaining imports of annotation classes and remove them:
```bash
grep -r "use Pagekit\\Database\\ORM\\Annotation" app/
```

**VERIFY**: After removal, test that system still works:
- `php pagekit setup`
- `curl http://localhost:8000`
- Load entities: `User::find(1)`

---

### 6. VERIFICATION:

**Test 1: Does the page load?**
- Ensures AttributeLoader works correctly
- Ensures no conflicts between Attributes and old annotations

**Test 2: Load entities**
- `User::find(1)` should work
- `Role::find(1)` should work
- Relations should work: `$user->roles` should work
- Database queries should work

**Test 3: Save entities**
- Create new user: Should work
- Update user: Should work
- Delete user: Should work

**Test 4: Check for remaining annotations**
- Search codebase: `grep -r "@Entity\|@Column\|@Id" app/` - Should return NO results
- Search for annotation imports: `grep -r "ORM\\Annotation" app/` - Should return NO results

**Test 5: Verify doctrine/annotations is removed**
- Check `composer.json`: `doctrine/annotations` should NOT be present
- Check `composer.lock`: `doctrine/annotations` should NOT be present

---

### 7. DOCUMENTATION:

Update `migration-docs/branches/ORM_ATTRIBUTES_MIGRATION.md`:

- Document the migration from Annotations to Attributes
- Document all attribute classes created
- Document AttributeLoader implementation
- Document migration pattern for entities
- Include examples for common use cases
- Document breaking changes (if any)
- Note that this completes Phase 1 Core Modernization

---

### 8. CLEANUP & REFACTORING NOTES:

- ✅ **DONE**: All annotation classes are removed
- ✅ **DONE**: AnnotationLoader is removed
- ✅ **DONE**: `doctrine/annotations` package is removed
- ✅ **DONE**: All entities use PHP 8 Attributes
- ✅ **DONE**: All TODO comments from Step 1.13 are removed
- ✅ **DONE**: Hybrid mode is complete - both Validation and ORM use Attributes

---

### SUCCESS CRITERIA:

**Attribute System:**

- [ ] All 19 attribute classes created in `app/modules/database/src/ORM/Attribute/`
- [ ] `AttributeLoader` created and working
- [ ] `AttributeLoader` registered in `app/modules/database/index.php`

**Entity Migration (Rule #4: DELETE OVER WRAP):**

- [ ] All 7 entities migrated to use `#[ORM\...]` attributes
- [ ] All `/** @Entity */`, `/** @Column */`, `/** @Id */` annotations **REMOVED**
- [ ] All `// TODO: Must be refactored in Step 1.14` comments **REMOVED**
- [ ] All PHPDoc blocks that only contained annotations are **REMOVED**
- [ ] No compatibility layers or adapters created (Rule #1, #2)

**Annotation Removal:**

- [ ] `app/modules/database/src/ORM/Annotation/` directory **DELETED**
- [ ] `AnnotationLoader.php` **DELETED**
- [ ] `doctrine/annotations` package **REMOVED** from `composer.json`
- [ ] `AnnotationRegistry` calls **REMOVED** (if any)
- [ ] No remaining imports of annotation classes

**Functionality:**

- [ ] Entities load correctly: `User::find(1)` works
- [ ] Relations work: `$user->roles` works
- [ ] Entity saving works: Create/Update/Delete operations work
- [ ] All safety checks pass (`php pagekit setup`, web, admin)
- [ ] No errors in logs related to annotations

**Documentation:**

- [ ] Documentation created in `ORM_ATTRIBUTES_MIGRATION.md`
- [ ] All migration steps documented
- [ ] Examples provided for common patterns

**Code Quality:**

- [ ] All code uses PHP 8.2+ strict types
- [ ] All attributes use proper PHP 8 syntax
- [ ] No legacy code remains
- [ ] Code follows Pagekit standards

---

### ROLLBACK PLAN:

If something breaks:

1. Revert the branch
2. Restore `AnnotationLoader` from git history
3. Restore annotation classes from git history
4. Add `doctrine/annotations` back to `composer.json`
5. Run `composer update`
6. Verify system works again

---

## 📝 Notes:

- **Aggressive Modernization**: Follow the 5 rules strictly - no mercy for legacy code
- **Final Step**: This completes Phase 1 Core Modernization
- **Clean Migration**: All annotations are completely removed, not kept for compatibility (Rule #4)
- **No Legacy Code**: All ORM mapping now uses PHP 8 Attributes (Rule #4)
- **Breaking Changes**: Internal API changes are allowed if they lead to cleaner PHP 8 code (Rule #3)
- **Incremental**: Migrate entities one by one, test after each
- **Test-Driven**: Test after every change
- **Documentation**: Document everything for future reference
- **Hybrid Mode Complete**: Both Validation (Step 1.13) and ORM (Step 1.14) now use PHP 8 Attributes

---

## 🎯 PHASE 1 COMPLETE:

After this step, Phase 1 Core Modernization is **COMPLETE**:
- ✅ Symfony 6.4 components
- ✅ PHP 8.2+ strict types
- ✅ PSR-11 Container
- ✅ PSR-6 Cache
- ✅ Doctrine DBAL 3.x
- ✅ Symfony Validator with PHP 8 Attributes
- ✅ ORM with PHP 8 Attributes
- ✅ No more Doctrine Annotations
- ✅ Modern infrastructure ready for Phase 2
