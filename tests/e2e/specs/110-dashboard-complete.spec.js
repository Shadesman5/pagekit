/**
 * Dashboard Tests for Pagekit
 * Tests dashboard widgets, layout, and functionality
 */

const { test, expect } = require('@playwright/test');
const { navigateAndWaitForVue, waitForVue } = require('../helpers/vue-helpers');

test.describe('Pagekit Dashboard', () => {
  test.beforeEach(async ({ page }) => {
    // Login as admin
    await navigateAndWaitForVue(page, '/admin/login');
    await page.fill('input[name="credentials[username]"]', 'admin');
    await page.fill('input[name="credentials[password]"]', 'admin123');
    await page.click('button:has-text("Login")');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 10000 });
    await waitForVue(page);
  });

  test('Dashboard loads after login', async ({ page }) => {
    console.log('📍 Testing dashboard load...');
    
    // Should be on dashboard
    expect(page.url()).toContain('/admin');
    
    // Check for dashboard elements
    const dashboardTitle = await page.locator('h1:has-text("Dashboard")').isVisible().catch(() => false);
    const dashboardWidgets = await page.locator('.uk-grid [class*="widget"], .pk-dashboard-widget').count();
    
    expect(dashboardTitle || dashboardWidgets > 0).toBeTruthy();
    console.log('✅ Dashboard loaded successfully');
  });

  test('Dashboard widgets display', async ({ page }) => {
    console.log('📍 Testing dashboard widgets...');
    
    await page.goto('/admin');
    await waitForVue(page);
    
    // Check for common dashboard widgets
    const widgets = [
      { selector: '[class*="stats"], [class*="statistics"]', name: 'Statistics' },
      { selector: '[class*="feed"], [class*="news"]', name: 'Feed/News' },
      { selector: '[class*="user"] [class*="widget"]', name: 'User Info' },
      { selector: '[class*="location"], [class*="weather"]', name: 'Location' }
    ];
    
    for (const widget of widgets) {
      const isVisible = await page.locator(widget.selector).first().isVisible().catch(() => false);
      if (isVisible) {
        console.log(`✅ ${widget.name} widget found`);
      }
    }
  });

  test('Dashboard widget drag and drop', async ({ page }) => {
    console.log('📍 Testing widget reordering...');
    
    await page.goto('/admin');
    await waitForVue(page);
    
    // Find draggable widgets
    const widgets = page.locator('.uk-sortable-handle, [draggable="true"]');
    
    if (await widgets.count() > 1) {
      const firstWidget = widgets.first();
      const secondWidget = widgets.nth(1);
      
      // Attempt drag and drop
      await firstWidget.hover();
      await page.mouse.down();
      await secondWidget.hover();
      await page.mouse.up();
      await waitForVue(page);
      
      console.log('✅ Widget reordering attempted');
    }
  });

  test('Dashboard quick stats', async ({ page }) => {
    console.log('📍 Testing quick stats...');
    
    await page.goto('/admin');
    await waitForVue(page);
    
    // Look for stats numbers
    const stats = page.locator('.uk-text-large, .pk-text-large').filter({ hasText: /\d+/ });
    
    if (await stats.count() > 0) {
      const statCount = await stats.count();
      console.log(`✅ Found ${statCount} stat displays`);
      
      // Check for stat labels
      const labels = ['Users', 'Pages', 'Posts', 'Comments'];
      for (const label of labels) {
        const hasLabel = await page.locator(`text=${label}`).isVisible().catch(() => false);
        if (hasLabel) {
          console.log(`✅ ${label} stat found`);
        }
      }
    }
  });

  test('Dashboard feed widget', async ({ page }) => {
    console.log('📍 Testing feed widget...');
    
    await page.goto('/admin');
    await waitForVue(page);
    
    // Look for feed/news widget
    const feedWidget = page.locator('[class*="feed"], [class*="news"]').first();
    
    if (await feedWidget.isVisible()) {
      // Check for feed items
      const feedItems = feedWidget.locator('li, article, .uk-article');
      const itemCount = await feedItems.count();
      
      if (itemCount > 0) {
        console.log(`✅ Feed widget has ${itemCount} items`);
        
        // Check if links are clickable
        const firstLink = feedItems.first().locator('a').first();
        if (await firstLink.count() > 0) {
          const href = await firstLink.getAttribute('href');
          expect(href).toBeTruthy();
          console.log('✅ Feed items have links');
        }
      }
    }
  });

  test('Dashboard navigation menu', async ({ page }) => {
    console.log('📍 Testing navigation from dashboard...');
    
    await page.goto('/admin');
    await waitForVue(page);
    
    // Check main navigation items
    const navItems = [
      { text: 'Site', url: '/site' },
      { text: 'Blog', url: '/blog' },
      { text: 'Users', url: '/user' },
      { text: 'System', url: '/system' }
    ];
    
    for (const item of navItems) {
      const navLink = page.locator(`.pk-navbar a:has-text("${item.text}"), .uk-navbar a:has-text("${item.text}")`).first();
      
      if (await navLink.isVisible()) {
        await navLink.click();
        await waitForVue(page);
        
        // Check URL changed
        if (page.url().includes(item.url)) {
          console.log(`✅ Navigation to ${item.text} works`);
        }
        
        // Go back to dashboard
        await page.goto('/admin');
        await waitForVue(page);
      }
    }
  });

  test('Dashboard user menu', async ({ page }) => {
    console.log('📍 Testing user menu...');
    
    await page.goto('/admin');
    await waitForVue(page);
    
    // Find user menu
    const userMenu = page.locator('.pk-navbar-nav-right a[data-uk-dropdown], .uk-navbar-right a[data-uk-dropdown]').first();
    
    if (await userMenu.isVisible()) {
      await userMenu.click();
      await page.waitForTimeout(300);
      
      // Check menu items
      const menuItems = ['Profile', 'Settings', 'Logout'];
      
      for (const item of menuItems) {
        const menuItem = page.locator(`a:has-text("${item}")`).first();
        const isVisible = await menuItem.isVisible().catch(() => false);
        
        if (isVisible) {
          console.log(`✅ ${item} menu item found`);
        }
      }
      
      // Close menu
      await page.keyboard.press('Escape');
    }
  });

  test('Dashboard refresh and reload', async ({ page }) => {
    console.log('📍 Testing dashboard refresh...');
    
    await page.goto('/admin');
    await waitForVue(page);
    
    // Get initial widget count
    const initialWidgets = await page.locator('.uk-grid > div').count();
    
    // Refresh page
    await page.reload();
    await waitForVue(page);
    
    // Check widgets still load
    const afterRefreshWidgets = await page.locator('.uk-grid > div').count();
    expect(afterRefreshWidgets).toBeGreaterThan(0);
    
    console.log('✅ Dashboard survives refresh');
  });

  test('Dashboard responsive layout', async ({ page }) => {
    console.log('📍 Testing responsive layout...');
    
    await page.goto('/admin');
    await waitForVue(page);
    
    // Test different viewport sizes
    const viewports = [
      { width: 1920, height: 1080, name: 'Desktop' },
      { width: 768, height: 1024, name: 'Tablet' },
      { width: 375, height: 667, name: 'Mobile' }
    ];
    
    for (const viewport of viewports) {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await waitForVue(page);
      
      // Check if navigation adapts
      const mobileMenu = await page.locator('.uk-navbar-toggle, [uk-navbar-toggle]').isVisible();
      const desktopMenu = await page.locator('.uk-navbar-nav').isVisible();
      
      if (viewport.width < 960) {
        expect(mobileMenu).toBeTruthy();
        console.log(`✅ Mobile menu shown at ${viewport.name}`);
      } else {
        expect(desktopMenu).toBeTruthy();
        console.log(`✅ Desktop menu shown at ${viewport.name}`);
      }
    }
  });
});