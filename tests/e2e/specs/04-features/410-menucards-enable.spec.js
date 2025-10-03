/**
 * E2E Test: Enable and Test Menucards Extension
 * 
 * Prerequisites: Fresh Pagekit installation
 * 
 * This test:
 * 1. Enables the Menucards extension
 * 2. Verifies database tables are created
 * 3. Verifies menu entries appear
 * 4. Tests basic navigation
 */

const { test, expect } = require('@playwright/test');
const testConfig = require('../../helpers/test-config');
const { exec } = require('child_process');
const { promisify } = require('util');
const execAsync = promisify(exec);

test.describe('Menucards Extension - Enable and Setup', () => {
    
    test.beforeAll(async () => {
        testConfig.startTestTimer();
    });

    test('Enable Menucards extension via admin UI', async ({ page }) => {
        testConfig.log('=== ENABLING MENUCARDS EXTENSION ===', '🚀');
        
        // Login as admin
        testConfig.log('Step 1: Logging in as admin...');
        await page.goto(testConfig.getAdminUrl());
        
        const isLoggedIn = await page.locator('.pk-user-avatar').isVisible().catch(() => false);
        
        if (!isLoggedIn) {
            const adminCreds = testConfig.getAdminCredentials();
            await page.fill('input[name="username"]', adminCreds.username);
            await page.fill('input[name="password"]', adminCreds.password);
            await page.click('button[type="submit"]');
            await page.waitForURL(/.*\/admin\/dashboard/, { timeout: 15000 });
            testConfig.log('Logged in successfully', '✅');
        } else {
            testConfig.log('Already logged in', '✅');
        }
        
        // Navigate to Extensions
        testConfig.log('Step 2: Navigating to Extensions page...');
        await page.goto(testConfig.getAdminUrl() + '/system/extensions');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(2000); // Give Vue time to render
        testConfig.log('Extensions page loaded', '✅');
        
        // Look for Menucards extension
        testConfig.log('Step 3: Looking for Menucards extension...');
        
        // Try different selectors
        const extensionSelectors = [
            '.uk-panel:has-text("Menucards")',
            '.uk-card:has-text("Menucards")',
            'div:has-text("Menucards")',
            '[data-extension="menucards"]'
        ];
        
        let extensionElement = null;
        for (const selector of extensionSelectors) {
            const el = page.locator(selector).first();
            if (await el.isVisible().catch(() => false)) {
                extensionElement = el;
                testConfig.log('Found extension with selector: ' + selector, '✅');
                break;
            }
        }
        
        if (!extensionElement) {
            testConfig.error('Menucards extension not found on extensions page!');
            testConfig.info('Taking screenshot for debugging...');
            await page.screenshot({ path: 'test-results/menucards-not-found.png', fullPage: true });
            throw new Error('Menucards extension not found - check if extension is properly registered');
        }
        
        testConfig.log('Menucards extension found', '✅');
        
        // Find and click Enable button
        testConfig.log('Step 4: Enabling extension...');
        
        const enableButton = extensionElement.locator('a:has-text("Enable"), button:has-text("Enable")').first();
        const isEnabled = await enableButton.isVisible().catch(() => false);
        
        if (!isEnabled) {
            testConfig.log('Extension appears to be already enabled', 'ℹ️');
        } else {
            testConfig.log('Clicking Enable button...');
            await enableButton.click();
            await page.waitForTimeout(3000); // Give time for enable script to run
            testConfig.log('Enable button clicked', '✅');
            
            // Wait for notification or page reload
            await page.waitForLoadState('networkidle');
            await page.waitForTimeout(1000);
        }
        
        testConfig.log('Extension enabled successfully!', '🎉');
    });

    test('Verify database tables were created', async () => {
        testConfig.log('=== VERIFYING DATABASE TABLES ===', '🗄️');
        
        // Check if tables exist using sqlite3
        const tables = ['pk_menucards_menu', 'pk_menucards_category', 'pk_menucards_product', 'pk_menucards_category_product'];
        
        for (const table of tables) {
            try {
                const { stdout } = await execAsync(`sqlite3 /workspace/pagekit.db "SELECT name FROM sqlite_master WHERE type='table' AND name='${table}'"`);
                
                if (stdout.trim() === table) {
                    testConfig.log(`Table ${table} exists`, '✅');
                } else {
                    testConfig.error(`Table ${table} NOT FOUND!`);
                    throw new Error(`Expected table ${table} was not created`);
                }
            } catch (error) {
                testConfig.error(`Error checking table ${table}: ${error.message}`);
                throw error;
            }
        }
        
        testConfig.log('All 4 database tables created successfully!', '🎉');
    });

    test('Verify Menucards menu appears in admin sidebar', async ({ page }) => {
        testConfig.log('=== VERIFYING ADMIN MENU ===', '📋');
        
        // Go to admin dashboard
        await page.goto(testConfig.getAdminUrl());
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(2000);
        
        // Look for Menucards menu in sidebar
        testConfig.log('Looking for Menucards menu in sidebar...');
        
        // Try to find the menu
        const menucardsLink = page.locator('a[href*="/menucards"]').first();
        const isVisible = await menucardsLink.isVisible({ timeout: 5000 }).catch(() => false);
        
        if (!isVisible) {
            testConfig.error('Menucards menu NOT visible in sidebar!');
            await page.screenshot({ path: 'test-results/sidebar-no-menucards.png', fullPage: true });
            throw new Error('Menucards menu not found in admin sidebar');
        }
        
        testConfig.log('Menucards menu visible in sidebar', '✅');
        
        // Check Products submenu
        const productsLink = page.locator('a[href*="/menucards/products"]').first();
        const productsVisible = await productsLink.isVisible({ timeout: 5000 }).catch(() => false);
        
        if (productsVisible) {
            testConfig.log('Products submenu visible', '✅');
        } else {
            testConfig.warn('Products submenu not visible (might be collapsed)');
        }
    });

    test('Can navigate to Menucards admin page', async ({ page }) => {
        testConfig.log('=== TESTING MENUCARDS ADMIN PAGE ===', '🎯');
        
        await page.goto(testConfig.getAdminUrl());
        await page.waitForLoadState('networkidle');
        
        // Click Menucards menu
        testConfig.log('Clicking Menucards menu...');
        const menucardsLink = page.locator('a[href$="/menucards"]:not([href*="products"])').first();
        await menucardsLink.click();
        
        await page.waitForURL(/.*\/menucards$/, { timeout: 10000 });
        await page.waitForTimeout(1500);
        testConfig.log('Navigated to Menucards page', '✅');
        
        // Check if Vue app loaded
        const menucardsApp = page.locator('#menucards');
        await expect(menucardsApp).toBeVisible({ timeout: 10000 });
        testConfig.log('Menucards Vue app loaded', '✅');
        
        // Take screenshot
        await page.screenshot({ path: 'test-results/menucards-admin-page.png', fullPage: true });
        testConfig.log('Screenshot saved', '📸');
    });

    test('Can navigate to Products admin page', async ({ page }) => {
        testConfig.log('=== TESTING PRODUCTS ADMIN PAGE ===', '📦');
        
        await page.goto(testConfig.getAdminUrl() + '/menucards/products');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1500);
        
        // Check if Vue app loaded
        const productsApp = page.locator('#products');
        await expect(productsApp).toBeVisible({ timeout: 10000 });
        testConfig.log('Products Vue app loaded', '✅');
        
        // Check for Add Product button
        const addButton = page.locator('button:has-text("Add Product")');
        await expect(addButton).toBeVisible({ timeout: 5000 });
        testConfig.log('Add Product button visible', '✅');
        
        // Take screenshot
        await page.screenshot({ path: 'test-results/products-admin-page.png', fullPage: true });
        testConfig.log('Screenshot saved', '📸');
    });

});
