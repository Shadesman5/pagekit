# PSR-11 Container Stage 3: System, Installer & Console

**ROADMAP Step:** 2.0.1c
**Branch:** `feature/psr11-stage3-system-installer-console`
**GitHub Issue:** #164
**Status:** Discovery

---

## Objective

Migrate all ArrayAccess READ call sites (`$app['x']`) to PSR-11 (`$app->get('x')`) and all `App::*()` static proxy calls to constructor injection (or `$app->get()` where `$app` is in scope) across `app/system/`, `app/installer/`, and `app/console/`.

### Migration Rules

| Pattern | Before | After |
|---------|--------|-------|
| READ | `$app['x']` | `$app->get('x')` |
| EXISTS | `isset($app['x'])` | `$app->has('x')` |
| WRITE | `$app['x'] = ...` | Keep as-is → Step 2.0.1d |
| Static proxy (controller/listener) | `App::module('x')` | Constructor injection |
| Static proxy (index.php/bootstrap) | `App::module('x')` | `$app->get('x')` |
| Static proxy (model) | `App::module('x')` | `App::getInstance()->get('x')` (temporary bridge) |
| `App::abort()` / `App::redirect()` | — | Keep as-is → Step 2.0.1e |
| `App::on()` / `App::subscribe()` / `App::trigger()` | — | Keep as-is → Step 2.0.1e |

---

## Discovery Summary

### Grand Totals

| Pattern | System | Installer | Console | **Total** |
|---------|--------|-----------|---------|-----------|
| `$app['x']` READ | 96 | 26 | 9 | **131** |
| `$app['x'] = ...` WRITE | 20 | 2 | 1 | **23** |
| `isset($app['x'])` | 4 | 2 | 0 | **6** |
| `App::*()` static calls | 322 | 107 | 3 | **432** |
| `App::getInstance()->get()` | 8 | 0 | 0 | **8** |

### Deferred Patterns (not migrated in Stage 3)

| Pattern | System | Installer | Console | Total | Deferred To |
|---------|--------|-----------|---------|-------|-------------|
| `App::abort()` | 39 | 10 | 2 | 51 | Step 2.0.1e |
| `App::redirect()` | 15 | 0 | 0 | 15 | Step 2.0.1e |
| `App::on()/subscribe()/trigger()` | 5 | 1 | 0 | 6 | Step 2.0.1e |
| `$app['x'] = ...` WRITE | 20 | 2 | 1 | 23 | Step 2.0.1d |
| **Deferred subtotal** | **79** | **13** | **3** | **95** |  |

### In-Scope Migrations

| Pattern | System | Installer | Console | **Total** |
|---------|--------|-----------|---------|-----------|
| `$app['x']` READ → `$app->get('x')` | 96 | 26 | 9 | **131** |
| `isset($app['x'])` → `$app->has('x')` | 4 | 2 | 0 | **6** |
| `App::*()` static → injection/get() | 264 | 83 | 1 | **348** |
| `App::getInstance()->get()` (existing) | 8 | 0 | 0 | **8** |
| **In-scope subtotal** | **372** | **111** | **10** | **493** |

---

## Categorization by Subdirectory

### 1. System Module index.php Files

| File | READs | WRITEs | isset |
|------|-------|--------|-------|
| `app/system/index.php` | 8 | 1 | 0 |
| `app/system/modules/theme/index.php` | 10 | 0 | 0 |
| `app/system/modules/widget/index.php` | 11 | 2 | 0 |
| `app/system/modules/view/index.php` | 4 | 0 | 0 |
| `app/system/modules/user/index.php` | 2 | 0 | 0 |
| `app/system/modules/site/index.php` | 15 | 0 | 0 |
| `app/system/modules/settings/index.php` | 1 | 0 | 0 |
| `app/system/modules/editor/index.php` | 4 | 0 | 0 |
| `app/system/modules/mail/index.php` | 1 | 4 | 0 |
| `app/system/modules/finder/index.php` | 4 | 1 | 0 |
| `app/system/modules/info/index.php` | 0 | 1 | 0 |
| `app/system/modules/content/index.php` | 0 | 1 | 0 |
| **Subtotal** | **60** | **10** | **0** |

### 2. System Bootstrap & Scripts

| File | READs | WRITEs |
|------|-------|--------|
| `app/system/app.php` | 6 | 1 |
| `app/system/scripts.php` | 3 | 0 |
| **Subtotal** | **9** | **1** |

### 3. System Module Classes & Helpers (`$app['x']` patterns)

| File | READs | WRITEs | isset |
|------|-------|--------|-------|
| `app/system/src/SystemModule.php` | 8 | 4 | 0 |
| `app/system/src/ValidatorServiceProvider.php` | 0 | 1 | 0 |
| `app/system/modules/site/src/SiteModule.php` | 3 | 2 | 0 |
| `app/system/modules/user/src/UserModule.php` | 1 | 1 | 0 |
| `app/system/modules/intl/src/IntlModule.php` | 0 | 1 | 0 |
| **Subtotal** | **12** | **9** | **0** |

### 4. System Views & Widgets (`$app['x']` patterns)

| File | READs |
|------|-------|
| `app/system/modules/theme/views/blank.php` | 1 |
| `app/system/modules/user/widgets/login.php` | 4 |
| `app/system/modules/user/mails/approve.php` | 1 |
| `app/system/modules/user/mails/welcome.php` | 1 |
| `app/system/modules/user/mails/reset.php` | 1 |
| `app/system/modules/user/mails/verification.php` | 1 |
| `app/system/modules/site/views/widget-text.php` | 1 |
| `app/system/modules/site/widgets/text.php` | 1 |
| **Subtotal** | **11** |

### 5. System Controllers (`App::*()` static calls)

| File | `App::*()` | `$app['x']` READ | isset |
|------|------------|-------------------|-------|
| `app/system/src/Controller/AdminController.php` | 7 | 0 | 0 |
| `app/system/src/Controller/ExceptionController.php` | 2 | 0 | 0 |
| `app/system/src/Controller/MigrationController.php` | 6 | 0 | 0 |
| `app/system/src/Controller/ValidatesRequestTrait.php` | 5 | 0 | 0 |
| `app/system/modules/cache/src/Controller/CacheController.php` | 2 | 0 | 0 |
| `app/system/modules/dashboard/src/Controller/DashboardController.php` | 9 | 0 | 0 |
| `app/system/modules/finder/src/Controller/FinderController.php` | 13 | 0 | 0 |
| `app/system/modules/finder/src/Controller/StorageController.php` | 1 | 0 | 0 |
| `app/system/modules/info/src/Controller/InfoController.php` | 1 | 0 | 0 |
| `app/system/modules/intl/src/Controller/IntlController.php` | 4 | 0 | 0 |
| `app/system/modules/intl/src/Controller/IntlApiController.php` | 1 | 0 | 0 |
| `app/system/modules/mail/src/Controller/MailController.php` | 5 | 0 | 0 |
| `app/system/modules/settings/src/Controller/SettingsController.php` | 5 | 0 | 0 |
| `app/system/modules/site/src/Controller/NodeController.php` | 8 | 0 | 0 |
| `app/system/modules/site/src/Controller/NodeApiController.php` | 15 | 0 | 0 |
| `app/system/modules/site/src/Controller/MenuApiController.php` | 9 | 0 | 0 |
| `app/system/modules/site/src/Controller/PageController.php` | 3 | 0 | 0 |
| `app/system/modules/user/src/Controller/AuthController.php` | 18 | 0 | 0 |
| `app/system/modules/user/src/Controller/UserController.php` | 9 | 0 | 0 |
| `app/system/modules/user/src/Controller/UserApiController.php` | 22 | 0 | 0 |
| `app/system/modules/user/src/Controller/RoleApiController.php` | 5 | 0 | 0 |
| `app/system/modules/user/src/Controller/ProfileController.php` | 7 | 0 | 0 |
| `app/system/modules/user/src/Controller/RegistrationController.php` | 27 | 0 | 0 |
| `app/system/modules/user/src/Controller/ResetPasswordController.php` | 22 | 4 | 2 |
| `app/system/modules/widget/src/Controller/WidgetController.php` | 7 | 0 | 0 |
| `app/system/modules/widget/src/Controller/WidgetApiController.php` | 12 | 0 | 0 |
| **Subtotal** | **225** | **4** | **2** |

### 6. System Listeners (`App::*()` static calls)

| File | `App::*()` |
|------|------------|
| `app/system/modules/user/src/Event/AuthorizationListener.php` | 6 |
| `app/system/modules/user/src/Event/AccessListener.php` | 9 |
| `app/system/modules/user/src/Event/LoginAttemptListener.php` | 4 |
| `app/system/modules/view/src/Event/ResponseListener.php` | 1 |
| `app/system/modules/site/src/Event/MaintenanceListener.php` | 7 |
| `app/system/modules/site/src/Event/NodesListener.php` | 6 |
| `app/system/modules/captcha/src/CaptchaListener.php` | 15 |
| **Subtotal** | **48** |

### 7. System Helpers & Module Classes (`App::*()` static calls)

| File | `App::*()` | `App::getInstance()->get()` |
|------|------------|-----------------------------|
| `app/system/src/SystemMenu.php` | 5 | 0 |
| `app/system/src/SystemModule.php` | 1 | 0 |
| `app/system/src/Validator/Constraints/UniqueValidator.php` | 1 | 0 |
| `app/system/modules/widget/src/PositionHelper.php` | 3 | 0 |
| `app/system/modules/site/src/MenuHelper.php` | 2 | 0 |
| `app/system/modules/info/src/InfoHelper.php` | 10 | 4 |
| `app/system/modules/view/src/Asset/FileLocatorAsset.php` | 2 | 0 |
| `app/system/modules/cache/src/CacheModule.php` | 5 | 2 |
| `app/system/modules/dashboard/src/DashboardModule.php` | 2 | 0 |
| `app/system/modules/intl/src/IntlModule.php` | 3 | 0 |
| `app/system/modules/site/src/SiteModule.php` | 3 | 0 |
| `app/system/modules/user/src/UserModule.php` | 2 | 0 |
| **Subtotal** | **39** | **6** |

### 8. System Model & Global Functions (Deferred/Bridge)

| File | `App::*()` | Pattern |
|------|------------|---------|
| `app/system/modules/site/src/Model/Node.php` | 2 | → `App::getInstance()->get()` bridge |
| `app/system/modules/intl/functions.php` | 3 | → TODO Step 2.0.1e |
| `app/system/modules/intl/functions-pagekit-namespace.php` | 3 | → TODO Step 2.0.1e |
| `app/system/modules/content/src/ContentHelper.php` | 1 | → constructor injection |
| `app/system/modules/content/src/Plugin/MarkdownPlugin.php` | 1 | → constructor injection |
| **Subtotal** | **10** | |

### 9. Installer

| File | `$app['x']` READ | `$app['x'] =` WRITE | isset | `App::*()` |
|------|-------------------|----------------------|-------|------------|
| `app/installer/app.php` | 5 | 1 | 0 | 0 |
| `app/installer/index.php` | 8 | 1 | 0 | 0 |
| `app/installer/install.php` | 0 | 0 | 0 | 9 |
| `app/installer/install-demo.php` | 0 | 0 | 0 | 20 |
| `app/installer/src/Controller/InstallerController.php` | 2 | 0 | 0 | 4 |
| `app/installer/src/Controller/MarketplaceController.php` | 0 | 0 | 0 | 4 |
| `app/installer/src/Controller/PackageController.php` | 0 | 0 | 0 | 36 |
| `app/installer/src/Controller/UpdateController.php` | 0 | 0 | 0 | 9 |
| `app/installer/src/Package/PackageManager.php` | 11 | 0 | 4 | 22 |
| `app/installer/src/Package/PackageFactory.php` | 0 | 0 | 0 | 1 |
| `app/installer/src/Package/PackageScripts.php` | 0 | 0 | 0 | 1 |
| `app/installer/src/SelfUpdater.php` | 0 | 0 | 0 | 1 |
| **Subtotal** | **26** | **2** | **4** | **107** |

### 10. Console

| File | `$app['x']` READ | `$app['x'] =` WRITE | `App::*()` |
|------|-------------------|----------------------|------------|
| `app/console/app.php` | 7 | 1 | 0 |
| `app/console/src/Commands/SetupCommand.php` | 1 | 0 | 1 |
| `app/console/src/Commands/ExtensionTranslateCommand.php` | 1 | 0 | 0 |
| `app/console/src/Commands/SelfupdateCommand.php` | 0 | 0 | 2 |
| **Subtotal** | **9** | **1** | **3** |

---

## App:: Static Proxy Method Distribution (In-Scope)

Top service proxies to migrate (excluding deferred `abort`/`redirect`/`on`/`subscribe`/`trigger`):

| Method | System | Installer | Console | Total |
|--------|--------|-----------|---------|-------|
| `App::request()` | 53 | 1 | 0 | 54 |
| `App::module()` | 35 | 9 | 1 | 45 |
| `App::user()` | 30 | 0 | 0 | 30 |
| `App::db()` | 3 | 27 | 0 | 30 |
| `App::config()` | 11 | 15 | 0 | 26 |
| `App::url()` | 14 | 3 | 0 | 17 |
| `App::response()` | 11 | 5 | 0 | 16 |
| `App::package()` | 0 | 11 | 0 | 11 |
| `App::mailer()` | 10 | 0 | 0 | 10 |
| `App::auth()` | 9 | 0 | 0 | 9 |
| `App::translator()` | 7 | 0 | 0 | 7 |
| `App::view()` | 6 | 0 | 0 | 6 |
| `App::message()` | 6 | 0 | 0 | 6 |
| `App::menu()` | 6 | 0 | 0 | 6 |
| `App::csrf()` | 6 | 0 | 0 | 6 |
| `App::session()` | 5 | 3 | 0 | 8 |
| `App::routes()` | 5 | 0 | 0 | 5 |
| `App::position()` | 5 | 0 | 0 | 5 |
| `App::cache()` | 5 | 0 | 0 | 5 |
| `App::file()` | 4 | 1 | 0 | 5 |
| Other (≤3 each) | 33 | 8 | 0 | 41 |

---

## Migration Strategy per Category

| # | Category | Pattern | Migration Target | Step |
|---|----------|---------|------------------|------|
| 1 | Module index.php | `$app['x']` READ | `$app->get('x')` | Step 2 |
| 2 | Bootstrap/scripts | `$app['x']` READ | `$app->get('x')` | Step 3 |
| 3 | Module classes | `$app['x']` READ | `$app->get('x')` | Step 3 |
| 4 | Views/widgets/mails | `$app['x']` READ | `$app->get('x')` | Step 3 |
| 5 | Controllers | `App::*()` | Constructor injection | Step 4 |
| 6 | Controllers | `$app['x']` READ | Constructor injection | Step 4 |
| 7 | Listeners | `App::*()` | Constructor injection via index.php | Step 5 |
| 8 | Helpers/modules | `App::*()` | Constructor injection | Step 6 |
| 9 | Models | `App::*()` | `App::getInstance()->get()` bridge | Step 7 |
| 10 | intl functions | `App::*()` | TODO → Step 2.0.1e | Step 7 |
| 11 | Installer files | `$app['x']` + `App::*()` | `$app->get()` + constructor injection | Step 8 |
| 12 | Console files | `$app['x']` + `App::*()` | `$app->get()` + constructor injection | Step 9 |
| — | All WRITEs | `$app['x'] = ...` | Keep as-is | Step 2.0.1d |
| — | Router/Event traits | `App::abort/redirect/on/...` | Keep as-is | Step 2.0.1e |

---

## Validation Plan (Step 10)

- [ ] `./app/vendor/bin/phpunit` — all 261+ tests pass
- [ ] `php -S localhost:8080 index.php` — app serves correctly
- [ ] No `$app['x']` READ access remains in scope (only WRITEs)
- [ ] No `App::service()` proxy calls in controllers/listeners (only deferred patterns remain)
- [ ] All WRITEs tagged: `// TODO: Must be refactored in Step 2.0.1d`
- [ ] All deferred statics tagged: `// TODO: Must be refactored in Step 2.0.1e`
- [ ] Model bridges tagged: `// TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e`
