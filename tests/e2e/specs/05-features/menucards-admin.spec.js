const { test, expect } = require('@playwright/test');
const testConfig = require('../../helpers/test-config');

test.describe('Menucards Admin Interface', () => {
    test.beforeEach(async ({ page }) => {
        console.log('Test: Navigating to admin');
        await page.goto(testConfig.getAdminUrl());
        await page.waitForLoadState('networkidle');
    });

    test('should display Menucards menu items in admin navigation', async ({ page }) => {
        console.log('Test: Checking for Menucards navigation items');
        
        // Check for main Menucards menu item
        const menucardsLink = page.locator('text=Menucards').first();
        await expect(menucardsLink).toBeVisible();
        console.log('✅ Menucards menu item found');

        // Check for Products submenu
        const productsLink = page.locator('text=Products').first();
        await expect(productsLink).toBeVisible();
        console.log('✅ Products submenu item found');
    });

    test('should navigate to Products management page', async ({ page }) => {
        console.log('Test: Navigating to Products page');
        
        await page.click('text=Products');
        await page.waitForLoadState('networkidle');
        
        // Verify we're on the products page
        await expect(page).toHaveURL(/\/admin\/menucards\/products/);
        console.log('✅ Products page loaded');
        
        // Verify page content
        const heading = page.locator('h2:has-text("Products")');
        await expect(heading).toBeVisible();
        console.log('✅ Products heading visible');
    });

    test('should navigate to Menucards management page', async ({ page }) => {
        console.log('Test: Navigating to Menucards page');
        
        await page.click('text=Menucards');
        await page.waitForLoadState('networkidle');
        
        // Verify we're on the menucards page
        await expect(page).toHaveURL(/\/admin\/menucards/);
        console.log('✅ Menucards page loaded');
        
        // Verify page content
        const heading = page.locator('h2:has-text("Menu Cards")');
        await expect(heading).toBeVisible();
        console.log('✅ Menu Cards heading visible');
    });

    test('should display "Add Product" button on Products page', async ({ page }) => {
        console.log('Test: Checking for Add Product button');
        
        await page.click('text=Products');
        await page.waitForLoadState('networkidle');
        
        const addButton = page.locator('button:has-text("Add Product")');
        await expect(addButton).toBeVisible();
        console.log('✅ Add Product button found');
    });

    test('should display "Add Menu" button on Menucards page', async ({ page }) => {
        console.log('Test: Checking for Add Menu button');
        
        await page.click('text=Menucards');
        await page.waitForLoadState('networkidle');
        
        const addButton = page.locator('button:has-text("Add Menu")');
        await expect(addButton).toBeVisible();
        console.log('✅ Add Menu button found');
    });
});
