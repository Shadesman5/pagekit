/**
 * Complete Installation Test for Pagekit
 * Tests the actual multi-step installation process
 * 
 * Prerequisites: Pagekit must NOT be installed (no config.php or pagekit.db)
 */

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test.describe('Pagekit Complete Installation Process', () => {
  test.beforeAll(async () => {
    // Ensure Pagekit is NOT installed
    const configPath = path.join(process.cwd(), 'config.php');
    const dbPath = path.join(process.cwd(), 'pagekit.db');
    
    if (fs.existsSync(configPath)) {
      console.log('⚠️  config.php exists - removing for clean installation test');
      fs.unlinkSync(configPath);
    }
    if (fs.existsSync(dbPath)) {
      console.log('⚠️  pagekit.db exists - removing for clean installation test');
      fs.unlinkSync(dbPath);
    }
  });

  test('Complete 5-step installation flow', async ({ page }) => {
    // Set longer timeout for installation (90 seconds)
    test.setTimeout(90000);
    
    console.log('🚀 Starting Pagekit installation test...');
    
    // Navigate to Pagekit
    await page.goto('/', { waitUntil: 'networkidle' });
    
    // Should redirect to installer
    await expect(page).toHaveURL(/\/installer/, { timeout: 10000 });
    console.log('✅ Step 0: Redirected to installer');
    
    // Wait for Vue.js to initialize (check for v-cloak removal)
    await page.waitForFunction(() => !document.querySelector('[v-cloak]'), { timeout: 10000 });
    console.log('✅ Vue.js initialized');
    
    // ========================================
    // Step 1: Welcome screen with Pagekit logo
    // ========================================
    console.log('📍 Step 1: Welcome screen');
    
    // Wait for the logo to be clickable
    await page.waitForSelector('#next', { state: 'visible', timeout: 10000 });
    
    // Click on the logo/next button
    await page.click('#next');
    await page.waitForTimeout(1000); // Wait for animation
    
    console.log('✅ Step 1: Clicked welcome screen');
    
    // ========================================
    // Step 2: Language selection
    // ========================================
    console.log('📍 Step 2: Language selection');
    
    // Wait for language selector
    await page.waitForSelector('#selectbox', { state: 'visible', timeout: 10000 });
    
    // Select English (should be selected by default)
    const selectedLang = await page.$eval('#selectbox', el => el.value);
    if (selectedLang !== 'en_US') {
      await page.selectOption('#selectbox', 'en_US');
    }
    
    // Click Next button
    await page.click('#next');
    await page.waitForTimeout(1000);
    
    console.log('✅ Step 2: Language selected (en_US)');
    
    // ========================================
    // Step 3: Database configuration
    // ========================================
    console.log('📍 Step 3: Database configuration');
    
    // Wait for database driver selector
    await page.waitForSelector('#form-dbdriver', { state: 'visible', timeout: 10000 });
    
    // Check if SQLite is selected (should be default)
    const dbDriver = await page.$eval('#form-dbdriver', el => el.value);
    console.log(`   Database driver: ${dbDriver}`);
    
    if (dbDriver !== 'sqlite') {
      await page.selectOption('#form-dbdriver', 'sqlite');
      console.log('   Selected SQLite');
    }
    
    // For SQLite, we might need to set table prefix
    const prefixInput = await page.$('#form-sqlite-dbprefix');
    if (prefixInput) {
      const currentPrefix = await prefixInput.inputValue();
      if (!currentPrefix) {
        await prefixInput.fill('pk_');
      }
    }
    
    // Click Next button
    await page.click('#next');
    await page.waitForTimeout(1000);
    
    console.log('✅ Step 3: Database configured (SQLite)');
    
    // ========================================
    // Step 4: Site and Admin user configuration
    // ========================================
    console.log('📍 Step 4: Site and Admin configuration');
    
    // Wait for form fields to be visible
    await page.waitForSelector('input[name="config[title]"]', { state: 'visible', timeout: 10000 });
    
    // Fill site title
    await page.fill('input[name="config[title]"]', 'Pagekit E2E Test Site');
    console.log('   Site title set');
    
    // Fill admin user details
    await page.fill('input[name="user[username]"]', 'admin');
    await page.fill('input[name="user[password]"]', 'admin123');
    await page.fill('input[name="user[email]"]', 'admin@e2e-test.local');
    console.log('   Admin user configured');
    
    // Optional: Check demo content option
    const optionsButton = await page.$('#options');
    if (optionsButton) {
      // Click options to open modal
      await optionsButton.click();
      await page.waitForTimeout(500);
      
      // Check if demo content checkbox exists and check it
      const demoCheckbox = await page.$('input[type="checkbox"][name*="demo"]');
      if (demoCheckbox) {
        const isChecked = await demoCheckbox.isChecked();
        if (!isChecked) {
          await demoCheckbox.check();
          console.log('   Demo content enabled');
        }
      }
      
      // Close modal (click outside or close button)
      const closeButton = await page.$('button:has-text("Close")');
      if (closeButton) {
        await closeButton.click();
      } else {
        // Click outside modal
        await page.click('body', { position: { x: 10, y: 10 } });
      }
      await page.waitForTimeout(500);
    }
    
    // Click Install button (which is the next button in this step)
    console.log('   Clicking Install button...');
    await page.click('#next');
    
    // ========================================
    // Step 5: Installation process
    // ========================================
    console.log('📍 Step 5: Installing Pagekit...');
    console.log('   This may take a few seconds...');
    
    // Wait for redirect to login page (installation complete)
    await page.waitForURL(/\/(admin\/login|user\/login)/, { timeout: 30000 });
    
    console.log('✅ Step 5: Installation completed!');
    console.log('✅ Redirected to login page');
    
    // ========================================
    // Verification
    // ========================================
    console.log('📍 Verifying installation...');
    
    // Check that config.php was created
    const configExists = fs.existsSync('config.php');
    expect(configExists).toBeTruthy();
    console.log('✅ config.php created');
    
    // Check that database was created
    const dbExists = fs.existsSync('pagekit.db');
    expect(dbExists).toBeTruthy();
    console.log('✅ pagekit.db created');
    
    // Try to login with the created admin account
    console.log('📍 Testing admin login...');
    
    // We should already be on login page, but make sure
    if (!page.url().includes('login')) {
      await page.goto('/admin/login');
    }
    
    // Wait for login form to be ready
    await page.waitForSelector('input[name="credentials[username]"]', { state: 'visible' });
    
    // Fill login credentials
    await page.fill('input[name="credentials[username]"]', 'admin');
    await page.fill('input[name="credentials[password]"]', 'admin123');
    
    // Submit login form
    await page.click('button:has-text("Login")');
    
    // Wait for redirect to admin dashboard
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 10000 });
    
    console.log('✅ Admin login successful!');
    console.log('✅ Installation test completed successfully!');
    
    // Final check: we should be in admin area
    expect(page.url()).toContain('/admin');
    expect(page.url()).not.toContain('/login');
  });
});