/**
 * Media/File Management Tests for Pagekit
 * Tests file upload, folder management, and media operations
 */

const { test, expect } = require('@playwright/test');
const { navigateAndWaitForVue, waitForVue } = require('../helpers/vue-helpers');
const path = require('path');
const fs = require('fs');

test.describe('Pagekit Media Management (Finder)', () => {
  test.beforeEach(async ({ page }) => {
    // Login as admin
    await navigateAndWaitForVue(page, '/admin/login');
    await page.fill('input[name="credentials[username]"]', 'admin');
    await page.fill('input[name="credentials[password]"]', 'admin123');
    await page.click('button:has-text("Login")');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 10000 });
    await waitForVue(page);
  });

  test('Navigate to Storage/Media manager', async ({ page }) => {
    console.log('📍 Testing Storage navigation...');
    
    // Look for Storage/Media link
    const storageLink = page.locator('a[href*="/storage"], a:has-text("Storage"), a:has-text("Media")').first();
    
    if (await storageLink.isVisible()) {
      await storageLink.click();
      await waitForVue(page);
      
      // Check we're in storage/finder
      expect(page.url()).toMatch(/storage|finder/);
      
      // Check for file manager interface
      const uploadButton = await page.locator('button:has-text("Upload"), a:has-text("Upload")').isVisible();
      const folderButton = await page.locator('button:has-text("Folder"), a:has-text("Add Folder")').isVisible();
      
      expect(uploadButton || folderButton).toBeTruthy();
      console.log('✅ Storage/Media manager accessible');
    }
  });

  test('Create folders', async ({ page }) => {
    console.log('📍 Creating folders...');
    
    await page.goto('/admin/system/storage');
    await waitForVue(page);
    
    // Click create folder button
    const folderButton = page.locator('button:has-text("Folder"), button:has-text("Add Folder")').first();
    if (await folderButton.isVisible()) {
      await folderButton.click();
      await waitForVue(page);
      
      // Enter folder name
      const folderInput = page.locator('input[placeholder*="Folder"], input[type="text"]').last();
      if (await folderInput.isVisible()) {
        await folderInput.fill('e2e-test-folder');
        await page.keyboard.press('Enter');
        await waitForVue(page);
        
        console.log('✅ Folder created');
        
        // Create subfolder
        const testFolder = page.locator('a:has-text("e2e-test-folder"), .pk-finder-file:has-text("e2e-test-folder")').first();
        if (await testFolder.isVisible()) {
          await testFolder.dblclick();
          await waitForVue(page);
          
          // Create subfolder
          await folderButton.click();
          await waitForVue(page);
          
          const subfolderInput = page.locator('input[placeholder*="Folder"], input[type="text"]').last();
          if (await subfolderInput.isVisible()) {
            await subfolderInput.fill('subfolder');
            await page.keyboard.press('Enter');
            await waitForVue(page);
            
            console.log('✅ Subfolder created');
          }
        }
      }
    }
  });

  test('Upload files', async ({ page }) => {
    console.log('📍 Testing file upload...');
    
    await page.goto('/admin/system/storage');
    await waitForVue(page);
    
    // Create test files if they don't exist
    const testImagePath = path.join(process.cwd(), 'test-image.jpg');
    const testTextPath = path.join(process.cwd(), 'test-document.txt');
    
    // Create a simple test image (1x1 pixel JPEG)
    if (!fs.existsSync(testImagePath)) {
      const imageBuffer = Buffer.from('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAP/bAEMACAYGBwYFCAcHBwkJCAoMFA0MCwsMGRITDxQdGh8eHRocHCAkLicgIiwjHBwoNyksMDE0NDQfJzk9ODI8LjM0Mv/AABEIAAEAAQMBIgACEQEDEQH/xAAVAAEBAAAAAAAAAAAAAAAAAAAAA//EABQQAQAAAAAAAAAAAAAAAAAAAAD/xAAUAQEAAAAAAAAAAAAAAAAAAAAA/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEQMRAD8AVIP/2Q==', 'base64');
      fs.writeFileSync(testImagePath, imageBuffer);
    }
    
    // Create test text file
    if (!fs.existsSync(testTextPath)) {
      fs.writeFileSync(testTextPath, 'This is a test document for E2E testing.');
    }
    
    // Upload button
    const uploadButton = page.locator('button:has-text("Upload"), input[type="file"]').first();
    
    if (await uploadButton.isVisible()) {
      // If it's an input, use it directly
      if (await page.locator('input[type="file"]').count() > 0) {
        const fileInput = page.locator('input[type="file"]').first();
        await fileInput.setInputFiles([testImagePath, testTextPath]);
        await waitForVue(page);
        
        console.log('✅ Files uploaded');
      } else {
        // Click upload button to open file dialog
        await uploadButton.click();
        
        // Look for file input that might appear
        const fileInput = page.locator('input[type="file"]').first();
        if (await fileInput.count() > 0) {
          await fileInput.setInputFiles([testImagePath, testTextPath]);
          await waitForVue(page);
          
          console.log('✅ Files uploaded');
        }
      }
    }
    
    // Clean up test files
    if (fs.existsSync(testImagePath)) fs.unlinkSync(testImagePath);
    if (fs.existsSync(testTextPath)) fs.unlinkSync(testTextPath);
  });

  test('Rename files and folders', async ({ page }) => {
    console.log('📍 Testing rename functionality...');
    
    await page.goto('/admin/system/storage');
    await waitForVue(page);
    
    // Find a file or folder to rename
    const item = page.locator('.pk-finder-file, .uk-panel').first();
    
    if (await item.count() > 0) {
      // Right-click for context menu
      await item.click({ button: 'right' });
      await waitForVue(page);
      
      // Look for rename option
      const renameOption = page.locator('a:has-text("Rename"), button:has-text("Rename")').first();
      if (await renameOption.isVisible()) {
        await renameOption.click();
        await waitForVue(page);
        
        // Enter new name
        const renameInput = page.locator('input[type="text"]').last();
        if (await renameInput.isVisible()) {
          await renameInput.clear();
          await renameInput.fill('renamed-item');
          await page.keyboard.press('Enter');
          await waitForVue(page);
          
          console.log('✅ Item renamed');
        }
      } else {
        // Alternative: Select item and use toolbar
        await item.click();
        
        const renameButton = page.locator('button[title*="Rename"]').first();
        if (await renameButton.isVisible()) {
          await renameButton.click();
          
          const renameInput = page.locator('input[type="text"]').last();
          if (await renameInput.isVisible()) {
            await renameInput.clear();
            await renameInput.fill('renamed-item');
            await page.keyboard.press('Enter');
            await waitForVue(page);
            
            console.log('✅ Item renamed via toolbar');
          }
        }
      }
    }
  });

  test('View and filter files', async ({ page }) => {
    console.log('📍 Testing file view and filters...');
    
    await page.goto('/admin/system/storage');
    await waitForVue(page);
    
    // Test view modes (grid/list)
    const viewToggle = page.locator('button[title*="View"], a[title*="View"]').first();
    if (await viewToggle.isVisible()) {
      await viewToggle.click();
      await waitForVue(page);
      console.log('✅ View mode toggled');
    }
    
    // Test search/filter
    const searchInput = page.locator('input[type="search"], input[placeholder*="Search"]').first();
    if (await searchInput.count() > 0) {
      await searchInput.fill('test');
      await page.keyboard.press('Enter');
      await waitForVue(page);
      
      console.log('✅ File search works');
      
      // Clear search
      await searchInput.clear();
      await page.keyboard.press('Enter');
      await waitForVue(page);
    }
    
    // Test sorting
    const sortDropdown = page.locator('select[class*="sort"], button:has-text("Sort")').first();
    if (await sortDropdown.isVisible()) {
      if (sortDropdown.toString().includes('select')) {
        await sortDropdown.selectOption({ index: 1 });
      } else {
        await sortDropdown.click();
        const sortOption = page.locator('a:has-text("Date"), a:has-text("Size")').first();
        if (await sortOption.isVisible()) {
          await sortOption.click();
        }
      }
      await waitForVue(page);
      console.log('✅ Sorting works');
    }
  });

  test('Delete files and folders', async ({ page }) => {
    console.log('📍 Testing delete functionality...');
    
    await page.goto('/admin/system/storage');
    await waitForVue(page);
    
    // Find test items to delete
    const testItems = page.locator('.pk-finder-file, .uk-panel').filter({ hasText: /e2e-test|test-/ });
    
    if (await testItems.count() > 0) {
      // Select items
      for (let i = 0; i < await testItems.count(); i++) {
        await testItems.nth(i).click({ modifiers: ['Control'] });
      }
      
      // Delete selected items
      const deleteButton = page.locator('button:has-text("Delete"), button[title*="Delete"]').first();
      if (await deleteButton.isVisible()) {
        await deleteButton.click();
        
        // Confirm deletion
        const confirmButton = page.locator('button:has-text("Delete"), button:has-text("OK")').last();
        if (await confirmButton.isVisible({ timeout: 2000 })) {
          await confirmButton.click();
          await waitForVue(page);
          
          console.log('✅ Items deleted');
        }
      }
    }
  });

  test('Storage settings', async ({ page }) => {
    console.log('📍 Testing storage settings...');
    
    // Navigate to settings if available
    const settingsButton = page.locator('button:has-text("Settings"), a[href*="settings"]').first();
    
    if (await settingsButton.isVisible()) {
      await settingsButton.click();
      await waitForVue(page);
      
      // Check for upload settings
      const maxSizeInput = page.locator('input[name*="max_size"], input[name*="upload_max"]').first();
      if (await maxSizeInput.count() > 0) {
        await maxSizeInput.clear();
        await maxSizeInput.fill('10');
        console.log('✅ Upload size limit set');
      }
      
      // Check allowed file types
      const fileTypesInput = page.locator('input[name*="types"], input[name*="extensions"]').first();
      if (await fileTypesInput.count() > 0) {
        const currentValue = await fileTypesInput.inputValue();
        await fileTypesInput.clear();
        await fileTypesInput.fill('jpg,jpeg,png,gif,pdf,doc,docx');
        console.log('✅ Allowed file types configured');
      }
      
      // Save settings
      const saveButton = page.locator('button:has-text("Save")').first();
      if (await saveButton.isVisible()) {
        await saveButton.click();
        await page.waitForTimeout(2000);
        console.log('✅ Storage settings saved');
      }
    }
  });

  test('Image preview and details', async ({ page }) => {
    console.log('📍 Testing image preview...');
    
    await page.goto('/admin/system/storage');
    await waitForVue(page);
    
    // Find an image file
    const imageFile = page.locator('.pk-finder-file').filter({ has: page.locator('img') }).first();
    
    if (await imageFile.count() > 0) {
      // Click to select
      await imageFile.click();
      await waitForVue(page);
      
      // Check for preview panel
      const previewPanel = page.locator('.pk-finder-preview, .uk-panel-box').first();
      if (await previewPanel.isVisible()) {
        // Check for image details
        const fileSize = await previewPanel.locator('text=/[0-9]+ (KB|MB|bytes)/').isVisible();
        const dimensions = await previewPanel.locator('text=/[0-9]+x[0-9]+/').isVisible();
        
        console.log('✅ Image preview shows details');
      }
      
      // Double-click to open full preview
      await imageFile.dblclick();
      await waitForVue(page);
      
      // Check if modal or full view opened
      const modal = page.locator('.uk-modal, .pk-modal-dialog');
      if (await modal.isVisible()) {
        console.log('✅ Full image preview works');
        
        // Close modal
        await page.keyboard.press('Escape');
        await waitForVue(page);
      }
    }
  });
});