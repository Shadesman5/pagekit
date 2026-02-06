## TASK: Complete Symfony Validator Migration - Phase 2 (Step 1.13 Continued)

### CONTEXT:

**Previous Work Completed**:

- ✅ Symfony Validator 7.4 installed and configured
- ✅ `ValidatorServiceProvider` created and registered
- ✅ `ValidatesRequestTrait` created for controller validation
- ✅ Custom `Unique` constraint implemented
- ✅ **User Module fully modernized**: `User.php`, `Role.php`, `UserApiController`, `RegistrationController`, `ProfileController`
- ✅ Validation attributes added to User and Role entities
- ✅ Old `validate()` method removed from User entity (Rule #4: DELETE OVER WRAP)
- ✅ All User controllers use `ValidatesRequestTrait`

**Current Task**: Complete the migration for ALL remaining modules/entities in the system.

**CRITICAL CONTEXT**: The system still uses `doctrine/annotations` for ORM mapping (Step 1.14 is pending). We must continue using **HYBRID setup**: Attributes for Validation, Annotations for ORM.

**REFERENCE RULES**:

- Follow `pagekit-context.mdc` (Strict types, No WordPress, No Laravel)
- Follow `pagekit-files.mdc` (PHP 8.2+ standards)
- Follow `conventional-commits.mdc` for commit messages

---

### 💀 AGGRESSIVE MODERNIZATION RULES (NO MERCY FOR LEGACY):

1. **NO COMPATIBILITY LAYERS**: Do not create "Shim" classes or wrappers just to support old calling patterns.
2. **NO ADAPTERS**: If a method signature changes, update all usages in the code immediately. Do not create adapters.
3. **BREAKING CHANGES ALLOWED**: It is explicitly allowed to break the internal API if it leads to cleaner, stricter PHP 8 code.
4. **DELETE OVER WRAP**: If old logic conflicts with the new Symfony Validator, DELETE the old logic. Do not try to merge/wrap it.
5. **MANDATORY FLAGGING**: If a legacy fallback/workaround is absolutely unavoidable, you MUST mark it with `// TODO: Must be refactored`. Silent workarounds are FORBIDDEN.

---

### 0. 🛑 SAFETY CHECKS (CRITICAL):

**AFTER EVERY SINGLE CHANGE:**

- Run: `php pagekit setup` (console must work)
- Test web: `curl http://localhost:8000` (MUST return 200, not 500!)
- Test admin: `curl http://localhost:8000/admin` (must load and redirect to http://localhost:8000/admin/login)
- **IF ANY TEST FAILS → STOP AND FIX BEFORE CONTINUING!**

**NOTE**: The frontend .vue validation is still active and will be migrated in a later phase.

**Before starting:**

- Verify `symfony/validator` is already in `composer.json` (should be there from Phase 1)
- Verify `ValidatorServiceProvider` exists and is registered
- Verify `ValidatesRequestTrait` exists

---

### 1. PREPARATION & DISCOVERY:

**1.1. Create Your Own To-Do List:**

Create a structured checklist document (similar to the User module migration) that tracks:
- Entities/Models that need validation attributes
- Controllers that need to use `ValidatesRequestTrait`
- Manual validation code that needs to be replaced
- Translation messages that need to be added

**1.2. Discover All Entities/Models Requiring Validation:**

**CRITICAL**: You MUST find ALL entities that need Symfony Validator migration. Do not rely on assumptions!

**Search Strategy**:

1. **Find all Model/Entity classes:**
   ```bash
   # Search for entities in all modules
   grep -r "@Entity\|class.*implements.*JsonSerializable\|use.*ModelTrait" app/system/modules/*/src/Model/
   ```

2. **Find manual validation patterns:**
   ```bash
   # Search for manual validation in controllers
   grep -r "App::abort(400\|if (empty(\|if (!\$.*->\|throw new Exception" app/system/modules/*/src/Controller/
   ```

3. **Check for existing `validate()` methods:**
   ```bash
   # Search for validate() methods in entities (should already be removed from User)
   grep -r "public function validate()" app/system/modules/
   ```

4. **Identify Controller validation patterns:**
   - Look for `App::abort(400, ...)` calls (manual validation)
   - Look for `if (empty(...))` checks before saving
   - Look for `throw new Exception(...)` in controllers

**Known Modules to Check** (non-exhaustive - you must find ALL):

- ✅ `app/system/modules/user/` (ALREADY DONE - use as reference)
- ⏳ `app/system/modules/site/` (Node, Page entities)
- ⏳ `app/system/modules/widget/` (Widget entity)
- ⏳ `app/system/modules/comment/` (Comment entity - if exists)
- ⏳ Any other modules with entities/models

**1.3. Document Findings:**

Create a discovery document `migration-docs/branches/VALIDATION_PHASE2_DISCOVERY.md` that lists:
- All entities found that need validation
- All controllers with manual validation
- All validation messages currently hardcoded
- Priority order for migration

---

### 2. MESSAGE EXTRACTION (NO HARDCODED MESSAGES):

**⚠️ CRITICAL RULE**: Extract ALL hardcoded validation messages into a central file. NO hardcoded English strings in attributes!

**NOTE**: This is message extraction only - actual translation integration (using `__()` function or translator) will be handled in a later phase. For now, just move hardcoded messages to the message file and reference them by key.

**2.1. Create Validation Messages File:**

**Location**: `app/system/languages/en_US/validation.php` (or `en_GB/validation.php` if preferred)

**Purpose**: Store all validation messages in one place for future translation integration. The actual translation integration will be handled in a later phase.

**Format** (PHP array):

```php
<?php

return [
    // Generic validation messages
    'validation.required' => 'This field is required.',
    'validation.email' => 'This value is not a valid email address.',
    'validation.url' => 'This value is not a valid URL.',
    'validation.min_length' => 'This value is too short. It should have {{ limit }} or more characters.',
    'validation.max_length' => 'This value is too long. It should have {{ limit }} or fewer characters.',
    'validation.length' => 'This value should be between {{ min }} and {{ max }} characters.',
    'validation.unique' => 'This value already exists.',
    
    // User module (for reference - already migrated)
    'validation.user.username_required' => 'Username is required.',
    'validation.user.username_length' => 'Username must be between {{ min }} and {{ max }} characters.',
    'validation.user.email_required' => 'Email is required.',
    'validation.user.email_invalid' => 'Email is invalid.',
    
    // Site module
    'validation.node.slug_required' => 'Slug is required.',
    'validation.node.slug_invalid' => 'Invalid slug.',
    'validation.node.title_required' => 'Title is required.',
    'validation.node.link_required' => 'Link is required. Please specify a valid URL or route.',
    'validation.page.title_required' => 'Page title is required.',
    
    // Widget module
    'validation.widget.title_required' => 'Widget title is required.',
    'validation.widget.title_empty' => 'Widget title cannot be empty.',
    
    // Add more as you discover entities...
];
```

**2.2. Use Message Keys in Attributes:**

**WRONG** (hardcoded):
```php
#[Assert\NotBlank(message: 'Title is required.')]
public ?string $title = null;
```

**CORRECT** (message key - can be translated later):
```php
#[Assert\NotBlank(message: 'validation.node.title_required')]
public ?string $title = null;
```

**2.3. Extract Messages from Existing Code:**

When migrating entities, extract any hardcoded validation messages from:
- Manual validation in controllers (`App::abort(400, __('...'))` calls)
- Old `validate()` methods (if still present)
- Any other validation-related strings

Move them to `validation.php` with appropriate keys, then reference the keys in your attributes.

---

### 3. MIGRATION STRATEGY PER MODULE:

**For each module/entity found:**

**3.1. Entity Updates:**

1. **Add Symfony Validator Attributes** to entity properties:
   - Import: `use Symfony\Component\Validator\Constraints as Assert;`
   - Use message keys in `message` parameters: `message: 'validation.entity.field_required'`
   - Add appropriate constraints: `#[Assert\NotBlank]`, `#[Assert\Length]`, `#[Assert\Email]`, etc.
   - Use `#[PagekitAssert\Unique]` for database uniqueness checks

2. **Mark ORM Annotations** with TODO:
   ```php
   /**
    * @Column
    */
   // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
   #[Assert\NotBlank(message: 'validation.entity.field_required')]
   public ?string $field = null;
   ```

3. **Remove manual validation methods** if they exist (Rule #4: DELETE OVER WRAP)

**3.2. Controller Updates:**

1. **Add ValidatesRequestTrait** to controller:
   ```php
   use Pagekit\System\Controller\ValidatesRequestTrait;
   
   class MyApiController
   {
       use ValidatesRequestTrait;
       // ...
   }
   ```

2. **Replace manual validation** with Symfony Validator:
   
   **BEFORE** (manual validation):
   ```php
   if (empty($data['title'])) {
       App::abort(400, __('Widget title empty.'));
   }
   $widget->save($data);
   ```
   
   **AFTER** (Symfony Validator):
   ```php
   $widget->save($data);
   
   // Validate using Symfony Validator
   if ($errorResponse = $this->validate($widget)) {
       return $errorResponse;
   }
   
   $widget->save();
   ```
   
   OR use `validateOrFail()` if you prefer exception handling:
   ```php
   $widget->save($data);
   $this->validateOrFail($widget);
   $widget->save();
   ```

3. **Remove all manual validation checks** (Rule #4: DELETE OVER WRAP):
   - Remove `if (empty(...))` checks
   - Remove `App::abort(400, ...)` calls
   - Remove `throw new Exception(...)` for validation

**3.3. Add Messages to File:**

For each validation message added, add corresponding entry to `app/system/languages/en_US/validation.php` (or `en_GB/validation.php`). Extract hardcoded messages from controllers and old validation code.

---

### 4. IMPLEMENTATION STEPS:

**4.1. Discovery Phase:**

1. Search for all entities/models requiring validation
2. Search for all controllers with manual validation
3. Create discovery document
4. Create your own to-do checklist

**4.2. Message File Setup:**

1. Create `app/system/languages/en_US/validation.php` (or `en_GB/validation.php`)
2. Add all validation message keys (start with generic, then module-specific)
3. Extract hardcoded messages from existing code into this file

**4.3. Migration Per Module:**

For each module found (prioritize by complexity/usage):

1. **Entity**: Add validation attributes with message keys from `validation.php`
2. **Controller**: Add `ValidatesRequestTrait`, replace manual validation
3. **Messages**: Add extracted messages to `validation.php`
4. **Test**: Verify HTTP status codes (curl checks - 200 OK, no 500 errors)
5. **Commit**: Create atomic commit per module (following Conventional Commits)

**Recommended Order**:

1. **Site Module** (`Node`, `Page` entities) - Likely has manual validation in `NodeApiController`
2. **Widget Module** (`Widget` entity) - Likely has manual validation in `WidgetApiController`
3. **Comment Module** (if exists) - Check for Comment entity
4. **Any other modules** discovered

---

### 5. EXAMPLE: Site Module Migration

**5.1. Entity: Node.php**

```php
<?php

declare(strict_types=1);

namespace Pagekit\Site\Model;

use Pagekit\System\Validator\Constraints as PagekitAssert;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @Entity(tableClass="@system_node")   // TODO: Must be refactored in Step 1.14
 */
class Node implements NodeInterface, \JsonSerializable
{
    // ... existing traits ...

    /** 
     * @Column(type="string")
     * TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
     */
    #[Assert\NotBlank(message: 'validation.node.slug_required')]
    #[Assert\Regex(
        pattern: '/^[a-z0-9\-_]+$/',
        message: 'validation.node.slug_invalid'
    )]
    public ?string $slug = null;

    /**
     * @Column(type="string")
     * TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
     */
    #[Assert\NotBlank(message: 'validation.node.title_required')]
    #[Assert\Length(max: 255)]
    public ?string $title = null;

    /**
     * @Column(type="string")
     * TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
     */
    #[Assert\Length(max: 500, message: 'validation.node.link_max_length')]
    public ?string $link = null;

    // ... rest of properties ...
}
```

**5.2. Controller: NodeApiController.php**

```php
<?php

namespace Pagekit\Site\Controller;

use Pagekit\Application as App;
use Pagekit\Site\Model\Node;
use Pagekit\System\Controller\ValidatesRequestTrait;
use function Pagekit\__;

/**
 * @Access("site: manage site")
 */
class NodeApiController
{
    use ValidatesRequestTrait;

    /**
     * @Route("/", methods="POST")
     * @Route("/{id}", methods="POST", requirements={"id"="\d+"})
     */
    public function saveAction($id = 0, $data = null): array
    {
        // ... get data from request ...

        if (!$node = Node::find($id)) {
            $node = Node::create();
            unset($data['id']);
        }

        // Generate slug from title if not provided (business logic, not validation)
        $slug = $data['slug'] ?? '';
        $title = $data['title'] ?? '';
        
        if (!$data['slug'] = App::filter($slug ?: $title, 'slugify')) {
            // This is business logic, could be moved to validation or kept here
            // If kept here, consider making it a validation rule instead
        }

        $node->save($data);

        // Validate using Symfony Validator (REPLACES manual validation)
        if ($errorResponse = $this->validate($node)) {
            return $errorResponse;
        }

        $node->save();

        return ['message' => 'success', 'node' => $node];
    }

    // ... other methods ...
}
```

**5.3. Messages: validation.php**

```php
<?php

return [
    // ... existing messages ...
    
    // Site module
    'validation.node.slug_required' => 'Slug is required.',
    'validation.node.slug_invalid' => 'Invalid slug. Only lowercase letters, numbers, hyphens and underscores are allowed.',
    'validation.node.title_required' => 'Title is required.',
    'validation.node.link_max_length' => 'Link cannot exceed {{ limit }} characters.',
];
```

---

### 6. DOCUMENTATION:

**Update VALIDATION_SYSTEM.md:**

Update `migration-docs/branches/VALIDATION_SYSTEM.md` with:
- List of all migrated modules
- Message file structure and usage
- Examples for each migrated module
- Message key naming conventions

---

### 7. SUCCESS CRITERIA:

**Discovery:**

- [ ] All entities requiring validation are identified and documented
- [ ] All controllers with manual validation are identified
- [ ] Discovery document created

**Messages:**

- [ ] Message file created: `app/system/languages/en_US/validation.php` (or `en_GB/validation.php`)
- [ ] All hardcoded validation messages extracted to message file
- [ ] NO hardcoded validation messages in attributes (use message keys)

**Migration (for each module):**

- [ ] Entity has Symfony Validator attributes with translation keys
- [ ] ORM annotations marked with TODO comments (Rule #5)
- [ ] Controller uses `ValidatesRequestTrait`
- [ ] All manual validation removed (Rule #4: DELETE OVER WRAP)
- [ ] Messages added to `validation.php`

**Documentation:**

- [ ] `VALIDATION_SYSTEM.md` updated
- [ ] All TODO comments in place (Rule #5: MANDATORY FLAGGING)

**Overall:**

- [ ] All discovered modules migrated
- [ ] No manual validation remains in controllers
- [ ] All validation messages extracted to message file (no hardcoded strings)
- [ ] All HTTP status checks pass (web/admin return 200/302, not 500)

---

### 8. COMMITS & CHANGELOG:

**Follow Conventional Commits:**

- `feat(validation): add translation support for validation messages`
- `feat(site): migrate Node entity to Symfony Validator`
- `feat(widget): migrate Widget entity to Symfony Validator`
- `refactor(site): replace manual validation with ValidatesRequestTrait in NodeApiController`
- etc.

**Update CHANGELOG-2025.md:**

Update the existing "Symfony Validator Integration" entry with Phase 2 completion:
- List all additional modules migrated
- Document message file creation
- List breaking changes (if any)

---

### 9. ROLLBACK PLAN:

If something breaks:

1. Revert the problematic commit
2. Verify system works again
3. Fix the issue and recommit

---

## 📝 Notes:

- **Self-Discovery**: You MUST find all entities/modules yourself. Don't assume the list is complete.
- **Message Extraction**: Extract all hardcoded messages to `validation.php` file (actual translation integration comes later).
- **Atomic Commits**: Commit each module separately for easier rollback.
- **HTTP Status Tests Only**: You can only test HTTP status codes (200/302 OK, no 500 errors). No full functionality tests (login, save, etc.).
- **Frontend Validation**: The .vue frontend validation is still active and separate - it will be migrated in a later phase.
- **Documentation**: Document everything for future reference.
- **No Hardcoded Messages**: ALL validation messages MUST be extracted to message file (use message keys in attributes).

---

## 🎯 Key Differences from Phase 1:

1. **Self-Discovery**: You must find all modules yourself (not just User)
2. **Message Extraction**: Extract hardcoded messages to file (translation integration is deferred)
3. **Multiple Modules**: Migrate all discovered modules, not just one
4. **To-Do List**: Create your own structured checklist to track progress
5. **Limited Testing**: Only HTTP status checks, no functional tests

---

**START BY CREATING YOUR TO-DO CHECKLIST, THEN BEGIN DISCOVERY!**
