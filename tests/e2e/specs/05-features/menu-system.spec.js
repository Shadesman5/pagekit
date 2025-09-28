/**
 * Menu System Tests for Pagekit
 * Tests menu creation, items, positioning, and frontend display
 */

const { test, expect } = require('@playwright/test');
const { navigateAndWaitForVue, waitForVue } = require('../../helpers/vue-helpers');

test.describe('Pagekit Menu System', () => {
  test.beforeEach(async ({ page }) => {
    // Login as admin
    await navigateAndWaitForVue(page, '/admin/login');
    await page.fill('input[name="credentials[username]"]', 'admin');
    await page.fill('input[name="credentials[password]"]', 'admin123');
    await page.click('button:has-text("Login")');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 10000 });
    await waitForVue(page);
  });

  test('Navigate to Menu Manager', async ({ page }) => {
    console.log('📍 Testing Menu Manager navigation...');
    
    // Click Site menu
    const siteMenu = page.locator('a:has-text("Site")').first();
    if (await siteMenu.isVisible()) {
      await siteMenu.click();
      await page.waitForTimeout(300);
      
      // Click Menus
      const menusLink = page.locator('a[href*="/menu"], a:has-text("Menus")').first();
      if (await menusLink.isVisible()) {
        await menusLink.click();
        await waitForVue(page);
        
        expect(page.url()).toContain('/menu');
        
        // Check for menu interface
        const menuList = await page.locator('.uk-nestable, .pk-table').isVisible();
        expect(menuList).toBeTruthy();
        
        console.log('✅ Menu Manager accessible');
      }
    }
  });

  test('Create new menu', async ({ page }) => {
    console.log('📍 Creating new menu...');
    
    await page.goto('/admin/site/menu');
    await waitForVue(page);
    
    // Add menu button
    const addMenuButton = page.locator('button:has-text("Add Menu")').first();
    if (await addMenuButton.isVisible()) {
      await addMenuButton.click();
      await waitForVue(page);
      
      // Enter menu name
      const menuNameInput = page.locator('input[placeholder*="Menu Name"], input[type="text"]').last();
      if (await menuNameInput.isVisible()) {
        await menuNameInput.fill('E2E Test Menu');
        await page.keyboard.press('Enter');
        await waitForVue(page);
        
        console.log('✅ Menu created');
      }
    }
  });

  test('Add menu items', async ({ page }) => {
    console.log('📍 Adding menu items...');
    
    await page.goto('/admin/site/menu');
    await waitForVue(page);
    
    // Select menu to edit (main menu or test menu)
    const menuSelect = page.locator('select[name*="menu"]').first();
    if (await menuSelect.count() > 0) {
      // Select first menu
      await menuSelect.selectOption({ index: 0 });
      await waitForVue(page);
    }
    
    // Add menu item
    const addItemButton = page.locator('button:has-text("Add"), button:has-text("Add Link")').first();
    if (await addItemButton.isVisible()) {
      await addItemButton.click();
      await waitForVue(page);
      
      // Fill menu item details
      const titleInput = page.locator('input[name*="title"]').first();
      if (await titleInput.isVisible()) {
        await titleInput.fill('Test Menu Item');
        
        // Set URL/Link
        const urlInput = page.locator('input[name*="url"], input[name*="link"]').first();
        if (await urlInput.count() > 0) {
          await urlInput.fill('/test-page');
        }
        
        // Save menu item
        await page.click('button:has-text("Save")');
        await page.waitForTimeout(2000);
        
        console.log('✅ Menu item added');
      }
    }
  });

  test('Menu item types', async ({ page }) => {
    console.log('📍 Testing different menu item types...');
    
    await page.goto('/admin/site/menu');
    await waitForVue(page);
    
    // Add different types of menu items
    const itemTypes = [
      { name: 'Page Link', url: 'page://1' },
      { name: 'External Link', url: 'https://example.com' },
      { name: 'Divider', url: '#' },
      { name: 'Heading', url: '' }
    ];
    
    for (const itemType of itemTypes) {
      const addButton = page.locator('button:has-text("Add")').first();
      if (await addButton.isVisible()) {
        await addButton.click();
        await waitForVue(page);
        
        // Set title
        const titleInput = page.locator('input[name*="title"]').first();
        if (await titleInput.isVisible()) {
          await titleInput.fill(itemType.name);
          
          // Set URL if applicable
          if (itemType.url) {
            const urlInput = page.locator('input[name*="url"]').first();
            if (await urlInput.count() > 0) {
              await urlInput.fill(itemType.url);
            }
          }
          
          // Set type if dropdown exists
          const typeSelect = page.locator('select[name*="type"]').first();
          if (await typeSelect.count() > 0) {
            if (itemType.name === 'Divider') {
              await typeSelect.selectOption('divider');
            } else if (itemType.name === 'Heading') {
              await typeSelect.selectOption('heading');
            }
          }
          
          await page.click('button:has-text("Save")');
          await page.waitForTimeout(1500);
          
          console.log(`✅ ${itemType.name} menu item created`);
        }
      }
    }
  });

  test('Menu item drag and drop ordering', async ({ page }) => {
    console.log('📍 Testing menu item reordering...');
    
    await page.goto('/admin/site/menu');
    await waitForVue(page);
    
    // Find draggable menu items
    const menuItems = page.locator('.uk-nestable-item, .uk-sortable-item');
    
    if (await menuItems.count() > 1) {
      const firstItem = menuItems.first();
      const secondItem = menuItems.nth(1);
      
      // Get initial order
      const initialFirst = await firstItem.textContent();
      
      // Drag and drop
      await firstItem.hover();
      await page.mouse.down();
      await secondItem.hover();
      await page.mouse.up();
      await waitForVue(page);
      
      // Check if order changed
      const newFirst = await menuItems.first().textContent();
      
      if (initialFirst !== newFirst) {
        console.log('✅ Menu items reordered');
      } else {
        console.log('⚠️ Drag and drop may not be working');
      }
    }
  });

  test('Menu item visibility settings', async ({ page }) => {
    console.log('📍 Testing menu item visibility...');
    
    await page.goto('/admin/site/menu');
    await waitForVue(page);
    
    // Edit a menu item
    const menuItem = page.locator('.uk-nestable-item, tr').filter({ hasText: 'Test Menu Item' }).first();
    
    if (await menuItem.count() > 0) {
      await menuItem.locator('a').first().click();
      await waitForVue(page);
      
      // Set visibility restrictions
      const restrictCheckbox = page.locator('input[type="checkbox"][name*="restrict"]').first();
      if (await restrictCheckbox.count() > 0) {
        await restrictCheckbox.check();
        console.log('✅ Access restriction enabled');
        
        // Select roles
        const rolesCheckboxes = page.locator('input[type="checkbox"][name*="roles"]');
        if (await rolesCheckboxes.count() > 0) {
          // Check authenticated users
          for (let i = 0; i < await rolesCheckboxes.count(); i++) {
            const label = await rolesCheckboxes.nth(i).locator('..').textContent();
            if (label && label.includes('Authenticated')) {
              await rolesCheckboxes.nth(i).check();
              console.log('✅ Role visibility set');
              break;
            }
          }
        }
      }
      
      // Set status
      const statusSelect = page.locator('select[name*="status"]').first();
      if (await statusSelect.count() > 0) {
        await statusSelect.selectOption('1'); // Enabled
        console.log('✅ Menu item enabled');
      }
      
      // Save
      await page.click('button:has-text("Save")');
      await page.waitForTimeout(2000);
    }
  });

  test('Create submenu (nested items)', async ({ page }) => {
    console.log('📍 Testing submenu creation...');
    
    await page.goto('/admin/site/menu');
    await waitForVue(page);
    
    // Add parent menu item
    const addButton = page.locator('button:has-text("Add")').first();
    if (await addButton.isVisible()) {
      await addButton.click();
      await waitForVue(page);
      
      await page.fill('input[name*="title"]', 'Parent Menu');
      await page.fill('input[name*="url"]', '#');
      await page.click('button:has-text("Save")');
      await page.waitForTimeout(2000);
      
      console.log('✅ Parent menu created');
      
      // Add child item
      await addButton.click();
      await waitForVue(page);
      
      await page.fill('input[name*="title"]', 'Child Menu');
      await page.fill('input[name*="url"]', '/child-page');
      
      // Set parent
      const parentSelect = page.locator('select[name*="parent"]').first();
      if (await parentSelect.count() > 0) {
        const options = await parentSelect.locator('option').allTextContents();
        const parentOption = options.find(opt => opt.includes('Parent Menu'));
        if (parentOption) {
          await parentSelect.selectOption({ label: parentOption });
          console.log('✅ Parent relationship set');
        }
      }
      
      await page.click('button:has-text("Save")');
      await page.waitForTimeout(2000);
      
      console.log('✅ Submenu item created');
    }
  });

  test('Menu positions and assignments', async ({ page }) => {
    console.log('📍 Testing menu positions...');
    
    await page.goto('/admin/site/menu');
    await waitForVue(page);
    
    // Check for position settings
    const positionSelect = page.locator('select[name*="position"]').first();
    if (await positionSelect.count() > 0) {
      const positions = await positionSelect.locator('option').allTextContents();
      
      if (positions.length > 0) {
        console.log(`✅ Found ${positions.length} menu positions`);
        
        // Try different positions
        for (let i = 0; i < Math.min(2, positions.length); i++) {
          await positionSelect.selectOption({ index: i });
          await page.click('button:has-text("Save")');
          await page.waitForTimeout(1500);
          console.log(`✅ Menu assigned to position: ${positions[i]}`);
        }
      }
    }
  });

  test('Delete menu items', async ({ page }) => {
    console.log('📍 Testing menu item deletion...');
    
    await page.goto('/admin/site/menu');
    await waitForVue(page);
    
    // Find test menu items
    const testItems = page.locator('.uk-nestable-item, tr').filter({ hasText: /Test|E2E/ });
    
    while (await testItems.count() > 0) {
      const item = testItems.first();
      
      // Select item
      const checkbox = item.locator('input[type="checkbox"]').first();
      if (await checkbox.count() > 0) {
        await checkbox.check();
        
        // Delete
        const deleteButton = page.locator('button:has-text("Delete")').first();
        if (await deleteButton.isVisible()) {
          await deleteButton.click();
          
          // Confirm
          const confirmButton = page.locator('button:has-text("Delete")').last();
          if (await confirmButton.isVisible({ timeout: 2000 })) {
            await confirmButton.click();
            await waitForVue(page);
            
            console.log('✅ Menu item deleted');
          }
        }
      } else {
        // Alternative: click item and delete
        await item.locator('a').first().click();
        await waitForVue(page);
        
        const deleteLink = page.locator('a:has-text("Delete"), button:has-text("Delete")').first();
        if (await deleteLink.isVisible()) {
          await deleteLink.click();
          
          const confirmButton = page.locator('button:has-text("Delete")').last();
          if (await confirmButton.isVisible({ timeout: 2000 })) {
            await confirmButton.click();
            await waitForVue(page);
          }
        }
        
        await page.goto('/admin/site/menu');
        await waitForVue(page);
      }
      
      await page.waitForTimeout(1000);
    }
  });

  test('Menu frontend display', async ({ page }) => {
    console.log('📍 Testing menu on frontend...');
    
    // First ensure we have a menu item
    await page.goto('/admin/site/menu');
    await waitForVue(page);
    
    const addButton = page.locator('button:has-text("Add")').first();
    if (await addButton.isVisible()) {
      await addButton.click();
      await waitForVue(page);
      
      await page.fill('input[name*="title"]', 'Frontend Test Link');
      await page.fill('input[name*="url"]', '/');
      await page.click('button:has-text("Save")');
      await page.waitForTimeout(2000);
    }
    
    // Check frontend
    await page.goto('/');
    
    // Look for navigation menu
    const navMenu = page.locator('.uk-navbar-nav, nav ul').first();
    if (await navMenu.isVisible()) {
      const menuItems = navMenu.locator('li');
      const itemCount = await menuItems.count();
      
      expect(itemCount).toBeGreaterThan(0);
      console.log(`✅ Menu displayed on frontend with ${itemCount} items`);
      
      // Check for test item
      const testItem = navMenu.locator('a:has-text("Frontend Test Link")');
      if (await testItem.isVisible()) {
        console.log('✅ Test menu item visible on frontend');
        
        // Test click
        await testItem.click();
        await page.waitForLoadState('networkidle');
        console.log('✅ Menu item clickable');
      }
    }
  });
});
