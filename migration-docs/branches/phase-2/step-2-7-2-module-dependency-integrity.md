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

Remaining work keeps that contract: the import-edge test, archive/disable/uninstall/restore pre-flights, prefix-folded dump selection, and panel plus console activation on the same `PackageManager` seam.

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

### Import edges (Checklist Step 5) — plan

The edge test accepts a transitive `require` that reaches the module directory of the class. It fails cycles and has no parent-import exception.

### Activation and pre-flights (Checklist Steps 6–10) — plan

Archive `require` is read like `autoload` (literals only) and refused before any write under `packages/`. Disable/uninstall share one pre-flight query (blockers / orphans / data-risk); the panel and new console `enable` / `disable` commands call `PackageManager` on that seam — panel-only activation is rejected; no `--force`. Restore refuses on recorded application version and dumped `packages.*` before `reinstate()`. Prefix fold for dump selection sits beside `RestoreTableNames`. PHASE deferrals (2.7.3 / 2.8 / 5.0) already name the follow-ons — no PHASE amendment from this plan pass.

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

---

## 💥 Breaking Changes (Extensions)

Enabling a package whose registered module requires something unregistered or registered-but-disabled fails before the package changes. The panel `error` names both modules even when debug is off. An already-enabled extension in that state is auto-disabled and the sentence is stored on the failure record. A theme is recorded and left on. There is no override.

`Config::pull` on a list (through `Arr::pull`) now returns a packed list. Pulling `needs-off` from `['needs-off', 'pages']` leaves `['pages']` at index 0.

`Pagekit\Application\UrlProvider` and `Pagekit\Application\Response` are `Pagekit\Routing\UrlProvider` and `Pagekit\Routing\Response`. The old names are gone.

`Pagekit\System\Controller\SettingsController` is `Pagekit\Settings\Controller\SettingsController`. The `view/twig` module is gone; `view` registers the Twig environment.

`Pagekit\User\Attribute\Access` is `Pagekit\Routing\Attribute\Access`. `Pagekit\Cache\CacheKeyUtil` is `Pagekit\Util\CacheKeyUtil`. The old names are gone. `UserInterface` requires `isAuthenticated(): bool`.

---

## ⚠️ Risks & Rollout Notes

`load('system')` still fails the request when a core `require` is not registered. That throw is not an extension auto-disable.

An update calls `enable()` without going through `load()` again. The requirement walk in `enable()` is the one that refuses before config is written.

SQL logging is attached only after `debug`'s `main` has run. A boot that never loads that module leaves connections unwrapped.

---

## 🔐 Security & Data Impact

A registered-but-disabled requirement is not loaded. The resolver does not recurse into it.

Captcha treats any `UserInterface` whose `isAuthenticated()` is true as signed in.

---

## 🛡️ No-Mercy Compliance

`resolveModules` no longer logs an unsatisfied `require` and continues. Nothing still loads that module, and there is no flag that asks it to.

The URL classes live under `routing`. The old files are deleted, and call sites name `Pagekit\Routing\UrlProvider` and `Pagekit\Routing\Response`.

The foundation sits in `kernel` beside HttpKernel. `app/modules/application/src`, the `view/twig` module, and `system/view`'s `Pagekit\View\` autoload are deleted. No alias.

`#[Access]` and `CacheKeyUtil` live under `routing` and `kernel`. The old files are deleted. `database` does not name `Pagekit\Debug`. `Filesystem` and `CaptchaListener` do not import the modules they used to. No alias.

---

## ✅ Verification (links only)

<!-- Links only. Quality metrics are CI-owned: link the PR sticky quality-report comment and the
     quality dashboard. Never paste metric numbers (coverage %, MSI, test counts) or build a table here. -->

- CI run: _TBD_
- Notable deviations: `Arr::pull` writes the reindexed list back after `unset`. `enableAction` restores the previous error and exception handlers. Plan refine: Steps 2–11 tightened (existing `kernel`, console on the manager seam); Step 1 unchanged; no PHASE amendment. Step 3: none. Step 4: production verifier FAIL (docblocks) then PASS; test verifier FAIL (the login double never ran login; ownership docblock) then PASS. The login check is `hasAccess`; `isAuthenticated` is what captcha calls.

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
