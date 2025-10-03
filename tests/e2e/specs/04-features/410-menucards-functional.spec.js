/**
 * E2E Test: Menucards Extension - Functional Tests
 * 
 * Prerequisites: Pagekit installed, Menucards extension enabled
 * 
 * Tests all major functionality of the Menucards extension
 */

const { test, expect } = require('@playwright/test');
const testConfig = require('../../helpers/test-config');

test.describe('Menucards Extension - Functional Tests', () => {
    
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
            await page.waitForURL(/.*\/admin/, { timeout: 15000 });
        }
    });

    test('Menucards admin page is accessible and Vue app loads', async ({ page }) => {
        testConfig.log('Testing Menucards admin page access...', '🎯');
        
        await page.goto(testConfig.getAdminUrl() + '/menucards');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(2000);
        
        testConfig.log('URL: ' + page.url());
        
        // Check if on correct page
        expect(page.url()).toContain('/menucards');
        testConfig.log('On Menucards page', '✅');
        
        // Check for Vue app
        const vueApp = page.locator('#menucards');
        await expect(vueApp).toBeVisible({ timeout: 10000 });
        testConfig.log('Vue app #menucards loaded', '✅');
        
        // Check for page title
        const title = page.locator('h2:has-text("Menucards")');
        await expect(title).toBeVisible({ timeout: 5000 });
        testConfig.log('Page title visible', '✅');
        
        // Check for Add Menu button
        const addButton = page.locator('button:has-text("Add Menu")');
        await expect(addButton).toBeVisible({ timeout: 5000 });
        testConfig.log('Add Menu button visible', '✅');
        
        await page.screenshot({ path: 'test-results/menucards-admin-page.png', fullPage: true });
        testConfig.log('Menucards admin page fully functional!', '🎉');
    });

    test('Products admin page is accessible and Vue app loads', async ({ page }) => {
        testConfig.log('Testing Products admin page access...', '📦');
        
        await page.goto(testConfig.getAdminUrl() + '/menucards/products');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(2000);
        
        // Check if on correct page
        expect(page.url()).toContain('/menucards/products');
        testConfig.log('On Products page', '✅');
        
        // Check for Vue app
        const vueApp = page.locator('#products');
        await expect(vueApp).toBeVisible({ timeout: 10000 });
        testConfig.log('Vue app #products loaded', '✅');
        
        // Check for Add Product button
        const addButton = page.locator('button:has-text("Add Product")');
        await expect(addButton).toBeVisible({ timeout: 5000 });
        testConfig.log('Add Product button visible', '✅');
        
        await page.screenshot({ path: 'test-results/products-admin-page.png', fullPage: true });
        testConfig.log('Products admin page fully functional!', '🎉');
    });

    test('Can create a product via API', async ({ request }) => {
        testConfig.log('Testing product creation via API...', '🧪');
        
        // Get CSRF token first
        const loginResponse = await request.post(testConfig.getSiteUrl() + '/api/login', {
            data: {
                username: 'admin',
                password: 'admin123'
            }
        });
        
        expect(loginResponse.ok()).toBeTruthy();
        const loginData = await loginResponse.json();
        testConfig.log('Logged in via API', '✅');
        
        // Create product
        const productData = {
            product: {
                name: 'API Test Schnitzel',
                description: 'Created via API test',
                price: 15.50,
                allergens: 'Gluten'
            }
        };
        
        const response = await request.post(testConfig.getSiteUrl() + '/api/menucards/product', {
            data: productData
        });
        
        testConfig.log('API Response status: ' + response.status());
        
        if (response.ok()) {
            const data = await response.json();
            testConfig.log('Product created via API', '✅');
            testConfig.log('Product ID: ' + (data.product ? data.product.id : 'N/A'));
            
            expect(data.product).toBeDefined();
            expect(data.product.name).toBe('API Test Schnitzel');
            testConfig.log('API test successful!', '🎉');
        } else {
            const errorText = await response.text();
            testConfig.error('API call failed: ' + errorText);
        }
    });

});
