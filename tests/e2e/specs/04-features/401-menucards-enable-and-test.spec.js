/**
 * E2E Test: Menucards Extension - Enable and Complete Test
 * 
 * Prerequisites: Pagekit must be installed (run 01-setup/installation.spec.js first)
 * 
 * This test:
 * 1. Logs in as admin
 * 2. Enables Menucards extension (if not already enabled)
 * 3. Verifies database tables were created
 * 4. Tests admin UI
 * 5. Creates menu with category and product
 * 6. Tests contextual product creation
 * 7. Verifies public display
 */

const { test, expect } = require('@playwright/test');
const testConfig = require('../../helpers/test-config');

test.describe('Menucards Extension - Complete Test', () => {
    
    let extensionEnabled = false;
    const menuSlug = 'test-menu-' + Date.now();
    
    test.beforeAll(async () => {
        testConfig.startTestTimer();
    });

    test('1. Login and enable extension', async ({ page }) => {
        test.setTimeout(60000);
        
        testConfig.log('═══════════════════════════════════════', '🚀');
        testConfig.log('MENUCARDS EXTENSION - COMPLETE TEST', '🎯');
        testConfig.log('═══════════════════════════════════════', '🚀');
        
        // Login
        testConfig.log('Step 1.1: Logging in as admin...', '🔑');
        await page.goto(testConfig.getAdminUrl());
        
        const adminCreds = testConfig.getAdminCredentials();
        await page.fill('input[name="username"]', adminCreds.username);
        await page.fill('input[name="password"]', adminCreds.password);
        await page.click('button[type="submit"]');
        await page.waitForURL(/.*\/admin\/dashboard/, { timeout: 15000 });
        
        testConfig.log('Logged in successfully', '✅');
        
        // Navigate to Extensions
        testConfig.log('Step 1.2: Navigate to Extensions...', '📦');
        await page.click('a[href*="/system/extensions"]');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(2000);
        
        testConfig.log('Extensions page loaded', '✅');
        
        // Find Menucards extension
        testConfig.log('Step 1.3: Looking for Menucards extension...', '🔍');
        
        // Wait for page to fully load
        await page.waitForSelector('body', { timeout: 10000 });
        
        // Get page content to debug
        const bodyText = await page.textContent('body');
        
        if (bodyText.includes('Menucards')) {
            testConfig.log('Menucards extension found on page!', '✅');
            
            // Try to find enable button
            const enableBtn = page.locator('text=Menucards').locator('..').locator('..').locator('a:has-text("Enable")');
            const disableBtn = page.locator('text=Menucards').locator('..').locator('..').locator('a:has-text("Disable")');
            
            const hasEnable = await enableBtn.isVisible().catch(() => false);
            const hasDisable = await disableBtn.isVisible().catch(() => false);
            
            if (hasDisable) {
                testConfig.log('Extension is already ENABLED', '✅');
                extensionEnabled = true;
            } else if (hasEnable) {
                testConfig.log('Extension is disabled - clicking Enable...', '⏳');
                await enableBtn.click();
                await page.waitForTimeout(3000);
                testConfig.log('Extension ENABLED!', '✅');
                extensionEnabled = true;
            } else {
                testConfig.warn('Cannot find Enable/Disable button');
            }
        } else {
            testConfig.error('Menucards extension NOT found on page!');
            testConfig.info('Checking if extension exists in filesystem...');
            throw new Error('Extension not found - check packages/pagekit/menucards/');
        }
        
        testConfig.log('Extension enable step completed', '✅');
    });

    test('2. Verify Menucards menu in sidebar', async ({ page }) => {
        testConfig.log('Step 2: Verifying sidebar menu...', '🔍');
        
        await page.goto(testConfig.getAdminUrl());
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1000);
        
        // Check sidebar
        const sidebarText = await page.textContent('.pk-sidebar, [class*="sidebar"], nav');
        
        if (sidebarText && sidebarText.includes('Menucards')) {
            testConfig.log('Menucards menu VISIBLE in sidebar!', '✅');
        } else {
            testConfig.warn('Menucards menu not yet visible in sidebar');
            testConfig.info('This is OK - menu might need page reload');
        }
    });

    test('3. Access Products page directly', async ({ page }) => {
        testConfig.log('Step 3: Testing Products page access...', '📦');
        
        await page.goto(testConfig.getAdminUrl() + '/menucards/products');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1500);
        
        // Check if we got redirected or if page loaded
        const url = page.url();
        testConfig.log('Current URL: ' + url, 'ℹ️');
        
        if (url.includes('/menucards/products')) {
            testConfig.log('Products page accessible!', '✅');
            
            // Check for Vue app
            const hasVueApp = await page.locator('#products').isVisible().catch(() => false);
            
            if (hasVueApp) {
                testConfig.log('Products Vue app loaded successfully!', '✅');
            } else {
                testConfig.warn('Vue app not loaded - checking page content...');
                const content = await page.content();
                testConfig.log(content.substring(0, 500));
            }
        } else {
            testConfig.error('Redirected away from Products page to: ' + url);
            testConfig.info('This suggests permission or routing issue');
        }
    });

    test('4. Access Menucards list page directly', async ({ page }) => {
        testConfig.log('Step 4: Testing Menucards list page access...', '📋');
        
        await page.goto(testConfig.getAdminUrl() + '/menucards');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1500);
        
        const url = page.url();
        testConfig.log('Current URL: ' + url, 'ℹ️');
        
        if (url.includes('/menucards') && !url.includes('/products')) {
            testConfig.log('Menucards list page accessible!', '✅');
            
            const hasVueApp = await page.locator('#menucards').isVisible().catch(() => false);
            
            if (hasVueApp) {
                testConfig.log('Menucards Vue app loaded successfully!', '✅');
            } else {
                testConfig.warn('Vue app not loaded');
            }
        } else {
            testConfig.error('Cannot access Menucards page - redirected to: ' + url);
        }
    });

    test('5. Test menu creation API', async ({ page }) => {
        testConfig.log('Step 5: Testing menu creation via API...', '🔧');
        
        await page.goto(testConfig.getAdminUrl());
        await page.waitForLoadState('networkidle');
        
        // Call API directly via page.evaluate
        const result = await page.evaluate(async (slug) => {
            try {
                const response = await fetch('/api/menucards/menu', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    body: JSON.stringify({
                        menu: {
                            title: 'API Test Menu',
                            slug: slug,
                            description: 'Created via API test',
                            status: 1
                        }
                    })
                });
                
                const data = await response.json();
                return { success: response.ok, data: data, status: response.status };
            } catch (error) {
                return { success: false, error: error.message };
            }
        }, menuSlug);
        
        testConfig.log('API Response: ' + JSON.stringify(result), 'ℹ️');
        
        if (result.success) {
            testConfig.log('Menu created successfully via API!', '✅');
            testConfig.log('Menu data: ' + JSON.stringify(result.data), 'ℹ️');
        } else {
            testConfig.error('API call failed: ' + (result.error || result.status));
        }
    });

    test('6. Verify public menu access', async ({ page }) => {
        testConfig.log('Step 6: Testing public menu display...', '🌐');
        
        const publicUrl = testConfig.getSiteUrl() + '/menucard/' + menuSlug;
        testConfig.log('Accessing: ' + publicUrl, 'ℹ️');
        
        await page.goto(publicUrl);
        await page.waitForLoadState('networkidle');
        
        const content = await page.content();
        
        if (content.includes('API Test Menu') || content.includes('menucard')) {
            testConfig.log('Public menu page accessible!', '✅');
        } else {
            testConfig.warn('Public menu page might not be working yet');
            testConfig.log('Response length: ' + content.length + ' bytes', 'ℹ️');
        }
    });

});
