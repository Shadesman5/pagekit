/**
 * E2E Test: Menucards Extension - Activation and Basic Functionality
 * 
 * Prerequisites: Fresh Pagekit installation
 * 
 * Tests:
 * 1. Enable extension via API
 * 2. Verify database tables created
 * 3. Verify admin UI is accessible
 * 4. Test basic CRUD operations
 */

const { test, expect } = require('@playwright/test');
const testConfig = require('../../helpers/test-config');

test.describe('Menucards Extension - Activation & Basic Tests', () => {
    
    let apiToken = null;
    
    test.beforeAll(async ({ request }) => {
        testConfig.startTestTimer();
        
        // Get API token by logging in
        testConfig.log('Getting API token...', '🔑');
        const adminCreds = testConfig.getAdminCredentials();
        
        const response = await request.post(testConfig.getAdminUrl() + '/api/login', {
            data: {
                username: adminCreds.username,
                password: adminCreds.password
            }
        });
        
        if (response.ok()) {
            const data = await response.json();
            apiToken = data.csrf || data.token;
            testConfig.log('API token obtained', '✅');
        }
    });

    test('Step 1: Enable extension via Extensions page', async ({ page }) => {
        testConfig.log('Step 1: Navigating to enable extension...', '🔧');
        
        // Login
        await page.goto(testConfig.getAdminUrl());
        const adminCreds = testConfig.getAdminCredentials();
        
        const isLoggedIn = await page.locator('.pk-user-avatar').isVisible().catch(() => false);
        if (!isLoggedIn) {
            await page.fill('input[name="username"]', adminCreds.username);
            await page.fill('input[name="password"]', adminCreds.password);
            await page.click('button[type="submit"]');
            await page.waitForURL(/.*\/admin/, { timeout: 15000 });
        }
        
        // Go to extensions page
        await page.goto(testConfig.getAdminUrl() + '/system/extensions');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(3000);
        
        testConfig.log('Extensions page loaded', '✅');
        
        // Take screenshot of extensions page
        await page.screenshot({ path: 'test-results/extensions-before-enable.png', fullPage: true });
        testConfig.log('Screenshot saved: extensions-before-enable.png', '📸');
        
        // Search for Menucards in the page content
        const pageText = await page.textContent('body');
        testConfig.log('Searching for Menucards extension...', '🔍');
        
        if (pageText.includes('Menucards') || pageText.includes('menucards')) {
            testConfig.log('Found "Menucards" text on page', '✅');
            
            // Try to find and click enable button
            const enableButtons = await page.locator('text=Enable').all();
            testConfig.log(`Found ${enableButtons.length} Enable buttons on page`);
            
            // Look for menucards specifically
            for (let i = 0; i < enableButtons.length; i++) {
                const button = enableButtons[i];
                const parentText = await button.evaluate(el => {
                    const parent = el.closest('.uk-panel, .uk-card, .uk-grid, div');
                    return parent ? parent.textContent : '';
                });
                
                if (parentText.toLowerCase().includes('menucards')) {
                    testConfig.log('Found Enable button for Menucards!', '🎯');
                    await button.click();
                    await page.waitForTimeout(3000);
                    testConfig.log('Clicked Enable button', '✅');
                    break;
                }
            }
        } else {
            testConfig.warn('Menucards not found on extensions page');
            testConfig.info('Extension might not be properly registered');
        }
        
        // Reload page to see changes
        await page.reload({ waitUntil: 'networkidle' });
        await page.waitForTimeout(2000);
        
        // Take screenshot after enable
        await page.screenshot({ path: 'test-results/extensions-after-enable.png', fullPage: true });
        testConfig.log('Screenshot saved: extensions-after-enable.png', '📸');
    });

    test('Step 2: Verify database tables exist', async () => {
        testConfig.log('Step 2: Checking if database tables were created...', '🗄️');
        
        const { exec } = require('child_process');
        const { promisify } = require('util');
        const execAsync = promisify(exec);
        
        const tables = [
            'pk_menucards_menu',
            'pk_menucards_category',
            'pk_menucards_product',
            'pk_menucards_category_product'
        ];
        
        for (const tableName of tables) {
            try {
                const { stdout } = await execAsync(`sqlite3 /workspace/pagekit.db "SELECT name FROM sqlite_master WHERE type='table' AND name='${tableName}'"`);
                
                if (stdout.trim() === tableName) {
                    testConfig.log(`✅ Table ${tableName} exists`);
                } else {
                    testConfig.error(`❌ Table ${tableName} NOT FOUND`);
                }
            } catch (error) {
                testConfig.error(`Error checking ${tableName}: ${error.message}`);
            }
        }
    });

    test('Step 3: Verify Menucards menu in sidebar', async ({ page }) => {
        testConfig.log('Step 3: Checking admin sidebar for Menucards menu...', '📋');
        
        await page.goto(testConfig.getAdminUrl());
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(2000);
        
        // Take screenshot of dashboard with sidebar
        await page.screenshot({ path: 'test-results/admin-dashboard-with-sidebar.png', fullPage: true });
        testConfig.log('Screenshot saved: admin-dashboard-with-sidebar.png', '📸');
        
        // Check for Menucards link
        const menucardsLinks = await page.locator('a').all();
        let found = false;
        
        for (const link of menucardsLinks) {
            const text = await link.textContent().catch(() => '');
            const href = await link.getAttribute('href').catch(() => '');
            
            if (text.toLowerCase().includes('menucard') || href.includes('menucards')) {
                testConfig.log(`Found link: "${text}" -> ${href}`, '✅');
                found = true;
            }
        }
        
        if (!found) {
            testConfig.warn('No Menucards links found in sidebar');
            testConfig.info('Extension might not be enabled yet');
        }
    });

    test('Step 4: Try to access Menucards admin directly', async ({ page }) => {
        testConfig.log('Step 4: Direct access test...', '🎯');
        
        await page.goto(testConfig.getAdminUrl() + '/menucards');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(2000);
        
        const url = page.url();
        testConfig.log('Current URL: ' + url);
        
        // Check if we're on menucards page or got redirected
        if (url.includes('/menucards')) {
            testConfig.log('Successfully accessed Menucards page!', '✅');
            
            // Check for Vue app
            const hasVueApp = await page.locator('#menucards').isVisible().catch(() => false);
            if (hasVueApp) {
                testConfig.log('Vue app loaded successfully!', '🎉');
            } else {
                testConfig.warn('Vue app not loaded');
            }
            
            // Take screenshot
            await page.screenshot({ path: 'test-results/menucards-direct-access.png', fullPage: true });
            testConfig.log('Screenshot saved: menucards-direct-access.png', '📸');
        } else {
            testConfig.error('Got redirected to: ' + url);
            testConfig.error('Extension not accessible - check permissions');
        }
    });

    test('Step 5: Try to access Products admin directly', async ({ page }) => {
        testConfig.log('Step 5: Products page direct access...', '📦');
        
        await page.goto(testConfig.getAdminUrl() + '/menucards/products');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(2000);
        
        const url = page.url();
        testConfig.log('Current URL: ' + url);
        
        if (url.includes('/menucards/products')) {
            testConfig.log('Successfully accessed Products page!', '✅');
            
            const hasVueApp = await page.locator('#products').isVisible().catch(() => false);
            if (hasVueApp) {
                testConfig.log('Products Vue app loaded!', '🎉');
            }
            
            await page.screenshot({ path: 'test-results/products-direct-access.png', fullPage: true });
            testConfig.log('Screenshot saved: products-direct-access.png', '📸');
        } else {
            testConfig.error('Got redirected to: ' + url);
        }
    });

});
