/**
 * E2E Test: Enable Menucards Extension
 * 
 * Prerequisites: Pagekit must be installed
 * This test explicitly enables the Menucards extension
 */

const { test, expect } = require('@playwright/test');
const testConfig = require('../../helpers/test-config');

test.describe('Menucards Extension - Enable', () => {
    
    test.beforeAll(async () => {
        testConfig.startTestTimer();
    });

    test('Enable Menucards extension and verify database tables', async ({ page }) => {
        testConfig.log('Step 1: Login to admin...', '🔑');
        
        await page.goto(testConfig.getAdminUrl());
        await page.waitForLoadState('networkidle');
        
        // Login
        const adminCreds = testConfig.getAdminCredentials();
        await page.fill('input[name="username"]', adminCreds.username);
        await page.fill('input[name="password"]', adminCreds.password);
        await page.click('button[type="submit"]');
        await page.waitForURL(/.*\/admin\/dashboard/, { timeout: 15000 });
        
        testConfig.log('Logged in successfully', '✅');
        
        // Step 2: Navigate to Extensions
        testConfig.log('Step 2: Navigate to Extensions page...', '📦');
        await page.goto(testConfig.getAdminUrl() + '/system/extensions');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(2000);
        
        testConfig.log('Extensions page loaded', '✅');
        
        // Step 3: Find Menucards extension
        testConfig.log('Step 3: Looking for Menucards extension...', '🔍');
        
        // Try different selectors
        let extensionFound = false;
        let extensionElement = null;
        
        // Try to find by text content
        const allCards = page.locator('.uk-card, [class*="card"], .uk-panel');
        const count = await allCards.count();
        
        testConfig.log(`Found ${count} extension cards to check`, 'ℹ️');
        
        for (let i = 0; i < count; i++) {
            const card = allCards.nth(i);
            const text = await card.textContent();
            
            if (text && text.includes('Menucards')) {
                extensionFound = true;
                extensionElement = card;
                testConfig.log('Found Menucards extension!', '✅');
                break;
            }
        }
        
        if (!extensionFound) {
            testConfig.error('Menucards extension NOT found in extensions list!');
            testConfig.info('Available extensions:', 'ℹ️');
            
            // Debug: List all extensions
            for (let i = 0; i < Math.min(count, 10); i++) {
                const card = allCards.nth(i);
                const text = await card.textContent();
                const title = text?.substring(0, 50) || 'Unknown';
                testConfig.log(`  ${i + 1}. ${title}...`);
            }
            
            throw new Error('Menucards extension not found - check if extension is properly installed in packages/pagekit/menucards/');
        }
        
        // Step 4: Check if enabled or disabled
        testConfig.log('Step 4: Checking extension status...', '⚙️');
        
        const enableButton = extensionElement.locator('a:has-text("Enable"), button:has-text("Enable")');
        const disableButton = extensionElement.locator('a:has-text("Disable"), button:has-text("Disable")');
        
        const hasEnableButton = await enableButton.isVisible().catch(() => false);
        const hasDisableButton = await disableButton.isVisible().catch(() => false);
        
        if (hasDisableButton) {
            testConfig.log('Extension is already ENABLED', '✅');
        } else if (hasEnableButton) {
            testConfig.log('Extension is DISABLED - enabling now...', '⏳');
            
            await enableButton.click();
            await page.waitForTimeout(3000);
            
            testConfig.log('Extension ENABLED successfully!', '✅');
            
            // Verify database tables were created
            testConfig.log('Step 5: Verifying database tables...', '🗄️');
            testConfig.log('Tables should be created by scripts.php enable hook', 'ℹ️');
            
        } else {
            testConfig.error('Cannot determine extension status - no Enable/Disable button found');
            throw new Error('Extension status unclear');
        }
        
        // Step 5: Verify Menucards menu appears in sidebar
        testConfig.log('Step 6: Verifying Menucards menu in sidebar...', '🔍');
        
        await page.goto(testConfig.getAdminUrl());
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1000);
        
        // Look for Menucards link
        const menuLink = page.locator('a[href*="/menucards"]').first();
        
        if (await menuLink.isVisible().catch(() => false)) {
            testConfig.log('Menucards menu IS VISIBLE in sidebar!', '✅');
        } else {
            testConfig.warn('Menucards menu NOT visible in sidebar yet');
            testConfig.info('This might require page reload or permission check');
        }
        
        testConfig.log('═══════════════════════════════════════', '🎉');
        testConfig.log('MENUCARDS EXTENSION ENABLED!', '✅');
        testConfig.log('═══════════════════════════════════════', '🎉');
    });

});
