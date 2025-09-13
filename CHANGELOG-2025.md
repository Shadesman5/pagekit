# Changelog 2025

## PHP 8.4 Compatibility (September 12, 2025)

### Added
- Development tools configuration (`.editorconfig`, `.php-cs-fixer.php`, `.prettierrc`, `.vscode/settings.json`)
- `#[\AllowDynamicProperties]` attribute for PHP 8.2+ compatibility

### Fixed
- Fixed implicit nullable parameters across all modules (28+ files)
- Fixed deprecated `ReflectionParameter::getClass()` method
- Fixed `JsonSerializable::jsonSerialize()` return type compatibility
- Fixed string functions null parameter deprecations (`strpos`, `strstr`, `ltrim`, `substr_count`)
- Fixed UrlGenerator cache template for PHP 8.4 compatibility
- Fixed dynamic property creation warnings
- **Fixed PackageController error handling compatibility with Symfony ErrorHandler** - Replaced deprecated `App::exception()` API with native PHP error handlers to resolve "exception_handler is not defined" errors when enabling extensions. The new implementation provides proper error catching during module activation with clean JSON error responses and debug information display.

### Changed
- Updated `.gitignore` to exclude build artifacts
- Improved code formatting in Installer

---

*For complete version history see [CHANGELOG.md](CHANGELOG.md)*
