/**
 * Complete Installation Test for Pagekit
 * Tests the actual multi-step installation process
 *
 * Prerequisites: Pagekit must NOT be installed (no config.php or pagekit.db)
 * Configuration: Uses test-config.json for installation settings
 */

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const testConfig = require('../../helpers/test-config');

// Wait-time constants derived from central test configuration
const waitConfig = testConfig.getWaitConfig();
const WAIT_ANIMATION = waitConfig.animation; // Full UI animation (default 500ms)
const WAIT_INSTALLER_STEP = WAIT_ANIMATION * 2; // Installer step transitions (default ~1000ms)

test.describe('Pagekit Installation Process', () => {
  test.beforeAll(async () => {
    // Start test timer
    testConfig.startTestTimer();

    // Test connectivity and configuration (skip admin URL – not available before installation)
    await testConfig.testConnectivity({ skipAdminCheck: true });
  });

  test('Complete 5-step installation flow', async ({ page }) => {
    // Set longer timeout for installation (from config)
    test.setTimeout(testConfig.getTimeout('installation'));

    testConfig.log('Starting Pagekit installation test...', '🚀');

    // Quick check if Pagekit is already installed
    const configPath = path.join(process.cwd(), 'config.php');
    const dbPath = path.join(process.cwd(), 'pagekit.db');

    if (fs.existsSync(configPath) || fs.existsSync(dbPath)) {
      testConfig.error('Pagekit is already installed!');
      testConfig.info('Remove config.php and pagekit.db for fresh installation test', '💡');
      throw new Error(
        'Installation test requires clean state - remove existing installation files'
      );
    }

    // Navigate to Pagekit (should redirect to installer when not installed)
    const siteUrl = testConfig.getSiteUrl();
    await page.goto(siteUrl, { waitUntil: 'networkidle' });

    // If still on root, try /installer directly (some setups redirect only via that path)
    if (!page.url().includes('/installer')) {
      await page.goto(siteUrl.replace(/\/+$/, '') + '/installer', { waitUntil: 'networkidle' });
    }
    await expect(page).toHaveURL(/\/installer/, { timeout: testConfig.getTimeout('medium') });
    testConfig.log('Step 0: On installer', '🌐');

    // Wait for Vue.js to initialize (check for v-cloak removal)
    await page.waitForFunction(() => !document.querySelector('[v-cloak]'), {
      timeout: testConfig.getTimeout('medium')
    });
    testConfig.log('Vue.js initialized', '⚡');

    // ========================================
    // Step 1: Welcome screen with Pagekit logo
    // ========================================
    testConfig.log('Step 1: Welcome screen', '👋');

    // Wait for the logo to be clickable
    await page.waitForSelector('#next', {
      state: 'visible',
      timeout: testConfig.getTimeout('medium')
    });

    // Click on the logo/next button
    await page.click('#next');
    await page.waitForTimeout(WAIT_INSTALLER_STEP); // Wait for step animation

    testConfig.success('Step 1: Clicked welcome screen');

    // ========================================
    // Step 2: Language selection
    // ========================================
    testConfig.log('Step 2: Language selection', '🌍');

    // Wait for language selector
    await page.waitForSelector('#selectbox', {
      state: 'visible',
      timeout: testConfig.getTimeout('medium')
    });

    // Select language from config (should be selected by default)
    const selectedLang = await page.locator('#selectbox').evaluate(el => el.value);
    const configLang = testConfig.getInstallationLanguage();
    if (selectedLang !== configLang) {
      await page.selectOption('#selectbox', configLang);
    }

    // Click Next button
    await page.click('#next');
    await page.waitForTimeout(WAIT_INSTALLER_STEP);

    testConfig.success(`Step 2: Language selected (${configLang})`);

    // ========================================
    // Step 3: Database configuration
    // ========================================
    testConfig.log('Step 3: Database configuration', '🗄️');

    // Wait for database driver selector
    await page.waitForSelector('#form-dbdriver', {
      state: 'visible',
      timeout: testConfig.getTimeout('medium')
    });

    // Check if SQLite is selected (should be default)
    const dbDriver = await page.locator('#form-dbdriver').evaluate(el => el.value);
    testConfig.debug(`Database driver: ${dbDriver}`, '🔧');

    if (dbDriver !== 'sqlite') {
      await page.selectOption('#form-dbdriver', 'sqlite');
      testConfig.debug('Selected SQLite', '🔧');
    }

    // For SQLite, we might need to set table prefix
    const prefixInput = page.locator('#form-sqlite-dbprefix');
    if ((await prefixInput.count()) > 0) {
      const currentPrefix = await prefixInput.inputValue();
      if (!currentPrefix) {
        await prefixInput.fill('pk_');
      }
    }

    // Click Next button
    await page.click('#next');
    await page.waitForTimeout(WAIT_INSTALLER_STEP);

    testConfig.success('Step 3: Database configured (SQLite)');

    // ========================================
    // Step 4: Site and Admin user configuration
    // ========================================
    testConfig.log('Step 4: Site and Admin configuration', '⚙️');

    // Wait for form fields to be visible
    await page.waitForSelector('div[step="site"]', {
      state: 'visible',
      timeout: testConfig.getTimeout('medium')
    });

    // Fill site title from config
    const siteTitle = testConfig.getSiteTitle();
    await page.fill('input#form-sitename', siteTitle);
    testConfig.debug(`Site title set: ${siteTitle}`, '🏷️');

    // Fill admin user details from config
    const adminCreds = testConfig.getAdminCredentials();
    await page.fill('input#form-username', adminCreds.username);
    await page.fill('input#form-password', adminCreds.password);
    await page.fill('input#form-email', adminCreds.email);
    testConfig.debug(`Admin user configured: ${adminCreds.username}`, '👤');

    // Optional: Check demo content option from config
    const optionsButton = page.locator('#options');
    if ((await optionsButton.count()) > 0 && testConfig.getInstallationDemoContent()) {
      // Click options to open modal
      await optionsButton.click();
      await page.waitForTimeout(WAIT_ANIMATION);

      // Check if demo content checkbox exists and check it
      const demoCheckbox = page.locator('input[type="checkbox"]').first();
      if ((await demoCheckbox.count()) > 0) {
        const isChecked = await demoCheckbox.isChecked();
        if (!isChecked) {
          await demoCheckbox.check();
          testConfig.debug('Demo content enabled', '📦');
        }
      }

      // Close modal (click outside or close button)
      const closeButton = page.locator('button.uk-modal-close').first();
      if (await closeButton.isVisible().catch(() => false)) {
        await closeButton.click();
      } else {
        // Click outside modal
        await page.click('body', { position: { x: 10, y: 10 } });
      }
      await page.waitForTimeout(WAIT_ANIMATION);
    }

    // Click Install button (which is the next button in this step)
    testConfig.log('Clicking Install button...', '🚀');
    await page.click('#next');

    // ========================================
    // Step 5: Installation process
    // ========================================
    testConfig.log('Step 5: Installing Pagekit...', '⏳');
    testConfig.info('This may take a few seconds...', '⏱️');

    // Wait for redirect to login page (installation complete)
    await page.waitForURL(/\/(admin\/login|user\/login)/, {
      timeout: testConfig.getNavigationTimeout()
    });

    testConfig.success('Step 5: Installation completed!');
    testConfig.log('Redirected to login page', '🔄');

    // ========================================
    // Verification
    // ========================================
    testConfig.log('Verifying installation...', '🔍');

    // Check that config.php was created
    const configExists = fs.existsSync('config.php');
    expect(configExists).toBeTruthy();
    testConfig.success('config.php created', '📄');

    // Check that database was created
    const dbExists = fs.existsSync('pagekit.db');
    expect(dbExists).toBeTruthy();
    testConfig.success('pagekit.db created', '🗃️');

    // ========================================
    // Critical: Test admin login functionality
    // ========================================
    // This is essential because installation without working login
    // is not a complete success - user needs to access admin area
    testConfig.log('Testing admin login (critical for complete installation)...', '🔐');

    // We should already be on login page, but make sure
    if (!page.url().includes('login')) {
      await page.goto(testConfig.getAdminUrl() + '/login');
    }

    // Wait for login form to be ready
    await page.waitForSelector('input[name="credentials[username]"]', { state: 'visible' });

    // Ignore CORS warnings for external update checks (not critical for installation)
    page.on('console', msg => {
      if (msg.type() === 'error' && msg.text().includes('CORS policy')) {
        testConfig.info('External update check blocked by CORS (expected in test environment)');
        return; // Prevent default console output
      }
    });

    // Fill login credentials from config (reuse same credentials)
    await page.fill('input[name="credentials[username]"]', adminCreds.username);
    await page.fill('input[name="credentials[password]"]', adminCreds.password);

    // Submit login form
    await page.click('.js-login button');

    // Wait for redirect to admin dashboard
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: testConfig.getTimeout('medium') });

    testConfig.success('Admin login successful!');
    testConfig.success('Complete installation verified - Pagekit is fully functional!', '🎯');

    // Final check: we should be in admin area
    expect(page.url()).toContain('/admin');
    expect(page.url()).not.toContain('/login');

    // Keep admin logged in for subsequent tests
    // This improves performance and mimics real-world usage
    testConfig.info('Admin session maintained for following tests', '🔑');

    // Calculate and display total installation time
    const totalTime = testConfig.getFormattedTestDuration();

    // Summary of what was accomplished (single block output)
    const duration = testConfig.getTestDuration();
    let performanceRating = '⚠️ Slow (over 20s)';
    if (duration < 10000) {
      performanceRating = '⚡ Excellent (under 10s)';
    } else if (duration < 20000) {
      performanceRating = '✅ Good (under 20s)';
    }

    const summary = `📋 Installation Summary:
• Site: ${siteTitle}
• Admin: ${adminCreds.username}
• Database: SQLite (pagekit.db)
• Language: ${testConfig.getInstallationLanguage()}
• Demo content: ${testConfig.getInstallationDemoContent() ? 'Enabled' : 'Disabled'}
• Total time: ${totalTime}
• Performance: ${performanceRating}`;

    testConfig.log(summary);

    testConfig.success('Installation test completed successfully!', '🎉');
  });
});
