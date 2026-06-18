## ARCHITECT OUTPUT
- **Current Step (ROADMAP):** 2.0.1c
- **GitHub Issue:** #164
- **Scope:** All ArrayAccess reads, `App::service()` static calls, and `App::getInstance()->get()` calls in `app/system/`, `app/installer/`, `app/console/` — migrated to `$app->get()` (index.php/module files) or constructor injection (controllers, listeners, helpers, module classes).
- **Deferred:**
  - Step 2.0.1d — ArrayAccess WRITE removal (`$app['x'] = ...` → `$app->set()`), package migration
  - Step 2.0.1e — RouterTrait calls (`App::abort`, `App::redirect`, `App::forward`, `App::error`), EventTrait calls (`App::on`, `App::subscribe`, `App::trigger`), StaticTrait removal, Model repository pattern
- **Bridges:**
  - ArrayAccess WRITE (`$app['service'] = fn() => ...`) kept as-is: `// TODO: Must be refactored in Step 2.0.1d (Packages + ArrayAccess Removal)`
  - `App::abort()`, `App::redirect()`, `App::forward()`, `App::error()` kept: `// TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)`
  - `App::on()`, `App::subscribe()`, `App::trigger()` kept: `// TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)`
  - Model `App::getInstance()->get()` temporary pattern: `// TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e`
  - `intl/functions.php` and `intl/functions-pagekit-namespace.php` global function wrappers: `// TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)` — these are plain functions, not classes; cannot use constructor injection

---

### Checklist

**Step 1: Discovery + Branch Documentation**
- Create `migration-docs/branches/PSR11_CONTAINER_STAGE3.md`
- Run discovery commands, document file counts and categorization
- **Files:** `migration-docs/branches/PSR11_CONTAINER_STAGE3.md`
- **Commit:** `docs(migration): add Stage 3 branch documentation with discovery results`

**Step 2: System module index.php — ArrayAccess reads → `$app->get()`**
- Migrate all `$app['x']` READ access to `$app->get('x')` and `isset($app['x'])` to `$app->has('x')` in system module index.php files
- Keep `$app['x'] = ...` WRITE patterns as-is (mark with TODO for 2.0.1d)
- Within service factories (closures), replace `$app['x']` reads with `$app->get('x')`
- **Files:**
  - `app/system/index.php` (9 reads, 1 write)
  - `app/system/modules/theme/index.php` (10 reads)
  - `app/system/modules/widget/index.php` (13 reads, 2 writes)
  - `app/system/modules/view/index.php` (4 reads)
  - `app/system/modules/user/index.php` (2 reads)
  - `app/system/modules/site/index.php` (15 reads)
  - `app/system/modules/settings/index.php` (1 read)
  - `app/system/modules/editor/index.php` (4 reads)
  - `app/system/modules/mail/index.php` (5 reads, 4 writes)
  - `app/system/modules/finder/index.php` (4 reads, 1 write)
  - `app/system/modules/info/index.php` (1 read, 1 write)
  - `app/system/modules/content/index.php` (1 read, 1 write)
- **TODO-Spec:** `// TODO: Must be refactored in Step 2.0.1d (Packages + ArrayAccess Removal)` on remaining WRITE patterns
- **Commit:** `refactor(system): migrate module index.php ArrayAccess reads to PSR-11 get()`

**Step 3: System bootstrap + scripts — ArrayAccess reads → `$app->get()`**
- Migrate `$app['x']` reads in bootstrap and non-index files
- **Files:**
  - `app/system/app.php` (7 reads)
  - `app/system/scripts.php` (3 reads)
  - `app/system/src/ValidatorServiceProvider.php` (1 read)
  - `app/system/src/SystemModule.php` (12 reads)
  - `app/system/modules/theme/views/blank.php` (1 read)
  - `app/system/modules/user/widgets/login.php` (4 reads)
  - `app/system/modules/user/mails/approve.php` (1 read)
  - `app/system/modules/user/mails/welcome.php` (1 read)
  - `app/system/modules/user/mails/reset.php` (1 read)
  - `app/system/modules/user/mails/verification.php` (1 read)
  - `app/system/modules/site/views/widget-text.php` (1 read)
  - `app/system/modules/site/widgets/text.php` (1 read)
  - `app/system/modules/site/src/SiteModule.php` (5 reads)
  - `app/system/modules/user/src/UserModule.php` (2 reads)
  - `app/system/modules/intl/src/IntlModule.php` (1 read)
- **Commit:** `refactor(system): migrate bootstrap and module class ArrayAccess reads to PSR-11 get()`

**Step 4: System controllers — `App::service()` → constructor injection**
- Add constructor injection for all `App::service()` calls in system controllers
- Replace `App::getInstance()->get()` in controllers with constructor params
- Keep `App::abort()`, `App::redirect()` as-is (mark with TODO for 2.0.1e)
- **Files (25 controllers):**
  - `app/system/src/Controller/AdminController.php` (4 App:: calls)
  - `app/system/src/Controller/ExceptionController.php` (1)
  - `app/system/src/Controller/MigrationController.php` (1)
  - `app/system/modules/cache/src/Controller/CacheController.php` (2)
  - `app/system/modules/dashboard/src/Controller/DashboardController.php` (6 + 1 getInstance)
  - `app/system/modules/finder/src/Controller/FinderController.php` (9)
  - `app/system/modules/finder/src/Controller/StorageController.php` (1)
  - `app/system/modules/info/src/Controller/InfoController.php` (1)
  - `app/system/modules/intl/src/Controller/IntlController.php` (3)
  - `app/system/modules/intl/src/Controller/IntlApiController.php` (1)
  - `app/system/modules/mail/src/Controller/MailController.php` (5)
  - `app/system/modules/settings/src/Controller/SettingsController.php` (4 + 1 getInstance)
  - `app/system/modules/site/src/Controller/NodeController.php` (4)
  - `app/system/modules/site/src/Controller/NodeApiController.php` (11)
  - `app/system/modules/site/src/Controller/MenuApiController.php` (8)
  - `app/system/modules/site/src/Controller/PageController.php` (1)
  - `app/system/modules/user/src/Controller/AuthController.php` (15)
  - `app/system/modules/user/src/Controller/UserController.php` (8)
  - `app/system/modules/user/src/Controller/UserApiController.php` (12)
  - `app/system/modules/user/src/Controller/RoleApiController.php` (4)
  - `app/system/modules/user/src/Controller/ProfileController.php` (4)
  - `app/system/modules/user/src/Controller/RegistrationController.php` (18)
  - `app/system/modules/user/src/Controller/ResetPasswordController.php` (9 + 4 ArrayAccess + 2 isset)
  - `app/system/modules/widget/src/Controller/WidgetController.php` (4)
  - `app/system/modules/widget/src/Controller/WidgetApiController.php` (6)
- **TODO-Spec:** `// TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)` on remaining `App::abort()`, `App::redirect()` calls
- **Commit:** `refactor(system): migrate system controllers to constructor injection`

**Step 5: System listeners — `App::service()` → constructor injection via index.php**
- Add constructor params to listener classes; pass services from index.php instantiation
- **Files (listeners):**
  - `app/system/modules/user/src/Event/AuthorizationListener.php` (6 App:: calls)
  - `app/system/modules/user/src/Event/AccessListener.php` (6 App:: calls)
  - `app/system/modules/user/src/Event/LoginAttemptListener.php` (4 App:: calls)
  - `app/system/modules/view/src/Event/ResponseListener.php` (1 App:: call)
  - `app/system/modules/site/src/Event/MaintenanceListener.php` (4 App:: calls)
  - `app/system/modules/site/src/Event/NodesListener.php` (1 App:: call)
  - `app/system/modules/captcha/src/CaptchaListener.php` (13 App:: calls)
- **Files (index.php updated for listener instantiation):**
  - `app/system/modules/user/index.php`
  - `app/system/modules/view/index.php`
  - `app/system/modules/site/index.php`
  - `app/system/modules/captcha/` (find where listener is registered)
  - `app/system/index.php`
- **TODO-Spec:** `// TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)` on `App::abort()` in AccessListener, `App::redirect()` in MaintenanceListener
- **Commit:** `refactor(system): migrate system listeners to constructor injection`

**Step 6: System helpers + module classes — `App::service()` → constructor injection**
- Helpers and module classes that use `App::service()` need constructor injection; update their instantiation points
- **Files:**
  - `app/system/src/SystemMenu.php` (5 App:: calls)
  - `app/system/modules/widget/src/PositionHelper.php` (3 App:: calls)
  - `app/system/modules/site/src/MenuHelper.php` (2 App:: calls)
  - `app/system/modules/info/src/InfoHelper.php` (3 App:: + 4 getInstance calls)
  - `app/system/modules/view/src/Asset/FileLocatorAsset.php` (1 App:: call)
  - `app/system/modules/cache/src/CacheModule.php` (2 App:: + 2 getInstance calls)
  - `app/system/modules/dashboard/src/DashboardModule.php` (2 App:: calls)
  - `app/system/src/Validator/Constraints/UniqueValidator.php` (1 App:: call)
  - `app/system/modules/intl/src/IntlModule.php` (3 App:: calls)
  - `app/system/modules/site/src/SiteModule.php` (2 App:: calls)
  - `app/system/modules/user/src/UserModule.php` (1 App:: call)
- **Files (index.php / bootstrap updated for instantiation):**
  - Corresponding index.php files for each module
- **TODO-Spec:** `// TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)` on `App::on()`/`App::subscribe()` in UserModule, SiteModule, CacheModule
- **Commit:** `refactor(system): migrate helpers and module classes to constructor injection`

**Step 7: System model + intl functions — temporary workaround**
- Model files cannot use constructor injection; use `App::getInstance()->get()` pattern
- Global intl functions cannot use constructor injection; mark with TODO for 2.0.1e
- **Files:**
  - `app/system/modules/site/src/Model/Node.php` (2 App:: calls → `App::getInstance()->get()`)
  - `app/system/modules/intl/functions.php` (3 App:: calls — mark TODO)
  - `app/system/modules/intl/functions-pagekit-namespace.php` (2 App:: calls — mark TODO)
- **TODO-Spec:**
  - Model: `// TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e`
  - Functions: `// TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)`
- **Commit:** `refactor(system): apply temporary bridge for model and intl function static access`

**Step 8: Installer — ArrayAccess reads + `App::service()` → `$app->get()` + constructor injection**
- Migrate all `$app['x']` reads in installer
- Migrate `App::service()` in installer controllers to constructor injection
- Install scripts (`install.php`, `install-demo.php`) and PackageManager/PackageFactory use `App::service()` — apply appropriate pattern (constructor injection if instantiated from DI context, `$app->get()` if `$app` is in scope)
- **Files:**
  - `app/installer/app.php` (6 ArrayAccess reads)
  - `app/installer/index.php` (8 ArrayAccess reads, 1 write)
  - `app/installer/install.php` (9 App:: calls)
  - `app/installer/install-demo.php` (20 App:: calls)
  - `app/installer/src/Controller/InstallerController.php` (2 ArrayAccess + 1 App:: call)
  - `app/installer/src/Controller/PackageController.php` (11 App:: calls)
  - `app/installer/src/Controller/UpdateController.php` (3 App:: calls)
  - `app/installer/src/Package/PackageManager.php` (11 ArrayAccess + 12 App:: calls)
  - `app/installer/src/Package/PackageFactory.php` (1 App:: call)
- **TODO-Spec:** `// TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)` on remaining `App::abort()`, `App::redirect()` in PackageController/UpdateController
- **Commit:** `refactor(installer): migrate to PSR-11 get() and constructor injection`

**Step 9: Console — ArrayAccess reads + `App::service()` → `$app->get()` + constructor injection**
- Migrate all `$app['x']` reads in console
- Migrate `App::service()` in console commands
- **Files:**
  - `app/console/app.php` (8 ArrayAccess reads)
  - `app/console/src/Commands/SetupCommand.php` (1 ArrayAccess + 1 App:: call)
  - `app/console/src/Commands/ExtensionTranslateCommand.php` (1 ArrayAccess)
  - `app/console/src/Commands/SelfupdateCommand.php` (2 App::abort calls — deferred)
- **TODO-Spec:** `// TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)` on `App::abort()` in SelfupdateCommand
- **Commit:** `refactor(console): migrate to PSR-11 get() and constructor injection`

**Step 10: Validation + Documentation**
- Run all safety checks (PHPUnit, `php pagekit setup`, `php pagekit list`, curl)
- Verify no `$app['x']` READ access remains in scope (only WRITEs)
- Verify no `App::service()` in controllers/listeners (only models + deferred patterns)
- Update `migration-docs/branches/PSR11_CONTAINER_STAGE3.md` with final results
- **Commit:** `docs(migration): finalize Stage 3 documentation with validation results`

---

### Deferred Pattern Counts (for reference)

| Pattern | Area | Count | Deferred To |
|---------|------|-------|-------------|
| `App::abort()` | system controllers + listeners | ~52 | 2.0.1e |
| `App::redirect()` | system controllers + listeners | included above | 2.0.1e |
| `App::abort()`/`redirect()` | installer controllers | ~10 | 2.0.1e |
| `App::abort()` | console commands | ~2 | 2.0.1e |
| `App::on()`/`subscribe()`/`trigger()` | system modules | ~5 | 2.0.1e |
| `App::on()` | installer PackageManager | ~1 | 2.0.1e |
| `$app['x'] = ...` writes | system/installer index.php | ~11 | 2.0.1d |
| Model `App::` static | site Node model | 2 | 2.0.1e |
| intl global functions | functions.php + ns variant | 5 | 2.0.1e |
