/**
 * Authentication Tests for Installed Pagekit
 * Prerequisites: Pagekit must be installed
 */

const { test, expect } = require('@playwright/test');

test.describe('Pagekit Authentication (Installed)', () => {
  test('Admin login page loads', async ({ page }) => {
    await page.goto('/admin/login');
    
    // Wait for page to load
    await page.waitForTimeout(1000);
    
    // Check login form exists
    await expect(page.locator('input[name="credentials[username]"]')).toBeVisible();
    await expect(page.locator('input[name="credentials[password]"]')).toBeVisible();
    const button = page.locator('button:has-text("Login")').first();
    await expect(button).toBeVisible();
    
    console.log('✓ Login page loaded successfully');
  });

  test('Admin login with valid credentials', async ({ page }) => {
    await page.goto('/admin/login');
    
    // Fill login form
    await page.fill('input[name="credentials[username]"]', 'admin');
    await page.fill('input[name="credentials[password]"]', 'admin123');
    
    // Submit
    await page.click('button.uk-button');
    
    // Wait for navigation
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 10000 });
    
    // Verify we're in admin
    expect(page.url()).toContain('/admin');
    expect(page.url()).not.toContain('/login');
    
    console.log('✓ Successfully logged in as admin');
  });

  test('Admin login with invalid credentials', async ({ page }) => {
    await page.goto('/admin/login');
    
    // Fill with wrong credentials
    await page.fill('input[name="credentials[username]"]', 'admin');
    await page.fill('input[name="credentials[password]"]', 'wrongpassword');
    
    // Submit
    await page.click('button.uk-button');
    
    // Should stay on login page
    await page.waitForTimeout(2000);
    expect(page.url()).toContain('/login');
    
    // Check for error message
    const errorMessage = await page.locator('.uk-alert-danger').isVisible().catch(() => false);
    expect(errorMessage).toBeTruthy();
    
    console.log('✓ Invalid login correctly rejected');
  });

  test('Admin logout', async ({ page }) => {
    // First login
    await page.goto('/admin/login');
    await page.fill('input[name="credentials[username]"]', 'admin');
    await page.fill('input[name="credentials[password]"]', 'admin123');
    await page.click('button.uk-button');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 10000 });
    
    // Find and click logout
    const userMenu = await page.locator('.pk-navbar-nav-right a[data-uk-dropdown]').first();
    if (await userMenu.isVisible()) {
      await userMenu.click();
      await page.waitForTimeout(500);
      
      const logoutLink = await page.locator('a:has-text("Logout")').first();
      if (await logoutLink.isVisible()) {
        await logoutLink.click();
        
        // Should redirect to login
        await page.waitForURL(/\/login/, { timeout: 5000 });
        console.log('✓ Successfully logged out');
      }
    }
  });

  test('Protected admin area redirects to login', async ({ page }) => {
    // Clear cookies to ensure logged out
    await page.context().clearCookies();
    
    // Try to access admin
    await page.goto('/admin');
    
    // Should redirect to login
    await expect(page).toHaveURL(/\/login/, { timeout: 5000 });
    
    console.log('✓ Admin area correctly protected');
  });
});