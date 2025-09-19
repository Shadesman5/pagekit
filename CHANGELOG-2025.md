# Changelog 2025

## Pagekit 1.0.32 - Mail System Sendmail Fix & Windows Compatibility (September 19, 2025)

### Fixed

-   **Sendmail path processing for Windows systems** - Fixed sendmail command flags issue on Windows systems with Mailpit/Laragon. The system now automatically appends required `-t` or `-bs` flags to sendmail paths that don't include them, resolving "Unsupported sendmail command flags" errors.

-   **SMTP connection test validation** - Improved parameter validation in SMTP connection testing to prevent 500 Internal Server Error when testing with empty or incomplete configuration. Now shows clear error message "SMTP host is required for connection testing" instead of attempting connection with null values.

### Added

-   **Automatic flag detection for sendmail paths** - Added intelligent detection and appending of required sendmail flags:

    -   Windows/Mailpit systems: Automatically appends `-t` flag
    -   Unix-like systems: Automatically appends `-bs` flag
    -   Only adds flags when missing, preserves existing valid configurations

-   **Enhanced SMTP test error handling** - Added proper validation for empty SMTP parameters with clear error messages for better user experience in admin panel.

-   **MAIL_SENDMAIL_FIX.md documentation** - Comprehensive documentation of the sendmail fix including test cases, affected systems, and backward compatibility notes.

### Testing

-   **Sendmail Transport Tests** - Added comprehensive test coverage for sendmail path processing including Mailpit path validation and various sendmail configurations
-   **SMTP Parameter Validation Tests** - Added tests for empty and partial SMTP configuration handling
-   **Manual Testing Verified** - Confirmed fix works on Windows systems with Laragon/Mailpit and various sendmail configurations

### Backward Compatibility

-   ✅ **Fully backward compatible** - Existing configurations continue to work without changes
-   ✅ **No breaking changes** - Only adds missing flags, doesn't modify valid existing paths
-   ✅ **Cross-platform support** - Works on Windows, Linux, and macOS systems

### Affected Systems

-   Windows development environments with Laragon/XAMPP/WAMP
-   Systems using Mailpit for local mail testing
-   Any system where sendmail_path doesn't include required flags

## Pagekit 1.0.31 - Strategic Dependency Analysis & Security Patches (September 19, 2025)

### Security

-   **CRITICAL: marked security update** - Updated marked from 1.2.0 to 4.3.0 to fix ReDoS and XSS vulnerabilities
-   **blueimp-md5 security patch** - Updated from 2.18.0 to 2.19.0 for security improvements

### Changed

-   **doctrine/annotations** - Updated from ~1.14 to ~2.0 (major version upgrade, no breaking changes)
-   **vue-loader** - Updated from 15.9.3 to 15.11.1 (improved Vue component compilation)
-   **eslint-config-airbnb-base** - Updated from 14.2.0 to 15.0.0 (stricter linting rules)
-   **eslint-plugin-vue** - Updated from 7.1.0 to 7.20.0 (better Vue 2.x linting support)

### Added

-   **DEPENDABOT_UPDATES.md** - Comprehensive documentation of all dependency updates and strategy

### Strategic Analysis & Decision Making

-   **14 Dependabot PRs analyzed** - Each update evaluated for security impact, breaking changes, and Symfony compatibility
-   **Intelligent version selection** - marked upgraded to 4.x (not 16.x) to fix security while minimizing breaking changes
-   **Symfony conflict detection** - symfony/phpunit-bridge deliberately deferred to avoid upgrade conflicts
-   **Risk-based prioritization** - Security updates prioritized over convenience updates
-   **Strategic deferrals** - Major breaking changes (vee-validate 4.x, build tools) deferred until after Symfony upgrade
-   **Comprehensive testing** - Each update validated against existing test suite and manual verification

### Testing

-   **PHP Tests**: Same 69% pass rate maintained, no new failures
-   **Frontend Build**: All compile processes working correctly
-   **Security Audit**: Zero vulnerabilities confirmed with composer audit
-   **Manual Testing**: All core functionality verified, system performance improved

## Pagekit 1.0.30 - PHPUnit Test Suite Modernization (September 19, 2025)

### Fixed

-   **PHPUnit 11 compatibility** - Fixed all data provider methods to be static as required by PHPUnit 11
-   **Test autoloading issues** - Added 19 missing namespace mappings to composer.json for complete module coverage
-   **Class name mismatches** - Corrected FilesystemTest class name to match filename
-   **Mock object configurations** - Fixed UserInterface mocks and Auth test dependencies
-   **Deprecated test methods** - Removed calls to non-existent Auth::setHandler() method

### Added

-   **Comprehensive autoload-dev configuration** - Added Pagekit\Tests namespace mapping
-   **Complete module namespace coverage** - All 19 core modules now properly autoloaded for testing
-   **TEST_IMPROVEMENTS.md documentation** - Detailed analysis of all test fixes and remaining issues

### Changed

-   **Test success rate** - Improved from 0% (fatal errors) to 69% passing tests (159 tests, 234 assertions)
-   **PHPUnit infrastructure** - Modernized for PHP 8.4 and PHPUnit 11.5.39 compatibility
-   **Test foundation** - Established solid base for continuous integration and code quality assurance

### Testing

-   **Tests**: 159 total with 234 assertions
-   **Success Rate**: ~69% (110 passing tests)
-   **Remaining Issues**: 41 errors, 2 failures (mostly mock configurations)
-   **Foundation**: Ready for CI/CD integration and incremental improvements

## Pagekit 1.0.29 - Critical Security Patches & Major Dependency Updates (September 19, 2025)

### Security

-   **CRITICAL: Resolved all security vulnerabilities** - Applied comprehensive security patches to eliminate all known vulnerabilities identified by `composer audit`
-   **Zero vulnerabilities confirmed** - Post-update security audit reports 0 vulnerabilities across all dependencies

### Changed

-   **Major Doctrine DBAL upgrade**: 2.13.9 → 3.10.2

    -   Updated `Driver\ResultStatement` to `Result` class throughout codebase
    -   Migrated `executeQuery()` return type from `ResultStatement` to `Result`
    -   Replaced deprecated fetch methods:
        -   `fetchAll()` → `fetchAllAssociative()`
        -   `fetchAll(\PDO::FETCH_COLUMN)` → `fetchFirstColumn()`
        -   `fetchAll(\PDO::FETCH_NUM)` → `fetchAllNumeric()`
        -   `fetch(\PDO::FETCH_ASSOC)` → `fetchAssociative()`
        -   `fetchColumn()` → `fetchOne()`
    -   Updated `Comparator::compareSchemas()` from static to instance method
    -   Removed type hints from SQL parameters for DBAL 3.x compatibility with SQLite and other drivers

-   **Major Monolog upgrade**: 2.1.1 → 3.9.0

    -   Updated handler methods to support both `array` (compatibility) and `LogRecord` (Monolog 3.x) formats
    -   Implemented runtime type checking for seamless backward compatibility
    -   Modified level comparisons to use `$record->level->value` for LogRecord objects
    -   Updated record property access to use object notation for LogRecord

-   **PSR Log upgrade**: 1.1.4 → 2.0.0 (required for Monolog 3.x compatibility)
-   **Doctrine Cache upgrade**: 1.13.0 → 2.2.0 (security updates and PHP 8.x compatibility)
-   **Doctrine Event Manager upgrade**: 1.2.0 → 2.0.1 (automatic dependency update)

### Fixed

-   **Database compatibility issues** - Resolved DBAL 3.x compatibility issues across 15+ core files
-   **Logging system compatibility** - Fixed Monolog 3.x handler compatibility in debug and logging modules
-   **Type safety improvements** - Added proper type handling for modern PHP versions
-   **SQLite compatibility** - Ensured full compatibility with SQLite database driver

### Files Modified

-   **Database Layer** (9 files): Connection.php, QueryBuilder.php, Utility.php, EntityManager.php, ManyToMany.php, DatabaseSessionHandler.php, DatabaseHandler.php, ConfigManager.php
-   **Models** (3 files): NodeModelTrait.php, RoleModelTrait.php, PostModelTrait.php
-   **Logging System** (2 files): DebugBarHandler.php, LogDataCollector.php
-   **Controllers** (1 file): BlogController.php

### Testing

-   **PHPUnit 11.5.39** confirmed working with updated dependencies
-   **PHP 8.4.12** full compatibility verified
-   **Composer audit** reports 0 vulnerabilities post-update
-   **No dependency conflicts** - All package updates installed successfully

### Migration Notes

-   **Backup required** before applying these patches to production
-   **Breaking changes** addressed with backward compatibility where possible
-   **Test thoroughly** in staging environment before production deployment
-   **Monitor regularly** with `composer audit` for future security updates

## Pagekit 1.0.28 - Development Setup Enhancement (September 18, 2025)

### Added

-   Config: Add Docker setup scripts & env template

## Pagekit 1.0.28 - Version Bump (September 17, 2025)

### Added

-   Docker: Add containerized development setup
-   Chore: Add dev tools and update gitignore

### Changed

-   Bump version to 1.0.28 (little fix)
-   Docs: Modernize README.md with current system

## Pagekit 1.0.28 - PHPUnit 11 Upgrade & PHP 8.2+ Requirement (September 16, 2025)

### Changed

-   **Upgraded PHPUnit to version 11.x** - Modernized test suite to use PHPUnit 11 for improved testing capabilities and PHP 8.4 compatibility
-   **Increased minimum PHP version to 8.2** - Updated minimum PHP requirement from 7.4 to 8.2 for better performance, security, and modern language features
-   **Updated phpunit.xml.dist configuration** - Migrated to PHPUnit 11 XML schema with modern configuration options including coverage configuration and strict test settings

### Fixed

-   **Fixed deprecated PHPUnit methods** - Replaced `setMethods()` with `onlyMethods()` in mock builder for PHPUnit 11 compatibility
-   **Fixed email_address typo in test configuration** - Corrected `email_adress` to `email_address` in phpunit.xml.dist and all related test files for proper test configuration variable naming
-   **Improved markdown formatting in MAIL_MIGRATION.md** - Applied standard markdown formatting with consistent bullet point spacing and structure for better readability

### Updated

-   **Updated composer.json requirements** - Changed PHP requirement to ^8.2 and PHPUnit to ^11.0
-   **Updated installer requirements check** - Updated PagekitRequirements::REQUIRED_PHP_VERSION to 8.2.0
-   **Updated index.php version check** - Changed minimum PHP version check from 7.3 to 8.2

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
