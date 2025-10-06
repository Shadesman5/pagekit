/**
 * System Settings Tests for Pagekit
 * Tests all system configuration options
 */

const { test, expect } = require('@playwright/test');
const { waitForVue } = require('../../helpers/vue-helpers');
const testConfig = require('../../helpers/test-config');

test.describe('Pagekit System Settings (Optimized)', () => {
  test.beforeAll(async () => {
    // Start test timer and test connectivity
    testConfig.startTestTimer();
    await testConfig.testConnectivity();
  });

  test.beforeEach(async ({ page }) => {
    // Login as admin using config credentials
    await page.goto(testConfig.getAdminUrl() + '/login');
    await waitForVue(page);

    const adminCreds = testConfig.getAdminCredentials();
    await page.getByRole('textbox', { name: 'Benutzername' }).fill(adminCreds.username);
    await page.getByRole('textbox', { name: 'Passwort' }).fill(adminCreds.password);

    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      page.getByRole('button', { name: 'Anmelden' }).click()
    ]);

    await waitForVue(page);
  });

  test('Navigate to System Settings', async ({ page }) => {
    testConfig.log('Testing System Settings navigation...', '🚀');

    // Navigate directly to settings (more reliable than clicking through menus)
    await page.goto(testConfig.getAdminUrl() + '/system/settings');
    await waitForVue(page);

    expect(page.url()).toContain('/system/settings');
    testConfig.success('System Settings accessible');
  });

  test('General Settings', async ({ page }) => {
    testConfig.log('Testing General Settings...', '🚀');

    await page.goto(testConfig.getAdminUrl() + '/system/settings');
    await waitForVue(page);

    // Site Title
    const titleInput = page.locator('input[name*="title"]').first();
    if ((await titleInput.count()) > 0) {
      await titleInput.clear();
      await titleInput.fill('Pagekit E2E Test Site');
      console.log('✅ Site title updated');
    }

    // Site Description
    const descInput = page.locator('textarea[name*="description"]').first();
    if ((await descInput.count()) > 0) {
      await descInput.clear();
      await descInput.fill('This is a test site for E2E testing of Pagekit CMS');
      console.log('✅ Site description updated');
    }

    // Timezone
    const timezoneSelect = page.locator('select[name*="timezone"]').first();
    if ((await timezoneSelect.count()) > 0) {
      await timezoneSelect.selectOption({ value: 'Europe/Berlin' });
      console.log('✅ Timezone set');
    }

    // Save settings
    await page.click('button:has-text("Save")');
    await page.waitForTimeout(2000);

    // Verify save message
    const successMessage = await page
      .locator('.uk-alert-success')
      .isVisible()
      .catch(() => false);
    expect(successMessage).toBeTruthy();
  });

  test('Maintenance Mode', async ({ page }) => {
    console.log('📍 Testing Maintenance Mode...');

    await page.goto('/admin/system/settings');
    await waitForVue(page);

    // Find maintenance tab/section
    const maintenanceTab = page
      .locator('a:has-text("Maintenance"), li:has-text("Maintenance")')
      .first();
    if (await maintenanceTab.isVisible()) {
      await maintenanceTab.click();
      await waitForVue(page);
    }

    // Enable maintenance mode
    const maintenanceToggle = page.locator('input[type="checkbox"][name*="maintenance"]').first();
    if ((await maintenanceToggle.count()) > 0) {
      await maintenanceToggle.check();
      console.log('✅ Maintenance mode enabled');

      // Set maintenance message
      const messageInput = page.locator('textarea[name*="maintenance"][name*="message"]').first();
      if ((await messageInput.count()) > 0) {
        await messageInput.fill('Site is under maintenance. Please check back later.');
        console.log('✅ Maintenance message set');
      }

      // Save
      await page.click('button:has-text("Save")');
      await page.waitForTimeout(2000);

      // Test frontend (should show maintenance)
      const context = page.context();
      const frontendPage = await context.newPage();
      await frontendPage.goto('/');

      const maintenanceVisible = await frontendPage
        .locator('text=/maintenance/i')
        .isVisible()
        .catch(() => false);
      if (maintenanceVisible) {
        console.log('✅ Maintenance page shown on frontend');
      }

      await frontendPage.close();

      // Disable maintenance mode
      await maintenanceToggle.uncheck();
      await page.click('button:has-text("Save")');
      await page.waitForTimeout(2000);
      console.log('✅ Maintenance mode disabled');
    }
  });

  test('Localization Settings', async ({ page }) => {
    console.log('📍 Testing Localization Settings...');

    await page.goto('/admin/system/settings');
    await waitForVue(page);

    // Find localization section
    const localeTab = page.locator('a:has-text("Localization"), li:has-text("Locale")').first();
    if (await localeTab.isVisible()) {
      await localeTab.click();
      await waitForVue(page);
    }

    // Default language
    const languageSelect = page.locator('select[name*="locale"]').first();
    if ((await languageSelect.count()) > 0) {
      const currentLocale = await languageSelect.inputValue();
      await languageSelect.selectOption('de_DE');
      console.log('✅ Language changed to German');

      // Save
      await page.click('button:has-text("Save")');
      await page.waitForTimeout(2000);

      // Restore to English
      await languageSelect.selectOption('en_US');
      await page.click('button:has-text("Save")');
      await page.waitForTimeout(2000);
      console.log('✅ Language restored to English');
    }

    // Date format
    const dateFormatInput = page.locator('input[name*="date_format"]').first();
    if ((await dateFormatInput.count()) > 0) {
      await dateFormatInput.clear();
      await dateFormatInput.fill('Y-m-d');
      console.log('✅ Date format set');
    }
  });

  test('Mail Settings', async ({ page }) => {
    console.log('📍 Testing Mail Settings...');

    await page.goto('/admin/system/settings');
    await waitForVue(page);

    // Find mail section
    const mailTab = page.locator('a:has-text("Email"), a:has-text("Mail")').first();
    if (await mailTab.isVisible()) {
      await mailTab.click();
      await waitForVue(page);
    }

    // From Email
    const fromEmailInput = page
      .locator('input[name*="from_address"], input[name*="mail"][name*="from"]')
      .first();
    if ((await fromEmailInput.count()) > 0) {
      await fromEmailInput.clear();
      await fromEmailInput.fill('noreply@pagekit-test.local');
      console.log('✅ From email set');
    }

    // From Name
    const fromNameInput = page.locator('input[name*="from_name"]').first();
    if ((await fromNameInput.count()) > 0) {
      await fromNameInput.clear();
      await fromNameInput.fill('Pagekit E2E Test');
      console.log('✅ From name set');
    }

    // Mail Driver
    const driverSelect = page.locator('select[name*="driver"], select[name*="mailer"]').first();
    if ((await driverSelect.count()) > 0) {
      // Test SMTP configuration
      await driverSelect.selectOption('smtp');
      console.log('✅ SMTP driver selected');

      // SMTP Settings
      const smtpHost = page.locator('input[name*="host"]').first();
      if (await smtpHost.isVisible()) {
        await smtpHost.clear();
        await smtpHost.fill('smtp.mailtrap.io');

        const smtpPort = page.locator('input[name*="port"]').first();
        await smtpPort.clear();
        await smtpPort.fill('2525');

        const smtpUser = page.locator('input[name*="username"]').first();
        await smtpUser.clear();
        await smtpUser.fill('test_user');

        const smtpPass = page.locator('input[name*="password"][type="password"]').first();
        await smtpPass.clear();
        await smtpPass.fill('test_password');

        console.log('✅ SMTP settings configured');
      }

      // Test email button
      const testButton = page
        .locator('button:has-text("Send Test Email"), button:has-text("Test")')
        .first();
      if (await testButton.isVisible()) {
        // Note: This might fail without valid SMTP, but we test the UI
        await testButton.click();
        await page.waitForTimeout(3000);
        console.log('✅ Test email attempted');
      }

      // Switch back to mail()
      await driverSelect.selectOption('mail');
      console.log('✅ Switched back to PHP mail()');
    }

    // Save
    await page.click('button:has-text("Save")');
    await page.waitForTimeout(2000);
  });

  test('Code Editor Settings', async ({ page }) => {
    console.log('📍 Testing Code Editor Settings...');

    await page.goto('/admin/system/settings');
    await waitForVue(page);

    // Find code/editor section
    const editorTab = page.locator('a:has-text("Code"), a:has-text("Editor")').first();
    if (await editorTab.isVisible()) {
      await editorTab.click();
      await waitForVue(page);

      // Editor theme
      const themeSelect = page.locator('select[name*="theme"]').first();
      if ((await themeSelect.count()) > 0) {
        await themeSelect.selectOption({ index: 1 });
        console.log('✅ Editor theme changed');
      }

      // Line numbers
      const lineNumbersCheckbox = page
        .locator('input[type="checkbox"][name*="line_numbers"]')
        .first();
      if ((await lineNumbersCheckbox.count()) > 0) {
        const isChecked = await lineNumbersCheckbox.isChecked();
        await lineNumbersCheckbox.setChecked(!isChecked);
        console.log('✅ Line numbers toggled');
      }

      // Save
      await page.click('button:has-text("Save")');
      await page.waitForTimeout(2000);
    }
  });

  test('System Information', async ({ page }) => {
    console.log('📍 Testing System Information...');

    // Navigate to System Info
    await page.goto('/admin/system/info');
    await waitForVue(page);

    // Check for system info display
    const phpVersion = await page.locator('text=/PHP [0-9]+.[0-9]+/').isVisible();
    const webServer = await page.locator('text=/Apache|nginx|Server/i').isVisible();
    const database = await page.locator('text=/MySQL|SQLite|Database/i').isVisible();

    expect(phpVersion || webServer || database).toBeTruthy();
    console.log('✅ System information displayed');

    // Check for Pagekit version
    const pagekitVersion = await page.locator('text=/Pagekit [0-9]+.[0-9]+/').isVisible();
    if (pagekitVersion) {
      console.log('✅ Pagekit version shown');
    }

    // Check permissions table
    const permissionsTable = page
      .locator('table')
      .filter({ has: page.locator('text=/writable|readable/i') });
    if ((await permissionsTable.count()) > 0) {
      console.log('✅ File permissions table shown');
    }
  });

  test('Cache Management', async ({ page }) => {
    console.log('📍 Testing Cache Management...');

    // Navigate to cache settings
    const systemMenu = page.locator('a:has-text("System")').first();
    if (await systemMenu.isVisible()) {
      await systemMenu.click();
      await page.waitForTimeout(300);

      const cacheLink = page.locator('a:has-text("Cache")').first();
      if (await cacheLink.isVisible()) {
        await cacheLink.click();
        await waitForVue(page);

        // Clear cache button
        const clearCacheButton = page
          .locator('button:has-text("Clear Cache"), button:has-text("Clear")')
          .first();
        if (await clearCacheButton.isVisible()) {
          await clearCacheButton.click();
          await page.waitForTimeout(2000);

          // Check for success message
          const successMessage = await page
            .locator('.uk-alert-success')
            .isVisible()
            .catch(() => false);
          expect(successMessage).toBeTruthy();
          console.log('✅ Cache cleared successfully');
        }

        // Cache settings
        const cacheEnabled = page.locator('input[type="checkbox"][name*="cache"]').first();
        if ((await cacheEnabled.count()) > 0) {
          const isEnabled = await cacheEnabled.isChecked();
          await cacheEnabled.setChecked(!isEnabled);
          await page.click('button:has-text("Save")');
          await page.waitForTimeout(2000);
          console.log('✅ Cache settings toggled');

          // Restore
          await cacheEnabled.setChecked(isEnabled);
          await page.click('button:has-text("Save")');
          await page.waitForTimeout(2000);
        }
      }
    }
  });

  test('Update Check', async ({ page }) => {
    console.log('📍 Testing Update Check...');

    // Navigate to update section
    const updateLink = page.locator('a:has-text("Update"), a:has-text("Updates")').first();

    if (await updateLink.isVisible()) {
      await updateLink.click();
      await waitForVue(page);

      // Check for update button
      const checkUpdateButton = page.locator('button:has-text("Check for Updates")').first();
      if (await checkUpdateButton.isVisible()) {
        await checkUpdateButton.click();
        await page.waitForTimeout(5000); // Wait for update check

        // Check for update status
        const upToDate = await page
          .locator('text=/up to date|no updates/i')
          .isVisible()
          .catch(() => false);
        const updatesAvailable = await page
          .locator('text=/update available|new version/i')
          .isVisible()
          .catch(() => false);

        if (upToDate) {
          console.log('✅ System is up to date');
        } else if (updatesAvailable) {
          console.log('✅ Updates available shown');
        }
      }
    }
  });
});
