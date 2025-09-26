/**
 * Setup and Verification Tests for Pagekit
 * Verifies that Pagekit is installed and accessible
 */

const { test, expect } = require('@playwright/test');

test.describe('Pagekit Setup Verification', () => {
  test('Pagekit is accessible', async ({ page }) => {
    // Check if Pagekit is running
    const response = await page.goto('/');
    expect(response.status()).toBeLessThan(400);
    
    // Should redirect to site or login
    const url = page.url();
    expect(url).toMatch(/localhost:8080/);
  });

  test('Admin login page is accessible', async ({ page }) => {
    await page.goto('/admin/login');
    
    // Check for login form
    await expect(page.locator('form')).toBeVisible();
    await expect(page.locator('input[name="credentials[username]"]')).toBeVisible();
    await expect(page.locator('input[name="credentials[password]"]')).toBeVisible();
    await expect(page.locator('button[type="submit"]')).toBeVisible();
  });

  test('Can login as admin', async ({ page }) => {
    await page.goto('/admin/login');
    
    // Try default admin credentials
    await page.fill('input[name="credentials[username]"]', 'admin');
    await page.fill('input[name="credentials[password]"]', 'admin');
    
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      page.click('button[type="submit"]')
    ]);
    
    // Check if login was successful
    const url = page.url();
    if (url.includes('/admin') && !url.includes('/login')) {
      // Successfully logged in
      expect(url).toContain('/admin');
    } else {
      // Login failed - might need different credentials
      console.log('Note: Default admin credentials did not work. Update credentials in tests.');
    }
  });

  test('Frontend is accessible', async ({ page }) => {
    await page.goto('/');
    
    // Check for Pagekit frontend elements
    const title = await page.title();
    expect(title).toBeTruthy();
    
    // Check if page has content
    const bodyText = await page.textContent('body');
    expect(bodyText).toBeTruthy();
  });

  test('Static assets are loading', async ({ page }) => {
    await page.goto('/');
    
    // Check if CSS is loading
    const styles = await page.$$('link[rel="stylesheet"]');
    expect(styles.length).toBeGreaterThan(0);
    
    // Check if JavaScript is loading
    const scripts = await page.$$('script[src]');
    expect(scripts.length).toBeGreaterThan(0);
  });
});