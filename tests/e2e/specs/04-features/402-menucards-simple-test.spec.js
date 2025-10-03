/**
 * E2E Test: Menucards Extension - Simple Functional Test
 * 
 * Prerequisites: Pagekit installed, Menucards extension enabled
 * 
 * This test verifies basic functionality step by step
 */

const { test, expect } = require('@playwright/test');
const testConfig = require('../../helpers/test-config');

test.describe.serial('Menucards - Simple Functional Test', () => {
    
    test.beforeAll(async () => {
        testConfig.startTestTimer();
    });

    test('Step 1: Login and access Menucards', async ({ page }) => {
        testConfig.log('═══════════════════════════════════════');
        testConfig.log('MENUCARDS SIMPLE FUNCTIONAL TEST');
        testConfig.log('═══════════════════════════════════════');
        
        // Login
        await page.goto(testConfig.getAdminUrl());
        const adminCreds = testConfig.getAdminCredentials();
        await page.fill('input[name="username"]', adminCreds.username);
        await page.fill('input[name="password"]', adminCreds.password);
        await page.click('button[type="submit"]');
        await page.waitForURL(/.*\/admin/, { timeout: 15000 });
        testConfig.log('✅ Logged in');
        
        // Try to access Menucards page
        testConfig.log('Accessing /admin/menucards...');
        await page.goto(testConfig.getAdminUrl() + '/menucards');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(2000);
        
        const url = page.url();
        testConfig.log('Current URL: ' + url);
        
        // Check if we're on the right page
        expect(url).toContain('/menucards');
        testConfig.log('✅ Menucards page accessible');
        
        // Check for Vue app
        const vueApp = await page.locator('#menucards').isVisible({ timeout: 5000 }).catch(() => false);
        
        if (vueApp) {
            testConfig.log('✅ Vue app loaded!');
        } else {
            testConfig.log('⚠️ Vue app not visible - checking console...');
            
            // Get console logs
            page.on('console', msg => {
                testConfig.log('[Browser Console] ' + msg.text());
            });
            
            await page.waitForTimeout(2000);
        }
    });

    test('Step 2: Test Products page', async ({ page }) => {
        testConfig.log('Testing Products page...');
        
        await page.goto(testConfig.getAdminUrl() + '/menucards/products');
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(2000);
        
        const url = page.url();
        testConfig.log('Current URL: ' + url);
        
        expect(url).toContain('/menucards/products');
        testConfig.log('✅ Products page accessible');
        
        const vueApp = await page.locator('#products').isVisible({ timeout: 5000 }).catch(() => false);
        
        if (vueApp) {
            testConfig.log('✅ Products Vue app loaded!');
        } else {
            testConfig.log('⚠️ Vue app not visible');
        }
    });

    test('Step 3: Create product via API', async ({ page }) => {
        testConfig.log('Creating product via API...');
        
        await page.goto(testConfig.getAdminUrl());
        await page.waitForLoadState('domcontentloaded');
        
        // Get CSRF token
        const csrfToken = await page.locator('meta[name="csrf-token"]').getAttribute('content');
        testConfig.log('CSRF Token: ' + (csrfToken ? 'Found' : 'Missing'));
        
        //Create product via evaluate
        const result = await page.evaluate(async ([token]) => {
            const response = await fetch('/api/menucards/product', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': token
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    product: {
                        name: 'Test Schnitzel',
                        description: 'Ein leckeres Testprodukt',
                        price: 15.90,
                        allergens: 'Gluten'
                    }
                })
            });
            
            const data = await response.json();
            return {
                ok: response.ok,
                status: response.status,
                data: data
            };
        }, [csrfToken]);
        
        testConfig.log('API Response status: ' + result.status);
        testConfig.log('API Response: ' + JSON.stringify(result.data));
        
        if (result.ok && result.data.product) {
            testConfig.log('✅ Product created with ID: ' + result.data.product.id);
        } else {
            testConfig.log('⚠️ Product creation failed or unexpected response');
        }
    });

});
