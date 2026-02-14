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
 * @see https://playwright.dev/docs/test-configuration
 */
module.exports = defineConfig({
    testDir: './tests/e2e/specs',
    /* Run tests in files in parallel */
    fullyParallel: true,
    /* Fail the build on CI if you accidentally left test.only in the source code. */
    forbidOnly: !!process.env.CI,
    /* Retry on CI only */
    retries: process.env.CI ? 2 : 2,
    /* Number of parallel workers (config → PLAYWRIGHT_WORKERS env → CI ? 1 : 4). */
    workers: testConfig.getWorkers(),
    /* Reporter to use. See https://playwright.dev/docs/test-reporters */
    reporter: [['html', { outputFolder: 'playwright-report' }], ['list']],
    /* Shared settings for all the projects below. See https://playwright.dev/docs/api/class-testoptions. */
    use: {
        /* Base URL to use in actions like `await page.goto('/')`. */
        baseURL: process.env.BASE_URL || testConfig.getSiteUrl(),
        /* Collect trace when retrying the failed test. See https://playwright.dev/docs/trace-viewer */
        trace: 'on-first-retry',
        /* Capture screenshot on failure */
        screenshot: 'only-on-failure',
        /* Capture video on failure */
        video: 'retain-on-failure',
        /* Maximum time each action can take */
        actionTimeout: testConfig.getActionTimeout(),
        /* Navigation timeout */
        navigationTimeout: testConfig.getNavigationTimeout()
    },

    /* Configure projects for major browsers */
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] }
        },

        {
            name: 'firefox',
            use: { ...devices['Desktop Firefox'] }
        },

        {
            name: 'webkit',
            use: { ...devices['Desktop Safari'] }
        },

        /* Test against mobile viewports. */
        // {
        //     name: 'Mobile Chrome',
        //     use: { ...devices['Pixel 5'] }
        // },
        // {
        //     name: 'Mobile Safari',
        //     use: { ...devices['iPhone 12'] }
        // }

        /* Test against branded browsers. */
        // {
        //   name: 'Microsoft Edge',
        //   use: { ...devices['Desktop Edge'], channel: 'msedge' },
        // },
        // {
        //   name: 'Google Chrome',
        //   use: { ...devices['Desktop Chrome'], channel: 'chrome' },
        // },
    ],

    /* Run your local dev server before starting the tests */
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

    /* Global timeout for each test */
    timeout: testConfig.getGlobalTimeout(),

    /* Expect timeout */
    expect: {
        timeout: testConfig.getExpectTimeout()
    },

    /* Output folder for test artifacts */
    outputDir: 'test-results/'
});
