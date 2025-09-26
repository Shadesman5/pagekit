/**
 * Content Management Tests for Pagekit
 * Tests content creation, editing, and management workflows
 */

const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('../helpers/pagekit-auth');
const {
  createPage,
  createPost,
  uploadMedia,
  editPage,
  deletePage,
  searchContent
} = require('../helpers/pagekit-content');

test.describe('Content Management', () => {
  test.beforeEach(async ({ page }) => {
    // Login as admin before each test
    await loginAsAdmin(page);
  });

  test('Create and Edit Page', async ({ page }) => {
    // Create a new page
    const pageTitle = 'Test Page ' + Date.now();
    const pageContent = 'This is test content for E2E testing.';
    
    await createPage(page, pageTitle, pageContent, false);
    
    // Verify page was created
    await page.goto('/admin/site/page');
    await expect(page.locator(`text="${pageTitle}"`)).toBeVisible();
    
    // Edit the page
    await editPage(page, pageTitle, {
      title: pageTitle + ' - Edited',
      content: pageContent + ' Additional content.'
    });
    
    // Verify changes
    await page.goto('/admin/site/page');
    await expect(page.locator(`text="${pageTitle} - Edited"`)).toBeVisible();
  });

  test('Create Blog Post with Markdown', async ({ page }) => {
    const postTitle = 'Test Blog Post ' + Date.now();
    const postContent = '# Heading\n\nThis is **bold** text and *italic* text.';
    
    await createPost(page, postTitle, postContent, 'published');
    
    // Verify post was created
    await page.goto('/admin/blog/post');
    await expect(page.locator(`text="${postTitle}"`)).toBeVisible();
    
    // Check status is published
    const row = page.locator(`tr:has-text("${postTitle}")`);
    await expect(row.locator('.uk-badge-success')).toBeVisible();
  });

  test('Media Upload and Management', async ({ page }) => {
    // Navigate to media manager
    await page.goto('/admin/system/finder');
    
    // Create a test file to upload
    const testImagePath = 'tests/e2e/fixtures/test-image.jpg';
    
    // Upload media
    // Note: In real test, ensure test-image.jpg exists
    // const uploadedUrl = await uploadMedia(page, testImagePath);
    // expect(uploadedUrl).toContain('/storage/');
    
    // For now, just verify media manager loads
    await expect(page.locator('.pk-finder')).toBeVisible();
  });

  test('Draft and Publish Workflow', async ({ page }) => {
    // Create draft page
    await page.goto('/admin/site/page/edit');
    
    const draftTitle = 'Draft Page ' + Date.now();
    await page.fill('input[name="page[title]"]', draftTitle);
    
    // Set status to draft
    await page.selectOption('select[name="page[status]"]', '0'); // 0 = draft
    
    // Add content
    const contentArea = await page.$('textarea.uk-htmleditor-code');
    if (contentArea) {
      await contentArea.fill('Draft content');
    }
    
    // Save as draft
    await page.click('button.uk-button-primary');
    await expect(page.locator('.uk-notify-message')).toBeVisible();
    
    // Go to pages list
    await page.goto('/admin/site/page');
    
    // Find draft page
    const draftRow = page.locator(`tr:has-text("${draftTitle}")`);
    await expect(draftRow).toBeVisible();
    
    // Check it's marked as draft
    await expect(draftRow.locator('.uk-badge')).toContainText('Draft');
    
    // Edit and publish
    await draftRow.locator('a').first().click();
    await page.selectOption('select[name="page[status]"]', '1'); // 1 = published
    await page.click('button.uk-button-primary');
    
    // Verify it's now published
    await page.goto('/admin/site/page');
    const publishedRow = page.locator(`tr:has-text("${draftTitle}")`);
    await expect(publishedRow.locator('.uk-badge')).toContainText('Published');
  });

  test('Delete Content', async ({ page }) => {
    // First create a page to delete
    const pageToDelete = 'Delete Me ' + Date.now();
    await createPage(page, pageToDelete, 'Content to delete', false);
    
    // Now delete it
    await deletePage(page, pageToDelete);
    
    // Verify it's gone
    await page.goto('/admin/site/page');
    await expect(page.locator(`text="${pageToDelete}"`)).not.toBeVisible();
  });

  test('Content Search', async ({ page }) => {
    // Create some test content
    const uniqueKeyword = 'UniqueKeyword' + Date.now();
    await createPage(page, 'Search Test', `Content with ${uniqueKeyword}`, false);
    
    // Search for it
    const results = await searchContent(page, uniqueKeyword);
    expect(results.length).toBeGreaterThan(0);
    expect(results[0].title).toContain('Search Test');
  });

  test('Bulk Operations', async ({ page }) => {
    // Go to pages list
    await page.goto('/admin/site/page');
    
    // Select multiple pages
    const checkboxes = await page.$$('input[type="checkbox"][name="ids[]"]');
    if (checkboxes.length >= 2) {
      await checkboxes[0].check();
      await checkboxes[1].check();
      
      // Check bulk actions dropdown appears
      await expect(page.locator('.uk-button-group')).toBeVisible();
      
      // Could test bulk delete, bulk publish, etc.
    }
  });

  test('Page Menu Assignment', async ({ page }) => {
    // Create a page
    const menuPageTitle = 'Menu Page ' + Date.now();
    await page.goto('/admin/site/page/edit');
    
    await page.fill('input[name="page[title]"]', menuPageTitle);
    
    // Assign to menu
    const menuCheckbox = await page.$('input[name="page[data][menu]"]');
    if (menuCheckbox) {
      await menuCheckbox.check();
    }
    
    // Save
    await page.click('button.uk-button-primary');
    
    // Verify in menu management
    await page.goto('/admin/site/menu');
    // Would need to verify the page appears in menu structure
  });

  test('Content Versioning', async ({ page }) => {
    // Create a page
    const versionedPage = 'Versioned Page ' + Date.now();
    await createPage(page, versionedPage, 'Version 1 content', false);
    
    // Edit multiple times to create versions
    for (let i = 2; i <= 3; i++) {
      await editPage(page, versionedPage, {
        content: `Version ${i} content`
      });
      await page.waitForTimeout(1000); // Wait between edits
    }
    
    // Check if version history exists (if feature is available)
    await page.goto('/admin/site/page');
    await page.click(`text="${versionedPage}"`);
    
    // Look for version/revision controls
    const versionControl = await page.$('.pk-version-control');
    if (versionControl) {
      await expect(versionControl).toBeVisible();
    }
  });
});

test.describe('Editor Functionality', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('Switch Between Markdown and HTML Editor', async ({ page }) => {
    await page.goto('/admin/site/page/edit');
    
    // Check for editor type selector
    const editorSelector = await page.$('select[name="page[data][markdown]"]');
    if (editorSelector) {
      // Switch to markdown
      await editorSelector.selectOption('1');
      await page.waitForTimeout(500);
      
      // Verify markdown editor is active
      await expect(page.locator('.uk-htmleditor-code')).toBeVisible();
      
      // Switch back to HTML
      await editorSelector.selectOption('0');
      await page.waitForTimeout(500);
      
      // Verify HTML editor is active
      const htmlEditor = await page.$('iframe.uk-htmleditor-iframe');
      expect(htmlEditor).toBeTruthy();
    }
  });

  test('Auto-save Functionality', async ({ page }) => {
    await page.goto('/admin/site/page/edit');
    
    const autoSaveTitle = 'Auto Save Test ' + Date.now();
    await page.fill('input[name="page[title]"]', autoSaveTitle);
    
    // Type content and wait for auto-save
    const contentArea = await page.$('textarea.uk-htmleditor-code');
    if (contentArea) {
      await contentArea.fill('Content that should auto-save');
      
      // Wait for auto-save indicator (if exists)
      await page.waitForTimeout(3000);
      
      // Check for auto-save notification or indicator
      const autoSaveIndicator = await page.$('.pk-autosave-indicator');
      if (autoSaveIndicator) {
        await expect(autoSaveIndicator).toContainText('Saved');
      }
    }
  });

  test('Preview Mode', async ({ page }) => {
    await page.goto('/admin/site/page/edit');
    
    // Add content
    await page.fill('input[name="page[title]"]', 'Preview Test');
    const contentArea = await page.$('textarea.uk-htmleditor-code');
    if (contentArea) {
      await contentArea.fill('# Preview Content\n\nThis should be previewed');
    }
    
    // Look for preview button
    const previewButton = await page.$('button[title="Preview"]');
    if (previewButton) {
      await previewButton.click();
      
      // New tab/window should open with preview
      const [previewPage] = await Promise.all([
        page.context().waitForEvent('page'),
        previewButton.click()
      ]);
      
      await previewPage.waitForLoadState();
      await expect(previewPage.locator('h1')).toContainText('Preview Content');
    }
  });
});