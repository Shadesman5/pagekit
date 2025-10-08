/**
 * Menucards Working Test
 * Using same pattern as authentication.spec.js
 */

const { test, expect } = require('@playwright/test');
const testConfig = require('../../helpers/test-config');
const { waitForVue, navigateAndWaitForVue, fillVueInput } = require('../../helpers/vue-helpers');

test.describe('Menucards Extension Test', () => {
  test.beforeAll(async () => {
    testConfig.startTestTimer();
    await testConfig.testConnectivity();
  });

  test('Navigate to Menucards menu page and verify it loads', async ({ page }) => {
    testConfig.log('═══════════════════════════════════════');
    testConfig.log('MENUCARDS MENU PAGE TEST');
    testConfig.log('═══════════════════════════════════════');
    testConfig.log('Testing menucards/menu page...', '🚀');

    // LOGIN FIRST (wie authentication.spec.js!)
    testConfig.log('Logging in first...', '🔐');
    await navigateAndWaitForVue(page, testConfig.getAdminUrl() + '/login');
    const adminCreds = testConfig.getAdminCredentials();
    await fillVueInput(page, 'input[name="credentials[username]"]', adminCreds.username);
    await fillVueInput(page, 'input[name="credentials[password]"]', adminCreds.password);
    
    await Promise.all([
      page.waitForURL(/\/admin(?!\/login)/, { timeout: testConfig.getActionTimeout() }),
      page.click('.js-login button')
    ]);
    
    await waitForVue(page);
    testConfig.success('Logged in successfully!');
    
    // Navigate to menucards using Vue helper
    await navigateAndWaitForVue(page, testConfig.getAdminUrl() + '/menucards/menu');
    
    testConfig.log('Waiting for Vue to initialize...', '⚡');
    await waitForVue(page);
    
    // Wait a bit more for component to mount
    await page.waitForTimeout(2000);
    
    testConfig.log('Checking page content...', '🔍');
    
    // Check URL
    const url = page.url();
    testConfig.debug(`Current URL: ${url}`, '🌐');
    
    // Check if we're on the right page (not redirected to login)
    if (url.includes('/login')) {
      testConfig.error('Redirected to login - session might be missing!');
      throw new Error('Session lost - user not authenticated');
    }
    
    // Check for the menus div
    const menusDiv = await page.locator('#menus').count();
    testConfig.debug(`#menus div count: ${menusDiv}`, '📦');
    
    if (menusDiv === 0) {
      testConfig.error('#menus div not found in HTML!');
      const bodyHTML = await page.locator('body').innerHTML();
      testConfig.debug(`Body HTML (first 500 chars): ${bodyHTML.substring(0, 500)}`);
    }
    
    // Check if Vue component rendered
    const vueRendered = await page.evaluate(() => {
      const div = document.getElementById('menus');
      if (!div) return { success: false, reason: '#menus not found' };
      
      const innerHTML = div.innerHTML;
      const hasContent = innerHTML.length > 50; // More than just <menu-list></menu-list>
      
      return {
        success: hasContent,
        innerHTML: innerHTML.substring(0, 200),
        hasH2: !!div.querySelector('h2'),
        hasButton: !!div.querySelector('button')
      };
    });
    
    testConfig.debug(`Vue rendered: ${JSON.stringify(vueRendered, null, 2)}`, '🎨');
    
    // Check for console errors
    const consoleLogs = [];
    page.on('console', msg => {
      if (msg.type() === 'error' || msg.type() === 'warning') {
        consoleLogs.push(`[${msg.type()}] ${msg.text()}`);
      }
    });
    
    // Reload to capture console
    await page.reload();
    await waitForVue(page);
    await page.waitForTimeout(1000);
    
    if (consoleLogs.length > 0) {
      testConfig.warn('Console errors/warnings found:');
      consoleLogs.forEach(log => testConfig.debug(log));
    }
    
    // Take screenshot
    await page.screenshot({ path: '/tmp/menucards-real-test.png', fullPage: true });
    testConfig.log('Screenshot saved: /tmp/menucards-real-test.png', '📸');
    
    // Try to find Add Menu button
    const addMenuButton = page.locator('button:has-text("Add Menu")');
    const buttonCount = await addMenuButton.count();
    
    if (buttonCount > 0) {
      testConfig.success('✅ Add Menu button found - Vue component rendered!');
      await expect(addMenuButton).toBeVisible();
    } else {
      testConfig.error('Add Menu button NOT found - Vue not rendering!');
      testConfig.debug(`Vue rendered check: ${JSON.stringify(vueRendered)}`);
    }
  });
  
  test('Navigate to Products page and verify it loads', async ({ page }) => {
    testConfig.log('Testing menucards/product page...', '🚀');

    // LOGIN FIRST
    await navigateAndWaitForVue(page, testConfig.getAdminUrl() + '/login');
    const adminCreds = testConfig.getAdminCredentials();
    await fillVueInput(page, 'input[name="credentials[username]"]', adminCreds.username);
    await fillVueInput(page, 'input[name="credentials[password]"]', adminCreds.password);
    await Promise.all([
      page.waitForURL(/\/admin(?!\/login)/),
      page.click('.js-login button')
    ]);
    await waitForVue(page);
    
    await navigateAndWaitForVue(page, testConfig.getAdminUrl() + '/menucards/product');
    await waitForVue(page);
    await page.waitForTimeout(2000);
    
    // Check for products div
    const productsDiv = await page.locator('#products').count();
    testConfig.debug(`#products div count: ${productsDiv}`, '📦');
    
    // Check for Add Product button
    const addProductButton = page.locator('button:has-text("Add Product")');
    const buttonCount = await addProductButton.count();
    
    if (buttonCount > 0) {
      testConfig.success('✅ Add Product button found - Vue component rendered!');
    } else {
      testConfig.error('Add Product button NOT found!');
    }
  });
});
