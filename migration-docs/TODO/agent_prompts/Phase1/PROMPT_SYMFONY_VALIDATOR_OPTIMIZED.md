## TASK: Integrate Symfony Validator (Step 1.13 - Hybrid Mode)

### CONTEXT:

We are introducing Symfony Validator 6.4 with PHP 8 Attributes.

**CRITICAL CONTEXT**: The system still relies on `doctrine/annotations` for ORM mapping (Step 1.14 is pending).

We must run a **HYBRID setup**: Attributes for Validation, Annotations for ORM.

**Current State**:

- ✅ Symfony 6.4 components already integrated
- ✅ PHP 8.2+ with strict types
- ❌ Symfony Validator NOT installed
- ✅ Doctrine Annotations used for ORM (`@Entity`, `@Column`, `@Id`)
- ✅ Manual validation in `validate()` methods (e.g., `User::validate()`)
- ✅ Services registered in module `index.php` files via `$app['service'] = function($app) {...}`

**REFERENCE RULES**:

- Follow `pagekit-context.mdc` (Strict types, No WordPress, No Laravel)
- Follow `pagekit-files.mdc` (PHP 8.2+ standards)

---

### 💀 AGGRESSIVE MODERNIZATION RULES (NO MERCY FOR LEGACY):

1. NO COMPATIBILITY LAYERS: Do not create "Shim" classes or wrappers just to support old calling patterns.
2. NO ADAPTERS: If a method signature changes, update all usages in the code immediately. Do not create adapters.
3. BREAKING CHANGES ALLOWED: It is explicitly allowed to break the internal API if it leads to cleaner, stricter PHP 8 code.
4. DELETE OVER WRAP: If old logic conflicts with the new Symfony Validator, DELETE the old logic. Do not try to merge/wrap it.
5. MANDATORY FLAGGING: If a legacy fallback/workaround is absolutely unavoidable (e.g. to prevent a fatal crash), you MUST mark it with `// TODO: Must be refactored`. Silent workarounds are FORBIDDEN.

### 0. 🛑 SAFETY CHECKS (CRITICAL):

**AFTER EVERY SINGLE CHANGE:**

- Run: `php pagekit setup` (console must work)
- Test web: `curl http://localhost:8000` (MUST return 200, not 500!)
- Test admin: `curl http://localhost:8000/admin` (must load and redirect to http://localhost:8000/admin/login)
- **IF ANY TEST FAILS → STOP AND FIX BEFORE CONTINUING!**

**Before starting:**

- Verify `symfony/validator` is NOT in `composer.json` (it will be added)
- Check that `doctrine/annotations` is present (required for ORM)

---

### 1. PREPARATION:

- Create new branch: `feature/symfony-validator` or custom one from `develop`
- Create `migration-docs/branches/VALIDATION_SYSTEM.md` to document changes
- Analyze current validation patterns:
  - Check `app/system/modules/user/src/Model/User.php::validate()`
  - Check `app/system/modules/user/src/Controller/UserApiController.php::saveAction()`
  - Document all manual validations found

---

### 2. INSTALLATION:

**2.1. Add Dependency:**

```bash
composer require symfony/validator
```

**2.2. Create ValidatorServiceProvider:**

Create `app/system/src/ValidatorServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Pagekit\System;

use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ValidatorServiceProvider
{
    public static function register($app): void
    {
        $app['validator'] = function ($app): ValidatorInterface {
            $builder = Validation::createValidatorBuilder();

            // CRITICAL: Enable Attribute support for Validation
            $builder->enableAttributeMapping();

            // Optional: Add custom constraint validators if needed
            // $builder->addMethodMapping('loadValidatorMetadata');

            return $builder->getValidator();
        };
    }
}
```

**2.3. Register Service:**

In `app/system/index.php`, add to the `'main'` function or create a new module file:

```php
'boot' => function ($event, $app) {
    \Pagekit\System\ValidatorServiceProvider::register($app);
    // ... existing boot code ...
}
```

**OR** register directly in `app/system/src/SystemModule.php` in the `main()` method:

```php
public function main($app): void
{
    ValidatorServiceProvider::register($app);
    // ... existing code ...
}
```

**VERIFY**: After registration, test that `$app['validator']` returns a `ValidatorInterface` instance.

---

### 3. THE "HYBRID" ENTITY STRATEGY (CRITICAL):

**⚠️ CRITICAL RULE**: You will modify Entity classes (e.g., `User.php`).

- ✅ **ADD**: `#[Assert\NotBlank]`, `#[Assert\Email]`, `#[Assert\Length]` attributes
- ⚠️ **TEMPORARY**: `/** @ORM\... */` or `/** @Entity */` annotations MUST stay until Step 1.14 (ORM Attributes migration)
  - Mark with `// TODO: Must be refactored in Step 1.14 (ORM Attributes migration)`
- ✅ **REPLACE**: Remove existing `validate()` methods and replace with Symfony Validator
  - Old manual validation code should be removed completely
  - Use Symfony Validator attributes instead
  - **IMPORTANT**: `validate()` is called in multiple controllers (`UserApiController`, `RegistrationController`, `ProfileController`)
  - **SOLUTION**: Replace ALL `$user->validate()` calls in controllers with `$this->validate($user)` (using ValidatesRequestTrait)
  - **CRITICAL**: Following Rule #4 (DELETE OVER WRAP), you MUST update all controller calls immediately. Do NOT create a compatibility wrapper.
  - **EMERGENCY ONLY**: If updating all calls is impossible in one step (e.g., fatal error prevention), create a temporary bridge method ONLY with explicit deprecation:
    ```php
    /**
     * @deprecated This method is a temporary bridge. Replace all $user->validate() calls with Symfony Validator in controllers.
     * TODO: Must be refactored - Remove this method after all controllers are updated (Rule #4: DELETE OVER WRAP)
     */
    public function validate(): bool
    {
        $validator = \Pagekit\Application::getInstance()['validator'];
        $violations = $validator->validate($this);

        if (count($violations) > 0) {
            $firstViolation = $violations[0];
            throw new \Pagekit\Application\Exception($firstViolation->getMessage());
        }

        return true;
    }
    ```
  - **NOTE**: This temporary bridge violates Rule #1 (NO COMPATIBILITY LAYERS) and Rule #4 (DELETE OVER WRAP). It MUST be removed immediately after all controller calls are updated. Mark with `@deprecated` and `// TODO: Must be refactored`.

**Example of a correct Hybrid Entity:**

```php
<?php

declare(strict_types=1);

namespace Pagekit\User\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * @Entity(tableClass="@system_user")   // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
 */
class User implements UserInterface, \JsonSerializable
{
    /**
     * @Column(type="integer") @Id        // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
     */
    public ?int $id = null;

    /**
     * @Column                              // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
     */
    #[Assert\NotBlank(message: "Username is required.")]
    #[Assert\Length(min: 3, max: 255)]
    public ?string $username = '';

    /**
     * @Column                              // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
     */
    #[Assert\NotBlank(message: "Email is required.")]
    #[Assert\Email(message: "Email is invalid.")]
    public ?string $email = '';

    // ... rest of properties ...
}
```

**Target Entities for Validation:**

1. `app/system/modules/user/src/Model/User.php` (Priority 1)
2. `app/system/modules/user/src/Model/Role.php` (Priority 2)
3. Other entities as needed

---

### 4. CREATE VALIDATION TRAIT (Vue-Bridge):

**4.1. Create ValidatesRequestTrait:**

Create `app/system/src/Controller/ValidatesRequestTrait.php`:

```php
<?php

declare(strict_types=1);

namespace Pagekit\System\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

trait ValidatesRequestTrait
{
    protected function validate($object, ?ValidatorInterface $validator = null): ?JsonResponse
    {
        if ($validator === null) {
            $validator = \Pagekit\Application::getInstance()['validator'];
        }

        $violations = $validator->validate($object);

        if (count($violations) > 0) {
            return $this->validationErrorResponse($violations);
        }

        return null; // Validation passed
    }

    protected function validationErrorResponse(ConstraintViolationListInterface $violations): JsonResponse
    {
        $errors = [];

        foreach ($violations as $violation) {
            $propertyPath = $violation->getPropertyPath();
            $message = $violation->getMessage();

            if (!isset($errors[$propertyPath])) {
                $errors[$propertyPath] = [];
            }

            $errors[$propertyPath][] = $message;
        }

        return new JsonResponse([
            'error' => true,
            'message' => 'Validation failed',
            'errors' => $errors
        ], 400);
    }
}
```

**4.2. Usage in Controller:**

Example for `UserApiController::saveAction()`:

```php
use Pagekit\System\Controller\ValidatesRequestTrait;

class UserApiController
{
    use ValidatesRequestTrait;

    public function saveAction($id = 0)
    {
        // ... get user data ...

        $user = User::find($id) ?: User::create();
        $user->save($data);

        // Validate using Symfony Validator (REPLACES old $user->validate() call)
        if ($errorResponse = $this->validate($user)) {
            return $errorResponse;
        }

        // Save the user (validation passed)
        $user->save();

        return ['message' => 'success', 'user' => $user];
    }
}
```

**IMPORTANT**:

- Remove any calls to `$user->validate()` (old method is deleted)
- Remove any manual validation checks (e.g., `if (empty($email))`)
- Use Symfony Validator for ALL validation

---

### 5. IMPLEMENTATION STEPS:

**5.1. Start with User Entity:**

- Add validation attributes to `User.php` properties
- **MANDATORY (Rule #4: DELETE OVER WRAP)**: Remove `validate()` method completely
  - Replace ALL `$user->validate()` calls in controllers with `$this->validate($user)` (using ValidatesRequestTrait)
  - Update all controllers in the same commit (Rule #2: NO ADAPTERS)
  - Do NOT create a compatibility wrapper (Rule #1: NO COMPATIBILITY LAYERS)
- **EMERGENCY ONLY**: If updating all calls causes fatal errors, create a temporary bridge method with explicit deprecation:
  ```php
  /**
   * @deprecated Temporary bridge - violates Rule #1 and #4. Remove after controller updates.
   * TODO: Must be refactored - Replace all $user->validate() calls with Symfony Validator in controllers
   */
  public function validate(): bool
  {
      $validator = \Pagekit\Application::getInstance()['validator'];
      $violations = $validator->validate($this);

      if (count($violations) > 0) {
          $firstViolation = $violations[0];
          throw new \Pagekit\Application\Exception($firstViolation->getMessage());
      }

      return true;
  }
  ```
  - This bridge MUST be removed in the same PR after controllers are updated
- Mark ORM annotations with `// TODO: Must be refactored in Step 1.14 (ORM Attributes migration)` (Rule #5: MANDATORY FLAGGING)
- Test that Symfony Validator works correctly

**5.2. Update Controllers (Rule #2: NO ADAPTERS - Update all usages immediately):**

- **CRITICAL**: Update ALL controllers in the same commit. Do NOT leave any `$user->validate()` calls.
- **UserApiController::saveAction()**:
  - Add `ValidatesRequestTrait`
  - Replace `$user->validate()` (line 212) with `$this->validate($user)` BEFORE saving
  - Remove any manual validation checks (Rule #4: DELETE OVER WRAP)
  - Return JSON errors on validation failure
- **RegistrationController::registerAction()**:
  - Replace `$user->validate()` (line 81) with `$this->validate($user)` (add ValidatesRequestTrait)
  - Remove any manual validation checks
  - Handle validation errors appropriately
- **ProfileController::saveAction()**:
  - Replace `$user->validate()` (line 69) with `$this->validate($user)` (add ValidatesRequestTrait)
  - Remove any manual validation checks
  - Handle validation errors appropriately
- **Rule #4 Compliance**: After updating all controllers, the `validate()` method in User.php MUST be deleted
- Test with invalid data (empty email, invalid username, etc.)

**5.3. Verify Integration:**

- Ensure ORM annotations still work (entities load correctly) - they must stay until Step 1.14
- Ensure no conflicts between Attribute-based Validation and Annotation-based ORM
- Ensure all validation logic is now handled by Symfony Validator (no manual checks remain)

---

### 6. CUSTOM CONSTRAINTS (if needed):

If you need custom validations (e.g., Unique constraint with database check):

Create `app/system/src/Validator/Constraints/Unique.php`:

```php
<?php

declare(strict_types=1);

namespace Pagekit\System\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Unique extends Constraint
{
    public string $message = 'This value already exists.';
    public string $table;
    public string $column;

    public function __construct(
        string $table,
        string $column,
        ?string $message = null,
        ?array $groups = null,
        mixed $payload = null
    ) {
        parent::__construct([], $groups, $payload);
        $this->table = $table;
        $this->column = $column;
        $this->message = $message ?? $this->message;
    }
}
```

Create validator: `app/system/src/Validator/Constraints/UniqueValidator.php`:

```PHP
<?php

declare(strict_types=1);

namespace Pagekit\System\Validator\Constraints;

use Pagekit\Application as App;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class UniqueValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Unique) {
            throw new UnexpectedTypeException($constraint, Unique::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        // Use Pagekit's static DB access since DI is not yet fully modernized for Validators
        $db = App::db();

        // Check if record exists (using Pagekit's QueryBuilder - DBAL 3.x compatible)
        $query = $db->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($constraint->table)
            ->where("{$constraint->column} = :value", ['value' => $value]);

        // Handle update context (exclude current record)
        // TODO: Must be refactored - Implement context awareness for updates
        // For now, this will trigger on updates if the value already exists.
        // Options:
        // 1. Use validation groups (skip Unique on updates)
        // 2. Pass entity ID via constraint payload and exclude it from query
        // 3. Handle uniqueness check in Controller before validation

        // Pagekit's QueryBuilder uses execute() which returns Result (DBAL 3.x)
        $count = (int) $query->execute()->fetchOne();

        if ($count > 0) {
            $this->context->buildViolation($constraint->message)
                ->addViolation();
        }
    }
}
```

---

### 7. VERIFICATION:

**Test 1: Does the page load?**

- Ensures AnnotationReader didn't crash
- Ensures no conflicts between Attributes and Annotations

**Test 2: Send empty data via POST**

- Expect 400 Bad Request JSON
- Expect proper error structure: `{ "error": true, "errors": { "email": ["Email is required."] } }`

**Test 3: Save valid data**

- Expect success (200 OK)
- Data should be saved correctly

**Test 4: ORM still works**

- Load entities: `User::find(1)` should work
- Relations should work: `$user->roles` should work
- Database queries should work

---

### 8. DOCUMENTATION:

Update the `migration-docs/branches/VALIDATION_SYSTEM.md`:

- Document the hybrid approach (Attributes + Annotations)
- Document all validation constraints used
- Document how to add new constraints
- Document migration path from manual validation to Symfony Validator
- Include examples for common use cases

---

### 9. CLEANUP & REFACTORING NOTES:

- ✅ **DONE**: Old `validate()` methods are removed and replaced with Symfony Validator
- ✅ **DONE**: Manual validation checks in controllers are removed
- ⚠️ **TEMPORARY**: ORM annotations remain (marked with TODO comments) until Step 1.14

---

### SUCCESS CRITERIA:

**Installation:**

- [ ] `symfony/validator` is in `composer.json` "require" section
- [ ] `$app['validator']` service is registered and accessible
- [ ] `ValidatesRequestTrait` is created and working

**Entity Updates (Rule #4: DELETE OVER WRAP):**

- [ ] `User` entity utilizes `#[Assert\...]` attributes
- [ ] Old `validate()` method is **REMOVED** from `User.php` (no compatibility layer)
- [ ] All manual validation code in `User::validate()` is deleted
- [ ] Doctrine ORM Annotations are preserved (marked with TODO comments per Rule #5) until Step 1.14

**Controller Updates (Rule #2: NO ADAPTERS):**

- [ ] ALL `$user->validate()` calls are replaced with `$this->validate($user)` in controllers
- [ ] `UserApiController::saveAction()` uses Symfony Validator (no manual validation)
- [ ] `RegistrationController::registerAction()` uses Symfony Validator
- [ ] `ProfileController::saveAction()` uses Symfony Validator
- [ ] All manual validation checks in controllers are removed (Rule #4)
- [ ] No compatibility wrappers or adapters created (Rule #1)

**Functionality:**

- [ ] Invalid requests return clean JSON errors: `{ "error": true, "errors": {...} }`
- [ ] Valid requests still work (200 OK)
- [ ] ORM still works (entities load, relations work)
- [ ] All safety checks pass (`php pagekit setup`, web, admin)

**Documentation:**

- [ ] Documentation created in `VALIDATION_SYSTEM.md`
- [ ] All TODO comments are in place (Rule #5: MANDATORY FLAGGING)

---

### ROLLBACK PLAN:

If something breaks:

1. Revert the branch
2. Remove `symfony/validator` from `composer.json`
3. Run `composer update`
4. Verify system works again

---

## 📝 Notes:

- **Aggressive Modernization**: Follow the 5 rules strictly - no mercy for legacy code
- **Hybrid Mode**: ORM annotations are temporary until Step 1.14 (ORM Attributes migration)
  - All ORM annotations must be marked with `// TODO: Must be refactored in Step 1.14` (Rule #5)
- **Clean Migration**: Old `validate()` methods are completely removed, not kept for compatibility (Rule #4)
- **No Legacy Code**: All manual validation is replaced with Symfony Validator (Rule #4)
- **Breaking Changes**: Internal API changes are allowed if they lead to cleaner PHP 8 code (Rule #3)
- **Incremental**: Start with User entity, then expand to others
- **Test-Driven**: Test after every change
- **Documentation**: Document everything for future reference
