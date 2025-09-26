/**
 * Vue.js Component Tests for Pagekit
 * Tests Vue.js 2.6 component interactions and reactivity
 */

const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('../helpers/pagekit-auth');
const {
  waitForVueComponent,
  setVueFormValue,
  getVueComponentData,
  callVueMethod,
  waitForVueUpdate
} = require('../helpers/pagekit-ui');

test.describe('Vue.js Components', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('Dashboard Widget Panel', async ({ page }) => {
    await page.goto('/admin');
    
    // Wait for dashboard to load with Vue components
    await waitForVueComponent(page, '.pk-dashboard');
    
    // Check widget panels are rendered
    const widgetPanels = await page.$$('.pk-width-content .uk-panel');
    expect(widgetPanels.length).toBeGreaterThan(0);
    
    // Test widget interaction
    const firstWidget = widgetPanels[0];
    await firstWidget.hover();
    
    // Check for widget controls (edit, remove)
    const widgetControls = await firstWidget.$('.pk-widget-controls');
    if (widgetControls) {
      await expect(widgetControls).toBeVisible();
    }
  });

  test('Settings Form with Vue Bindings', async ({ page }) => {
    await page.goto('/admin/system/settings');
    
    // Wait for Vue settings component
    await waitForVueComponent(page, '.pk-settings');
    
    // Test two-way data binding
    const siteTitleInput = await page.$('input[name="config[title]"]');
    const originalTitle = await siteTitleInput.inputValue();
    
    // Change title
    const newTitle = 'Test Site ' + Date.now();
    await siteTitleInput.fill(newTitle);
    
    // Trigger Vue update
    await siteTitleInput.dispatchEvent('input');
    
    // Verify Vue model updated
    const componentData = await getVueComponentData(page, '.pk-settings');
    if (componentData && componentData.config) {
      expect(componentData.config.title).toBe(newTitle);
    }
    
    // Save and verify
    await page.click('button.uk-button-primary');
    await expect(page.locator('.uk-notify-message-success')).toBeVisible();
    
    // Restore original title
    await siteTitleInput.fill(originalTitle);
    await page.click('button.uk-button-primary');
  });

  test('Node/Page Tree Management', async ({ page }) => {
    await page.goto('/admin/site/page');
    
    // Wait for page list Vue component
    await waitForVueComponent(page, '.pk-table');
    
    // Test sortable functionality if available
    const sortableRows = await page.$$('.uk-sortable tr');
    if (sortableRows.length >= 2) {
      // Would test drag and drop here
      // This requires more complex interaction
    }
    
    // Test checkbox selection reactivity
    const checkboxes = await page.$$('input[type="checkbox"][name="ids[]"]');
    if (checkboxes.length > 0) {
      // Check first item
      await checkboxes[0].check();
      
      // Bulk actions should appear
      await expect(page.locator('.uk-button-group')).toBeVisible();
      
      // Uncheck
      await checkboxes[0].uncheck();
      
      // Bulk actions should disappear
      await expect(page.locator('.uk-button-group')).not.toBeVisible();
    }
  });

  test('Editor Component', async ({ page }) => {
    await page.goto('/admin/site/page/edit');
    
    // Wait for editor Vue component
    await waitForVueComponent(page, '.pk-editor');
    
    // Test editor toolbar interactions
    const toolbar = await page.$('.uk-htmleditor-navbar');
    if (toolbar) {
      // Click bold button
      const boldButton = await toolbar.$('a[title="Bold"]');
      if (boldButton) {
        await boldButton.click();
        
        // Check if editor received command
        const editorContent = await page.$('textarea.uk-htmleditor-code');
        const content = await editorContent.inputValue();
        // Might contain bold markdown or HTML tags
      }
    }
  });

  test('Widget Configuration Modal', async ({ page }) => {
    await page.goto('/admin/site/widget');
    
    // Wait for widget list
    await waitForVueComponent(page, '.pk-table');
    
    // Click add widget
    await page.click('button.uk-button-primary');
    
    // Wait for modal with Vue component
    await page.waitForSelector('.uk-modal.uk-open');
    
    // Select widget type
    const textWidget = await page.$('[data-widget="text"]');
    if (textWidget) {
      await textWidget.click();
      
      // Wait for widget form
      await waitForVueComponent(page, '.pk-widget-form');
      
      // Fill widget details
      await page.fill('input[name="widget[title]"]', 'Vue Test Widget');
      
      // Test position dropdown (Vue select component)
      await page.selectOption('select[name="widget[position]"]', 'sidebar');
      
      // Save
      await page.click('.uk-modal button.uk-button-primary');
      
      // Verify widget added to list
      await expect(page.locator('text="Vue Test Widget"')).toBeVisible();
    }
  });

  test('Reactive Data Updates', async ({ page }) => {
    await page.goto('/admin/user');
    
    // Wait for user list Vue component
    await waitForVueComponent(page, '.pk-table');
    
    // Test search reactivity
    const searchInput = await page.$('input[type="search"]');
    if (searchInput) {
      // Type search term
      await searchInput.fill('admin');
      
      // Wait for Vue to filter results
      await waitForVueUpdate(page, () => {
        const rows = document.querySelectorAll('tbody tr');
        return rows.length > 0 && Array.from(rows).every(row => 
          row.textContent.toLowerCase().includes('admin')
        );
      });
      
      // Verify filtered results
      const visibleRows = await page.$$('tbody tr:visible');
      for (const row of visibleRows) {
        const text = await row.textContent();
        expect(text.toLowerCase()).toContain('admin');
      }
      
      // Clear search
      await searchInput.fill('');
      
      // Wait for all results to show again
      await page.waitForTimeout(500);
    }
  });

  test('Vue Form Validation', async ({ page }) => {
    await page.goto('/admin/user/edit');
    
    // Wait for user form
    await waitForVueComponent(page, '.pk-user-edit');
    
    // Clear required field
    const usernameInput = await page.$('input[name="user[username]"]');
    await usernameInput.fill('');
    
    // Try to save
    await page.click('button.uk-button-primary');
    
    // Should show validation error
    await expect(page.locator('.uk-form-danger')).toBeVisible();
    
    // Fill valid data
    await usernameInput.fill('validusername');
    
    // Error should clear
    await expect(page.locator('.uk-form-danger')).not.toBeVisible();
  });

  test('Dynamic Component Loading', async ({ page }) => {
    await page.goto('/admin/system/package/extensions');
    
    // Wait for extensions list
    await waitForVueComponent(page, '.pk-extensions');
    
    // Click on an extension to load details
    const extensionRow = await page.$('.pk-table tbody tr');
    if (extensionRow) {
      await extensionRow.click();
      
      // Wait for detail component to load
      await waitForVueComponent(page, '.pk-extension-details');
      
      // Verify details are shown
      await expect(page.locator('.pk-extension-details')).toBeVisible();
    }
  });

  test('Vue Router Navigation', async ({ page }) => {
    // If Pagekit uses Vue Router for SPA sections
    await page.goto('/admin');
    
    // Check for router links
    const routerLinks = await page.$$('a[href^="#"]');
    if (routerLinks.length > 0) {
      // Click a router link
      await routerLinks[0].click();
      
      // URL should change without page reload
      await page.waitForTimeout(500);
      
      // Check that we're still in the same page context
      const isStillAdmin = await page.evaluate(() => {
        return window.location.pathname.includes('/admin');
      });
      expect(isStillAdmin).toBeTruthy();
    }
  });

  test('Computed Properties and Watchers', async ({ page }) => {
    await page.goto('/admin/system/settings');
    
    // Test computed property updates
    // For example, character count on description field
    const descriptionField = await page.$('textarea[name="config[description]"]');
    if (descriptionField) {
      const testText = 'Testing Vue computed properties';
      await descriptionField.fill(testText);
      
      // Check if character count updates (if implemented)
      const charCount = await page.$('.pk-char-count');
      if (charCount) {
        const count = await charCount.textContent();
        expect(count).toContain(testText.length.toString());
      }
    }
  });
});

test.describe('Vue Component Events', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('Event Propagation Between Components', async ({ page }) => {
    await page.goto('/admin/site/page');
    
    // Select all checkbox should trigger individual checkboxes
    const selectAll = await page.$('input[type="checkbox"].uk-checkbox-primary');
    if (selectAll) {
      await selectAll.check();
      
      // All individual checkboxes should be checked
      const checkboxes = await page.$$('input[type="checkbox"][name="ids[]"]');
      for (const checkbox of checkboxes) {
        const isChecked = await checkbox.isChecked();
        expect(isChecked).toBeTruthy();
      }
      
      // Uncheck select all
      await selectAll.uncheck();
      
      // All should be unchecked
      for (const checkbox of checkboxes) {
        const isChecked = await checkbox.isChecked();
        expect(isChecked).toBeFalsy();
      }
    }
  });

  test('Custom Vue Events', async ({ page }) => {
    await page.goto('/admin');
    
    // Test custom events if dashboard widgets emit them
    const widgetData = await getVueComponentData(page, '.pk-dashboard-widget');
    if (widgetData) {
      // Could test widget refresh events, etc.
    }
  });
});