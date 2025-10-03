/**
 * Quick Menucards Functionality Test
 */

const { test, expect } = require('@playwright/test');
const testConfig = require('../../helpers/test-config');

test.describe('Menucards - Quick Functionality Test', () => {
    
    test.setTimeout(60000); // 1 minute max

    test('Menucards page loads', async ({ page }) => {
        testConfig.log('Quick test: Menucards page access');
        
        // Login
        await page.goto(testConfig.getAdminUrl());
        const adminCreds = testConfig.getAdminCredentials();
        
        await page.fill('input[name="username"]', adminCreds.username);
        await page.fill('input[name="password"]', adminCreds.password);
        await page.click('button[type="submit"]');
        await page.waitForURL(/.*\/admin/, { timeout: 10000 });
        
        // Go to menucards
        await page.goto(testConfig.getAdminUrl() + '/menucards', { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#menucards', { timeout: 10000 });
        
        testConfig.log('✅ Menucards page loaded');
        
        // Check for button
        const hasButton = await page.locator('button:has-text("Add Menu")').isVisible();
        expect(hasButton).toBeTruthy();
        testConfig.log('✅ Add Menu button present');
    });

    test('Products page loads', async ({ page }) => {
        testConfig.log('Quick test: Products page access');
        
        // Login
        await page.goto(testConfig.getAdminUrl());
        const adminCreds = testConfig.getAdminCredentials();
        
        await page.fill('input[name="username"]', adminCreds.username);
        await page.fill('input[name="password"]', adminCreds.password);
        await page.click('button[type="submit"]');
        await page.waitForURL(/.*\/admin/, { timeout: 10000 });
        
        // Go to products
        await page.goto(testConfig.getAdminUrl() + '/menucards/products', { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#products', { timeout: 10000 });
        
        testConfig.log('✅ Products page loaded');
        
        const hasButton = await page.locator('button:has-text("Add Product")').isVisible();
        expect(hasButton).toBeTruthy();
        testConfig.log('✅ Add Product button present');
    });

});
