/**
 * E2E Test: Menucards Extension - Basic Functionality
 * 
 * Tests basic admin access and functionality
 * Prerequisites: Pagekit installed, Menucards extension enabled during installation
 */

const { test, expect } = require('@playwright/test');
const testConfig = require('../../helpers/test-config');

test.describe('Menucards Extension - Basic Functionality', () => {
    
    test.beforeAll(async () => {
        testConfig.startTestTimer();
    });

    test.beforeEach(async ({ page }) => {
        // Login as admin
        testConfig.log('Logging in as admin...');
        await page.goto(testConfig.getAdminUrl());
        
        const adminCreds = testConfig.getAdminCredentials();
        await page.fill('input[name="username"]', adminCreds.username);
        await page.fill('input[name="password"]', adminCreds.password);
        await page.click('button[type="submit"]');
        await page.waitForURL(/.*\/admin\/dashboard/, { timeout: 15000 });
        
        testConfig.log('Logged in', '✅');
    });

    test('Can access Menucards list page', async ({ page }) => {
        testConfig.log('Testing access to /admin/menucards...', '🔍');
        
        // Direct navigation
        await page.goto(testConfig.getAdminUrl() + '/menucards');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1500);
        
        const url = page.url();
        testConfig.log('Current URL: ' + url, 'ℹ️');
        
        // Check for Vue app
        const menucardsApp = page.locator('#menucards');
        const isVisible = await menucardsApp.isVisible({ timeout: 10000 }).catch(() => false);
        
        if (isVisible) {
            testConfig.log('✅ #menucards Vue app LOADED!', '🎉');
        } else {
            testConfig.error('❌ #menucards Vue app NOT found!');
            const title = await page.title();
            testConfig.log('Page title: ' + title);
            throw new Error('Menucards Vue app not loaded');
        }
        
        // Check for heading
        await expect(page.locator('h2:has-text("Menucards")')).toBeVisible({ timeout: 5000 });
        testConfig.log('Page heading found', '✅');
        
        // Check for Add Menu button
        await expect(page.locator('button:has-text("Add Menu")')).toBeVisible({ timeout: 5000 });
        testConfig.log('Add Menu button found', '✅');
        
        testConfig.log('═══════════════════════════════════', '🎉');
        testConfig.log('MENUCARDS LIST PAGE WORKS!', '✅');
        testConfig.log('═══════════════════════════════════', '🎉');
    });

    test('Can access Products page', async ({ page }) => {
        testConfig.log('Testing access to /admin/menucards/products...', '🔍');
        
        await page.goto(testConfig.getAdminUrl() + '/menucards/products');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1500);
        
        const url = page.url();
        testConfig.log('Current URL: ' + url, 'ℹ️');
        
        // Check for Vue app
        const productsApp = page.locator('#products');
        const isVisible = await productsApp.isVisible({ timeout: 10000 }).catch(() => false);
        
        if (isVisible) {
            testConfig.log('✅ #products Vue app LOADED!', '🎉');
        } else {
            testConfig.error('❌ #products Vue app NOT found!');
            throw new Error('Products Vue app not loaded');
        }
        
        // Check for heading
        await expect(page.locator('h2:has-text("Products")')).toBeVisible({ timeout: 5000 });
        testConfig.log('Page heading found', '✅');
        
        // Check for Add Product button
        await expect(page.locator('button:has-text("Add Product")')).toBeVisible({ timeout: 5000 });
        testConfig.log('Add Product button found', '✅');
        
        testConfig.log('═══════════════════════════════════', '🎉');
        testConfig.log('PRODUCTS PAGE WORKS!', '✅');
        testConfig.log('═══════════════════════════════════', '🎉');
    });

    test('Can create a product', async ({ page }) => {
        testConfig.log('Testing product creation...', '📦');
        
        await page.goto(testConfig.getAdminUrl() + '/menucards/products');
        await page.waitForSelector('#products', { timeout: 10000 });
        await page.waitForTimeout(1000);
        
        // Click Add Product
        testConfig.log('Clicking "Add Product" button...');
        await page.click('button:has-text("Add Product")');
        await page.waitForTimeout(1000);
        
        // Check if modal opened
        const modal = page.locator('.uk-modal.uk-open');
        const modalVisible = await modal.isVisible({ timeout: 5000 }).catch(() => false);
        
        if (!modalVisible) {
            testConfig.error('❌ Modal did NOT open!');
            throw new Error('Product creation modal failed to open');
        }
        
        testConfig.log('✅ Modal opened!', '📝');
        
        // Fill form
        testConfig.log('Filling product form...');
        await modal.locator('input[type="text"]').first().fill('Test Produkt');
        await modal.locator('input[type="number"]').fill('12.50');
        await modal.locator('textarea').fill('Ein Test-Produkt');
        
        // Save
        testConfig.log('Saving product...');
        await modal.locator('button:has-text("Save")').click();
        await page.waitForTimeout(2000);
        
        // Check for notification
        const notification = page.locator('.uk-notify-message');
        const notifVisible = await notification.isVisible({ timeout: 5000 }).catch(() => false);
        
        if (notifVisible) {
            const notifText = await notification.textContent();
            testConfig.log('Notification: ' + notifText, 'ℹ️');
        }
        
        // Check if product appears in list
        await page.waitForTimeout(1000);
        const productLink = page.locator('a:has-text("Test Produkt")');
        await expect(productLink).toBeVisible({ timeout: 5000 });
        
        testConfig.log('✅ Product created and visible in list!', '🎉');
    });

});
