# Step 2.7.2 — Module Dependency Integrity

<!-- Branch doc for Roadmap Step 2.7.2.
     Path: migration-docs/branches/phase-2/step-2-7-2-module-dependency-integrity.md -->

**Branch:** `feature/module-dependency-integrity`
**ROADMAP Step:** 2.7.2 (Module Dependency Integrity)
**GitHub Issue:** [#268](https://github.com/Shadesman5/pagekit/issues/268)
**Pull Request:** _TBD_
**Status:** 🚧 In progress
**Started:** 2026-09-25 00:56
**Completed:** _TBD_

---

## 🎯 Overview

A module `require` that is missing or switched off is refused, and that module is not loaded. The enabled set is the extensions list plus the active theme, copied when `SystemModule::main` starts. Core modules stay loadable because they sit in the always-loaded closure of `system`, not because they appear in `extensions`.

The foundation (`Pagekit\Application`, `Event`, `Module`, `Container`, `Util`) lives in the existing `kernel` module, beside `Pagekit\Kernel\`. `application` is still the composition root. `view` owns Twig; there is no `view/twig` module.

Dump selection, dump ownership, and restore collisions fold through one helper. A byte prefix matches without asking the server. A database that lists tables and yields none is not a dump; one that lists nothing still is.

Disable and uninstall ask one query before anything changes. Blockers stop the call. Orphans and the data-risk hint are reported and do not. The active theme counts as enabled. Disabling that theme clears `site.theme`.

The extensions status toggle and the uninstall confirm ask that query before they offer Disable or Remove. `php pagekit enable` and `php pagekit disable` call the same manager. `php pagekit install` still does not enable.

Remaining work is the restore pre-flight.

---

## ✅ What Changed

### Fail-closed requirements and `requiredBy` (Checklist Step 1)

`resolveModules` throws when a `require` is not registered or is registered but disabled, and does not load that module. Until `setActivityPolicy` runs, a registered module still counts as active, so `load('system')` can walk core requirements. Afterwards, active means the enabled set or the always-loaded closure of the boot module. `requiredBy` lists direct dependers in registration order and is dropped on the next `register()`.

`Arr::pull` writes the packed list back through its by-ref parameter after `unset`. Rebinding the parameter would leave `Config::pull` storing the gapped keys.

`enableAction` pops the error and exception handlers it pushed. Putting the previous callable back with `set_error_handler` / `set_exception_handler` would leave those frames on the stack.

| File | Change |
|---|---|
| `app/modules/application/src/Module/UnsatisfiedRequirementException.php` (new) | English sentence names the depender and the requirement and says either "not registered" or "registered but disabled". `messageId()` is that sentence, for the panel to translate. Now `app/modules/kernel/src/Module/UnsatisfiedRequirementException.php`. |
| `app/modules/application/src/Module/ModuleManager.php` | `resolveModules` throws `UnsatisfiedRequirementException` or the existing circular-requirement message. `setActivityPolicy` / `isActive` / `requiredBy` / `assertRequirements`. `load()` of an unknown name still throws `Undefined module`. `assertRequirements` returns when the name is not registered. Now `app/modules/kernel/src/Module/ModuleManager.php`. |
| `app/system/src/SystemModule.php` | `main()` calls `setActivityPolicy` with `extensions` plus a non-empty `site.theme` before `ExtensionLoader::load`. The list is the copy taken at the start of `main`. |
| `app/package/src/PackageManager.php` | `enable()` calls `assertRequirements` before any config write, lifecycle hook, or `package.enable`, and only when `Package::get('module')` is a string. |
| `app/package/src/Controller/PackageController.php` | `enableAction` translates `messageId()` into `error` even when debug is off. Any other failure, including a circular requirement, stays the generic enable error when debug is off. The cleanup closure calls `restore_error_handler()` and `restore_exception_handler()`. |
| `app/modules/application/src/Util/Arr.php` | `pull` assigns `array_values` through the by-ref parameter after `unset`. Now `app/modules/kernel/src/Util/Arr.php`. |

#### Tests (Checklist Step 1)

| File | Change |
|---|---|
| `tests/Unit/Module/ModuleRequirementTest.php` (new) | Fixture modules. Unregistered vs registered-but-disabled (the depender is not loaded), a registered requirement loads before a policy exists, `load` of the boot module still throws "not registered", a boot-module requirement stays active when it is absent from the enabled set, an active cycle throws the existing message and loads nothing, a cycle behind a disabled module is reported as disabled, `load` of an unknown name throws `Undefined module` while `assertRequirements` of one does not, `requiredBy` is direct dependers once in registration order, `register()` rebuilds the always-loaded closure, a `require` that is not a module name is refused. |
| `tests/Unit/Package/PackageEnableRequirementTest.php` (new) | `enable()` throws `UnsatisfiedRequirementException` or `Circular requirement "%s > %s" detected.` before the package changes, including when the module is already loaded and when the cycle sits behind a disabled module. A non-string `module` skips the walk. An unregistered module name does not throw `Undefined module`. |
| `tests/Unit/Package/PackageControllerEnableRequirementTest.php` (new) | The enable `error` names the depender and the requirement for both sentences with debug off. A circular requirement stays the generic enable error when debug is off and is the exception message when debug is on. |
| `tests/Unit/System/SystemModuleActivityTest.php` (new) | A theme set when `main()` starts satisfies a requirement that is absent from `extensions`. A name pushed onto the config service afterwards does not. A module `system` requires is active without being enabled. An enabled extension whose requirement is disabled is recorded and not loaded; `extensions` is left packed (`['pages']` at index 0). |
| `tests/Unit/Extension/ExtensionRequirementFailureTest.php` (new) | An already-enabled extension whose requirement is missing is auto-disabled and the sentence is stored. A theme whose requirement fails is recorded and not switched off. |

Gates: production verifier PASS; PHPUnit + PHPStan PASS; test-writer done; test-file verifier PASS; coverage PHPUnit + PHPStan PASS.

### Routing owns the URL helpers (Checklist Step 2)

`Pagekit\Application\UrlProvider` and `Pagekit\Application\Response` are `Pagekit\Routing\UrlProvider` and `Pagekit\Routing\Response`. `application` still constructs the `url` and `response` services and still requires `routing`. `routing`'s `require` stays `kernel` and `filter`. The old classes are deleted.

| File | Change |
|---|---|
| `app/modules/routing/src/UrlProvider.php` (new) | `Pagekit\Routing\UrlProvider`. The `use Pagekit\Routing\Router` import is dropped; `@param Router` resolves in this namespace. |
| `app/modules/routing/src/Response.php` (new) | `Pagekit\Routing\Response`. |
| `app/modules/application/src/Application/UrlProvider.php` (deleted) | |
| `app/modules/application/src/Application/Response.php` (deleted) | |
| `app/modules/application/index.php` | `url` and `response` are `new UrlProvider` / `new Response` from `Pagekit\Routing`. |
| `app/modules/view/src/Helper/UrlHelper.php` | The provider property is `Pagekit\Routing\UrlProvider`. |
| `app/installer/src/Controller/UpdateController.php`, `app/package/src/Controller/PackageController.php`, `app/package/src/PackageFactory.php`, `app/system/src/SystemMenu.php`, `app/system/src/Controller/AdminController.php`, `app/system/src/Controller/ExceptionController.php`, `app/system/src/Controller/MigrationController.php`, `app/system/modules/finder/src/Controller/FinderController.php`, `app/system/modules/intl/src/Controller/IntlController.php`, `app/system/modules/site/src/Controller/NodeController.php`, `app/system/modules/site/src/MenuHelper.php`, `app/system/modules/site/src/NodePresenter.php`, `app/system/modules/user/src/Controller/AuthController.php`, `app/system/modules/user/src/Controller/ProfileController.php`, `app/system/modules/user/src/Controller/RegistrationController.php`, `app/system/modules/user/src/Controller/ResetPasswordController.php`, `app/system/modules/user/src/Event/AccessListener.php`, `app/modules/view/src/Event/ResponseListener.php`, `packages/pagekit/blog/src/Controller/SiteController.php`, `packages/pagekit/blog/src/PostPresenter.php` | Import `Pagekit\Routing\UrlProvider`, `Pagekit\Routing\Response`, or both. |

#### Tests (Checklist Step 2)

| File | Change |
|---|---|
| `app/modules/routing/src/Tests/UrlProviderTest.php` (new) | Base path, query merge, named routes, an unknown or invalid route, a non-integer reference type, and static filesystem paths. |
| `app/modules/routing/src/Tests/ResponseTest.php` (new) | `create` / `__invoke`, JSON, a resolved redirect, an unresolved redirect, a stream, and download of a present or missing file. |
| `app/modules/routing/src/Tests/RoutingUrlOwnershipTest.php` (new) | The application module registers the routing services. The old classes and files are absent. `routing` does not require `application` and its tree does not reference `Pagekit\Application\`. Production code under `app/` and `packages/` does not name the old classes. |
| `app/modules/view/src/Tests/UrlHelperTest.php` (new) | A resolved route is returned, an unresolved route is `''`, `base` and `previous` are forwarded, and an unknown method names `Pagekit\Routing\UrlProvider`. |
| `app/system/modules/site/src/Tests/MenuHelperTest.php`, `app/system/modules/site/src/Tests/NodeApiControllerTest.php`, `app/system/modules/site/src/Tests/NodeControllerTest.php`, `app/system/modules/site/src/Tests/NodePresenterTest.php`, `app/system/modules/user/src/Tests/AccessListenerTest.php`, `app/system/modules/user/src/Tests/RegistrationControllerTest.php`, `app/system/modules/user/src/Tests/ResetPasswordControllerTest.php`, `tests/Unit/Blog/CommentApiControllerTest.php`, `tests/Unit/Blog/PostApiControllerTest.php`, `tests/Unit/Blog/PostPresenterTest.php`, `tests/Unit/Blog/SiteControllerTest.php`, `tests/Unit/Package/PackageControllerEnableRequirementTest.php`, `tests/Unit/Package/PackageFactoryTest.php`, `tests/Unit/Package/PackageFailureRecordTest.php`, `tests/Unit/Package/PackageHookWarningTest.php`, `tests/Unit/Package/PackageUploadBoundaryTest.php`, `tests/Unit/Snapshot/RemovalPromiseTest.php`, `tests/Unit/Snapshot/SnapshotAdminSurfaceTest.php`, `tests/Unit/System/MigrationControllerTest.php`, `tests/Unit/Theme/ThemeOneLogoTemplateTest.php` | Import `Pagekit\Routing\UrlProvider`, `Pagekit\Routing\Response`, or both. |

Gates: production verifier PASS; production tester PASS; test-writer verifier FAIL once (`RoutingUrlOwnershipTest` class docblock), retry PASS; test verifier PASS; tester PASS.

### Kernel owns the foundation (Checklist Step 3)

`Pagekit\Application`, `Pagekit\Event`, `Pagekit\Module` (including `ModuleManager` and `UnsatisfiedRequirementException`), `Pagekit\Container`, and `Pagekit\Util` live under `app/modules/kernel/src`. Namespaces are unchanged. HttpKernel types stay `Pagekit\Kernel\`. `application` requires `kernel` and still requires the stack. `kernel` requires none of that stack. Step 1's activity policy is unchanged; only the file home moved.

`kernel`'s autoload maps both `Pagekit\` and `Pagekit\Kernel\` to `src`, so the `Application` class and the HttpKernel types load from the same directory. A prefix per sub-namespace (`Pagekit\Application\` → `src`) would resolve `Pagekit\Application\Exception` to `src/Exception.php` and would not load the `Pagekit\Application` class.

`view` registers the Twig environment and autoloads `Pagekit\Twig\` from `src/Twig`. Composer maps that prefix to the same tree: an optimized dump scans `Pagekit\View\`'s `src` and skips a `Pagekit\Twig` class sitting in that tree. `system/view` requires `view` and no longer autoloads `Pagekit\View\`. `SettingsController` is `Pagekit\Settings\Controller\SettingsController`. Composer `Pagekit\System\` stays on `app/system/src`.

| File | Change |
|---|---|
| `composer.json` | `"Pagekit\\"` points at `app/modules/kernel/src`. `"Pagekit\\Twig\\"` points at `app/modules/view/src/Twig`. `"Pagekit\\Kernel\\"` and `"Pagekit\\System\\"` stay where they were. |
| `phpstan-baseline.neon` | Paths for `Event`, `Arr`, and the db/ftp test cases follow the move to `kernel/src`. |
| `app/modules/application/index.php` | Requires `kernel` and keeps the stack `require`. No `Pagekit\` autoload. |
| `app/modules/application/src/` (deleted) | |
| `app/modules/kernel/index.php` | Autoload maps `Pagekit\` and `Pagekit\Kernel\` to `src`. No `require`. |
| `app/modules/kernel/src/Application.php`, `Application/`, `Container.php`, `Container/`, `Event/`, `Module/`, `Util/` | Moved from `application/src`. Namespaces unchanged. |
| `app/modules/kernel/src/Tests/` (moved) | Container, env-config, trusted-proxies, and the db/ftp helpers moved with the classes. |
| `app/modules/view/index.php` | Registers `twig`. Autoloads `Pagekit\View\` from `src` and `Pagekit\Twig\` from `src/Twig`. The `modules/*/index.php` include and the `view/twig` require are gone. |
| `app/modules/view/modules/twig/` (deleted) | |
| `app/modules/view/src/Twig/` | `TwigCache` and `TwigLoader` (`Pagekit\Twig`). |
| `app/modules/view/src/Asset/FileLocatorAsset.php`, `app/modules/view/src/Event/ResponseListener.php` | Moved from `system/view`. Still `Pagekit\View`. |
| `app/system/modules/view/index.php` | Requires `view`. The `Pagekit\View\` autoload is gone. |
| `app/system/modules/view/src/` (deleted) | |
| `app/system/modules/settings/index.php` | Autoload is `Pagekit\Settings\` → `src`. The settings route names `Pagekit\Settings\Controller\SettingsController`. |
| `app/system/modules/settings/src/Controller/SettingsController.php` | Namespace `Pagekit\Settings\Controller`. |
| `tests/Unit/Package/PackageModuleBoundaryTest.php` | Bootstrap and `AutoLoader` paths point at `kernel/src`. |
| `tests/Unit/Settings/SettingsControllerTest.php` | Imports `Pagekit\Settings\Controller\SettingsController`. Still loads the file with `require_once`, because Composer does not map `Pagekit\Settings\`. |

#### Tests (Checklist Step 3)

| File | Change |
|---|---|
| `app/modules/kernel/src/Tests/KernelFoundationOwnershipTest.php` (new) | Both prefixes resolve `Application`, `Application\Exception`, `Container`, `Event`, `ModuleManager`, `Arr`, and `HttpKernel` from `kernel/src`. `kernel` does not require the stack; `application` requires `kernel` and still requires the stack. `view` owns Twig and `Pagekit\View\`; the `view/twig` module is absent and `view`'s `main` builds the Twig environment. `SettingsController` is `Pagekit\Settings\`; Composer still maps `Pagekit\System\` to `app/system/src` and does not map `Pagekit\Settings\`. |

Gates: production verifier PASS; PHPUnit + PHPStan PASS; test-writer done; test-file verifier PASS; PHPUnit + PHPStan PASS. No deviations.

### Sibling imports (Checklist Step 4)

`#[Access]` is `Pagekit\Routing\Attribute\Access`. The user-module class is deleted. `AccessListener` stays in `system/user` and imports the routing attribute.

`UserInterface::isAuthenticated(): bool`. `User::isAuthenticated()` still checks the authenticated role. `CaptchaListener` uses `Auth` and that interface. It does not import `Pagekit\User`. The login check asks `hasAccess('system: software updates')`. A signed-in flag does not decide whether that check runs.

`CacheKeyUtil` is `Pagekit\Util\CacheKeyUtil`. `MetadataManager` and `LoginAttemptListener` import it there. The cache-module class is deleted.

`Filesystem::getUrl` uses `UrlGeneratorInterface::ABSOLUTE_PATH` (`1`) and `NETWORK_PATH` (`3`). It does not import `Pagekit\Routing`. `getUrl($file)` is the path form, `getUrl($file, 3)` the network path, and `getUrl($file, true)` the absolute URL.

`debug` registers one `DebugMiddleware` and its logger at the start of `main`, before the bar may return, and stores them as `db.middlewares`, `db.debug_middleware`, and `db.debug_logger`. `database` appends `Doctrine\DBAL\Driver\Middleware` entries from that list after any already on the connection and skips other entries. It does not name `Pagekit\Debug`. An absent `db.middlewares` leaves the connection's own list unchanged. A boot that never loads `debug` adds no wrapper.

| File | Change |
|---|---|
| `app/modules/routing/src/Attribute/Access.php` (new) | `Pagekit\Routing\Attribute\Access`. |
| `app/system/modules/user/src/Attribute/Access.php` (deleted) | |
| `app/system/modules/user/src/Event/AccessListener.php` | Imports `Pagekit\Routing\Attribute\Access`. |
| `app/installer/src/Controller/UpdateController.php`, `app/package/src/Controller/PackageController.php`, `app/package/src/Controller/SnapshotController.php`, `app/system/src/Controller/AdminController.php`, `app/system/src/Controller/MigrationController.php`, `app/system/modules/cache/src/Controller/CacheController.php`, `app/system/modules/dashboard/src/Controller/DashboardController.php`, `app/system/modules/finder/src/Controller/StorageController.php`, `app/system/modules/info/src/Controller/InfoController.php`, `app/system/modules/mail/src/Controller/MailController.php`, `app/system/modules/settings/src/Controller/SettingsController.php`, `app/system/modules/site/src/Controller/MenuApiController.php`, `app/system/modules/site/src/Controller/NodeApiController.php`, `app/system/modules/site/src/Controller/NodeController.php`, `app/system/modules/site/src/Controller/PageApiController.php`, `app/system/modules/user/src/Controller/RoleApiController.php`, `app/system/modules/user/src/Controller/UserApiController.php`, `app/system/modules/user/src/Controller/UserController.php`, `app/system/modules/widget/src/Controller/WidgetApiController.php`, `app/system/modules/widget/src/Controller/WidgetController.php`, `packages/pagekit/blog/src/Controller/BlogController.php`, `packages/pagekit/blog/src/Controller/CommentApiController.php`, `packages/pagekit/blog/src/Controller/PostApiController.php` | Import `Pagekit\Routing\Attribute\Access`. |
| `app/modules/auth/src/UserInterface.php` | `isAuthenticated(): bool`. |
| `app/system/modules/captcha/src/CaptchaListener.php` | Signed-in means `UserInterface::isAuthenticated()`. Does not import `Pagekit\User`. |
| `app/modules/kernel/src/Util/CacheKeyUtil.php` (new) | `Pagekit\Util\CacheKeyUtil`. |
| `app/system/modules/cache/src/CacheKeyUtil.php` (deleted) | |
| `app/modules/database/src/ORM/MetadataManager.php`, `app/system/modules/user/src/Event/LoginAttemptListener.php` | Import `Pagekit\Util\CacheKeyUtil`. |
| `app/modules/filesystem/src/Filesystem.php` | `getUrl` uses `UrlGeneratorInterface` constants. Does not import `Pagekit\Routing`. |
| `app/modules/debug/index.php` | One `DebugMiddleware` and its logger, registered at the start of `main`, shared as `db.middlewares`. |
| `app/modules/database/index.php` | Appends `Middleware` entries from `db.middlewares` after the connection's own. Skips other entries. Does not name `Pagekit\Debug`. |

#### Tests (Checklist Step 4)

| File | Change |
|---|---|
| `app/modules/routing/src/Tests/AccessAttributeOwnershipTest.php` (new) | Controller attributes resolve to the routing class. The user-module class and file are gone. Blog controllers name `Pagekit\Routing\Attribute\Access`. |
| `app/modules/kernel/src/Tests/CacheKeyUtilTest.php` (new) | Reserved characters become underscores, including a class name. The class loads from `kernel/src/Util`. The cache-module class is gone. |
| `app/modules/database/src/Tests/ConnectionMiddlewareTest.php` (new) | An absent `db.middlewares` adds no wrapper. Held middlewares are appended after the connection's own. A non-middleware is not wrapped. `database` does not name `Pagekit\Debug`. |
| `app/modules/database/src/Tests/ORM/MetadataManagerCacheKeyTest.php` (new) | A cached class name is sanitized through `Pagekit\Util\CacheKeyUtil`. |
| `app/modules/debug/src/Tests/SharedDatabaseLoggerTest.php` (new) | One middleware is registered when the bar is off. Every connection writes to the logger the bar reads. |
| `app/system/modules/captcha/src/Tests/CaptchaListenerTest.php` | A `UserInterface` that is authenticated skips verify, data, and scripts. One that is not does not. The listener source does not name `Pagekit\User`. |
| `app/modules/filesystem/src/Tests/FilesystemTest.php` | `getUrl` follows the path (`1`), network (`3`), and absolute (`true`) types. The class source does not name `Pagekit\Routing`. |
| `tests/Unit/System/UpdateCheckOnLoginTest.php` | `AdministratorWhoUpdates` implements `isAuthenticated()`. Login runs the check for an account that may update even when it has not signed in, and skips a signed-in account that may not. |
| `app/system/modules/user/src/Tests/AccessListenerTest.php`, `tests/Unit/Package/PackageModuleBoundaryTest.php`, `tests/Unit/Package/PackageUploadBoundaryTest.php` | Import `Pagekit\Routing\Attribute\Access`. |

Gates: production verifier FAIL (docblocks) then PASS; production tester PASS; test verifier FAIL (login double never ran login; ownership docblock) then PASS; tester PASS.

### Import edges (Checklist Step 5)

A production `use` or attribute of a `Pagekit\…` class must reach, through transitive `require`, the module directory that contains the class file. The owner is the longest module path. A class in the importer's own directory is not an edge. An import whose owner already requires the importer fails. `use function` and `use const` are not edges. A namespace `use` is an edge only through an attribute class under that prefix. The scan reads core module PHP (`app/modules`, `app/system`, `app/system/modules`, `app/package`, `app/installer`, `app/console`) and skips `Tests/`, `tests/`, and `packages/pagekit/*`. The existing `Pagekit\Database\`, `Pagekit\Routing\`, and `Pagekit\Site\` autoloads cover the moved files.

`ValidatesRequestTrait` is `Pagekit\Routing\ValidatesRequestTrait`. `DataModelTrait` is `Pagekit\Database\ORM\DataModelTrait`. `NodeInterface` and `NodeTrait` are `Pagekit\Site\Model`, beside `Node`. `Unique` and `UniqueValidator` are `Pagekit\Database\Validator\Constraints`. The old `Pagekit\System` names are deleted.

`system` does not require `database`. `ValidatorServiceProvider` still imports `UniqueValidator`; `application` requires `database`, so the edge is transitive. `User` imports `Unique` from database directly, and `system/user` requires `database`.

| File | Change |
|---|---|
| `app/modules/routing/src/ValidatesRequestTrait.php` (new) | `Pagekit\Routing\ValidatesRequestTrait`. |
| `app/system/src/Controller/ValidatesRequestTrait.php` (deleted) | |
| `app/modules/database/src/ORM/DataModelTrait.php` (new) | `Pagekit\Database\ORM\DataModelTrait`. |
| `app/system/src/Model/DataModelTrait.php` (deleted) | |
| `app/system/modules/site/src/Model/NodeInterface.php` (new), `app/system/modules/site/src/Model/NodeTrait.php` (new) | `Pagekit\Site\Model`. |
| `app/system/src/Model/NodeInterface.php` (deleted), `app/system/src/Model/NodeTrait.php` (deleted) | |
| `app/modules/database/src/Validator/Constraints/Unique.php` (new), `app/modules/database/src/Validator/Constraints/UniqueValidator.php` (new) | `Pagekit\Database\Validator\Constraints`. The validator takes `Connection`. |
| `app/system/src/Validator/Constraints/Unique.php` (deleted), `app/system/src/Validator/Constraints/UniqueValidator.php` (deleted) | |
| `app/system/modules/site/src/Controller/MenuApiController.php`, `app/system/modules/site/src/Controller/NodeApiController.php`, `app/system/modules/user/src/Controller/ProfileController.php`, `app/system/modules/user/src/Controller/RegistrationController.php`, `app/system/modules/user/src/Controller/RoleApiController.php`, `app/system/modules/user/src/Controller/UserApiController.php`, `app/system/modules/widget/src/Controller/WidgetApiController.php`, `packages/pagekit/blog/src/Controller/CommentApiController.php`, `packages/pagekit/blog/src/Controller/PostApiController.php` | Import `Pagekit\Routing\ValidatesRequestTrait`. |
| `app/system/modules/site/src/Model/Menu.php` | The validator `@see` names `Pagekit\Routing\ValidatesRequestTrait`. |
| `app/system/modules/site/src/Model/Node.php` | `DataModelTrait` comes from database. Node types are the same namespace. |
| `app/system/modules/site/src/Model/Page.php`, `app/system/modules/widget/src/Model/Widget.php`, `packages/pagekit/blog/src/Model/Post.php` | Import `Pagekit\Database\ORM\DataModelTrait`. |
| `app/system/modules/user/src/Model/User.php` | Imports `DataModelTrait` and `Unique` from database. The username and email attributes are `#[Unique]`. |
| `app/system/src/ValidatorServiceProvider.php` | Imports `Pagekit\Database\Validator\Constraints\UniqueValidator`. |
| `app/console/index.php` | Require adds `installer`, `package`. |
| `app/modules/auth/index.php` | Require adds `cookie`, `database`, `kernel`. |
| `app/modules/config/index.php` | Require adds `kernel`. |
| `app/modules/database/index.php` | Require adds `filesystem`, `kernel`. |
| `app/modules/debug/index.php` | Require adds `auth`, `database`, `system/info`, `system/user`. |
| `app/modules/routing/index.php` | Require adds `filesystem`. |
| `app/modules/session/index.php` | Require adds `database`, `kernel`. |
| `app/modules/view/index.php` | Require adds `filesystem`, `kernel`, `markdown`, `routing`, `session`. |
| `app/system/modules/cache/index.php` | Require adds `kernel`, `routing`. |
| `app/system/modules/captcha/index.php` | Require adds `auth`, `kernel`, `routing`, `view`. |
| `app/system/modules/comment/index.php` | Require adds `database`, `kernel`, `system/content`. |
| `app/system/modules/content/index.php` | Require adds `kernel`, `markdown`. |
| `app/system/modules/dashboard/index.php` | Require adds `kernel`, `routing`. |
| `app/system/modules/finder/index.php` | Require adds `filesystem`, `kernel`, `routing`. |
| `app/system/modules/info/index.php` | Require adds `routing`. |
| `app/system/modules/intl/index.php` | Require adds `kernel`, `routing`. |
| `app/system/modules/mail/index.php` | Require adds `kernel`, `routing`. |
| `app/system/modules/settings/index.php` | Require adds `config`, `filesystem`, `routing`. |
| `app/system/modules/site/index.php` | Require adds `config`, `database`, `filter`, `kernel`, `package`, `routing`, `system/content`, `system/user`, `view`. |
| `app/system/modules/user/index.php` | Require adds `auth`, `config`, `database`, `kernel`, `routing`, `session`, `system/captcha`, `system/mail`, `view`. |
| `app/system/modules/view/index.php` | Require adds `kernel`. |
| `app/system/modules/widget/index.php` | Require adds `config`, `database`, `kernel`, `routing`, `system/site`, `system/user`, `view`. |

#### Tests (Checklist Step 5)

| File | Change |
|---|---|
| `tests/Unit/Module/ModuleImportEdgeTest.php` (new) | Every production import reaches its owner through `require`, including a transitive one. A parent import stays a violation after the reverse `require` is added. `Tests/` and `tests/` are ignored. `use function` / `use const` and a bare namespace import are not edges; attribute classes under that prefix are. A same-module class is not an edge. The owner is the longest path. Site, user, and widget require `routing` and `database` and do not import `Pagekit\System` or `Pagekit\System\Model`. Node types are imported only by site. `system` does not require `database`. Comment, site, user, and widget require `database`. `use function Pagekit\__` adds no `system/intl` require. |
| `tests/Unit/Validator/UniqueValidatorContainerTest.php` | Imports `Unique` and `UniqueValidator` from database. |
| `tests/Unit/Validator/ValidatorTranslatorIntegrationTest.php` | Imports `Pagekit\Routing\ValidatesRequestTrait`. |

Gates: production verifier PASS; production tester PASS; test verifier PASS; tester PASS. No deviations.

### Archive `require` before any write (Checklist Step 6)

`PackageArchive::require()` is the list a PHP array would yield from the literal `return` in `index.php`. The file is not executed. A missing `require` is `[]`, including when a spread precedes the literal keys and `require` is not written again. A spread, a non-string, or a non-literal key inside `require` is refused like a non-literal `autoload`. An omitted key is appended. `"-0"` stays a string key, so the next omitted name is integer 0 and does not replace it. From PHP 8.3 a negative integer continues at n+1, so the name after `"-4"` is `-3` and a later `"0"` does not replace it. An omitted key once the next index cannot be allocated is refused, because that literal throws.

`install()` and `uploadAction` refuse the first unregistered name before anything is written under `packages/` (`ArchiveRefusedException`; the upload is a 400 before `move()`). The message names the archive's module and the missing name. The activation walk runs only when `install()` is about to `enable()`, on `PackageArchive::module()`, not the previously installed module. Upload and a fresh install allow a requirement that is registered but disabled. `assertRequirementsUsing` overlays that list and restores the manifest and the `requiredBy` cache whether the walk returns or throws, so a later failure to replace the tree does not leave the archive list registered. A `module` service that is not a `ModuleManager` cannot show a name as registered, so a non-empty `require` is refused. An empty `require` with nothing to activate does not read the service.

| File | Change |
|---|---|
| `app/package/src/Archive/PackageArchive.php` | `require()` is that literal list. A missing key is `[]`. A spread, a non-string, a non-literal key, or an omitted key past `PHP_INT_MAX` throws `ArchiveRefusedException` and writes nothing. `unknownRequirement()` names the archive module and the missing name. |
| `app/package/src/PackageManager.php` | `install()` calls `assertArchiveRequirements` before `replaceTree`. The activation walk runs only when the installed module is already loaded, on the archive's module. A non-`ModuleManager` `module` service refuses a non-empty `require`. An empty `require` with nothing to activate does not read the service. |
| `app/package/src/Controller/PackageController.php` | `uploadAction` calls `assertArchiveRequirements` before `move()`. `ArchiveRefusedException` is a 400. |
| `app/modules/kernel/src/Module/ModuleManager.php` | `assertRequirementsUsing` overlays `$require` (inserting a manifest when the name is not registered), walks, and restores the manifest and the `requiredBy` cache. |

#### Tests (Checklist Step 6)

| File | Change |
|---|---|
| `tests/Unit/Package/PackageArchiveRequirementTest.php` (new) | Install and upload refuse the first unregistered name before a file is written, quoting it without control characters. A fresh install and an upload allow a registered-but-disabled requirement. An update refuses a disabled requirement or a cycle before `replaceTree`, walks the archive module, and allows an active requirement. A service that is not a `ModuleManager` refuses a non-empty `require`; an empty one does not read the service and still installs. |
| `tests/Unit/Package/PackageArchiveTest.php` | `require()` is the list the literal yields, including `"-0"` and `"-4"` keys. A missing `require`, and a spread that does not rewrite it, are `[]`. A non-literal `require` and an omitted key past the last index are refused. The file is not run. |
| `tests/Unit/Module/ModuleRequirementTest.php` | An overlaid require is restored, including when the walk throws a disabled requirement or a cycle, and when the name was not registered. |
| `tests/Unit/Package/PackageEnableRequirementTest.php` | After an empty overlay and after a refused overlay, `enable()` still walks the require that was registered before the overlay. |

Gates: production verifier PASS; PHPUnit + PHPStan PASS; test-writer done; test verifier PASS; PHPUnit + PHPStan PASS. No deviations.

### Prefix fold and empty-dump refusal (Checklist Step 7)

`TableNameFold` is the comparison for selecting an installation's tables, for refusing a dump name that is not one of them, and for a restore's own copies. A byte prefix matches without asking the server. The server is asked only when the names differ by case, and only `lower_case_table_names` of `1` or `2` (string or int) then counts the table. A folded mismatch does not ask. SQLite never runs that statement. `RestoreTableNames::isReserved` stays a byte match.

`read()` throws `The dump selected no table.` before `stage()` only when the database lists at least one table and the prefix selects none. A database that lists no table is written. `dump()` wraps that failure as `Failed to dump the database to "%s".` `DatabaseRestorer::end()` still refuses a dump that holds no tables.

| File | Change |
|---|---|
| `app/package/src/Snapshot/TableNameFold.php` (new) | `prefixed` matches a byte prefix without `SHOW`. It asks `SHOW GLOBAL VARIABLES LIKE 'lower_case_table_names'` only when the folded forms match and the bytes do not. `1` and `2`, string or int, fold; any other value, or no row, keeps the bytes. `comparable` is that same answer. |
| `app/package/src/Snapshot/DatabaseDumper.php` | `schema()` selects through `prefixed` and still skips a reserved name byte for byte. `read()` throws before `stage()` when tables were listed and none were selected. A database that lists nothing is still written. |
| `app/package/src/Snapshot/DatabaseRestorer.php` | `name()` uses `prefixed`. Copy and collision checks use `comparable`. `isReserved` stays a byte match. `end()` still throws when the dump holds no tables. |

#### Tests (Checklist Step 7)

| File | Change |
|---|---|
| `tests/Unit/Snapshot/DatabaseDumperTest.php` | A byte prefix matches without `SHOW`. An uppercase prefix selects the other-case tables only when the answer is `1` or `2`. SQLite matches by bytes and does not ask. A database that lists no table returns `['tables' => 0, 'rows' => 0]` and the finished path exists. A database whose only table lies outside the prefix throws; `getPrevious()` is `The dump selected no table.`; the finished path and the `.part` file are absent. A marker in another case is still dumped. |
| `tests/Unit/Snapshot/DatabaseRestorerTest.php` | A neighbour in the dump is refused without asking. A name that differs from the prefix only by case belongs only when the server folds, and that question is asked before the lock. A same-case MySQL refusal still asks `lower_case_table_names` after `GET_LOCK` and before `REFERENTIAL_CONSTRAINTS`. A marker in another case is refused as outside the installation, not as a restore's own copy. |
| `tests/Unit/Snapshot/ConnectionThatAnswersForAMysqlServer.php` | `$folding` is what `SHOW GLOBAL VARIABLES LIKE 'lower_case_table_names'` returns, including an integer and no row. |

Gates: production verifier PASS; PHPUnit + PHPStan FAIL then PASS after a production retry; test-writer done; test verifier PASS; PHPUnit + PHPStan PASS.

### Disable and uninstall pre-flight (Checklist Step 8)

`PackageImpact` is the query `removalImpact`, `disable`, and `uninstall` share. Blockers are enabled modules — the extensions list and the active theme — that `requiredBy` says require a target, including a sibling in the same call. A self-require is not one. Orphans and data risk are in the payload and do not stop the call. Every name is resolved before the first snapshot or the first hook. There is no override. Disabling the active theme, once the pre-flight allows it, clears `site.theme`.

| File | Change |
|---|---|
| `app/package/src/PackageImpact.php` (new) | Blockers, orphans, and `dataRisk` (`migrations`, `config`, `nodes`, `tables`). A sibling in this call still blocks. Names in this call are not orphans. `config` is a row named for the module. `tables` use `TableNameFold`. No connection yields no tables. |
| `app/package/src/RemovalBlockedException.php` (new) | Thrown when an enabled module still requires a package that was about to be switched off. |
| `app/package/src/PackageManager.php` | `removalImpact()` is that query. `disable()` and `uninstall()` throw one `RemovalBlockedException` after every name is resolved and before the first hook or snapshot. A missing name still throws `Unable to find` before any snapshot. The lifecycle file is read once for the pre-flight and the hooks. `clearActiveTheme` removes `site.theme` only for the active `pagekit-theme`. |
| `app/package/src/Controller/PackageController.php` | `@system/package/impact` takes the package name, requires CSRF, and returns the three keys without throwing on blockers. `disableAction` rethrows the refusal as a 400 with the same message. The Step 2.7.2 TODO on `uninstallAction` is gone. |
| `app/modules/kernel/src/Module/ModuleManager.php` | `requires`, `isAlwaysLoaded`, and `nodeTypes` (string keys on the registered `nodes` map, in key order). |

#### Tests (Checklist Step 8)

| File | Change |
|---|---|
| `tests/Unit/Package/PackageRemovalPreflightTest.php` (new) | Impact and disable require the package name and CSRF. An enabled theme is a blocker; that disable is a 400 and leaves `extensions` and `site.theme`. Several blockers, and a package whose `module` is not a string, are named before anything changes. Uninstall of a package and its enabled depender throws before a snapshot and both stay installed. A missing name throws `Unable to find` and does not snapshot the present package. A self-require does not stop disable. Disabling the active theme clears `site.theme`; another theme, an extension, or a blocked theme leaves it. A boot requirement is an orphan only before the activity policy. A requirement is an orphan only when nothing outside the call still needs it. `packages.{module}` on the system row is not config at risk; a row named for the module is. Orphans, migrations, nodes, and tables are reported and do not stop disable or uninstall. The pre-flight and the removal hooks share one lifecycle read. A lifecycle that returns nothing still disables and reports `migrations` false. `pk_blog_post` matches without `SHOW`; a different case matches only when the server folds; SQLite keeps a different case out; no connection yields no tables. |

Gates: production verifier PASS; PHPUnit + PHPStan PASS; test-writer done; test-file verifier PASS; PHPUnit + PHPStan PASS. No deviations.

### Panel and console activation (Checklist Step 9)

The extensions status toggle and the uninstall confirm read the impact route before they offer Disable or Remove. Both render only when the payload has `blockers`, `orphans`, and `dataRisk` and `blockers` is empty. A failed read, or a payload missing one of those keys, leaves the control out. Each of the three results is one sentence. Tables are named and said to be left as they are. `enable` still notifies `response.data.error`.

`php pagekit enable` loads a registered module, then calls `PackageManager::enable()`. An unregistered module name is not loaded. A requirement refusal is printed and the command returns failure. `php pagekit disable` resolves every name, then calls `disable()` once. A `RemovalBlockedException` is printed and the command returns failure. Any other throwable, including an unknown package and a circular requirement, propagates. Neither command has `--force`. Success prints the package title, or the package name when `title` is empty. `php pagekit install` still does not call `enable()`. Its description and help say the install lifecycle runs and activation is `php pagekit enable`. The console module already adds every `*Command.php`.

| File | Change |
|---|---|
| `app/package/app/lib/impact-query.js` (new) | Posts to the impact route. `canProceed` is true only when `blockers`, `orphans`, and `dataRisk` have the expected shape and `blockers` is empty. A failed read or any other shape sets `impactFailed` and leaves the button out. |
| `app/package/app/lib/impact.vue` (new) | One sentence each for blockers, orphans, and data risk, including the empty data-risk line. Tables are named and said to be left as they are. |
| `app/package/app/lib/disable.vue` (new) | The status-toggle confirm. Disable renders only when `canProceed`. |
| `app/package/app/lib/package.js` | `disable()` opens that confirm. `commitDisable` is the switch-off. `enable` still notifies `response.data.error`. |
| `app/package/app/lib/uninstall.vue` | The confirm shows the same impact. Remove renders only when `canProceed`. |
| `app/console/src/Commands/EnableCommand.php` (new) | Loads a registered module, then `enable()`. Prints a requirement refusal and returns failure. Other throwables propagate. No `--force`. Success prints the title, or the package name. |
| `app/console/src/Commands/DisableCommand.php` (new) | Calls `disable()` on the resolved packages. Prints `RemovalBlockedException` and returns failure. Other throwables propagate. No `--force`. |
| `app/console/src/Commands/InstallCommand.php` | Description and help say install runs the lifecycle and does not enable; activation is `php pagekit enable`. `execute()` does not call `enable()`. |

#### Tests (Checklist Step 9)

| File | Change |
|---|---|
| `tests/Unit/Console/ActivationCommandInstallation.php` (new) | Fixture packages and modules the enable and disable commands resolve, including whether `main()` ran. |
| `tests/Unit/Console/EnableCommandTest.php` (new) | No `--force`. A disabled or unregistered requirement is printed, `extensions` stay, and `main()` does not run. An unregistered module is enabled without `load()`. A registered module runs `main()` before it is enabled. An empty title uses the package name. A cycle, an unknown name, and a catalogue that is not a factory propagate, and an unknown name changes nothing. |
| `tests/Unit/Console/DisableCommandTest.php` (new) | No `--force`. A blocked package and a blocked pair are printed and `extensions` stay. An unknown name throws before any package changes. Success prints the title or the package name. A catalogue that is not a factory throws. |
| `tests/Unit/Console/InstallCommandTest.php` | The description and help say install does not activate. A successful install leaves the new module out of `extensions`. |

Gates: production verifier FAIL (comment length in `impact-query.js`) then PASS after a production retry; PHPUnit + PHPStan PASS; test-writer done; test-file verifier PASS; PHPUnit + PHPStan PASS.

---

## 🧠 Key Decisions (Rationale)

- **No policy yet means registered modules are active.** Treating "absent from `extensions`" as disabled would fail `load('system')`, which walks core `require` before `SystemModule::main` can set the closure. An unregistered require still throws out of that load and is not an extension auto-disable. A registered-but-disabled require throws only after the policy is set, and that module is not loaded.
- **`enable()` is not `load()`.** `assertRequirements` returns when the root is not registered, so a package can be enabled without its module being in the registered set. `load()` of an unknown name still throws `Undefined module: $name`. A registered module throws before any config write, lifecycle hook, or `package.enable`.
- **A disabled module is not walked.** A cycle reachable only through one is reported as registered-but-disabled. Walking it would load it. A cycle among the module being activated and modules that are active still throws `Circular requirement "%s > %s" detected.`, and the disabled module stays out of the loaded set.
- **The sentence is translated in the controller.** The exception message is the English sentence with the names filled in. The resolver does not call `__()`, because the walk runs before the translator is booted. `enableAction` translates `messageId()` even when debug is off. Any other enable failure, including a circular requirement, stays the generic enable error when debug is off.
- **`requiredBy` is direct and cached.** Registration order, duplicates once, cache dropped on `register()`. The always-loaded closure (the boot module plus registered modules reachable through `require`) is rebuilt then. A module `system` requires is active after the policy is set even when it is absent from `extensions`.
- **The enabled set is a copy taken at the start of `main`.** `extensions` plus a non-empty `site.theme` — the list `ExtensionLoader` loads. Reading the config service on each check would see names `enable()` pushes onto that object later in the request. A theme set when `main()` starts satisfies a requirement even when it is absent from `extensions`.
- **The walk takes a module name.** `assertPackageRequirements(string)` skips a non-string `Package::get('module')`. The parameter stays `string` because `Package::get` is the generic container.
- **`Arr::pull` packs through the reference.** `$array = array_values($array)` after `unset`. `$array = &$packed` rebinds the parameter, so `Config::pull` would write the gapped array back. `Config::pull('extensions', $name)` on `['needs-off', 'pages']` leaves `['pages']` at index 0.
- **Handler cleanup pops the frame it pushed.** `restore_error_handler()` and `restore_exception_handler()`. Reinstalling the previous callable pushes another frame, and skipping that call when the previous handler is null leaves the new frame in place. After `enableAction()` returns, both stacks match the ones that were active when the action was entered.
- **Both prefixes share `kernel/src`.** `Pagekit\` and `Pagekit\Kernel\` map to the same directory, so `Pagekit\Application` and `Pagekit\Kernel\HttpKernel` load from there. A prefix per sub-namespace (`Pagekit\Application\` → `src`) would resolve `Pagekit\Application\Exception` to `src/Exception.php` and would not load the `Pagekit\Application` class. `application` stays the composition root and requires `kernel`. `kernel` does not require the stack.
- **`Pagekit\Twig\` is a Composer prefix of `view`.** It maps to `app/modules/view/src/Twig`, the same tree as the view manifest. Leaving it off Composer lets an optimized dump scan `Pagekit\View\`'s `src` and skip a `Pagekit\Twig` class in that tree. There is no `view/twig` module.
- **Console activation shares the panel seam.** `enable` / `disable` commands call `PackageManager` like the panel and uninstall already do. Rejected a panel-only gate or a `--force` path.
- **`isAuthenticated` is the auth contract.** `User` still checks the authenticated role. `CaptchaListener` calls `UserInterface` and does not import `Pagekit\User`. The login check asks `hasAccess('system: software updates')`. A signed-in flag does not decide whether that check runs. `AdministratorWhoUpdates::isAuthenticated()` returns true for the signed-in administrator that fixture uses.
- **Filesystem uses Symfony's reference-type integers.** `ABSOLUTE_PATH` is `1` and `NETWORK_PATH` is `3`, the integers the Pagekit generator inherited. `getUrl($file)` is the path form, `getUrl($file, 3)` the network path, and `getUrl($file, true)` the absolute URL.
- **Debug owns the SQL middleware.** One `DebugMiddleware` and its logger, registered at the start of `main` (the bar may be off), shared by every connection. A new interface, or constructing the middleware in `database/index.php`, would make that file name `Pagekit\Debug`. A new logger per connection would leave the bar with only the last one. When `db.middlewares` is absent, `dbs` adds no wrapper. When debug has loaded, every connection uses that middleware and `db.debug_logger` is the logger the bar reads.
- **Held middlewares are appended.** Entries that implement `Doctrine\DBAL\Driver\Middleware` are added after any the connection config already listed. Other entries are skipped. Replacing the key would drop the connection's own list. An absent `db.middlewares` leaves that list unchanged.
- **`ValidatesRequestTrait` is routing's.** Site, user, and widget import it, and `system` already requires those three. Leaving the trait on `system` would cycle. `system/site` is the wrong home: user and widget use it, and site already imports user. Those three require `routing` and do not import `Pagekit\System`.
- **`DataModelTrait` is database's.** The trait imports `Pagekit\Database`. A home on site, user, or widget would cycle (site imports user; widget imports both). `kernel` would cycle the other way: the trait imports database. Site, user, and widget require `database` and do not import `Pagekit\System\Model`.
- **Node types sit beside `Node`.** `NodeInterface` and `NodeTrait` are `Pagekit\Site\Model`. No core module outside `system/site` imports them.
- **`Unique` sits with the connection.** The validator takes `Connection`, so the constraint and the validator are `Pagekit\Database\Validator\Constraints`. `system/user` is the wrong home: `system` registers the validator for every model. `User` imports `Unique` from database. `ValidatorServiceProvider` imports `UniqueValidator`. `system` reaches database through `application` and does not require `database`.
- **A function or const import is not an edge.** `__()` has no class file. `use function Pagekit\__` adds no `system/intl` require.
- **A namespace import is not a class.** `use Pagekit\Database\ORM\Attribute` names a prefix; the interface is `Attribute\Attribute`. Attribute classes under that prefix are the edges. The same use inside `database` is not an edge. Comment, site, user, and widget require `database`.
- **The scan is production PHP.** `Tests/` and `tests/` are ignored. A database test that imports `Application` would cycle, because `application` requires `database`, and `Application` stays in `kernel`. Production code has no parent-import exception.
- **`require()` is the list the PHP array yields.** An omitted key is appended. `"-0"` stays a string key, so the next omitted name is integer 0 and does not replace it. From PHP 8.3 a negative integer continues at n+1, so the name after `"-4"` is `-3` and a later `"0"` does not replace it. A counter that starts at 0 and treats `(int)"-0"` as 0 would store the name after `"-4"` at 0, where a later `"0"` overwrites it. That name was never overwritten in the PHP array, so it stays in `require()`. An omitted key once the next index cannot be allocated is refused, because that literal throws. A missing `require` is `[]`, including when a spread precedes the literal keys and `require` is not written again. A spread, a non-string, or a non-literal key inside `require` is refused like a non-literal `autoload`. The file is not executed. `['-0' => 'unregistered', 'system']` is `['unregistered', 'system']`. `['-4' => 'kept', 'unregistered', '0' => 'system']` is `['kept', 'unregistered', 'system']`. `open()` throws `ArchiveRefusedException` mentioning `'require'` when the value is not an array of string literals or the next index is past `PHP_INT_MAX`, and writes nothing.
- **The overlay does not stay.** `assertRequirementsUsing` sets the archive list on the named manifest, inserting one when the name is not registered, and restores that manifest and the `requiredBy` cache whether the walk returns or throws. Leaving the list in place would stick if replacing the tree then failed. After the call, `isRegistered` and `requiredBy` match what they were. A cycle still throws `Circular requirement "%s > %s" detected.` A registered-but-disabled requirement still throws `Module "%depender%" requires "%required%", which is registered but disabled.` `enable()` afterwards still walks the require from before the call.
- **Activation is only the enable path.** The first unregistered name throws `ArchiveRefusedException` (`Module "%depender%" requires "%required%", which is not registered.`, the missing name through `printable`, the depender `PackageArchive::module()`). The activation walk runs only when `install()` is about to `enable()`, on the module `index.php` names. Walking it on upload or on a fresh install would refuse a registered-but-disabled requirement that activation is allowed to meet later. Overlaying the previously installed module would attach this archive's `require` to a different name. Upload of an unregistered name is a 400 before `move()`. `install()` throws before `replaceTree`, so nothing is created under `packages/`. A fresh install whose requirement is registered but disabled still unpacks. An update of a loaded package whose archive requires a registered-but-disabled module throws before `replaceTree`.
- **No registry means not registered.** A `module` service that is not a `ModuleManager` cannot show a name as registered, so a non-empty `require` is refused. Skipping that check would install a name nobody can look up. An empty `require` with nothing to activate does not read the service. With no `ModuleManager`, `require()` of `['missing']` throws `ArchiveRefusedException` naming `missing` before a file is written. `require()` of `[]` does not read the service.
- **The server is asked only when the bytes differ.** `TableNameFold` is the one comparison. A byte prefix matches without `SHOW`. The server is asked only when the folded forms match and the bytes do not, and only `lower_case_table_names` of `1` or `2` (string or int) then counts the table. Any other value, or no row, keeps the byte comparison. Folding every comparison was rejected: `name()` runs while the dump is read, before the lock, and a neighbour would ask on every dump. The variable is read with `SHOW GLOBAL VARIABLES`, because the connection substitutes the installation prefix for an `@`-led name outside quotes. SQLite never runs that statement. `pk_items` against `pk_` issues no `SHOW`. `other_items` against `pk_` issues none. `pk_items` against `PK_` matches only when the answer is `1` or `2`. `RestoreTableNames::isReserved` stays a byte match: a marker in another case is not a name this restore wrote. A same-case MySQL refusal still asks `lower_case_table_names` after `GET_LOCK` and before `REFERENTIAL_CONSTRAINTS`.
- **A database that lists no table is still a dump.** `read()` throws `The dump selected no table.` before `stage()` only when the database lists at least one table and the prefix selects none. A database that lists nothing is written (header and the closing line, zero tables). Throwing then was rejected: uninstall snapshots first, and the uninstall suites open SQLite that has never had a table, so the removal would stop with no dump file. Putting the sentence on the outer exception was rejected: `dump()` already wraps every failure as `Failed to dump the database to "%s".` A connection with no tables returns `['tables' => 0, 'rows' => 0]` and the finished path exists. A database whose only table lies outside the prefix throws, `getPrevious()` is exactly `The dump selected no table.`, and the finished path and the `.part` file are both absent. `DatabaseRestorer::end()` still throws `The database dump holds no tables, so there is nothing in it to restore.`
- **A sibling in this call still blocks.** An enabled depender counts even when it is also in the call. A self-require does not. Subtracting the whole call was rejected: uninstall would snapshot the first package, then `disable()` of that package alone would throw because the sibling is still enabled. `uninstall` of A and B, where enabled B requires A, throws `RemovalBlockedException` before any snapshot and both stay installed. `disable()` of a module whose only depender is itself still pulls it from `extensions`.
- **Orphans are what this call would leave behind.** Names in this call are not orphans. A required name is one only outside the always-loaded closure, and only when no enabled module outside this call requires it. Before `setActivityPolicy` that closure is empty. `removalImpact` does not list the package's own module. It lists a required module that is not always-loaded when this package is the only enabled depender. After the policy, a module the boot module requires is not an orphan.
- **The payload is three keys.** `blockers`, `orphans`, and `dataRisk` (`migrations` bool, `config` bool, `nodes` list, `tables` list). `config` is `ConfigManager::has($module)`; `packages.{module}` on the system row is not a row. `nodes` are the string keys on the registered manifest, in key order. `tables` are the live names, sorted by bytes, for which `TableNameFold::prefixed($name, $prefix . $module . '_')` is true. No connection yields no tables. `pk_blog_post` matches module `blog` and prefix `pk_` with no `SHOW`. `PK_blog_post` matches only when `lower_case_table_names` is `1` or `2`. `pk_blogpost` and `other_blog_post` do not. Orphans and data risk do not refuse.
- **The lifecycle file is read once.** A throwable while reading it is no `MigrationSet` and does not refuse. The pre-flight and the disable and uninstall hooks share one `LifecycleRunner`. A second `require` was rejected: a file that declares a class would fatal. `scripts.php` that does not return a lifecycle still drops the module from `extensions` and reports `migrations` false. A `migrations()` that returns a `MigrationSet` reports true and does not refuse. That same object then runs the disable hook and, on uninstall, the uninstall hook.
- **One refusal after every name.** `RemovalBlockedException` is thrown after every name is resolved and before the first snapshot or the first hook. One blocker and one module: `"%blocker%" requires "%name%", so it cannot be switched off.` Otherwise: `"%blockers%" require "%names%", so nothing was switched off.` The name is the module, or the package name when `module` is not a non-empty string. `uninstall(['present', 'missing'])` throws `Unable to find "missing".` and does not snapshot `present`. A blocker names the depender and the target and leaves `extensions` unchanged.
- **Only the active theme clears `site.theme`.** The value is removed when the type is `pagekit-theme` and it equals the module name, and only after the pre-flight allows it. Clearing on a module-name match for an extension was rejected. Disabling the active theme unsets `site.theme`. Disabling another theme or an extension leaves it. A blocked theme stays the active theme.
- **The route reports; disable refuses.** `@system/package/impact` takes the package `name`, requires CSRF, and returns the three keys without throwing on blockers. `disableAction` rethrows `RemovalBlockedException` as `BadRequestHttpException` with the same message. The payload lists an enabled theme that requires the package. That disable is a 400 with the sentence and does not pull `extensions`.
- **A registered module is loaded before `enable()`.** `load()` throws `UnsatisfiedRequirementException`, and a successful enable runs `main()` the way the panel does. An unregistered name is left to `enable()`, which does not walk it. Calling `enable()` alone was rejected: that refusal would be wrapped, and `main()` would not have run. A registered module whose requirement is disabled or missing exits 1 with that sentence, `extensions` is unchanged, and the command does not throw `Undefined module` for a name that is not registered.
- **Only the named refusals are printed.** A requirement refusal and a `RemovalBlockedException` are printed and return failure. Any other throwable, including `Unable to find "%name%".` and `Circular requirement "%s > %s" detected.`, propagates. There is no `--force`. Success prints `"%title%" enabled.` or `"%title%" disabled.` with the title, or the package name when `title` is not a non-empty string. Every name is a package before the first `enable()` or the `disable()` call. Letting the pre-flight exception escape was rejected, and so was passing the command-line strings into the manager.
- **Install help says activation is separate.** `getDescription()` is `Install places the package and runs the install lifecycle. Activation is php pagekit enable.` `getHelp()` adds `It does not enable the package.` `execute()` does not call `enable()`, so a successful install leaves the new module out of `extensions`.
- **A missing `blockers` key is not an empty list.** Remove and Disable render only when `blockers`, `orphans`, and `dataRisk` are present (`migrations` and `config` booleans, `nodes` and `tables` arrays) and `blockers` is empty. A failed read or any other shape leaves the button out. Each result is one sentence, including `No migrations, saved settings, node types, or tables were found.` Tables are named and said to be left as they are. `enable` still notifies `response.data.error`.

---

## 💥 Breaking Changes (Extensions)

Enabling a package whose registered module requires something unregistered or registered-but-disabled fails before the package changes. The panel `error` names both modules even when debug is off. An already-enabled extension in that state is auto-disabled and the sentence is stored on the failure record. A theme is recorded and left on. There is no override.

`Config::pull` on a list (through `Arr::pull`) now returns a packed list. Pulling `needs-off` from `['needs-off', 'pages']` leaves `['pages']` at index 0.

`Pagekit\Application\UrlProvider` and `Pagekit\Application\Response` are `Pagekit\Routing\UrlProvider` and `Pagekit\Routing\Response`. The old names are gone.

`Pagekit\System\Controller\SettingsController` is `Pagekit\Settings\Controller\SettingsController`. The `view/twig` module is gone; `view` registers the Twig environment.

`Pagekit\User\Attribute\Access` is `Pagekit\Routing\Attribute\Access`. `Pagekit\Cache\CacheKeyUtil` is `Pagekit\Util\CacheKeyUtil`. The old names are gone. `UserInterface` requires `isAuthenticated(): bool`.

`Pagekit\System\Controller\ValidatesRequestTrait` is `Pagekit\Routing\ValidatesRequestTrait`. `Pagekit\System\Model\DataModelTrait` is `Pagekit\Database\ORM\DataModelTrait`. `Pagekit\System\Model\NodeInterface` and `NodeTrait` are `Pagekit\Site\Model\NodeInterface` and `NodeTrait`. `Pagekit\System\Validator\Constraints\Unique` and `UniqueValidator` are `Pagekit\Database\Validator\Constraints\Unique` and `UniqueValidator`. The old names are gone.

Uploading or installing an archive whose `require` names a module that is not registered fails before anything is written under `packages/`. The panel upload is a 400. The message names the archive's module and the missing name. A non-literal `require` is refused the same way. An update of an already-loaded package whose archive requires a registered-but-disabled module, or whose requirements cycle, fails before the installed tree is replaced. A fresh install may still unpack a requirement that is registered but disabled.

Disabling or uninstalling a package that an enabled module still requires fails before hooks run and before a snapshot exists. The message names the depender and the target. Several names are checked first; a later refusal does not leave an earlier package removed. There is no override. Orphans and the data-risk hint are reported and do not stop the call. Disabling the active theme clears `site.theme`. `@system/package/impact` returns the three keys and does not throw when blockers exist.

The extensions status toggle asks before a package is switched off. Disable and Remove appear only when the impact answer has no blockers. A failed read or any other payload leaves both out. `php pagekit enable` and `php pagekit disable` call `PackageManager` and have no `--force`. A requirement refusal and a blocked disable are printed and exit 1. An unknown package and a circular requirement still throw. `php pagekit install` still does not enable; its help says activation is `php pagekit enable`.

---

## ⚠️ Risks & Rollout Notes

`load('system')` still fails the request when a core `require` is not registered. That throw is not an extension auto-disable.

An update calls `enable()` without going through `load()` again. The requirement walk in `enable()` is the one that refuses before config is written.

SQL logging is attached only after `debug`'s `main` has run. A boot that never loads that module leaves connections unwrapped.

Core modules that listed no `require` now name one. After the activity policy is set, a registered-but-disabled name in that list is not loaded.

A fresh install does not walk activation. A package can land while a requirement it names is registered but disabled. Enabling it later is what refuses.

A prefix that matches none of the tables the database lists refuses the dump and leaves no file. Uninstall snapshots first, so that refusal removes nothing. A database that has never held a table is still written; a later restore still refuses a dump that holds no tables.

Uninstall of two packages where one still requires the other is refused entirely. The sibling stays a blocker while it is enabled, so the call does not snapshot the first and then fail on the second. A reported orphan stays installed. A reported table stays in the database.

`enable` of a registered module runs `load()` before the package is marked enabled, so `main()` has already run when the command prints success. A requirement refusal stops before that. A circular requirement still throws, and the module stays out of `extensions`.

A failed impact read hides Disable and Remove. The server still refuses a switch-off those buttons never offered.

---

## 🔐 Security & Data Impact

A registered-but-disabled requirement is not loaded. The resolver does not recurse into it.

Captcha treats any `UserInterface` whose `isAuthenticated()` is true as signed in.

An unregistered or non-literal `require` never lands under `packages/`. The upload is refused before the file is staged. The archive file is not executed.

A selection of no table among the ones listed never becomes a finished dump. The staging file is removed on that failure.

A blocker is refused before the package is switched off and before a snapshot is taken. `@system/package/impact` requires the package name and CSRF and does not itself switch anything off.

Disable and Remove stay out unless the impact answer has an empty blockers list. The enable notify still shows the named requirement refusal.

---

## 🛡️ No-Mercy Compliance

`resolveModules` no longer logs an unsatisfied `require` and continues. Nothing still loads that module, and there is no flag that asks it to.

The URL classes live under `routing`. The old files are deleted, and call sites name `Pagekit\Routing\UrlProvider` and `Pagekit\Routing\Response`.

The foundation sits in `kernel` beside HttpKernel. `app/modules/application/src`, the `view/twig` module, and `system/view`'s `Pagekit\View\` autoload are deleted. No alias.

`#[Access]` and `CacheKeyUtil` live under `routing` and `kernel`. The old files are deleted. `database` does not name `Pagekit\Debug`. `Filesystem` and `CaptchaListener` do not import the modules they used to. No alias.

Imports that would cycle were moved. The old `Pagekit\System` trait, node types, and unique constraint are deleted. The edge test has no parent-import exception. No alias.

The archive `require` is the literal list. The file is not executed. A refusal writes nothing under `packages/`. The overlay is restored before the tree moves. No flag asks the check to continue.

Selection, dump ownership, and restore collisions share `TableNameFold`. `isReserved` is still the bytes a restore writes. There is no second fold.

Disable and uninstall share `PackageImpact`. Orphans are reported and not removed. There is no flag that skips a blocker. The Step 2.7.2 TODO on `uninstallAction` is gone.

The panel and the console call `PackageManager`. There is no `--force`. `php pagekit install` does not enable.

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: `Arr::pull` writes the reindexed list back after `unset`. `enableAction` restores the previous error and exception handlers. Plan refine: Steps 2–11 tightened (existing `kernel`, console on the manager seam); Step 1 unchanged; no PHASE amendment. Step 3: none. Step 4: production verifier FAIL (docblocks) then PASS; test verifier FAIL (the login double never ran login; ownership docblock) then PASS. The login check is `hasAccess`; `isAuthenticated` is what captcha calls. Step 5: none. Step 6: none. Step 7: PHPUnit + PHPStan FAIL then PASS after a production retry. Step 8: none. Step 9: production verifier FAIL (comment length in `impact-query.js`) then PASS after a production retry.

---

## 📋 Phase 1 Audit Closure

_TBD / None_

---

## 👤 Maintainer action

<!-- Human-only follow-ups the maintainer must do (ruleset flips, real Docker/Apache
     verification, secrets, etc.). Not ROADMAP deferrals — those go under Deferred. -->

_TBD / None_

---

## 📚 Deferred / Out-of-Scope

- **Step 2.7.3** — static discovery must carry `require` and retarget the archive parser that reads it (PHASE §2.7.3).
- **Step 2.8** — author contract states the unregistered-`require` refusal (PHASE §2.8).
- **Step 5.0** — automatic orphan removal and install-reason bookkeeping; a package-scoped restore; a purge that drops the tables the pre-flight names (PHASE §5.0).
- **Non-goals:** which characters an install prefix may contain; marketplace (5.6).
- **Bridges:** none planned for the remaining checklist.

---

## 📌 Follow-on (ROADMAP)

- 2.7.3 — Static Module Registration
- 2.8 — Package Author Contract
- 5.0 — Package lifecycle data ownership (orphans / purge / scoped restore)

---

## 🧊 Parked (unplanned)

<!-- Filled by the post-close review after Finalize: what the finished work left unowned,
     one bullet per finding with the ROADMAP step whose area it belongs to. Doc-writer leaves None. -->

_TBD / None_

---

## 🧹 Cleanup

<!-- Removed in passing (deleted files, dropped baseline/ignore entries, dead code). Doc-writer from
     the handover; the post-close review adds what the diff shows and the handover missed. -->

_TBD / None_

---

## 🛡️ Audit

<!-- No-Mercy leftovers of the shipped diff that have no owner (forward-debt tags, added baseline
     entries, ANOMALIES patterns), each with the ROADMAP step that resolves it. Post-close review. -->

_TBD / None_

---

## 🎁 Bonus

<!-- Work delivered beyond the ticket. Doc-writer from the handover; the post-close review adds
     what the diff shows and the handover missed. -->

_TBD / None_

---

## 🔍 Research

<!-- The verified facts behind each DECISION the post-close review raised — symbols, call chain,
     what each exit deletes or adds — so the maintainer can decide without re-reading the tree. -->

_TBD / None_

---

## 📎 Related Documents

- Ticket: `migration-docs/tickets/active/PROMPT_2_7_2_Module-Dependency-Integrity_plan.md` (_TBD_ → move to `done/` after Finalize)
- Task prompt: `migration-docs/TODO/agent_prompts/phase-2/PROMPT_2_7_2_Module-Dependency-Integrity.md`
- Predecessor: Step 2.7.1c — Runtime Composer Removal
- Successor: Step 2.7.3 — Static Module Registration

---

## 📊 <Step-specific appendix>

<!-- Narrative/structural notes only. Never a metrics table (coverage %, MSI, test counts): quality
     numbers are CI-owned — link the sticky quality-report comment + dashboard instead. -->

_TBD — remove this section if not applicable._
