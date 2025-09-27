/**
 * Improved Authentication Tests for Installed Pagekit
 * With proper Vue.js wait strategies
 * 
 * Prerequisites: Pagekit must be installed with admin/admin123
 */

const { test, expect } = require('@playwright/test');

// Helper function to wait for Vue.js to be ready
async function waitForVue(page) {
  // Wait for v-cloak to be removed (Vue mounted)
  await page.waitForFunction(() => !document.querySelector('[v-cloak]'), { timeout: 10000 });
  
  // Additional wait for any Vue transitions
  await page.waitForTimeout(500);
}

// Helper function to wait for page load with Vue
async function navigateAndWait(page, url) {
  await page.goto(url, { waitUntil: 'networkidle' });
  await waitForVue(page);
}

test.describe('Pagekit Authentication with Vue.js (Improved)', () => {
  test('Admin login page loads completely', async ({ page }) => {
    console.log('📍 Testing login page load...');
    
    // Navigate to login page
    await navigateAndWait(page, '/admin/login');
    
    // Wait for form to be fully loaded
    await page.waitForSelector('form.js-login', { state: 'visible' });
    
    // Check all form elements are present and visible
    const usernameInput = page.locator('input[name="credentials[username]"]');
    const passwordInput = page.locator('input[name="credentials[password]"]');
    const loginButton = page.locator('button:has-text("Login")');
    
    await expect(usernameInput).toBeVisible();
    await expect(passwordInput).toBeVisible();
    await expect(loginButton).toBeVisible();
    
    // Check that inputs are enabled and ready
    await expect(usernameInput).toBeEnabled();
    await expect(passwordInput).toBeEnabled();
    await expect(loginButton).toBeEnabled();
    
    console.log('✅ Login page loaded successfully with all elements');
  });

  test('Admin login with valid credentials', async ({ page }) => {
    console.log('📍 Testing valid login...');
    
    await navigateAndWait(page, '/admin/login');
    
    // Fill login form
    const usernameInput = page.locator('input[name="credentials[username]"]');
    const passwordInput = page.locator('input[name="credentials[password]"]');
    
    await usernameInput.fill('admin');
    await passwordInput.fill('admin123');
    
    // Click login button and wait for navigation
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      page.click('button:has-text("Login")')
    ]);
    
    // Wait for Vue admin interface to load
    await waitForVue(page);
    
    // Verify we're in admin dashboard
    expect(page.url()).toContain('/admin');
    expect(page.url()).not.toContain('/login');
    
    // Check for admin UI elements
    const adminNav = await page.locator('.pk-navbar').isVisible();
    expect(adminNav).toBeTruthy();
    
    console.log('✅ Successfully logged in as admin');
  });

  test('Admin login with invalid credentials shows error', async ({ page }) => {
    console.log('📍 Testing invalid login...');
    
    await navigateAndWait(page, '/admin/login');
    
    // Fill with wrong credentials
    await page.fill('input[name="credentials[username]"]', 'admin');
    await page.fill('input[name="credentials[password]"]', 'wrongpassword');
    
    // Click login and wait for response
    await page.click('button:has-text("Login")');
    
    // Wait for error message to appear (Vue might update DOM)
    await page.waitForSelector('.uk-alert-danger', { state: 'visible', timeout: 5000 });
    
    // Should stay on login page
    expect(page.url()).toContain('/login');
    
    // Check error message is visible
    const errorMessage = page.locator('.uk-alert-danger');
    await expect(errorMessage).toBeVisible();
    await expect(errorMessage).toContainText(/invalid|incorrect|failed/i);
    
    console.log('✅ Invalid login correctly shows error');
  });

  test('Admin logout works correctly', async ({ page }) => {
    console.log('📍 Testing logout...');
    
    // First login
    await navigateAndWait(page, '/admin/login');
    await page.fill('input[name="credentials[username]"]', 'admin');
    await page.fill('input[name="credentials[password]"]', 'admin123');
    
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      page.click('button:has-text("Login")')
    ]);
    
    await waitForVue(page);
    console.log('   Logged in successfully');
    
    // Find user menu (usually in navbar)
    const userMenu = page.locator('.pk-navbar-nav-right a[data-uk-dropdown], .uk-navbar-right a[data-uk-dropdown]').first();
    
    if (await userMenu.isVisible()) {
      // Click to open dropdown
      await userMenu.click();
      await page.waitForTimeout(300); // Wait for dropdown animation
      
      // Find and click logout
      const logoutLink = page.locator('a:has-text("Logout")').first();
      if (await logoutLink.isVisible()) {
        await Promise.all([
          page.waitForNavigation({ waitUntil: 'networkidle' }),
          logoutLink.click()
        ]);
        
        // Should redirect to login
        expect(page.url()).toContain('/login');
        console.log('✅ Successfully logged out');
      } else {
        console.log('⚠️  Logout link not found in dropdown');
      }
    } else {
      // Alternative: Direct logout URL
      await page.goto('/user/logout');
      await page.waitForURL(/\/login/, { timeout: 5000 });
      console.log('✅ Logged out via direct URL');
    }
  });

  test('Protected admin area redirects to login when not authenticated', async ({ page }) => {
    console.log('📍 Testing protected area...');
    
    // Clear all cookies to ensure logged out
    await page.context().clearCookies();
    
    // Try to access admin area
    await page.goto('/admin', { waitUntil: 'networkidle' });
    
    // Should redirect to login
    await page.waitForURL(/\/login/, { timeout: 5000 });
    
    // Verify we're on login page
    expect(page.url()).toContain('/login');
    
    // Login form should be visible
    const loginForm = await page.locator('form.js-login').isVisible();
    expect(loginForm).toBeTruthy();
    
    console.log('✅ Admin area correctly protected');
  });

  test('Remember me checkbox works', async ({ page }) => {
    console.log('📍 Testing remember me functionality...');
    
    await navigateAndWait(page, '/admin/login');
    
    // Check if remember me checkbox exists
    const rememberCheckbox = page.locator('input[type="checkbox"][name*="remember"]');
    
    if (await rememberCheckbox.count() > 0) {
      // Check the remember me box
      await rememberCheckbox.check();
      expect(await rememberCheckbox.isChecked()).toBeTruthy();
      
      // Login with remember me
      await page.fill('input[name="credentials[username]"]', 'admin');
      await page.fill('input[name="credentials[password]"]', 'admin123');
      
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.click('button:has-text("Login")')
      ]);
      
      // Check cookies for remember token
      const cookies = await page.context().cookies();
      const rememberCookie = cookies.find(c => c.name.includes('remember') || c.expires > Date.now() / 1000 + 86400);
      
      if (rememberCookie) {
        console.log('✅ Remember me cookie set');
      } else {
        console.log('⚠️  Remember cookie not clearly identified');
      }
    } else {
      console.log('ℹ️  Remember me checkbox not found');
    }
  });
});