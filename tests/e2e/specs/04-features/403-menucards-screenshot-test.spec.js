/**
 * Simple screenshot test to verify Menucards pages load
 */

const { test, expect } = require('@playwright/test');
const testConfig = require('../../helpers/test-config');
const fs = require('fs');

test.describe('Menucards - Screenshot Test', () => {
    
    test('Menucards pages screenshots', async ({ page }) => {
        // Login
        await page.goto(testConfig.getAdminUrl());
        const adminCreds = testConfig.getAdminCredentials();
        await page.fill('input[name="username"]', adminCreds.username);
        await page.fill('input[name="password"]', adminCreds.password);
        await page.click('button[type="submit"]');
        await page.waitForTimeout(3000);
        
        console.log('✅ Logged in');
        
        // Screenshot 1: Menucards page
        await page.goto(testConfig.getAdminUrl() + '/menucards');
        await page.waitForTimeout(3000);
        await page.screenshot({ path: 'menucards-page.png', fullPage: true });
        console.log('✅ Screenshot 1: menucards-page.png');
        
        // Get page content
        const content1 = await page.content();
        console.log('Page includes #menucards: ' + content1.includes('#menucards'));
        console.log('Page includes menucards.js: ' + content1.includes('menucards.js'));
        
        // Screenshot 2: Products page  
        await page.goto(testConfig.getAdminUrl() + '/menucards/products');
        await page.waitForTimeout(3000);
        await page.screenshot({ path: 'menucards-products.png', fullPage: true });
        console.log('✅ Screenshot 2: menucards-products.png');
        
        // Get page content
        const content2 = await page.content();
        console.log('Page includes #products: ' + content2.includes('#products'));
        console.log('Page includes products.js: ' + content2.includes('products.js'));
        
        // Get console errors
        const errors = [];
        page.on('console', msg => {
            if (msg.type() === 'error') {
                errors.push(msg.text());
            }
        });
        
        await page.reload();
        await page.waitForTimeout(2000);
        
        console.log('Console errors: ' + errors.length);
        errors.forEach(err => console.log('ERROR: ' + err));
    });

});
