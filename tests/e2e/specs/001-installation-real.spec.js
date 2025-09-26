/**
 * Real Installation Tests for Pagekit
 * Tests the actual installation process
 */

const { test, expect } = require('@playwright/test');
const fs = require('fs');

test.describe('Pagekit Real Installation', () => {
  test.beforeAll(async () => {
    // Ensure Pagekit is NOT installed
    if (fs.existsSync('config.php')) {
      console.log('Removing existing installation...');
      try {
        fs.unlinkSync('config.php');
      } catch (e) {}
    }
    if (fs.existsSync('pagekit.db')) {
      try {
        fs.unlinkSync('pagekit.db');
      } catch (e) {}
    }
  });

  test('Installation with SQLite', async ({ page }) => {
    // Set longer timeout for installation
    test.setTimeout(60000);
    
    // Navigate to Pagekit root - should redirect to installer
    await page.goto('/');
    
    // Should be redirected to installer
    await expect(page).toHaveURL(/\/installer/);
    
    // Wait for installer to load
    await page.waitForSelector('form', { timeout: 10000 });
    
    // Step 1: Database configuration
    // Select SQLite as database
    const dbSelect = await page.$('select[name="config[database.default]"]');
    if (dbSelect) {
      await dbSelect.selectOption('sqlite');
    }
    
    // Step 2: Site configuration
    await page.fill('input[name="config[title]"]', 'Pagekit E2E Test Site');
    
    // Step 3: Admin user creation
    await page.fill('input[name="user[username]"]', 'admin');
    await page.fill('input[name="user[email]"]', 'admin@test.local');
    await page.fill('input[name="user[password]"]', 'admin123');
    
    // Submit installation
    await page.click('button[type="submit"]');
    
    // Wait for installation to complete
    await page.waitForURL(/\/(admin|login)/, { timeout: 30000 });
    
    // Verify installation was successful
    const url = page.url();
    expect(url).toMatch(/\/(admin|login)/);
    
    // Verify config.php was created
    expect(fs.existsSync('config.php')).toBeTruthy();
    
    // Verify database was created
    expect(fs.existsSync('pagekit.db')).toBeTruthy();
  });

  test('Admin can login after installation', async ({ page }) => {
    // Try to login with the credentials we just created
    await page.goto('/admin/login');
    
    await page.fill('input[name="credentials[username]"]', 'admin');
    await page.fill('input[name="credentials[password]"]', 'admin123');
    
    await page.click('button[type="submit"]');
    
    // Should be logged in
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 10000 });
    
    // Verify we're in admin dashboard
    expect(page.url()).toContain('/admin');
    expect(page.url()).not.toContain('/login');
  });
});

test.describe('Post-Installation Tests', () => {
  test('Frontend is accessible', async ({ page }) => {
    await page.goto('/');
    
    // Should see the site, not installer
    expect(page.url()).not.toContain('/installer');
    
    // Check for site title
    const title = await page.title();
    expect(title).toContain('Pagekit');
  });

  test('Admin area is protected', async ({ page }) => {
    // Logout first if logged in
    await page.context().clearCookies();
    
    // Try to access admin without login
    await page.goto('/admin');
    
    // Should redirect to login
    await expect(page).toHaveURL(/\/login/);
  });
});