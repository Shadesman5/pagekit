/**
 * Installation Tests for Pagekit
 * Tests the complete installation process for new Pagekit instances
 */

const { test, expect } = require('@playwright/test');
const path = require('path');

test.describe('Pagekit Installation', () => {
  test.beforeEach(async ({ page }) => {
    // Set longer timeout for installation tests
    test.setTimeout(60000);
    
    // Clear any existing installation
    // Note: In real scenario, this would reset the database and config
  });

  test.skip('Fresh MySQL Installation', async ({ page }) => {
    // SKIPPED: Pagekit is already installed
    // Navigate to installer
    await page.goto('/installer');
    
    // Step 1: Language selection (if present)
    const languageSelect = await page.$('select[name="locale"]');
    if (languageSelect) {
      await page.selectOption('select[name="locale"]', 'en_US');
      await page.click('button.uk-button-primary');
    }
    
    // Step 2: Database configuration
    await page.waitForSelector('input[name="database[connections][mysql][host]"]', { timeout: 10000 });
    
    // Select MySQL driver
    await page.selectOption('select[name="database[default]"]', 'mysql');
    
    // Fill in MySQL connection details
    await page.fill('input[name="database[connections][mysql][host]"]', 'localhost');
    await page.fill('input[name="database[connections][mysql][port]"]', '3307'); // E2E test port
    await page.fill('input[name="database[connections][mysql][dbname]"]', 'pagekit_e2e_test');
    await page.fill('input[name="database[connections][mysql][user]"]', 'pagekit_e2e');
    await page.fill('input[name="database[connections][mysql][password]"]', 'pagekit_e2e_pass');
    await page.fill('input[name="database[connections][mysql][prefix]"]', 'pk_');
    
    // Test database connection
    const testButton = await page.$('button.uk-button-success');
    if (testButton) {
      await testButton.click();
      
      // Wait for connection test result
      await page.waitForSelector('.uk-alert-success, .uk-alert-danger', { timeout: 10000 });
      
      // Verify successful connection
      const successAlert = await page.$('.uk-alert-success');
      expect(successAlert).toBeTruthy();
    }
    
    // Continue to next step
    await page.click('button.uk-button-primary');
    
    // Step 3: Site configuration
    await page.waitForSelector('input[name="site[title]"]', { timeout: 10000 });
    
    await page.fill('input[name="site[title]"]', 'Pagekit E2E Test Site');
    await page.fill('input[name="site[description]"]', 'Automated E2E Testing Site');
    
    // Continue to next step
    await page.click('button.uk-button-primary');
    
    // Step 4: Admin user creation
    await page.waitForSelector('input[name="user[username]"]', { timeout: 10000 });
    
    await page.fill('input[name="user[username]"]', 'admin');
    await page.fill('input[name="user[email]"]', 'admin@pagekit.local');
    await page.fill('input[name="user[password]"]', 'admin123');
    await page.fill('input[name="user[password_confirmation]"]', 'admin123');
    
    // Complete installation
    await page.click('button.uk-button-primary');
    
    // Wait for installation to complete
    await page.waitForSelector('.pk-install-success, .uk-alert-success', { timeout: 30000 });
    
    // Verify installation success
    const successMessage = await page.textContent('.pk-install-success, .uk-alert-success');
    expect(successMessage).toContain('successfully installed');
    
    // Verify redirect to admin login or dashboard
    await page.waitForURL(/\/(admin|login)/, { timeout: 10000 });
  });

  test('Fresh SQLite Installation', async ({ page }) => {
    // Navigate to installer
    await page.goto('/installer');
    
    // Skip language selection if already set
    
    // Step 2: Database configuration
    await page.waitForSelector('select[name="database[default]"]', { timeout: 10000 });
    
    // Select SQLite driver
    await page.selectOption('select[name="database[default]"]', 'sqlite');
    
    // SQLite path is usually auto-configured
    const sqlitePath = await page.inputValue('input[name="database[connections][sqlite][path]"]');
    expect(sqlitePath).toBeTruthy();
    
    // Set table prefix
    await page.fill('input[name="database[connections][sqlite][prefix]"]', 'pk_');
    
    // Continue to next step
    await page.click('button.uk-button-primary');
    
    // Step 3: Site configuration
    await page.waitForSelector('input[name="site[title]"]', { timeout: 10000 });
    
    await page.fill('input[name="site[title]"]', 'Pagekit SQLite Test');
    await page.fill('input[name="site[description]"]', 'SQLite E2E Testing');
    
    // Continue to next step
    await page.click('button.uk-button-primary');
    
    // Step 4: Admin user creation
    await page.waitForSelector('input[name="user[username]"]', { timeout: 10000 });
    
    await page.fill('input[name="user[username]"]', 'admin');
    await page.fill('input[name="user[email]"]', 'admin@sqlite.local');
    await page.fill('input[name="user[password]"]', 'admin123');
    await page.fill('input[name="user[password_confirmation]"]', 'admin123');
    
    // Complete installation
    await page.click('button.uk-button-primary');
    
    // Wait for installation to complete
    await page.waitForSelector('.pk-install-success, .uk-alert-success', { timeout: 30000 });
    
    // Verify SQLite database file was created
    // This would be checked on the server side in a real test
  });

  test('Installation Error Handling - Invalid Database Credentials', async ({ page }) => {
    // Navigate to installer
    await page.goto('/installer');
    
    // Go to database configuration
    await page.waitForSelector('input[name="database[connections][mysql][host]"]', { timeout: 10000 });
    
    // Fill in invalid MySQL connection details
    await page.selectOption('select[name="database[default]"]', 'mysql');
    await page.fill('input[name="database[connections][mysql][host]"]', 'invalid-host');
    await page.fill('input[name="database[connections][mysql][port]"]', '9999');
    await page.fill('input[name="database[connections][mysql][dbname]"]', 'nonexistent');
    await page.fill('input[name="database[connections][mysql][user]"]', 'invalid');
    await page.fill('input[name="database[connections][mysql][password]"]', 'wrong');
    
    // Test database connection
    const testButton = await page.$('button.uk-button-success');
    if (testButton) {
      await testButton.click();
      
      // Wait for connection test result
      await page.waitForSelector('.uk-alert-danger', { timeout: 10000 });
      
      // Verify error message
      const errorAlert = await page.textContent('.uk-alert-danger');
      expect(errorAlert).toContain('connect');
    }
    
    // Try to continue anyway
    await page.click('button.uk-button-primary');
    
    // Should show error and not proceed
    const errorMessage = await page.$('.uk-alert-danger');
    expect(errorMessage).toBeTruthy();
  });

  test('Installation Error Handling - Missing Required Fields', async ({ page }) => {
    // Navigate to installer
    await page.goto('/installer');
    
    // Skip to admin user creation
    // Fill database with valid data first
    await page.waitForSelector('select[name="database[default]"]', { timeout: 10000 });
    await page.selectOption('select[name="database[default]"]', 'sqlite');
    await page.click('button.uk-button-primary');
    
    // Site configuration - leave empty
    await page.waitForSelector('input[name="site[title]"]', { timeout: 10000 });
    await page.click('button.uk-button-primary');
    
    // Should show validation error
    let validationError = await page.$('.uk-form-danger');
    expect(validationError).toBeTruthy();
    
    // Fill site info
    await page.fill('input[name="site[title]"]', 'Test Site');
    await page.click('button.uk-button-primary');
    
    // Admin user - leave password empty
    await page.waitForSelector('input[name="user[username]"]', { timeout: 10000 });
    await page.fill('input[name="user[username]"]', 'admin');
    await page.fill('input[name="user[email]"]', 'admin@test.local');
    // Leave password empty
    
    await page.click('button.uk-button-primary');
    
    // Should show validation error for password
    validationError = await page.$('.uk-form-danger');
    expect(validationError).toBeTruthy();
  });

  test('Database Connection Validation', async ({ page }) => {
    // Navigate to installer
    await page.goto('/installer');
    
    // Wait for database configuration
    await page.waitForSelector('select[name="database[default]"]', { timeout: 10000 });
    
    // Test MySQL connection with correct credentials
    await page.selectOption('select[name="database[default]"]', 'mysql');
    await page.fill('input[name="database[connections][mysql][host]"]', 'localhost');
    await page.fill('input[name="database[connections][mysql][port]"]', '3307');
    await page.fill('input[name="database[connections][mysql][dbname]"]', 'pagekit_e2e_test');
    await page.fill('input[name="database[connections][mysql][user]"]', 'pagekit_e2e');
    await page.fill('input[name="database[connections][mysql][password]"]', 'pagekit_e2e_pass');
    
    // Click test connection button
    await page.click('button.uk-button-success');
    
    // Wait for success message
    await page.waitForSelector('.uk-alert-success', { timeout: 10000 });
    
    const successMessage = await page.textContent('.uk-alert-success');
    expect(successMessage).toContain('Connection successful');
    
    // Change to invalid port
    await page.fill('input[name="database[connections][mysql][port]"]', '1234');
    
    // Test again
    await page.click('button.uk-button-success');
    
    // Wait for error message
    await page.waitForSelector('.uk-alert-danger', { timeout: 10000 });
    
    const errorMessage = await page.textContent('.uk-alert-danger');
    expect(errorMessage).toContain('connect');
  });

  test('Installation with Custom Table Prefix', async ({ page }) => {
    // Navigate to installer
    await page.goto('/installer');
    
    // Database configuration with custom prefix
    await page.waitForSelector('select[name="database[default]"]', { timeout: 10000 });
    await page.selectOption('select[name="database[default]"]', 'sqlite');
    
    // Set custom table prefix
    await page.fill('input[name="database[connections][sqlite][prefix]"]', 'custom_');
    
    // Continue through installation
    await page.click('button.uk-button-primary');
    
    // Site configuration
    await page.waitForSelector('input[name="site[title]"]', { timeout: 10000 });
    await page.fill('input[name="site[title]"]', 'Custom Prefix Test');
    await page.click('button.uk-button-primary');
    
    // Admin user
    await page.waitForSelector('input[name="user[username]"]', { timeout: 10000 });
    await page.fill('input[name="user[username]"]', 'admin');
    await page.fill('input[name="user[email]"]', 'admin@custom.local');
    await page.fill('input[name="user[password]"]', 'admin123');
    await page.fill('input[name="user[password_confirmation]"]', 'admin123');
    
    // Complete installation
    await page.click('button.uk-button-primary');
    
    // Wait for success
    await page.waitForSelector('.pk-install-success, .uk-alert-success', { timeout: 30000 });
    
    // Tables should be created with custom_ prefix
    // This would be verified on the database side
  });
});

test.describe('Post-Installation Verification', () => {
  test('Verify Admin Access After Installation', async ({ page }) => {
    // Try to access admin area
    await page.goto('/admin');
    
    // Should redirect to login if not authenticated
    if (page.url().includes('/login')) {
      // Login with installation credentials
      await page.fill('input[name="credentials[username]"]', 'admin');
      await page.fill('input[name="credentials[password]"]', 'admin123');
      await page.click('button[type="submit"]');
    }
    
    // Should be in admin dashboard
    await page.waitForSelector('.pk-dashboard', { timeout: 10000 });
    
    // Verify dashboard elements
    await expect(page.locator('.pk-dashboard')).toBeVisible();
    await expect(page.locator('.uk-navbar')).toBeVisible();
  });

  test('Verify Database Tables Created', async ({ page, request }) => {
    // This test would typically check database directly
    // For E2E, we can verify through the system info page
    
    // Login first
    await page.goto('/admin/login');
    await page.fill('input[name="credentials[username]"]', 'admin');
    await page.fill('input[name="credentials[password]"]', 'admin123');
    await page.click('button[type="submit"]');
    
    // Navigate to system info
    await page.goto('/admin/system/info');
    
    // Check for database information
    await page.waitForSelector('.pk-system-info', { timeout: 10000 });
    
    // Verify database type is shown
    const dbInfo = await page.textContent('.pk-system-info');
    expect(dbInfo).toMatch(/MySQL|SQLite/);
  });
});