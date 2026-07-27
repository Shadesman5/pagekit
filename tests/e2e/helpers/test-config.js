/**
 * Test Configuration Helper
 *
 * Central configuration management for Pagekit E2E tests.
 * Loads and validates test configuration from test-config.json
 *
 * @class TestConfig
 * @singleton
 */

const fs = require('fs');
const path = require('path');

class TestConfig {
  constructor() {
    this.testStartTime = null;
    this.stepTimes = [];
    this.config = null;
    this._initialized = false;
  }

  /**
   * Initialize configuration (lazy loading)
   * @private
   */
  _initialize() {
    if (!this._initialized) {
      this.config = this._loadConfig();
      this._initialized = true;
    }
    return this.config;
  }

  /**
   * Load configuration from file
   * @private
   * @returns {Object} Configuration object
   * @throws {Error} If config file is missing or invalid
   */
  _loadConfig() {
    const configPath = path.join(__dirname, '../config/test-config.json');
    const examplePath = path.join(__dirname, '../config/test-config.example.json');

    // Check if config exists
    if (!fs.existsSync(configPath)) {
      // Check if at least example exists
      if (!fs.existsSync(examplePath)) {
        // Critical error - even example is missing
        this.error('CRITICAL: Configuration system corrupted!', '🚨');
        this.error(`Missing: ${examplePath}`);
        this.error('Action: Reinstall test framework');
        throw new Error('Configuration system corrupted - missing example config');
      }

      // No config found - show setup instructions
      this.error('═══════════════════════════════════════');
      this.error('TEST CONFIGURATION NOT FOUND!');
      this.error('═══════════════════════════════════════');
      this.printSetupInstructions();

      throw new Error('test-config.json not found - please create configuration file');
    }

    // Try to parse config
    try {
      const configContent = fs.readFileSync(configPath, 'utf8');
      // Remove BOM if present
      const cleanContent = configContent.replace(/^\uFEFF/, '');
      const config = JSON.parse(cleanContent);

      // Only show success on first load
      if (!this._initialized) {
        // Create temporary config for initial log
        this.config = config;
        this._initialized = true;
        this.success('Loaded test configuration from test-config.json');
        this._initialized = false; // Reset for proper initialization
      }

      return config;
    } catch (error) {
      // JSON parse error - config is invalid
      this.error('JSON Parse Error in test-config.json');
      this.error(`Error: ${error.message}`);
      this.info('Fix: Check JSON syntax at https://jsonlint.com/');
      this.debug(`File: ${configPath}`);
      throw new Error(`Invalid test-config.json: ${error.message}`, { cause: error });
    }
  }

  /**
   * Ensure config is loaded before accessing
   * @private
   */
  _ensureConfig() {
    if (!this._initialized) {
      this._initialize();
    }
  }

  // ═══════════════════════════════════════
  // AUTH CONFIGURATION
  // ═══════════════════════════════════════

  /**
   * Get admin credentials
   * @returns {Object} Admin user credentials
   */
  getAdminCredentials() {
    this._ensureConfig();
    return this.config.auth.admin;
  }

  /**
   * Get test user credentials (falls back to admin if not defined)
   * @returns {Object} Test user credentials
   */
  getTestUserCredentials() {
    this._ensureConfig();
    return this.config.auth.testUser || this.config.auth.admin;
  }

  // ═══════════════════════════════════════
  // SITE CONFIGURATION
  // ═══════════════════════════════════════

  /**
   * Get site URL
   * @returns {string} Base site URL
   */
  getSiteUrl() {
    this._ensureConfig();
    return this.config.site.url;
  }

  /**
   * Get admin URL
   * @returns {string} Admin panel URL
   */
  getAdminUrl() {
    this._ensureConfig();
    return this.config.site.adminUrl;
  }

  /**
   * Get site title
   * @returns {string} Site title
   */
  getSiteTitle() {
    this._ensureConfig();
    return this.config.site.title;
  }

  // ═══════════════════════════════════════
  // INSTALLATION CONFIGURATION
  // ═══════════════════════════════════════

  /**
   * Get installation language
   * @returns {string} Language code (e.g., 'en_US')
   */
  getInstallationLanguage() {
    this._ensureConfig();
    return this.config.installation.language;
  }

  /**
   * Check if demo content should be installed
   * @returns {boolean} Demo content flag
   */
  getInstallationDemoContent() {
    this._ensureConfig();
    return this.config.installation.demoContent || false;
  }

  // ═══════════════════════════════════════
  // DATABASE CONFIGURATION
  // ═══════════════════════════════════════

  /**
   * Get complete database configuration
   * @returns {Object} Database configuration
   */
  getDatabaseConfig() {
    this._ensureConfig();
    return this.config.database || {};
  }

  /**
   * Check if MySQL is enabled
   * @returns {boolean} MySQL enabled flag
   */
  isMySQLEnabled() {
    this._ensureConfig();
    return this.config.database?.mysql?.enabled || false;
  }

  /**
   * Get MySQL configuration
   * @returns {Object} MySQL configuration
   */
  getMySQLConfig() {
    this._ensureConfig();
    return this.config.database?.mysql || {};
  }

  /**
   * Check if SQLite is enabled (default: true)
   * @returns {boolean} SQLite enabled flag
   */
  isSQLiteEnabled() {
    this._ensureConfig();
    return this.config.database?.sqlite?.enabled !== false;
  }

  /**
   * Get SQLite configuration
   * @returns {Object} SQLite configuration with defaults
   */
  getSQLiteConfig() {
    this._ensureConfig();
    return this.config.database?.sqlite || { path: 'pagekit.db', prefix: 'pk_' };
  }

  // ═══════════════════════════════════════
  // TEST SETTINGS
  // ═══════════════════════════════════════

  /**
   * Get all test settings
   * @returns {Object} Test settings configuration
   */
  getTestSettings() {
    this._ensureConfig();
    return this.config.testSettings || {};
  }

  /**
   * Get timeout by type
   * @param {string} type - Timeout type (short/medium/long)
   * @returns {number} Timeout in milliseconds
   */
  getTimeout(type = 'medium') {
    this._ensureConfig();
    const timeouts = this.config.testSettings?.timeouts || {};
    const defaults = { short: 5000, medium: 10000, long: 30000 };
    return timeouts[type] || defaults[type] || 10000;
  }

  /**
   * Get retry configuration
   * @returns {Object} Retry configuration
   */
  getRetryConfig() {
    this._ensureConfig();
    return (
      this.config.testSettings?.retry || {
        maxAttempts: 3,
        delayBetweenAttempts: 1000
      }
    );
  }

  /**
   * Get wait configuration
   * @returns {Object} Wait configuration
   */
  getWaitConfig() {
    this._ensureConfig();
    return (
      this.config.testSettings?.waitFor || {
        vueLoad: 10000,
        networkIdle: 5000,
        animation: 500
      }
    );
  }

  // ═══════════════════════════════════════
  // PLAYWRIGHT CONFIGURATION
  // ═══════════════════════════════════════

  /**
   * Get all Playwright timeouts
   * @returns {Object} Playwright timeout configuration
   */
  getPlaywrightTimeouts() {
    this._ensureConfig();
    return this.config.testSettings?.timeouts?.playwright || {};
  }

  /**
   * Get action timeout for Playwright
   * @returns {number} Action timeout in milliseconds
   */
  getActionTimeout() {
    return this.getPlaywrightTimeouts().action || 15000;
  }

  /**
   * Get navigation timeout for Playwright
   * @returns {number} Navigation timeout in milliseconds
   */
  getNavigationTimeout() {
    return this.getPlaywrightTimeouts().navigation || 30000;
  }

  /**
   * Get global timeout for Playwright
   * @returns {number} Global timeout in milliseconds
   */
  getGlobalTimeout() {
    return this.getPlaywrightTimeouts().global || 30000;
  }

  /**
   * Get expect timeout for Playwright assertions
   * @returns {number} Expect timeout in milliseconds
   */
  getExpectTimeout() {
    return this.getPlaywrightTimeouts().expect || 10000;
  }

  /**
   * Get connectivity check timeout
   * @returns {number} Connectivity timeout in milliseconds
   */
  getConnectivityTimeout() {
    return this.getPlaywrightTimeouts().connectivity || 5000;
  }

  /**
   * Get number of parallel workers for Playwright.
   * Order: env PLAYWRIGHT_WORKERS → testSettings.workers in config → CI ? 1 : 4.
   * Env always wins so CI pipelines or CLI overrides are respected even when
   * the config file has a `workers` value set.
   * Use 1 for strictly sequential runs (e.g. installation test), higher for parallel.
   * @returns {number} Number of workers (1 or more)
   */
  getWorkers() {
    this._ensureConfig();
    // 1) Environment variable always takes priority
    const fromEnv = process.env.PLAYWRIGHT_WORKERS;
    if (fromEnv !== undefined && fromEnv !== '') {
      const n = parseInt(fromEnv, 10);
      if (!Number.isNaN(n) && n >= 1) return n;
    }
    // 2) Config file value
    const fromConfig = this.config.testSettings?.workers;
    if (typeof fromConfig === 'number' && fromConfig >= 1) {
      return Math.floor(fromConfig);
    }
    // 3) Fallback: 1 in CI, 4 locally
    return process.env.CI ? 1 : 4;
  }

  // ═══════════════════════════════════════
  // TEST DATA
  // ═══════════════════════════════════════

  /**
   * Get all test data
   * @returns {Object} Test data configuration
   */
  getTestData() {
    this._ensureConfig();
    return this.config.testData || {};
  }

  /**
   * Get test pages data
   * @returns {Array} Array of test page definitions
   */
  getTestPages() {
    this._ensureConfig();
    return this.config.testData?.pages || [];
  }

  /**
   * Get test posts data
   * @returns {Array} Array of test post definitions
   */
  getTestPosts() {
    this._ensureConfig();
    return this.config.testData?.posts || [];
  }

  /**
   * Get test media data
   * @returns {Object} Test media file definitions
   */
  getTestMedia() {
    this._ensureConfig();
    return this.config.testData?.media || {};
  }

  // ═══════════════════════════════════════
  // CONNECTIVITY & VALIDATION
  // ═══════════════════════════════════════

  /**
   * Test connectivity to configured URLs and validate configuration
   * @async
   * @returns {Promise<boolean>} True if all checks pass
   * @param {Object} [options] - Optional settings
   * @param {boolean} [options.skipAdminCheck] - If true, only validate admin URL format; do not require /admin to be accessible (use for installation test, where admin does not exist yet)
   * @throws {Error} If configuration is invalid or URLs are not accessible
   */
  async testConnectivity(options = {}) {
    const skipAdminCheck = options.skipAdminCheck === true;
    if (skipAdminCheck) {
      this.info('Testing connectivity (installation mode: admin URL check skipped)...');
    } else {
      this.info('Testing connectivity to configured URLs...');
    }

    let hasErrors = false;
    let hasCriticalErrors = false;
    let installerDetected = false; // Track if installer was already detected
    const errorMessages = [];

    this._ensureConfig();

    // Validate admin credentials
    if (!this.config.auth?.admin?.username || this.config.auth.admin.username.startsWith('YOUR_')) {
      this.error('Admin username is required (replace YOUR_ADMIN_USERNAME)');
      errorMessages.push('Missing admin username');
      hasErrors = true;
    }

    if (!this.config.auth?.admin?.password || this.config.auth.admin.password.startsWith('YOUR_')) {
      this.error('Admin password is required (replace YOUR_ADMIN_PASSWORD)');
      errorMessages.push('Missing admin password');
      hasErrors = true;
    }

    // Helper function to test URL with timeout and redirect detection
    const testUrl = async (url, label) => {
      try {
        // Create AbortController for timeout
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), this.getConnectivityTimeout());

        const response = await fetch(url, {
          method: 'GET', // Use GET to detect redirects properly
          redirect: 'manual', // Don't follow redirects automatically
          signal: controller.signal
        });

        clearTimeout(timeoutId);

        // Check for server errors (500+)
        if (response.status >= 500) {
          this.error(`${label} server error (Status: ${response.status})`, '🚨');
          this.error(`   URL: ${url}`);
          errorMessages.push(`${label} has server error ${response.status}`);
          hasCriticalErrors = true;
          return false;
        }

        // Check for redirects (3xx)
        if (response.status >= 300 && response.status < 400) {
          const location = response.headers.get('location');

          // Special case: Redirect to installer is OK for fresh installation
          if (location && location.includes('/installer')) {
            // Only show message once for first detection
            if (!installerDetected) {
              this.success('Fresh installation detected - redirecting to installer', '🆕');
              installerDetected = true;
            }
            return true;
          }

          // Special case: Redirect to admin is OK (normal admin access)
          if (location && location.includes('/admin')) {
            this.success(`${label} accessible: ${url}`);
            return true;
          }

          // Other redirects are problematic
          this.warn(`${label} is redirecting (Status: ${response.status})`);
          this.warn(`   From: ${url}`);
          this.warn(`   To: ${location || 'unknown'}`);
          errorMessages.push(`${label} redirects unexpectedly`);
          hasErrors = true;
          return false;
        }

        // Check if response is OK (2xx)
        if (response.status >= 200 && response.status < 300) {
          this.success(`${label} accessible: ${url}`);
          return true;
        }

        // Other status codes (4xx, etc.)
        this.error(`${label} not accessible (Status: ${response.status})`);
        this.error(`   URL: ${url}`);
        errorMessages.push(`${label} returned status ${response.status}`);
        hasErrors = true;
        return false;
      } catch (error) {
        if (error.name === 'AbortError') {
          this.error(`${label} timeout (exceeded ${this.getConnectivityTimeout()}ms)`);
          this.error(`   URL: ${url}`);
          errorMessages.push(`${label} timeout`);
        } else {
          this.error(`${label} not reachable: ${error.message}`);
          this.error(`   URL: ${url}`);
          errorMessages.push(`${label} unreachable`);
        }
        hasErrors = true;
        return false;
      }
    };

    // Validate and test site URL
    if (!this.config.site?.url) {
      this.error('Site URL is required in configuration');
      errorMessages.push('Missing site URL');
      hasErrors = true;
    } else {
      // Validate URL format
      try {
        new URL(this.config.site.url);
        // Test connectivity if format is valid
        await testUrl(this.config.site.url, 'Site URL');
      } catch {
        this.error(`Invalid site URL format: ${this.config.site.url}`);
        errorMessages.push('Invalid site URL format');
        hasErrors = true;
      }
    }

    // Validate and test admin URL (skip accessibility check when running installation test)
    if (!this.config.site?.adminUrl) {
      this.error('Admin URL is required in configuration');
      errorMessages.push('Missing admin URL');
      hasErrors = true;
    } else {
      try {
        new URL(this.config.site.adminUrl);
        if (skipAdminCheck) {
          this.success('Admin URL format valid (accessibility skipped for installation test)');
        } else {
          await testUrl(this.config.site.adminUrl, 'Admin URL');
        }
      } catch {
        this.error(`Invalid admin URL format: ${this.config.site.adminUrl}`);
        errorMessages.push('Invalid admin URL format');
        hasErrors = true;
      }
    }

    // Handle critical errors (500+) - stop immediately
    if (hasCriticalErrors) {
      this.error('═══════════════════════════════════════', '🚨');
      this.error('CRITICAL SERVER ERRORS DETECTED!', '🚨');
      this.error('Server must be fixed before tests can run!', '🚨');
      this.error('═══════════════════════════════════════', '🚨');
      throw new Error(`Critical server errors: ${errorMessages.join(', ')}`);
    }

    // Handle other errors
    if (hasErrors) {
      this.error('═══════════════════════════════════════');
      this.error('Configuration/connectivity test failed!');
      this.error('Please fix the issues above and try again');
      this.error('═══════════════════════════════════════');
      throw new Error(`Test prerequisites failed: ${errorMessages.join(', ')}`);
    }

    this.success('All connectivity checks passed!');
    return true;
  }

  // ═══════════════════════════════════════
  // TIMING & PERFORMANCE
  // ═══════════════════════════════════════

  /**
   * Start test timer
   * @returns {number} Start timestamp
   */
  startTestTimer() {
    this.testStartTime = Date.now();
    return this.testStartTime;
  }

  getTestDuration() {
    if (!this.testStartTime) {
      return null;
    }
    return Date.now() - this.testStartTime;
  }

  getFormattedTestDuration() {
    const duration = this.getTestDuration();
    if (!duration) {
      return 'Timer not started';
    }
    return (duration / 1000).toFixed(3) + ' seconds';
  }

  /**
   * Mark a test step with timestamp
   * @param {string} stepName - Name of the step
   */
  markStep(stepName) {
    this.stepTimes.push({
      name: stepName,
      timestamp: Date.now(),
      relativeTime: this.testStartTime ? Date.now() - this.testStartTime : 0
    });
  }

  getStepTimes() {
    return this.stepTimes;
  }

  getFormattedStepTimes() {
    return this.stepTimes.map(step => `${step.name}: ${(step.relativeTime / 1000).toFixed(3)}s`);
  }

  // ═══════════════════════════════════════
  // LOGGING CONFIGURATION
  // ═══════════════════════════════════════

  /**
   * Get logging configuration
   * @returns {Object} Logging configuration
   */
  getLoggingConfig() {
    // Don't call _ensureConfig() here to avoid infinite loop during initialization
    // If config is not loaded yet, return empty object (defaults will be used)
    if (!this.config) {
      return {};
    }
    return this.config.logging || {};
  }

  /**
   * Get locale for logging timestamps
   * @returns {string} Locale string (e.g., 'de-DE')
   */
  getLoggingLocale() {
    return this.getLoggingConfig().locale || 'en-US';
  }

  /**
   * Get time format configuration
   * @returns {Object} Time format options
   */
  getTimeFormat() {
    return (
      this.getLoggingConfig().timeFormat || {
        hour12: true,
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        fractionalSecondDigits: 3
      }
    );
  }

  /**
   * Get formatted timestamp for logging
   * @returns {string} Formatted timestamp with relative time if timer is running
   */
  getTimestamp() {
    const now = new Date();
    const timeFormat = this.getTimeFormat();

    // Remove fractionalSecondDigits for clean absolute time
    const cleanTimeFormat = { ...timeFormat };
    delete cleanTimeFormat.fractionalSecondDigits;

    const absoluteTime = now.toLocaleTimeString(this.getLoggingLocale(), cleanTimeFormat);

    if (this.testStartTime) {
      const relativeMs = now.getTime() - this.testStartTime;
      return `${absoluteTime}, ${this.formatRelativeTime(relativeMs)}`;
    }

    return absoluteTime;
  }

  /**
   * Format relative time in human-readable format
   * @param {number} ms - Milliseconds
   * @returns {string} Formatted time string
   */
  formatRelativeTime(ms) {
    if (ms < 1000) {
      return `${ms}ms`;
    } else if (ms < 60000) {
      const seconds = (ms / 1000).toFixed(1);
      return `${seconds}s`;
    } else {
      const minutes = Math.floor(ms / 60000);
      const seconds = Math.floor((ms % 60000) / 1000);
      return `${minutes}m ${seconds}s`;
    }
  }

  /**
   * Get logging icons configuration
   * @returns {Object} Icons for different log levels
   */
  getLoggingIcons() {
    return (
      this.getLoggingConfig().icons || {
        default: '📝',
        log: '📝',
        warn: '⚠️',
        error: '❌',
        debug: '🐛',
        info: 'ℹ️',
        success: '✅'
      }
    );
  }

  getIconForLevel(level) {
    const icons = this.getLoggingIcons();
    return icons[level] || icons.default;
  }

  // ═══════════════════════════════════════
  // LOGGING METHODS
  // ═══════════════════════════════════════

  /**
   * Log a message with optional custom icon
   * @param {string} message - Message to log
   * @param {string} [icon] - Optional custom icon
   */
  log(message, icon = null) {
    const logIcon = icon || this.getIconForLevel('log');
    console.log(`${logIcon} [${this.getTimestamp()}] ${message}`);
  }

  /**
   * Log a warning message
   * @param {string} message - Warning message
   * @param {string} [icon] - Optional custom icon
   */
  warn(message, icon = null) {
    const warnIcon = icon || this.getIconForLevel('warn');
    console.warn(`${warnIcon} [${this.getTimestamp()}] ${message}`);
  }

  /**
   * Log an error message
   * @param {string} message - Error message
   * @param {string} [icon] - Optional custom icon
   */
  error(message, icon = null) {
    const errorIcon = icon || this.getIconForLevel('error');
    console.error(`${errorIcon} [${this.getTimestamp()}] ${message}`);
  }

  /**
   * Log a debug message
   * @param {string} message - Debug message
   * @param {string} [icon] - Optional custom icon
   */
  debug(message, icon = null) {
    const debugIcon = icon || this.getIconForLevel('debug');
    console.log(`${debugIcon} [${this.getTimestamp()}] ${message}`);
  }

  /**
   * Log an info message
   * @param {string} message - Info message
   * @param {string} [icon] - Optional custom icon
   */
  info(message, icon = null) {
    const infoIcon = icon || this.getIconForLevel('info');
    console.log(`${infoIcon} [${this.getTimestamp()}] ${message}`);
  }

  /**
   * Log a success message
   * @param {string} message - Success message
   * @param {string} [icon] - Optional custom icon
   */
  success(message, icon = null) {
    const successIcon = icon || this.getIconForLevel('success');
    console.log(`${successIcon} [${this.getTimestamp()}] ${message}`);
  }

  // ═══════════════════════════════════════
  // SETUP & HELP
  // ═══════════════════════════════════════

  /**
   * Print setup instructions for missing configuration
   */
  printSetupInstructions() {
    this.error('═══════════════════════════════════════', '📋');
    this.error('E2E TEST CONFIGURATION REQUIRED', '📋');
    this.error('═══════════════════════════════════════', '📋');

    this.info('Step 1: Create configuration file', '1️⃣');
    this.log('   cp tests/e2e/config/test-config.example.json tests/e2e/config/test-config.json');

    this.info('Step 2: Update configuration values', '2️⃣');
    this.log('   Replace placeholders in test-config.json:');
    this.log('   • YOUR_ADMIN_USERNAME → Your admin username');
    this.log('   • YOUR_ADMIN_PASSWORD → Your admin password');
    this.log('   • YOUR_ADMIN_EMAIL → Your admin email');
    this.log('   • YOUR_SITE_TITLE → Your site title');

    this.info('Step 3: Prepare test environment', '3️⃣');
    this.log('   • For fresh installation: Remove config.php and pagekit.db');
    this.log('   • For existing installation: Ensure admin credentials match config');

    this.info('Step 4: Verify accessibility', '4️⃣');
    this.log('   • Site URL must be accessible');
    this.log('   • Admin URL must be reachable');

    this.warn('═══════════════════════════════════════');
    this.warn('See test-config.example.json for complete documentation', '📄');
    this.warn('═══════════════════════════════════════');
  }
}

// Export singleton instance
module.exports = new TestConfig();
