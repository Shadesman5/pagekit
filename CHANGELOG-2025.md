# Changelog 2025

## Pagekit 1.0.27 - Login Fix & Security Improvements (September 15, 2025)

### Fixed
- **Fixed modal login retry mechanism for CSRF errors** - Implemented automatic retry when session expires during login process. Users no longer need to click the login button twice when their session has expired. The system now automatically handles CSRF token refresh and retries the login request transparently.

### Added
- **Enhanced .htaccess security configuration** - Added modern security headers including HSTS, X-Content-Type-Options, X-XSS-Protection, X-Frame-Options, Permissions-Policy, and Referrer-Policy for improved security posture
- **DSGVO-compliant local font implementation** - Replaced Google Fonts with local font files to ensure GDPR compliance and eliminate external data transfers
- **Content Security Policy (CSP) implementation** - Added restrictive CSP headers to prevent XSS attacks and unauthorized resource loading
- **Local font system for theme-one** - Created `local-fonts.less` with Open Sans and Roboto Mono font definitions using local font files instead of Google Fonts

### Changed
- **Updated theme-one template** - Modified template.php to load local fonts before theme CSS
- **Replaced Google Fonts imports** - Removed external Google Fonts @import statements from theme variables.less
- **Improved font loading performance** - Implemented font-display: swap for better loading experience

---

## Pagekit 1.0.26 - PHP 8.4 Compatibility (September 12, 2025)

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
