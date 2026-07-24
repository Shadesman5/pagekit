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
    /* Number of parallel workers (PLAYWRIGHT_WORKERS env → config → CI ? 1 : 4). */
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

    /*
     * Browser x viewport projects, composed from two env switches.
     *
     * Browsers (PW_BROWSERS): default chromium only — the cloud-agent VM ships
     * only chromium (see .cursor/Dockerfile) because firefox/webkit need root
     * for `playwright install-deps`, which is unavailable there. PW_BROWSERS=all
     * adds firefox + webkit (CI/CD hosts where `npx playwright install
     * --with-deps` succeeds).
     *
     * Viewports (PW_VIEWPORTS): default desktop only. PW_VIEWPORTS=all adds
     * tablet + mobile legs. Those use explicit viewport overrides only — no
     * isMobile/hasTouch — because Firefox does not support Playwright's
     * isMobile, so the sizes stay uniform across every browser engine.
     *
     * Project names are `${browser}-${viewport}`; chromium-desktop is always
     * first so it can act as the required leg while the others stay advisory.
     */
    projects: (() => {
        const browserPresets = {
            chromium: devices['Desktop Chrome'],
            firefox: devices['Desktop Firefox'],
            webkit: devices['Desktop Safari']
        };
        const viewportSizes = {
            tablet: { width: 820, height: 1180 },
            mobile: { width: 390, height: 844 }
        };

        const browsers =
            process.env.PW_BROWSERS === 'all' ? ['chromium', 'firefox', 'webkit'] : ['chromium'];
        const viewports =
            process.env.PW_VIEWPORTS === 'all' ? ['desktop', 'tablet', 'mobile'] : ['desktop'];

        const projects = [];
        for (const browser of browsers) {
            for (const viewport of viewports) {
                const use = { ...browserPresets[browser] };
                if (viewport !== 'desktop') {
                    use.viewport = viewportSizes[viewport];
                }
                projects.push({ name: `${browser}-${viewport}`, use });
            }
        }
        return projects;
    })(),

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
