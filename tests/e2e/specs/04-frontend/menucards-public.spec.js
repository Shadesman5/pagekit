const { test, expect } = require('@playwright/test');
const testConfig = require('../../helpers/test-config');

test.describe('Menucards Public View', () => {
    let menuSlug = 'test-public-menu';

    test.beforeAll(async ({ browser }) => {
        // Setup: Create a test menu with products via admin
        console.log('Setup: Creating test menu for public view tests...');
        
        const context = await browser.newContext();
        const page = await context.newPage();

        // Login and create menu
        await page.goto(testConfig.getAdminUrl());
        await page.waitForLoadState('networkidle');

        await page.goto(testConfig.getAdminUrl() + '/menucards');
        await page.waitForLoadState('networkidle');

        // Create menu
        await page.click('button:has-text("Add Menu")');
        await page.waitForTimeout(500);
        await page.fill('input[type="text"]', 'Public Test Menu');
        await page.fill('input[type="text"][value=""]', 'test-public-menu'); // slug
        await page.selectOption('select', '1'); // Published
        await page.click('button:has-text("Save")');
        await page.waitForTimeout(1000);

        // Add category
        await page.click('button:has-text("Add Category")').first();
        await page.waitForTimeout(500);
        await page.fill('input[type="text"]', 'Starters');
        await page.click('button:has-text("Save")');
        await page.waitForTimeout(1000);

        // Add product via context-aware creation
        await page.click('button:has-text("Create Product Here")');
        await page.waitForTimeout(500);
        await page.fill('input[type="text"]').last().fill('Bruschetta');
        await page.fill('input[type="number"]').last().fill('7.50');
        await page.fill('textarea').last().fill('Italian appetizer with tomatoes');
        await page.click('button:has-text("Create & Add")');
        await page.waitForTimeout(1500);

        await context.close();
        console.log('✅ Setup complete');
    });

    test('should display published menu card on public site', async ({ page }) => {
        console.log('Test: Accessing public menu card...');

        await page.goto(testConfig.getSiteUrl() + '/menucard/' + menuSlug);
        await page.waitForLoadState('networkidle');

        // Verify menu title
        await expect(page.locator('h1:has-text("Public Test Menu")')).toBeVisible();
        console.log('✅ Menu title displayed');

        // Verify category
        await expect(page.locator('h2:has-text("Starters")')).toBeVisible();
        console.log('✅ Category displayed');

        // Verify product
        await expect(page.locator('h3:has-text("Bruschetta")')).toBeVisible();
        await expect(page.locator('text=€7.50')).toBeVisible();
        await expect(page.locator('text=Italian appetizer')).toBeVisible();
        console.log('✅ Product displayed with price and description');
    });

    test('should display menu list on index page', async ({ page }) => {
        console.log('Test: Accessing menu list index...');

        await page.goto(testConfig.getSiteUrl() + '/menucard');
        await page.waitForLoadState('networkidle');

        // Verify menu appears in list
        await expect(page.locator('text=Public Test Menu')).toBeVisible();
        console.log('✅ Menu appears in public index');

        // Verify View Menu button
        const viewButton = page.locator('a:has-text("View Menu")');
        await expect(viewButton).toBeVisible();
        console.log('✅ View Menu button present');
    });

    test('should return 404 for non-existent menu', async ({ page }) => {
        console.log('Test: Accessing non-existent menu...');

        const response = await page.goto(testConfig.getSiteUrl() + '/menucard/non-existent-slug');
        
        expect(response.status()).toBe(404);
        console.log('✅ 404 returned for non-existent menu');
    });

    test('should not display draft menus on public site', async ({ browser }) => {
        console.log('Test: Draft menus should not be visible...');

        // Create draft menu via admin
        const context = await browser.newContext();
        const adminPage = await context.newPage();

        await adminPage.goto(testConfig.getAdminUrl() + '/menucards');
        await adminPage.waitForLoadState('networkidle');

        await adminPage.click('button:has-text("Add Menu")');
        await adminPage.waitForTimeout(500);
        await adminPage.fill('input[type="text"]', 'Draft Menu');
        await adminPage.fill('input[type="text"][value=""]', 'draft-menu');
        await adminPage.selectOption('select', '0'); // Draft status
        await adminPage.click('button:has-text("Save")');
        await adminPage.waitForTimeout(1000);

        await context.close();

        // Try to access draft menu on public site
        const response = await page.goto(testConfig.getSiteUrl() + '/menucard/draft-menu');
        expect(response.status()).toBe(404);
        console.log('✅ Draft menu not accessible on public site');
    });

    test.afterAll(async ({ browser }) => {
        // Cleanup: Delete test menus
        console.log('Cleanup: Removing test menus...');

        const context = await browser.newContext();
        const page = await context.newPage();

        await page.goto(testConfig.getAdminUrl() + '/menucards');
        await page.waitForLoadState('networkidle');

        // Delete all test menus
        const deleteButtons = await page.locator('button:has-text("Delete"), button[class*="danger"]').all();
        
        for (const button of deleteButtons) {
            page.once('dialog', dialog => dialog.accept());
            await button.click();
            await page.waitForTimeout(500);
        }

        await context.close();
        console.log('✅ Cleanup complete');
    });
});
