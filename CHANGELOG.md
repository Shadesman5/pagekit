# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] - PHP 8.4 Compatibility - 2025-09-12

### Added
- Development tools and IDE configuration
  - `.editorconfig` for consistent code formatting
  - `.php-cs-fixer.php` for PHP code style fixing
  - `.prettierrc` for JavaScript/CSS formatting
  - `.vscode/settings.json` for VS Code/Cursor IDE integration
- `#[\AllowDynamicProperties]` attribute to Site Node model for PHP 8.2+ compatibility

### Fixed
- **PHP 8.4 Compatibility Issues:**
  - **Implicit nullable parameters** - Added explicit nullable types (`?Type`) across all modules:
    - Filter module: `FilterManager` constructor parameter
    - Routing module: `RouterListener`, `RoutesLoader`, `AnnotationLoader`, `ConfigureRouteListener`, `ParamFetcherListener`, `ParamFetcher` constructors
    - Database module: `EntityManager`, `QueryBuilder`, `Connection` constructors and methods  
    - Kernel module: `ExceptionListener`, `ControllerListener`, `ControllerResolver`, `HttpKernel` constructors
    - Application module: `PrefixEventDispatcher`, `Arr::extract()` method
    - View module: `View`, `PhpEngine`, `ScriptHelper`, `StyleHelper`, `AssetManager`, `TwigLoader` constructors
    - System module: `Node`, `NodeTrait`, `NodeInterface`, `UserController`, `AccessListener`, `CaptchaListener`, `IntlModule` classes
    - Auth module: `AuthenticateEvent`, `Event` constructors

  - **Deprecated Reflection API** - Replaced deprecated `ReflectionParameter::getClass()` with modern `getType()`:
    - `ControllerResolver`: Updated parameter type checking to use `ReflectionNamedType` and `is_a()`

  - **JsonSerializable compatibility** - Added explicit return types:
    - `Widget\Type::jsonSerialize()`: Added `mixed` return type for PHP 8+ compatibility

  - **String function null safety** - Added null-safe operators for string functions:
    - `MenuHelper`: Fixed `substr_count()` with null-safe operator (`??`)
    - `UrlProvider`: Fixed `strpos()`, `strstr()`, `ltrim()` with null-safe operators

  - **URL Generator cache compatibility**:
    - `UrlGeneratorDumper`: Updated template to generate PHP 8.4 compatible cache files

  - **Dynamic property creation** warnings:
    - `Site\Model\Node`: Added `#[\AllowDynamicProperties]` attribute to allow ORM dynamic properties

### Changed
- Updated `.gitignore` to exclude build artifacts (`theme.css`)
- Improved code formatting and removed unnecessary blank lines in `Installer.php`
- Removed generated CSS files from version control (build artifacts should not be tracked)

### Technical Details

**Commits in this release:**
- `3f7e4fe` - fix: Add null safety to string functions and allow dynamic properties
- `4193d9e` - fix: Add explicit nullable types to Auth module events  
- `4887355` - chore: Add development tools and update gitignore
- `bf93f04` - style: Fix code formatting in Installer
- `aa75dc5` - fix: Update UrlGenerator template for PHP 8.4 cache compatibility
- `6dd8077` - fix: Add null safety to substr_count() in MenuHelper
- `54a18e3` - fix: Add mixed return type to JsonSerializable::jsonSerialize()
- `513b103` - fix: Replace deprecated ReflectionParameter::getClass() with getType()
- `321268a` - fix: Add explicit nullable types for PHP 8.4 compatibility

**Files Changed:** 30+ files across all major modules
**PHP Version:** Full compatibility with PHP 8.4
**Backward Compatibility:** All changes are backward compatible with PHP 7.4+

### Migration Notes

- **Developers:** No action required - all changes are transparent
- **Server Administrators:** Can now safely upgrade to PHP 8.4
- **Extension Developers:** Consider using explicit nullable types in your own extensions

### Testing

This release has been tested with:
- ✅ PHP 8.4 (primary target)
- ✅ Frontend functionality
- ✅ Backend administration
- ✅ Extension system
- ✅ Database operations
- ✅ User authentication
- ✅ Content management

---

## Previous Versions

See git history for previous changes.