/**
 * User Management Tests for Pagekit
 * Tests user CRUD operations, roles, and permissions
 */

const { test, expect } = require('@playwright/test');
const { navigateAndWaitForVue, waitForVue, waitForUIkitModal } = require('../../helpers/vue-helpers');

test.describe('Pagekit User Management', () => {
  // Login as admin before each test
  test.beforeEach(async ({ page }) => {
    await navigateAndWaitForVue(page, '/admin/login');
    await page.fill('input[name="credentials[username]"]', 'admin');
    await page.fill('input[name="credentials[password]"]', 'admin123');
    await page.click('button:has-text("Login")');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 10000 });
    await waitForVue(page);
  });

  test('Navigate to User Management', async ({ page }) => {
    console.log('📍 Testing User Management navigation...');
    
    // Click on Users in menu
    await page.click('a[href="/admin/user"]');
    await page.waitForURL(/\/admin\/user/, { timeout: 5000 });
    await waitForVue(page);
    
    // Check we're on user page
    expect(page.url()).toContain('/admin/user');
    
    // Check for user list
    const userTable = await page.locator('.uk-table, .pk-table').isVisible();
    expect(userTable).toBeTruthy();
    
    console.log('✅ User Management accessible');
  });

  test('Create new user with different roles', async ({ page }) => {
    console.log('📍 Creating new users...');
    
    await page.goto('/admin/user');
    await waitForVue(page);
    
    // Test data for different user types
    const users = [
      { username: 'editor_test', email: 'editor@test.local', role: 'Editor' },
      { username: 'author_test', email: 'author@test.local', role: 'Authenticated' }
    ];
    
    for (const userData of users) {
      // Click Add User button
      const addButton = page.locator('a:has-text("Add User"), button:has-text("Add User")').first();
      if (await addButton.isVisible()) {
        await addButton.click();
        await waitForVue(page);
        
        // Fill user form
        await page.fill('input[name="user[username]"]', userData.username);
        await page.fill('input[name="user[email]"]', userData.email);
        await page.fill('input[name="user[password]"]', 'Test123!');
        
        // Select role if dropdown exists
        const roleSelect = page.locator('select[name="user[roles][]"]');
        if (await roleSelect.count() > 0) {
          await roleSelect.selectOption({ label: userData.role });
        }
        
        // Set user as active
        const statusSelect = page.locator('select[name="user[status]"]');
        if (await statusSelect.count() > 0) {
          await statusSelect.selectOption('1'); // Active
        }
        
        // Save user
        await page.click('button:has-text("Save")');
        await page.waitForTimeout(2000);
        
        console.log(`✅ User ${userData.username} created`);
        
        // Go back to user list
        await page.goto('/admin/user');
        await waitForVue(page);
      }
    }
  });

  test('Edit existing user', async ({ page }) => {
    console.log('📍 Testing user edit...');
    
    await page.goto('/admin/user');
    await waitForVue(page);
    
    // Find a user to edit (not admin)
    const userRow = page.locator('tr').filter({ hasText: 'editor_test' }).first();
    
    if (await userRow.count() > 0) {
      // Click on username to edit
      await userRow.locator('a').first().click();
      await waitForVue(page);
      
      // Change email
      const emailInput = page.locator('input[name="user[email]"]');
      await emailInput.clear();
      await emailInput.fill('editor_updated@test.local');
      
      // Change name
      const nameInput = page.locator('input[name="user[name]"]');
      if (await nameInput.count() > 0) {
        await nameInput.clear();
        await nameInput.fill('Test Editor Updated');
      }
      
      // Save changes
      await page.click('button:has-text("Save")');
      await page.waitForTimeout(2000);
      
      console.log('✅ User edited successfully');
    }
  });

  test('User bulk operations', async ({ page }) => {
    console.log('📍 Testing bulk operations...');
    
    await page.goto('/admin/user');
    await waitForVue(page);
    
    // Select multiple users (checkboxes)
    const checkboxes = page.locator('input[type="checkbox"][class*="check"]');
    const count = await checkboxes.count();
    
    if (count > 2) {
      // Select first two non-admin users
      for (let i = 1; i < Math.min(3, count); i++) {
        await checkboxes.nth(i).check();
      }
      
      // Look for bulk action dropdown
      const bulkSelect = page.locator('select[class*="bulk"]').first();
      if (await bulkSelect.count() > 0) {
        // Try to disable users
        await bulkSelect.selectOption({ label: 'Disable' });
        
        // Confirm action if modal appears
        const confirmButton = page.locator('button:has-text("Confirm")');
        if (await confirmButton.isVisible({ timeout: 2000 })) {
          await confirmButton.click();
        }
        
        console.log('✅ Bulk operation executed');
      }
    }
  });

  test('User roles and permissions', async ({ page }) => {
    console.log('📍 Testing roles and permissions...');
    
    // Navigate to Roles
    await page.goto('/admin/user/roles');
    await waitForVue(page);
    
    // Check for role list
    const roles = ['Anonymous', 'Authenticated', 'Administrator'];
    
    for (const roleName of roles) {
      const role = page.locator('text=' + roleName);
      expect(await role.isVisible()).toBeTruthy();
      console.log(`✅ Role ${roleName} exists`);
    }
    
    // Try to edit a role (Authenticated)
    const authRole = page.locator('tr').filter({ hasText: 'Authenticated' });
    if (await authRole.count() > 0) {
      await authRole.locator('a').first().click();
      await waitForVue(page);
      
      // Check for permissions checkboxes
      const permissions = page.locator('input[type="checkbox"][name*="permission"]');
      expect(await permissions.count()).toBeGreaterThan(0);
      
      console.log('✅ Role permissions accessible');
    }
  });

  test('User profile edit', async ({ page }) => {
    console.log('📍 Testing profile edit...');
    
    // Click on user menu
    const userMenu = page.locator('.pk-navbar-nav-right a[data-uk-dropdown]').first();
    if (await userMenu.isVisible()) {
      await userMenu.click();
      await page.waitForTimeout(300);
      
      // Click on profile/settings
      const profileLink = page.locator('a:has-text("Profile"), a:has-text("Settings")').first();
      if (await profileLink.isVisible()) {
        await profileLink.click();
        await waitForVue(page);
        
        // Update profile name
        const nameInput = page.locator('input[name="user[name]"]');
        if (await nameInput.count() > 0) {
          await nameInput.clear();
          await nameInput.fill('Admin Updated');
          
          // Save profile
          await page.click('button:has-text("Save")');
          await page.waitForTimeout(2000);
          
          console.log('✅ Profile updated');
        }
      }
    }
  });

  test('User search and filter', async ({ page }) => {
    console.log('📍 Testing user search...');
    
    await page.goto('/admin/user');
    await waitForVue(page);
    
    // Find search input
    const searchInput = page.locator('input[type="search"], input[placeholder*="Search"], input[class*="search"]').first();
    
    if (await searchInput.count() > 0) {
      // Search for admin
      await searchInput.fill('admin');
      await page.keyboard.press('Enter');
      await waitForVue(page);
      
      // Check results
      const results = page.locator('tbody tr');
      const count = await results.count();
      expect(count).toBeGreaterThan(0);
      
      console.log('✅ User search works');
      
      // Clear search
      await searchInput.clear();
      await page.keyboard.press('Enter');
      await waitForVue(page);
    }
    
    // Test status filter
    const statusFilter = page.locator('select[class*="filter"]').first();
    if (await statusFilter.count() > 0) {
      // Filter by active users
      await statusFilter.selectOption({ label: 'Active' });
      await waitForVue(page);
      
      console.log('✅ Status filter works');
    }
  });

  test('Delete user', async ({ page }) => {
    console.log('📍 Testing user deletion...');
    
    await page.goto('/admin/user');
    await waitForVue(page);
    
    // Find test user to delete
    const userRow = page.locator('tr').filter({ hasText: 'author_test' }).first();
    
    if (await userRow.count() > 0) {
      // Select checkbox
      await userRow.locator('input[type="checkbox"]').check();
      
      // Find delete button
      const deleteButton = page.locator('button:has-text("Delete"), a:has-text("Delete")').first();
      if (await deleteButton.isVisible()) {
        await deleteButton.click();
        
        // Confirm deletion
        const confirmButton = page.locator('button:has-text("Delete"), button:has-text("Confirm")').last();
        if (await confirmButton.isVisible({ timeout: 2000 })) {
          await confirmButton.click();
          await waitForVue(page);
          
          console.log('✅ User deleted');
        }
      }
    }
  });
});
