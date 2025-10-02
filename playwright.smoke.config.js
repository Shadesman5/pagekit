// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * Read environment variables from file.
 * https://github.com/motdotla/dotenv
 */
require('dotenv').config();

/**
 * Load test configuration
 */
let testConfig;
try {
    testConfig = require('./tests/e2e/helpers/test-config');
} catch (error) {
    console.error('❌ Failed to load test configuration:', error.message);
    console.error('   Please ensure tests/e2e/config/test-config.json exists and is valid');
    process.exit(1);
}

/**
 * Smoke Tests Configuration
 * Quick tests for critical functionality
 * @see https://playwright.dev/docs/test-configuration
 */
module.exports = defineConfig({
    testDir: './tests/e2e/specs',
    testMatch: [
        '**/setup/installation.spec.js',
        '**/core/authentication.spec.js',
        '**/core/dashboard.spec.js'
    ],
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 1,
    workers: process.env.CI ? 1 : 2,
    reporter: [['html', { outputFolder: 'playwright-smoke-report' }], ['list']],
    use: {
        baseURL: process.env.BASE_URL || testConfig.getSiteUrl(),
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        actionTimeout: testConfig.getActionTimeout(),
        navigationTimeout: testConfig.getNavigationTimeout()
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] }
        }
    ],
    webServer: process.env.NO_SERVER
        ? undefined
        : {
              command: 'php pagekit start --no-ansi',
              url: testConfig.getSiteUrl(),
              reuseExistingServer: !process.env.CI,
              timeout: 120 * 1000,
              stdout: 'pipe',
              stderr: 'pipe'
          },
    timeout: testConfig.getGlobalTimeout(),
    expect: {
        timeout: testConfig.getExpectTimeout()
    },
    outputDir: 'test-results-smoke/'
});
