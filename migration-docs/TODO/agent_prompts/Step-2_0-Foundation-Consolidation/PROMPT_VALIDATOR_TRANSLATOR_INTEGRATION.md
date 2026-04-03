# Step 2.0.2: Validator-Translator Integration

**ROADMAP:** 2.0.2. GitHub Issue: #146. Reference: `@ROADMAP.md`.

---

## CONTEXT

**Previous Work:**
- ✅ Step 1.13 (Issue #133): Symfony Validator introduced with PHP 8 Attributes (`#[Assert\...]`)
- ✅ Step 2.0.1 (Issue #145): PSR-11 Container Vollmodernisierung — full DI, no static access
- Validation messages are currently **raw keys** (e.g. `validation.user.username_required`) in API responses because the Validator has no Translator connected

**Current State:**
- `ValidatorServiceProvider` builds the validator with `enableAttributeMapping()` only — **no Translator, no TranslationDomain**
- Translation files exist as `validation.php` under domain `validation` (Pagekit's loader derives domain from filename)
- Symfony standard expects domain `validators` — file must be renamed
- 8 models use `#[Assert\...]` with `message: 'validation.xxx.yyy'` keys
- `ValidatesRequestTrait` returns `$violation->getMessage()` — currently returns the raw key, not the translated string
- `ExtensionTranslateCommand` cannot extract `#[Assert\... message: '...']` keys from PHP 8 Attributes

**Goal:** Connect the Symfony Validator to Pagekit's Translator so `$violation->getMessage()` returns locale-aware translated strings instead of raw message keys.

---

## 0. SAFETY CHECKS (CRITICAL)

**Test environment:** From workspace root. PHPUnit: `./app/vendor/bin/phpunit`. Console: `php pagekit list`.

**AFTER EVERY LOGICAL CHANGE (per checklist step):**
```bash
./app/vendor/bin/phpunit
```
**IF ANY FAILS → STOP AND FIX!**

**Before starting:** Ensure you are on actual `branch` with all PSR-11 work (2.0.1) merged.

---

## 1. RENAME TRANSLATION FILES (`validation.php` → `validators.php`)

Symfony Validator's standard translation domain is `validators`. Pagekit's `IntlModule` derives the domain from the filename (`basename($file, '.php')`). Renaming the files aligns Pagekit with Symfony's convention.

### 1.1. System validation translations

```
app/system/languages/en_US/validation.php → app/system/languages/en_US/validators.php
```

Check for other locales — if `validation.php` exists in other locale directories (e.g. `de_DE`), rename those too:
```bash
rg -l 'validation\.php' app/system/languages/ --type-not php
# Also:
find app/system/languages/ -name 'validation.php'
```

### 1.2. Blog package validation translations

```
packages/pagekit/blog/languages/en_US/validation.php → packages/pagekit/blog/languages/en_US/validators.php
```

Same locale check as above for other blog language directories.

### 1.3. Verify no other code references the old filename

```bash
rg 'validation\.php' app/ packages/ --type php
```

If any code explicitly loads `validation.php` by name, update the reference. The `IntlModule::loadLocale()` uses glob (`*.php`) so it auto-discovers the renamed file — no changes needed there.

---

## 2. CONNECT TRANSLATOR TO VALIDATOR

### 2.1. Update `ValidatorServiceProvider`

**File:** `app/system/src/ValidatorServiceProvider.php`

Add `setTranslator()` and `setTranslationDomain()` to the validator builder:

```php
public static function register($app): void
{
    $app->set('validator', function ($app): ValidatorInterface {
        $builder = Validation::createValidatorBuilder();
        $builder->enableAttributeMapping();

        $builder->setTranslator($app->get('translator'));
        $builder->setTranslationDomain('validators');

        return $builder->getValidator();
    });
}
```

**Technical notes:**
- Pagekit's `translator` service is a `Symfony\Component\Translation\Translator` which implements `Symfony\Contracts\Translation\TranslatorInterface` — the interface required by `ValidatorBuilder::setTranslator()`.
- Both `validator` and `translator` are lazy-registered (factory closures). The `translator` factory is executed when first accessed. At the time `validator` is first used, `translator` must already be registered (not necessarily instantiated). Since both are registered during `boot`, order matters.

### 2.2. Verify boot order

**File:** `app/system/index.php`

The `ValidatorServiceProvider::register($app)` call happens in the `boot` event of `app/system`. The `translator` service is registered by `IntlModule` during its `main` callback (container registration phase, before boot).

Verify that `translator` is available when `validator` factory runs:
```bash
rg "set\('translator'" app/system/modules/intl/src/IntlModule.php
rg 'ValidatorServiceProvider::register' app/system/index.php
```

If `translator` is registered in `main` (container phase) and `validator` factory calls `$app->get('translator')` at runtime (not at registration time), the boot order is fine — the factory closure captures `$app` and resolves `translator` lazily on first validator access.

**⚠️ IMPORTANT:** If boot order is a problem (translator not yet registered when validator factory runs), wrap the translator access in a lazy pattern or move `ValidatorServiceProvider::register()` to after IntlModule boots. Document any boot order dependency.

---

## 3. VERIFY TRANSLATION KEY CONSISTENCY

The message keys in models must match the keys in the `validators.php` translation files.

### 3.1. Collect all Assert message keys from models

```bash
rg "message:\s*['\"]" app/system/modules/*/src/Model/*.php packages/*/src/Model/*.php --type php
```

**Known keys (system):**
- `validation.user.username_required`, `validation.user.username_invalid`, `validation.user.username_not_available`
- `validation.user.password_required`, `validation.user.email_required`, `validation.user.email_invalid`
- `validation.user.email_not_available`, `validation.user.url_invalid`, `validation.user.status_invalid`
- `validation.user.name_required`
- `validation.role.name_required`, `validation.role.priority_invalid`
- `validation.node.*` (parent_id_invalid, priority_invalid, slug_required, title_required, type_required, etc.)
- `validation.widget.title_required`, `validation.widget.type_required`

**Known keys (blog):**
- `validation.post.title_required`, `validation.post.slug_required`, `validation.post.user_required`
- `validation.comment.post_required`, `validation.comment.email_invalid`, `validation.comment.url_invalid`

### 3.2. Cross-reference with translation files

After renaming, read both `validators.php` files and verify every model key has a translation entry. Add any missing translations.

### 3.3. Constraints WITHOUT explicit message keys

Some `#[Assert\...]` attributes have no `message:` parameter (they use Symfony's built-in default messages like "This value should be positive."). These are translated by Symfony's internal `validators` catalogue. Verify that Symfony's built-in translations are loaded — `Symfony\Component\Validator\Resources\translations/validators.en.xlf` is bundled with the validator component and should auto-load.

If Symfony's built-in translations are NOT auto-loaded (because Pagekit bypasses Symfony's FrameworkBundle), the built-in defaults will remain in English. This is acceptable — the custom keys (e.g. `validation.user.*`) are more important.

---

## 4. VERIFY END-TO-END TRANSLATION

### 4.1. Manual verification (in test or debug)

After the integration, a validation violation on a User with a blank username should:
- **Before:** Return `validation.user.username_required` (raw key)
- **After:** Return `Username is required.` (or whatever the `validators.php` maps to)

### 4.2. Locale switching

If multiple locales are configured:
- Switching locale should return the validation message in the active locale
- If a locale has no `validators.php`, fallback to `en_US` (Symfony's standard fallback behavior)

---

## 5. (OPTIONAL) EXTEND `ExtensionTranslateCommand`

**File:** `app/console/src/Commands/ExtensionTranslateCommand.php`
**PHP AST:** `app/console/src/NodeVisitor/PhpNodeVisitor.php`

Currently, `extension:translate` extracts translation keys from `__()`, `_c()`, `->trans()`, and Vue templates. It does **not** extract `message:` parameters from PHP 8 Attributes.

### 5.1. Evaluate complexity

Adding AST extraction for `#[Assert\NotBlank(message: '...')]` requires:
- PHP AST traversal for Attribute nodes (not just FuncCall/MethodCall)
- Parsing named arguments in attribute constructors
- Filtering only `Symfony\Component\Validator\Constraints\*` attributes

### 5.2. Decision

If this is straightforward (e.g. using `PhpParser\Node\Attribute` visitor), implement it. If it requires significant refactoring of the command's extraction pipeline, defer with:
```
// TODO: Step 2.0.2 - Extract #[Assert\...] message keys (optional, low priority)
```

The validation keys are currently maintained manually in `validators.php` — this is acceptable for the small number of models.

---

## 6. WRITE TESTS

### 6.1. Unit test: Validator returns translated messages

Create a test that:
1. Boots the application (or mocks the container with `translator` + `validator` services)
2. Creates a User entity with an invalid state (e.g. blank username)
3. Validates the entity
4. Asserts `$violation->getMessage()` returns the translated string, NOT the raw key

### 6.2. Unit test: ValidatesRequestTrait returns translated JSON

Test that `validationErrorResponse()` contains human-readable messages:
```php
// Assert the JSON response contains translated messages
$this->assertStringNotContainsString('validation.user.', $response->getContent());
```

### 6.3. Unit test: Locale fallback

If feasible, test that:
- With `en_US` locale → English messages
- With missing locale → Falls back to `en_US`

---

## 7. UPDATE DOCUMENTATION

### 7.1. Update `ValidatorServiceProvider` docblock

Remove the "hybrid mode" comment from Step 1.13 (ORM has since been fully migrated to Attributes in Step 1.14). Update to reflect the Translator integration.

---

## SUCCESS CRITERIA

- `ValidatorServiceProvider` connects to `$app->get('translator')` with domain `validators`
- `validation.php` renamed to `validators.php` in all locale directories
- `$violation->getMessage()` returns translated strings (not raw keys like `validation.user.username_required`)
- Locale switching works for validation messages
- No regressions in User, Site, Widget, Blog validation flows
- All PHPUnit tests pass
- New tests verify translated validation messages
- `PHASE#2_MODERNISING.md` updated

---

## VALIDATION CHECKLIST

- [ ] `validation.php` renamed to `validators.php` (system + blog, all locales)
- [ ] `ValidatorServiceProvider` calls `setTranslator()` + `setTranslationDomain('validators')`
- [ ] Boot order verified (translator available when validator factory runs)
- [ ] All Assert message keys have corresponding entries in `validators.php`
- [ ] `$violation->getMessage()` returns human-readable translated text
- [ ] `ValidatesRequestTrait` responses contain translated messages
- [ ] New PHPUnit tests for translated validation messages
- [ ] All existing PHPUnit tests pass
- [ ] Documentation updated
