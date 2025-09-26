/**
 * Clean Installation Test for Pagekit
 * Tests the installation process on a fresh system
 */

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test.describe('Pagekit Clean Installation', () => {
  test.beforeAll(async () => {
    // Ensure Pagekit is NOT installed
    const configPath = path.join(process.cwd(), 'config.php');
    const dbPath = path.join(process.cwd(), 'pagekit.db');
    
    if (fs.existsSync(configPath)) {
      console.log('Warning: config.php exists - removing for clean installation test');
      fs.unlinkSync(configPath);
    }
    if (fs.existsSync(dbPath)) {
      console.log('Warning: pagekit.db exists - removing for clean installation test');
      fs.unlinkSync(dbPath);
    }
  });

  test('Complete installation flow', async ({ page }) => {
    // Set longer timeout for installation
    test.setTimeout(90000);
    
    console.log('Step 1: Navigate to installer');
    await page.goto('/', { waitUntil: 'networkidle' });
    
    // Should redirect to installer
    await expect(page).toHaveURL(/\/installer/, { timeout: 10000 });
    console.log('✓ Redirected to installer');
    
    // Wait for Vue.js installer app to load
    console.log('Step 2: Waiting for installer to initialize');
    await page.waitForTimeout(3000); // Give Vue time to mount
    
    // Check if we're on language selection or database step
    const languageVisible = await page.locator('select[name="locale"]').isVisible().catch(() => false);
    const databaseVisible = await page.locator('select[name="config[database.default]"]').isVisible().catch(() => false);
    
    if (languageVisible) {
      console.log('Step 3a: Language selection detected');
      // Select English
      await page.selectOption('select[name="locale"]', 'en_US');
      await page.click('button[type="submit"]');
      await page.waitForTimeout(2000);
    }
    
    // Now we should be on database configuration
    console.log('Step 3b: Database configuration');
    
    // Wait for database select to be visible
    await page.waitForSelector('select[name="config[database.default]"]', { 
      state: 'visible',
      timeout: 10000 
    });
    
    // Select SQLite
    await page.selectOption('select[name="config[database.default]"]', 'sqlite');
    console.log('✓ Selected SQLite database');
    
    // Click next/continue
    const nextButton = await page.locator('button[type="submit"]').first();
    await nextButton.click();
    await page.waitForTimeout(2000);
    
    console.log('Step 4: Site configuration');
    // Fill site title
    const titleInput = await page.locator('input[name="config[title]"]').first();
    if (await titleInput.isVisible()) {
      await titleInput.fill('Pagekit E2E Test Site');
      console.log('✓ Set site title');
    }
    
    // Click next
    await page.click('button[type="submit"]');
    await page.waitForTimeout(2000);
    
    console.log('Step 5: Admin user creation');
    // Fill admin user details
    await page.fill('input[name="user[username]"]', 'admin');
    await page.fill('input[name="user[email]"]', 'admin@test.local');
    await page.fill('input[name="user[password]"]', 'admin123');
    console.log('✓ Filled admin credentials');
    
    // Submit installation
    console.log('Step 6: Completing installation');
    await page.click('button[type="submit"]');
    
    // Wait for installation to complete (may take a while)
    console.log('Waiting for installation to complete...');
    await page.waitForURL(/\/(admin|login)/, { timeout: 60000 });
    
    console.log('✓ Installation completed!');
    
    // Verify we're on admin or login page
    const currentUrl = page.url();
    expect(currentUrl).toMatch(/\/(admin|login)/);
    
    // Verify config files were created
    const configExists = fs.existsSync('config.php');
    const dbExists = fs.existsSync('pagekit.db');
    
    expect(configExists).toBeTruthy();
    expect(dbExists).toBeTruthy();
    console.log('✓ Configuration files created');
  });

  test('Login with installed credentials', async ({ page }) => {
    // Try to login
    await page.goto('/admin/login');
    
    await page.fill('input[name="credentials[username]"]', 'admin');
    await page.fill('input[name="credentials[password]"]', 'admin123');
    await page.click('button[type="submit"]');
    
    // Should be logged in
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 10000 });
    
    const currentUrl = page.url();
    expect(currentUrl).toContain('/admin');
    expect(currentUrl).not.toContain('/login');
    console.log('✓ Admin login successful');
  });
});

test.describe('Post-Installation Verification', () => {
  test('Frontend loads correctly', async ({ page }) => {
    await page.goto('/');
    
    // Should NOT redirect to installer
    const currentUrl = page.url();
    expect(currentUrl).not.toContain('/installer');
    
    // Should see site content
    const title = await page.title();
    expect(title).toBeTruthy();
    console.log('✓ Frontend accessible');
  });

  test('Admin area requires authentication', async ({ page }) => {
    // Clear cookies to ensure logged out
    await page.context().clearCookies();
    
    // Try to access admin
    await page.goto('/admin');
    
    // Should redirect to login
    await expect(page).toHaveURL(/\/login/, { timeout: 5000 });
    console.log('✓ Admin area protected');
  });
});