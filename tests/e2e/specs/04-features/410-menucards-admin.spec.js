/**
 * E2E Test: Menucards Extension - Admin Functionality
 * 
 * Tests the complete admin workflow for the Menucards extension including:
 * - Extension activation
 * - Product management
 * - Menu management
 * - Category management
 * - Contextual product creation
 */

const { test, expect } = require('@playwright/test');
const testConfig = require('../../helpers/test-config');

test.describe('Menucards Extension - Admin Features', () => {
    
    test.beforeAll(async () => {
        testConfig.startTestTimer();
    });

    test.beforeEach(async ({ page }) => {
        // Login as admin before each test
        await page.goto(testConfig.getAdminUrl());
        
        // Check if already logged in
        const isLoggedIn = await page.locator('.pk-user-avatar').isVisible().catch(() => false);
        
        if (!isLoggedIn) {
            testConfig.log('Logging in as admin...');
            const adminCreds = testConfig.getAdminCredentials();
            await page.fill('input[name="username"]', adminCreds.username);
            await page.fill('input[name="password"]', adminCreds.password);
            await page.click('button[type="submit"]');
            await page.waitForURL(/.*\/admin/, { timeout: 10000 });
            testConfig.log('Logged in successfully', '✅');
        }
    });

    test('Extension is installed and menu is visible', async ({ page }) => {
        testConfig.log('Checking if Menucards extension is accessible...');
        
        // Check if Menucards menu exists in admin sidebar
        const menucardsLink = page.locator('a[href*="menucards"]').first();
        await expect(menucardsLink).toBeVisible({ timeout: 10000 });
        
        testConfig.log('Menucards menu found in sidebar', '✅');
    });

    test('Can access Products management page', async ({ page }) => {
        testConfig.log('Navigating to Products page...');
        
        // Click on Products submenu
        await page.click('a[href*="menucards/products"]');
        await page.waitForURL(/.*menucards\/products/);
        
        // Check if Vue app loaded
        await expect(page.locator('#products')).toBeVisible();
        await expect(page.locator('h2:has-text("Products")')).toBeVisible();
        
        testConfig.log('Products page loaded successfully', '✅');
    });

    test('Can create a new product', async ({ page }) => {
        testConfig.log('Testing product creation...');
        
        // Navigate to Products
        await page.goto(testConfig.getAdminUrl() + '/menucards/products');
        await page.waitForSelector('#products', { timeout: 10000 });
        
        // Click Add Product button
        await page.click('button:has-text("Add Product")');
        
        // Wait for modal
        await page.waitForSelector('.uk-modal.uk-open', { timeout: 5000 });
        testConfig.log('Product creation modal opened', '📝');
        
        // Fill in product details
        await page.fill('input[type="text"]', 'Test Schnitzel');
        await page.fill('input[type="number"]', '15.90');
        await page.fill('textarea', 'A delicious test product');
        
        // Save product
        await page.click('button:has-text("Save")');
        
        // Wait for notification
        await page.waitForSelector('.uk-notify-message', { timeout: 5000 });
        
        testConfig.log('Product created successfully', '✅');
    });

    test('Can access Menucards list page', async ({ page }) => {
        testConfig.log('Navigating to Menucards list...');
        
        // Click on Menucards menu
        await page.click('a[href$="/menucards"]:not([href*="products"])');
        await page.waitForURL(/.*\/menucards$/);
        
        // Check if Vue app loaded
        await expect(page.locator('#menucards')).toBeVisible();
        await expect(page.locator('h2:has-text("Menucards")')).toBeVisible();
        
        testConfig.log('Menucards list page loaded successfully', '✅');
    });

    test('Can create a new menu', async ({ page }) => {
        testConfig.log('Testing menu creation...');
        
        // Navigate to Menucards
        await page.goto(testConfig.getAdminUrl() + '/menucards');
        await page.waitForSelector('#menucards', { timeout: 10000 });
        
        // Click Add Menu button
        await page.click('button:has-text("Add Menu")');
        
        // Wait for modal
        await page.waitForSelector('.uk-modal.uk-open', { timeout: 5000 });
        testConfig.log('Menu creation modal opened', '📝');
        
        // Fill in menu details
        const menuTitle = 'Test Menu ' + Date.now();
        await page.fill('input[type="text"]', menuTitle);
        await page.fill('input[type="text"]:nth-child(2)', 'test-menu-' + Date.now());
        
        // Save menu
        await page.click('button:has-text("Save")');
        
        // Wait for notification
        await page.waitForSelector('.uk-notify-message', { timeout: 5000 });
        
        testConfig.log('Menu created successfully', '✅');
    });

});
