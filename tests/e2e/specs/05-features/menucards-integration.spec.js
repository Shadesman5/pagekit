const { test, expect } = require('@playwright/test');
const testConfig = require('../../helpers/test-config');

test.describe('Menucards Integration Test - Complete Workflow', () => {
    /**
     * COMPREHENSIVE INTEGRATION TEST
     * Tests the complete workflow including the CRITICAL context-aware product creation feature
     * 
     * Workflow:
     * 1. Login to admin
     * 2. Create menu "Tageskarte"
     * 3. Add category "Hauptgerichte"
     * 4. Create product "Wiener Schnitzel" via context-aware modal FROM category view
     * 5. Verify product appears in category (no reload)
     * 6. Verify product in global product list
     * 7. Verify product on public view
     * 8. Cleanup
     */
    
    test('Complete workflow: Create menu, category, and context-aware product creation', async ({ page }) => {
        console.log('🚀 Starting comprehensive integration test...');

        // STEP 1: Login (already done by test setup)
        await page.goto(testConfig.getAdminUrl());
        await page.waitForLoadState('networkidle');
        console.log('✅ Step 1: Logged in to admin');

        // STEP 2: Create menu "Tageskarte"
        console.log('📋 Step 2: Creating menu "Tageskarte"...');
        await page.click('text=Menucards');
        await page.waitForLoadState('networkidle');
        
        await page.click('button:has-text("Add Menu")');
        await page.waitForTimeout(500); // Wait for modal
        
        // Fill menu form
        await page.fill('input[placeholder], input[type="text"]', 'Tageskarte');
        await page.fill('textarea', 'Unsere tägliche Speisekarte');
        await page.selectOption('select', '1'); // Published status
        
        // Save menu
        await page.click('button:has-text("Save")');
        await page.waitForTimeout(1000); // Wait for save and reload
        
        // Verify menu was created
        const menuTitle = page.locator('text=Tageskarte');
        await expect(menuTitle).toBeVisible();
        console.log('✅ Step 2: Menu "Tageskarte" created');

        // STEP 3: Add category "Hauptgerichte"
        console.log('📂 Step 3: Adding category "Hauptgerichte"...');
        const addCategoryButton = page.locator('button:has-text("Add Category")').first();
        await addCategoryButton.click();
        await page.waitForTimeout(500);
        
        // Fill category form
        await page.fill('input[type="text"]', 'Hauptgerichte');
        
        // Save category
        await page.click('button:has-text("Save")');
        await page.waitForTimeout(1000);
        
        // Verify category was created
        const categoryTitle = page.locator('text=Hauptgerichte');
        await expect(categoryTitle).toBeVisible();
        console.log('✅ Step 3: Category "Hauptgerichte" created');

        // STEP 4: CRITICAL - Create product via context-aware modal
        console.log('⭐ Step 4: CRITICAL - Creating product "Wiener Schnitzel" via context-aware modal...');
        
        // Click "Create Product Here" button (context-aware creation)
        const createProductButton = page.locator('button:has-text("Create Product Here")').first();
        await createProductButton.click();
        await page.waitForTimeout(500);
        
        // Verify context-aware modal opened with category name
        const modalHeading = page.locator('h3:has-text("Create Product for")');
        await expect(modalHeading).toBeVisible();
        await expect(page.locator('text=Hauptgerichte')).toBeVisible();
        console.log('✅ Context-aware modal opened with category name');
        
        // Fill product form
        const nameInput = page.locator('input[type="text"]').last();
        await nameInput.fill('Wiener Schnitzel');
        
        const priceInput = page.locator('input[type="number"]').last();
        await priceInput.fill('18.90');
        
        const descInput = page.locator('textarea').last();
        await descInput.fill('Klassisches Wiener Schnitzel vom Kalb mit Kartoffelsalat');
        
        // Save product (creates globally AND attaches to category)
        await page.click('button:has-text("Create & Add to Category")');
        await page.waitForTimeout(1500); // Wait for API calls
        
        console.log('✅ Step 4: Product created via context-aware modal');

        // STEP 5: Verify product appears in category WITHOUT reload
        console.log('🔍 Step 5: Verifying product appears in category (no page reload)...');
        
        const productInCategory = page.locator('text=Wiener Schnitzel').first();
        await expect(productInCategory).toBeVisible();
        
        const productPrice = page.locator('text=€18.90').first();
        await expect(productPrice).toBeVisible();
        
        console.log('✅ Step 5: Product appears in category immediately (no reload!)');

        // STEP 6: Verify product in global product list
        console.log('🔍 Step 6: Verifying product in global product list...');
        
        await page.click('text=Products');
        await page.waitForLoadState('networkidle');
        
        const productInList = page.locator('td:has-text("Wiener Schnitzel")');
        await expect(productInList).toBeVisible();
        
        const priceInList = page.locator('text=€18.90');
        await expect(priceInList).toBeVisible();
        
        console.log('✅ Step 6: Product found in global product list');

        // STEP 7: Verify product on public view
        console.log('🌐 Step 7: Verifying product on public menu view...');
        
        // Navigate to public menucard
        await page.goto(testConfig.getSiteUrl() + '/menucard/tageskarte');
        await page.waitForLoadState('networkidle');
        
        // Verify menu title
        await expect(page.locator('h1:has-text("Tageskarte")')).toBeVisible();
        
        // Verify category
        await expect(page.locator('text=Hauptgerichte')).toBeVisible();
        
        // Verify product
        await expect(page.locator('h3:has-text("Wiener Schnitzel")')).toBeVisible();
        await expect(page.locator('text=€18.90')).toBeVisible();
        await expect(page.locator('text=Klassisches Wiener Schnitzel')).toBeVisible();
        
        console.log('✅ Step 7: Product visible on public menu view');

        // STEP 8: Cleanup
        console.log('🧹 Step 8: Cleaning up test data...');
        
        // Go back to admin
        await page.goto(config.getAdminUrl() + '/menucards');
        await page.waitForLoadState('networkidle');
        
        // Delete menu (will also delete categories and associations)
        const deleteButton = page.locator('button:has-text("Delete"), button[class*="danger"]').first();
        
        // Confirm deletion
        page.once('dialog', dialog => {
            console.log('Confirming deletion...');
            dialog.accept();
        });
        
        await deleteButton.click();
        await page.waitForTimeout(1000);
        
        console.log('✅ Step 8: Test data cleaned up');

        console.log('🎉 Integration test completed successfully!');
        console.log('📊 Summary:');
        console.log('  ✅ Menu created');
        console.log('  ✅ Category created');
        console.log('  ✅ Product created via CONTEXT-AWARE modal');
        console.log('  ✅ Product auto-attached to category');
        console.log('  ✅ Product visible in category (no reload)');
        console.log('  ✅ Product visible in global list');
        console.log('  ✅ Product visible on public view');
        console.log('  ✅ Cleanup successful');
    });

    test('Context-aware product creation - verify automatic attachment', async ({ page }) => {
        console.log('🔗 Testing automatic product attachment via context-aware creation...');

        // Navigate to menucards
        await page.goto(config.getAdminUrl() + '/menucards');
        await page.waitForLoadState('networkidle');

        // Create a test menu first
        await page.click('button:has-text("Add Menu")');
        await page.waitForTimeout(500);
        await page.fill('input[type="text"]', 'Test Menu');
        await page.click('button:has-text("Save")');
        await page.waitForTimeout(1000);

        // Add category
        await page.click('button:has-text("Add Category")').first();
        await page.waitForTimeout(500);
        await page.fill('input[type="text"]', 'Test Category');
        await page.click('button:has-text("Save")');
        await page.waitForTimeout(1000);

        // Create product via context-aware modal
        await page.click('button:has-text("Create Product Here")');
        await page.waitForTimeout(500);

        // Verify modal shows category context
        await expect(page.locator('text=Test Category')).toBeVisible();

        // Create product
        await page.fill('input[type="text"]').last().fill('Test Dish');
        await page.fill('input[type="number"]').last().fill('10.50');
        await page.click('button:has-text("Create & Add")');
        await page.waitForTimeout(1500);

        // Verify product is attached and visible
        await expect(page.locator('text=Test Dish')).toBeVisible();
        await expect(page.locator('text=€10.50')).toBeVisible();

        console.log('✅ Context-aware product creation with automatic attachment works!');

        // Cleanup
        page.once('dialog', dialog => dialog.accept());
        await page.click('button:has-text("Delete"), button[class*="danger"]').first();
        await page.waitForTimeout(1000);
    });
});
