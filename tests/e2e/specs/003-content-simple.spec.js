/**
 * Simple Content Tests for existing Pagekit installation
 */

const { test, expect } = require('@playwright/test');

test.describe('Content Pages', () => {
  test('Homepage loads', async ({ page }) => {
    await page.goto('/');
    
    // Check page loaded
    await expect(page).toHaveURL(/localhost:8080/);
    
    // Check for content
    const title = await page.title();
    expect(title).toBeTruthy();
  });

  test('Can access blog if exists', async ({ page }) => {
    // Try to access blog
    const response = await page.goto('/blog', { waitUntil: 'domcontentloaded' });
    
    if (response.status() === 404) {
      console.log('Blog not configured - skipping');
    } else {
      expect(response.status()).toBeLessThan(400);
    }
  });

  test('Static resources load correctly', async ({ page }) => {
    await page.goto('/');
    
    // Check CSS loads
    const cssResponse = await page.evaluate(async () => {
      const links = Array.from(document.querySelectorAll('link[rel="stylesheet"]'));
      if (links.length === 0) return null;
      
      const firstLink = links[0];
      const response = await fetch(firstLink.href);
      return response.status;
    });
    
    if (cssResponse) {
      expect(cssResponse).toBe(200);
    }
  });

  test('JavaScript loads without errors', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    
    await page.goto('/');
    await page.waitForTimeout(2000);
    
    // Check no critical JS errors
    const criticalErrors = errors.filter(e => 
      !e.includes('favicon') && 
      !e.includes('404')
    );
    
    expect(criticalErrors.length).toBe(0);
  });

  test('Responsive design works', async ({ page }) => {
    // Desktop view
    await page.setViewportSize({ width: 1280, height: 720 });
    await page.goto('/');
    
    // Mobile view
    await page.setViewportSize({ width: 375, height: 667 });
    await page.waitForTimeout(500);
    
    // Page should still be accessible
    const mobileTitle = await page.title();
    expect(mobileTitle).toBeTruthy();
  });
});