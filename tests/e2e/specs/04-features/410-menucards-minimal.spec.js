/**
 * Minimal Menucards Test - Fast and focused
 */

const { test, expect } = require('@playwright/test');
const testConfig = require('../../helpers/test-config');

test.describe('Menucards - Minimal Test', () => {

    test('Admin can access Menucards', async ({ page }) => {
        test.setTimeout(30000);
        
        console.log('[Test] Login...');
        await page.goto(testConfig.getAdminUrl() + '/login');
        
        const adminCreds = testConfig.getAdminCredentials();
        await page.fill('input[name="username"]', adminCreds.username);
        await page.fill('input[name="password"]', adminCreds.password);
        await page.click('button[type="submit"]');
        await page.waitForSelector('.pk-user-avatar', { timeout: 10000 });
        console.log('[Test] ✅ Logged in');
        
        console.log('[Test] Navigate to /menucards...');
        await page.goto(testConfig.getAdminUrl() + '/menucards', { timeout: 10000 });
        await page.waitForSelector('#menucards', { timeout: 10000 });
        console.log('[Test] ✅ #menucards element found');
        
        const hasButton = await page.locator('button').first().isVisible();
        expect(hasButton).toBeTruthy();
        console.log('[Test] ✅ Buttons visible - Page works!');
    });

    test('Admin can access Products', async ({ page }) => {
        test.setTimeout(30000);
        
        console.log('[Test] Login...');
        await page.goto(testConfig.getAdminUrl() + '/login');
        
        const adminCreds = testConfig.getAdminCredentials();
        await page.fill('input[name="username"]', adminCreds.username);
        await page.fill('input[name="password"]', adminCreds.password);
        await page.click('button[type="submit"]');
        await page.waitForSelector('.pk-user-avatar', { timeout: 10000 });
        console.log('[Test] ✅ Logged in');
        
        console.log('[Test] Navigate to /menucards/products...');
        await page.goto(testConfig.getAdminUrl() + '/menucards/products', { timeout: 10000 });
        await page.waitForSelector('#products', { timeout: 10000 });
        console.log('[Test] ✅ #products element found');
        
        const hasButton = await page.locator('button').first().isVisible();
        expect(hasButton).toBeTruthy();
        console.log('[Test] ✅ Buttons visible - Page works!');
    });

});
