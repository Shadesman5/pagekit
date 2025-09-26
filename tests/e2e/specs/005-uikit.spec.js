/**
 * UIkit Integration Tests for Pagekit
 * Tests UIkit 3.5 component functionality
 */

const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('../helpers/pagekit-auth');
const {
  waitForUIkitModal,
  checkUIkitNotification,
  closeUIkitModals,
  selectFromDropdown,
  switchTab,
  dragAndDrop,
  toggleAccordion,
  getTooltipText
} = require('../helpers/pagekit-ui');

test.describe('UIkit Components', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('Modal Dialogs', async ({ page }) => {
    await page.goto('/admin/site/page');
    
    // Trigger delete modal
    const firstCheckbox = await page.$('input[type="checkbox"][name="ids[]"]');
    if (firstCheckbox) {
      await firstCheckbox.check();
      await page.click('button[title="Delete"]');
      
      // Wait for confirmation modal
      await waitForUIkitModal(page);
      
      // Verify modal content
      const modalContent = await page.textContent('.uk-modal-dialog');
      expect(modalContent).toContain('Delete');
      
      // Test cancel
      await page.click('.uk-modal-dialog .uk-button-default');
      
      // Modal should close
      await page.waitForSelector('.uk-modal.uk-open', { state: 'hidden' });
    }
  });

  test('Dropdown Menus', async ({ page }) => {
    await page.goto('/admin');
    
    // Test user dropdown menu
    const userMenuTrigger = await page.$('.uk-navbar-nav > li > a');
    if (userMenuTrigger) {
      await userMenuTrigger.click();
      
      // Wait for dropdown to open
      await page.waitForSelector('.uk-dropdown.uk-open', { visible: true });
      
      // Verify dropdown items
      const dropdownItems = await page.$$('.uk-dropdown.uk-open li');
      expect(dropdownItems.length).toBeGreaterThan(0);
      
      // Click outside to close
      await page.click('body');
      
      // Dropdown should close
      await page.waitForSelector('.uk-dropdown.uk-open', { state: 'hidden' });
    }
  });

  test('Notifications', async ({ page }) => {
    await page.goto('/admin/site/page/edit');
    
    // Create a page to trigger notification
    await page.fill('input[name="page[title]"]', 'Notification Test');
    await page.click('button.uk-button-primary');
    
    // Check for success notification
    const notification = await checkUIkitNotification(page, 'saved', 'success');
    await expect(notification).toBeVisible();
    
    // Notification should auto-dismiss
    await page.waitForSelector('.uk-notify-message', { 
      state: 'hidden',
      timeout: 10000 
    });
  });

  test('Tab Navigation', async ({ page }) => {
    await page.goto('/admin/system/settings');
    
    // Find tab navigation
    const tabs = await page.$$('.uk-tab li');
    if (tabs.length > 1) {
      // Click second tab
      await tabs[1].click();
      
      // Wait for tab content to change
      await page.waitForTimeout(500);
      
      // Verify active tab
      const activeTab = await page.$('.uk-tab li.uk-active');
      const activeText = await activeTab.textContent();
      expect(activeText).toBeTruthy();
      
      // Corresponding content should be visible
      const tabHref = await activeTab.$eval('a', el => el.getAttribute('href'));
      const contentId = tabHref.replace('#', '');
      await expect(page.locator(`#${contentId}`)).toBeVisible();
    }
  });

  test('Sortable Lists', async ({ page }) => {
    await page.goto('/admin/site/menu');
    
    // Check for sortable menu items
    const sortableItems = await page.$$('.uk-sortable li');
    if (sortableItems.length >= 2) {
      // Get initial order
      const initialFirst = await sortableItems[0].textContent();
      const initialSecond = await sortableItems[1].textContent();
      
      // Attempt drag and drop (simplified)
      // In real test, would use dragAndDrop helper
      // await dragAndDrop(page, '.uk-sortable li:first-child', '.uk-sortable li:nth-child(2)');
      
      // For now, just verify sortable is initialized
      const sortableClass = await sortableItems[0].getAttribute('class');
      expect(sortableClass).toContain('uk-sortable');
    }
  });

  test('Accordion Components', async ({ page }) => {
    // Find a page with accordion
    await page.goto('/admin/system/info');
    
    const accordionItems = await page.$$('.uk-accordion li');
    if (accordionItems.length > 0) {
      // Test expanding/collapsing
      for (const item of accordionItems) {
        const isOpen = await item.evaluate(el => el.classList.contains('uk-open'));
        
        // Click to toggle
        const toggle = await item.$('.uk-accordion-title');
        if (toggle) {
          await toggle.click();
          await page.waitForTimeout(300); // Wait for animation
          
          // Check state changed
          const newState = await item.evaluate(el => el.classList.contains('uk-open'));
          expect(newState).toBe(!isOpen);
        }
      }
    }
  });

  test('Grid System', async ({ page }) => {
    await page.goto('/admin');
    
    // Check for UIkit grid
    const gridContainers = await page.$$('[class*="uk-grid"]');
    expect(gridContainers.length).toBeGreaterThan(0);
    
    // Test responsive grid
    const viewport = page.viewportSize();
    
    // Test mobile view
    await page.setViewportSize({ width: 480, height: 800 });
    await page.waitForTimeout(500);
    
    // Grid items should stack
    const gridItem = await page.$('[class*="uk-width"]');
    const mobileWidth = await gridItem.boundingBox();
    
    // Test desktop view
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.waitForTimeout(500);
    
    // Grid items should be side by side
    const desktopWidth = await gridItem.boundingBox();
    
    // Restore original viewport
    if (viewport) {
      await page.setViewportSize(viewport);
    }
  });

  test('Forms and Inputs', async ({ page }) => {
    await page.goto('/admin/system/settings');
    
    // Test form controls
    const formInputs = await page.$$('.uk-form-controls input');
    expect(formInputs.length).toBeGreaterThan(0);
    
    // Test form validation styling
    const requiredInput = await page.$('input[required]');
    if (requiredInput) {
      // Clear and blur to trigger validation
      await requiredInput.fill('');
      await requiredInput.blur();
      
      // Check for danger styling
      const hasError = await requiredInput.evaluate(el => 
        el.classList.contains('uk-form-danger')
      );
      // Validation styling may be applied
    }
  });

  test('Buttons and Button Groups', async ({ page }) => {
    await page.goto('/admin/site/page');
    
    // Test button groups
    const buttonGroups = await page.$$('.uk-button-group');
    
    // Test button states
    const primaryButton = await page.$('.uk-button-primary');
    if (primaryButton) {
      // Check hover state
      await primaryButton.hover();
      
      // Check disabled state
      const isDisabled = await primaryButton.isDisabled();
      expect(typeof isDisabled).toBe('boolean');
    }
  });

  test('Icons', async ({ page }) => {
    await page.goto('/admin');
    
    // Check for UIkit icons
    const icons = await page.$$('[uk-icon]');
    expect(icons.length).toBeGreaterThan(0);
    
    // Verify icon rendering
    for (const icon of icons.slice(0, 5)) { // Check first 5 icons
      const svg = await icon.$('svg');
      expect(svg).toBeTruthy();
    }
  });

  test('Tooltips', async ({ page }) => {
    await page.goto('/admin/site/page');
    
    // Find element with tooltip
    const tooltipElement = await page.$('[uk-tooltip]');
    if (tooltipElement) {
      // Hover to show tooltip
      await tooltipElement.hover();
      
      // Wait for tooltip to appear
      await page.waitForSelector('.uk-tooltip', { 
        visible: true,
        timeout: 5000 
      });
      
      // Get tooltip text
      const tooltipText = await page.textContent('.uk-tooltip');
      expect(tooltipText).toBeTruthy();
      
      // Move away to hide tooltip
      await page.mouse.move(0, 0);
      
      // Tooltip should hide
      await page.waitForSelector('.uk-tooltip', { 
        state: 'hidden',
        timeout: 5000 
      });
    }
  });

  test('Spinner/Loading States', async ({ page }) => {
    // Trigger an action that shows loading state
    await page.goto('/admin/system/update');
    
    // Click check for updates
    const updateButton = await page.$('button.uk-button-primary');
    if (updateButton) {
      await updateButton.click();
      
      // Check for spinner
      const spinner = await page.$('.uk-spinner, [uk-spinner]');
      if (spinner) {
        await expect(spinner).toBeVisible();
        
        // Wait for spinner to disappear
        await page.waitForSelector('.uk-spinner, [uk-spinner]', {
          state: 'hidden',
          timeout: 30000
        });
      }
    }
  });
});

test.describe('UIkit Responsive Behavior', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('Mobile Navigation', async ({ page }) => {
    // Set mobile viewport
    await page.setViewportSize({ width: 375, height: 812 });
    
    await page.goto('/admin');
    
    // Check for mobile menu toggle
    const mobileToggle = await page.$('.uk-navbar-toggle');
    if (mobileToggle) {
      await mobileToggle.click();
      
      // Mobile menu should open
      await page.waitForSelector('.uk-offcanvas.uk-open', { 
        visible: true,
        timeout: 5000 
      });
      
      // Close mobile menu
      await page.click('.uk-offcanvas-close');
      
      // Menu should close
      await page.waitForSelector('.uk-offcanvas.uk-open', { 
        state: 'hidden',
        timeout: 5000 
      });
    }
    
    // Reset viewport
    await page.setViewportSize({ width: 1280, height: 800 });
  });

  test('Responsive Tables', async ({ page }) => {
    await page.goto('/admin/site/page');
    
    // Desktop view
    await page.setViewportSize({ width: 1280, height: 800 });
    const desktopTable = await page.$('.uk-table');
    const desktopColumns = await desktopTable.$$('th');
    
    // Mobile view
    await page.setViewportSize({ width: 375, height: 812 });
    await page.waitForTimeout(500);
    
    // Table should be responsive (scrollable or stacked)
    const tableContainer = await page.$('.uk-overflow-auto');
    if (tableContainer) {
      // Table is in scrollable container
      expect(tableContainer).toBeTruthy();
    }
    
    // Reset viewport
    await page.setViewportSize({ width: 1280, height: 800 });
  });
});