/**
 * E2E Test: Menucards Extension - Complete Contextual Creation Workflow
 * 
 * This test validates the CRITICAL workflow:
 * 1. Create a menu "Tageskarte"
 * 2. Add category "Hauptgerichte"
 * 3. Click "Create New Product" in category
 * 4. Fill modal: "Wiener Schnitzel", 19.90€
 * 5. Verify product appears in category
 * 6. Verify product in global products list
 * 7. Verify public display
 */

const { test, expect } = require('@playwright/test');
const testConfig = require('../../helpers/test-config');

test.describe('Menucards - Contextual Product Creation Workflow', () => {
    
    let menuSlug = 'tageskarte-' + Date.now();
    
    test.beforeAll(async () => {
        testConfig.startTestTimer();
    });

    test.beforeEach(async ({ page }) => {
        // Login as admin
        await page.goto(testConfig.getAdminUrl());
        
        const isLoggedIn = await page.locator('.pk-user-avatar').isVisible().catch(() => false);
        
        if (!isLoggedIn) {
            const adminCreds = testConfig.getAdminCredentials();
            await page.fill('input[name="username"]', adminCreds.username);
            await page.fill('input[name="password"]', adminCreds.password);
            await page.click('button[type="submit"]');
            await page.waitForURL(/.*\/admin/, { timeout: 10000 });
        }
    });

    test('Complete contextual creation workflow', async ({ page }) => {
        testConfig.log('🚀 Starting complete contextual creation workflow test...', '🎯');
        
        // Step 1: Navigate to Menucards
        testConfig.log('Step 1: Navigate to Menucards');
        await page.goto(testConfig.getAdminUrl() + '/menucards');
        await page.waitForSelector('#menucards', { timeout: 10000 });
        
        // Step 2: Create new menu "Tageskarte"
        testConfig.log('Step 2: Create menu "Tageskarte"');
        await page.click('button:has-text("Add Menu")');
        await page.waitForSelector('.uk-modal.uk-open', { timeout: 5000 });
        
        await page.fill('input[type="text"]', 'Tageskarte');
        await page.fill('input[type="text"]:nth-of-type(2)', menuSlug);
        await page.selectOption('select', '1'); // Published
        
        await page.click('.uk-modal.uk-open button:has-text("Save")');
        await page.waitForSelector('.uk-notify-message', { timeout: 5000 });
        testConfig.log('Menu "Tageskarte" created', '✅');
        
        // Step 3: Open menu for editing
        testConfig.log('Step 3: Open menu for editing');
        await page.click('a:has-text("Tageskarte")');
        await page.waitForURL(/.*\/menucards\/menu\/\d+/);
        await page.waitForSelector('#menu-edit', { timeout: 10000 });
        testConfig.log('Menu edit page loaded', '✅');
        
        // Step 4: Add category "Hauptgerichte"
        testConfig.log('Step 4: Add category "Hauptgerichte"');
        await page.click('button:has-text("Add Category")');
        await page.waitForTimeout(500);
        
        // Fill category title
        const categoryInput = page.locator('input[placeholder*="Category Title"], input[type="text"]').last();
        await categoryInput.fill('Hauptgerichte');
        testConfig.log('Category "Hauptgerichte" added', '✅');
        
        // Step 5: Click "Create New Product" button (CRITICAL)
        testConfig.log('Step 5: Click "Create New Product" button', '⭐');
        await page.click('button:has-text("Create New Product")');
        await page.waitForSelector('.uk-modal.uk-open', { timeout: 5000 });
        testConfig.log('Product creator modal opened', '📝');
        
        // Step 6: Fill in product details
        testConfig.log('Step 6: Fill product details - Wiener Schnitzel, 19.90€');
        
        // Fill product form in modal
        const modal = page.locator('.uk-modal.uk-open');
        await modal.locator('input[type="text"]').first().fill('Wiener Schnitzel');
        await modal.locator('input[type="number"]').fill('19.90');
        await modal.locator('textarea').fill('Klassisches österreichisches Gericht');
        await modal.locator('input[placeholder*="Allergens"], input[type="text"]').last().fill('Gluten, Ei');
        
        // Step 7: Save product (Create & Add)
        testConfig.log('Step 7: Save product');
        await page.click('button:has-text("Create & Add"), button:has-text("Create")');
        
        // Wait for API response and modal close
        await page.waitForTimeout(2000);
        
        testConfig.log('Product created and added to category', '✅');
        
        // Step 8: Verify product appears in category (without page reload!)
        testConfig.log('Step 8: Verify product in category');
        const productInCategory = page.locator('td:has-text("Wiener Schnitzel")');
        await expect(productInCategory).toBeVisible({ timeout: 5000 });
        testConfig.log('Product visible in category', '✅');
        
        // Step 9: Save menu
        testConfig.log('Step 9: Save complete menu');
        await page.click('button[type="submit"]:has-text("Save")');
        await page.waitForSelector('.uk-notify-message', { timeout: 5000 });
        testConfig.log('Menu saved', '✅');
        
        // Step 10: Verify in global products list
        testConfig.log('Step 10: Verify in global products list');
        await page.goto(testConfig.getAdminUrl() + '/menucards/products');
        await page.waitForSelector('#products', { timeout: 10000 });
        
        const productInList = page.locator('td:has-text("Wiener Schnitzel")');
        await expect(productInList).toBeVisible({ timeout: 5000 });
        testConfig.log('Product found in global products list', '✅');
        
        // Step 11: Verify public display
        testConfig.log('Step 11: Verify public display');
        const publicUrl = testConfig.getSiteUrl() + '/menucard/' + menuSlug;
        await page.goto(publicUrl);
        
        // Check menu title
        await expect(page.locator('h1:has-text("Tageskarte")')).toBeVisible();
        
        // Check category
        await expect(page.locator('h2:has-text("Hauptgerichte")')).toBeVisible();
        
        // Check product
        await expect(page.locator('.product-name:has-text("Wiener Schnitzel")')).toBeVisible();
        await expect(page.locator('.product-price:has-text("19,90")')).toBeVisible();
        
        testConfig.log('Public display verified successfully', '✅');
        testConfig.log('🎉 COMPLETE WORKFLOW TEST PASSED!', '🎉');
    });

    test('Product can be assigned to multiple categories', async ({ page }) => {
        testConfig.log('Testing product reusability across categories...');
        
        // Navigate to menu edit
        await page.goto(testConfig.getAdminUrl() + '/menucards');
        await page.waitForSelector('#menucards');
        
        // Find and open Tageskarte menu
        await page.click('a:has-text("Tageskarte")');
        await page.waitForSelector('#menu-edit');
        
        // Add second category
        await page.click('button:has-text("Add Category")');
        await page.waitForTimeout(500);
        
        const categoryInputs = page.locator('input[placeholder*="Category Title"]');
        const count = await categoryInputs.count();
        await categoryInputs.nth(count - 1).fill('Vorspeisen');
        
        // Add existing product to new category
        await page.click('button:has-text("Add Existing Product")').last();
        await page.waitForSelector('.uk-modal.uk-open');
        
        // Select Wiener Schnitzel
        await page.click('tr:has-text("Wiener Schnitzel") button:has-text("Add")');
        
        // Save menu
        await page.click('button[type="submit"]:has-text("Save")');
        await page.waitForSelector('.uk-notify-message');
        
        testConfig.log('Product successfully added to multiple categories', '✅');
    });

});
