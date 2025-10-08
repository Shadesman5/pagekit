/**
 * Menucards Complete Workflow Test
 * Tests the CRITICAL context-aware product creation feature
 */

const { test, expect } = require('@playwright/test');
const testConfig = require('../../helpers/test-config');
const { waitForVue, navigateAndWaitForVue, fillVueInput } = require('../../helpers/vue-helpers');

test.describe('Menucards Full Workflow', () => {
  
  test('Complete workflow: Create menu with context-aware product creation', async ({ page }) => {
    testConfig.log('═══════════════════════════════════════');
    testConfig.log('MENUCARDS COMPLETE WORKFLOW TEST');
    testConfig.log('═══════════════════════════════════════');
    
    // LOGIN
    testConfig.log('Step 1: Login...', '🔐');
    await navigateAndWaitForVue(page, testConfig.getAdminUrl() + '/login');
    const adminCreds = testConfig.getAdminCredentials();
    await fillVueInput(page, 'input[name="credentials[username]"]', adminCreds.username);
    await fillVueInput(page, 'input[name="credentials[password]"]', adminCreds.password);
    await Promise.all([
      page.waitForURL(/\/admin(?!\/login)/),
      page.click('.js-login button')
    ]);
    await waitForVue(page);
    testConfig.success('Logged in!');
    
    // GO TO MENUCARDS
    testConfig.log('Step 2: Navigate to Menucards...', '📋');
    await navigateAndWaitForVue(page, testConfig.getAdminUrl() + '/menucards/menu');
    await waitForVue(page);
    await page.waitForTimeout(1000);
    testConfig.success('On Menucards page!');
    
    // CREATE MENU
    testConfig.log('Step 3: Creating menu "Tageskarte"...', '➕');
    await page.click('button:has-text("Add Menu")');
    await page.waitForTimeout(500);
    
    // Fill menu form
    await page.locator('input[type="text"]').first().fill('Tageskarte');
    await page.locator('select').selectOption('1'); // Published
    
    await page.click('button:has-text("Save")');
    await page.waitForTimeout(2000);
    
    // Verify menu created
    await expect(page.locator('text=Tageskarte')).toBeVisible();
    testConfig.success('Menu "Tageskarte" created!');
    
    // ADD CATEGORY
    testConfig.log('Step 4: Adding category "Hauptgerichte"...', '📂');
    await page.click('button:has-text("Add Category")');
    await page.waitForTimeout(500);
    
    await page.locator('input[type="text"]').last().fill('Hauptgerichte');
    await page.click('button:has-text("Save")');
    await page.waitForTimeout(2000);
    
    await expect(page.locator('text=Hauptgerichte')).toBeVisible();
    testConfig.success('Category "Hauptgerichte" created!');
    
    // CRITICAL: CONTEXT-AWARE PRODUCT CREATION
    testConfig.log('Step 5: CRITICAL - Context-aware product creation...', '⭐');
    await page.click('button:has-text("Create Product Here")');
    await page.waitForTimeout(500);
    
    // Verify modal shows category context
    await expect(page.locator('text=Hauptgerichte')).toBeVisible();
    testConfig.success('Context-aware modal opened!');
    
    // Create product
    testConfig.log('Creating "Wiener Schnitzel"...', '🍖');
    await page.locator('input[type="text"]').last().fill('Wiener Schnitzel');
    await page.locator('input[type="number"]').last().fill('18.90');
    await page.locator('textarea').last().fill('Klassisches Wiener Schnitzel');
    
    await page.click('button:has-text("Create & Add")');
    await page.waitForTimeout(2000);
    
    // Verify product appears in category
    await expect(page.locator('text=Wiener Schnitzel')).toBeVisible();
    await expect(page.locator('text=€18.90')).toBeVisible();
    testConfig.success('Product created and attached to category!');
    
    // Verify in global product list
    testConfig.log('Step 6: Verify in global product list...', '📝');
    await navigateAndWaitForVue(page, testConfig.getAdminUrl() + '/menucards/product');
    await waitForVue(page);
    await page.waitForTimeout(1000);
    
    await expect(page.locator('text=Wiener Schnitzel')).toBeVisible();
    testConfig.success('Product found in global list!');
    
    // Verify on public view
    testConfig.log('Step 7: Verify on public menu...', '🌐');
    await page.goto(testConfig.getSiteUrl() + '/menucard/tageskarte');
    await page.waitForLoadState('networkidle');
    
    await expect(page.locator('h1:has-text("Tageskarte")')).toBeVisible();
    await expect(page.locator('text=Hauptgerichte')).toBeVisible();
    await expect(page.locator('text=Wiener Schnitzel')).toBeVisible();
    testConfig.success('Product visible on public menu!');
    
    testConfig.log('✅✅✅ COMPLETE WORKFLOW SUCCESS! ✅✅✅', '🎉');
  });
});
