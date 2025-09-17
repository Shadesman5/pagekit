# Changelog 2025

## Pagekit 1.0.28 - Version Bump (September 17, 2025)

### Added

-   Docker: Add containerized development setup
-   Chore: Add dev tools and update gitignore

### Changed

-   Bump version to 1.0.28
-   Docs: Modernize README.md with current system

## Pagekit 1.0.28 - PHPUnit 11 Upgrade & PHP 8.2+ Requirement (September 16, 2025)

### Changed

-   **Upgraded PHPUnit to version 11.x** - Modernized test suite to use PHPUnit 11 for improved testing capabilities and PHP 8.4 compatibility
-   **Increased minimum PHP version to 8.2** - Updated minimum PHP requirement from 7.4 to 8.2 for better performance, security, and modern language features
-   **Updated phpunit.xml.dist configuration** - Migrated to PHPUnit 11 XML schema with modern configuration options including coverage configuration and strict test settings

### Fixed

-   **Fixed deprecated PHPUnit methods** - Replaced `setMethods()` with `onlyMethods()` in mock builder for PHPUnit 11 compatibility

### Updated

-   **Updated composer.json requirements** - Changed PHP requirement to ^8.2 and PHPUnit to ^11.0
-   **Updated installer requirements check** - Updated PagekitRequirements::REQUIRED_PHP_VERSION to 8.2.0
-   **Updated index.php version check** - Changed minimum PHP version check from 7.3 to 8.2

---

## Pagekit 1.0.27 - Documentation Improvements (September 16, 2025)

### Fixed

-   **Fixed email_address typo in test configuration** - Corrected `email_adress` to `email_address` in phpunit.xml.dist and all related test files for proper test configuration variable naming
-   **Improved markdown formatting in MAIL_MIGRATION.md** - Applied standard markdown formatting with consistent bullet point spacing and structure for better readability

---

## Pagekit 1.0.27 - Symfony Mailer Migration & Comprehensive Tests (September 15-16, 2025)

### Changed

-   **Completed Swift Mailer to Symfony Mailer 5.4 migration** - Fully migrated email system from deprecated Swift Mailer to modern Symfony Mailer 5.4. All email functionality now uses Symfony's modern mail component with improved performance and maintainability

### Added

-   **Comprehensive mail system test suite** - Added 42 tests covering all mail functionality including unit tests for Mailer, Message, and Plugin classes, plus integration tests for complete email workflows
-   **SMTP connection testing functionality** - Added test connection feature in admin panel to verify SMTP settings before saving
-   **Mail plugin system** - Implemented extensible plugin architecture for mail processing with ImpersonatePlugin as default implementation
-   **Enhanced error handling** - Improved error reporting and exception handling throughout the mail system

### Fixed

-   **MailController SMTP test parameter handling** - Fixed parameter mismatch between controller and mailer for SMTP connection testing
-   **Message::send() error handling** - Corrected return values and error collection in message sending methods
-   **Missing EsmtpTransport import** - Added missing Symfony Mailer transport imports
-   **Email address typo in test configuration** - Corrected `email_adress` to `email_address` in phpunit.xml.dist and all related test files
-   **Improved markdown formatting** - Applied standard markdown formatting with consistent bullet point spacing in documentation files

### Technical Details

-   No Swift Mailer references remaining in codebase
-   Full compatibility with Symfony Mailer 5.4
-   Maintains backward compatibility with existing mail configuration
-   Support for SMTP and Sendmail transports
-   Extensible plugin architecture for custom mail processing

---

## Pagekit 1.0.27 - Login Fix & Security Improvements (September 15, 2025)

### Fixed

-   **Fixed modal login retry mechanism for CSRF errors** - Implemented automatic retry when session expires during login process. Users no longer need to click the login button twice when their session has expired. The system now automatically handles CSRF token refresh and retries the login request transparently.

### Added

-   **Enhanced .htaccess security configuration** - Added modern security headers including HSTS, X-Content-Type-Options, X-XSS-Protection, X-Frame-Options, Permissions-Policy, and Referrer-Policy for improved security posture
-   **DSGVO-compliant local font implementation** - Replaced Google Fonts with local font files to ensure GDPR compliance and eliminate external data transfers
-   **Content Security Policy (CSP) implementation** - Added restrictive CSP headers to prevent XSS attacks and unauthorized resource loading
-   **Local font system for theme-one** - Created `local-fonts.less` with Open Sans and Roboto Mono font definitions using local font files instead of Google Fonts
-   **GitHub Dependabot configuration** - Added `.github/dependabot.yml` for automated dependency updates across Composer (PHP), npm/Yarn (JavaScript), Docker, and GitHub Actions with weekly schedules and proper reviewer assignments

### Changed

-   **Updated theme-one template** - Modified template.php to load local fonts before theme CSS
-   **Replaced Google Fonts imports** - Removed external Google Fonts @import statements from theme variables.less
-   **Improved font loading performance** - Implemented font-display: swap for better loading experience

---

## Pagekit 1.0.26 - PHP 8.4 Compatibility (September 12, 2025)

### Added

-   Development tools configuration (`.editorconfig`, `.php-cs-fixer.php`, `.prettierrc`, `.vscode/settings.json`)
-   `#[\AllowDynamicProperties]` attribute for PHP 8.2+ compatibility

### Fixed

-   Fixed implicit nullable parameters across all modules (28+ files)
-   Fixed deprecated `ReflectionParameter::getClass()` method
-   Fixed `JsonSerializable::jsonSerialize()` return type compatibility
-   Fixed string functions null parameter deprecations (`strpos`, `strstr`, `ltrim`, `substr_count`)
-   Fixed UrlGenerator cache template for PHP 8.4 compatibility
-   Fixed dynamic property creation warnings
-   **Fixed PackageController error handling compatibility with Symfony ErrorHandler** - Replaced deprecated `App::exception()` API with native PHP error handlers to resolve "exception_handler is not defined" errors when enabling extensions. The new implementation provides proper error catching during module activation with clean JSON error responses and debug information display.

### Changed

-   Updated `.gitignore` to exclude build artifacts
-   Improved code formatting in Installer

---

_For complete version history see [CHANGELOG.md](CHANGELOG.md)_
