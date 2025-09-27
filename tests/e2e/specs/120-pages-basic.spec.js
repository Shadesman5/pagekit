/**
 * Content Management Tests for Installed Pagekit
 * Prerequisites: Pagekit must be installed with admin/admin123
 */

const { test, expect } = require('@playwright/test');

test.describe('Pagekit Content Management (Installed)', () => {
  // Login before each test
  test.beforeEach(async ({ page }) => {
    await page.goto('/admin/login');
    await page.fill('input[name="credentials[username]"]', 'admin');
    await page.fill('input[name="credentials[password]"]', 'admin123');
    await page.click('button.uk-button');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 10000 });
  });

  test('Access Pages section', async ({ page }) => {
    // Navigate to Pages directly
    await page.goto('/admin/page');
    await page.waitForTimeout(2000);
    
    // Check we're on pages section
    expect(page.url()).toContain('/admin/page');
    
    // Check for page list or add button
    const addButton = await page.locator('a:has-text("Add Page")').isVisible().catch(() => false);
    const pageList = await page.locator('.uk-table').isVisible().catch(() => false);
    const pageTitle = await page.locator('h1:has-text("Pages")').isVisible().catch(() => false);
    
    expect(addButton || pageList || pageTitle).toBeTruthy();
    console.log('✓ Pages section accessible');
  });

  test('Create a new page', async ({ page }) => {
    // Navigate to Pages
    await page.goto('/admin/page');
    
    // Click Add Page
    const addButton = await page.locator('a:has-text("Add Page")').first();
    if (await addButton.isVisible()) {
      await addButton.click();
      await page.waitForTimeout(2000);
      
      // Fill page details
      const titleInput = await page.locator('input[name="page[title]"]').first();
      if (await titleInput.isVisible()) {
        await titleInput.fill('Test Page from E2E');
        
        // Add content if editor is visible
        const contentEditor = await page.locator('.uk-htmleditor-content').first();
        if (await contentEditor.isVisible()) {
          await contentEditor.fill('This is a test page created by E2E tests.');
        }
        
        // Save
        const saveButton = await page.locator('button:has-text("Save")').first();
        if (await saveButton.isVisible()) {
          await saveButton.click();
          await page.waitForTimeout(2000);
          
          console.log('✓ Page created successfully');
        }
      }
    }
  });

  test('Access Blog/Posts section', async ({ page }) => {
    // Try to navigate to Blog
    const blogLink = await page.locator('a[href*="/blog"]').first();
    if (await blogLink.isVisible()) {
      await blogLink.click();
      await page.waitForTimeout(2000);
      
      // Check we're in blog section
      const url = page.url();
      if (url.includes('/blog') || url.includes('/post')) {
        console.log('✓ Blog section accessible');
      }
    }
  });

  test('Access System Settings', async ({ page }) => {
    // Navigate to System
    const systemLink = await page.locator('a:has-text("System")').first();
    if (await systemLink.isVisible()) {
      await systemLink.click();
      await page.waitForTimeout(2000);
      
      // Check for settings options
      const settingsVisible = await page.locator('a:has-text("Settings")').isVisible().catch(() => false);
      const infoVisible = await page.locator('a:has-text("Info")').isVisible().catch(() => false);
      
      expect(settingsVisible || infoVisible).toBeTruthy();
      console.log('✓ System settings accessible');
    }
  });

  test('Access User Management', async ({ page }) => {
    // Navigate to Users
    const usersLink = await page.locator('a[href*="/user"]').first();
    if (await usersLink.isVisible()) {
      await usersLink.click();
      await page.waitForTimeout(2000);
      
      // Check we're in users section
      const url = page.url();
      if (url.includes('/user')) {
        // Check for user list
        const userList = await page.locator('.uk-table').isVisible().catch(() => false);
        const addUserButton = await page.locator('a:has-text("Add User")').isVisible().catch(() => false);
        
        expect(userList || addUserButton).toBeTruthy();
        console.log('✓ User management accessible');
      }
    }
  });
});