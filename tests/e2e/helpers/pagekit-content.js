/**
 * Content management helper functions for Pagekit E2E tests
 */

const { expect } = require('@playwright/test');
const path = require('path');

/**
 * Create a new page
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} title - Page title
 * @param {string} content - Page content
 * @param {boolean} markdown - Use markdown editor
 */
async function createPage(page, title, content, markdown = false) {
  // Navigate to page creation
  await page.goto('/admin/site/page/edit');
  
  // Wait for page editor to load
  await page.waitForSelector('input[name="page[title]"]', { timeout: 10000 });
  
  // Fill in page title
  await page.fill('input[name="page[title]"]', title);
  
  // Select editor type
  if (markdown) {
    await page.click('select[name="page[data][markdown]"]');
    await page.selectOption('select[name="page[data][markdown]"]', '1');
  }
  
  // Fill in content
  const contentSelector = markdown ? 'textarea.uk-htmleditor-code' : '.uk-htmleditor-content';
  await page.waitForSelector(contentSelector);
  
  if (markdown) {
    await page.fill(contentSelector, content);
  } else {
    // For HTML editor, we need to interact with the iframe
    const frame = page.frameLocator('iframe.uk-htmleditor-iframe');
    await frame.locator('body').click();
    await frame.locator('body').fill(content);
  }
  
  // Save the page
  await page.click('button.uk-button-primary');
  
  // Wait for save confirmation
  await page.waitForSelector('.uk-notify-message', { timeout: 5000 });
  
  // Verify success message
  const message = await page.textContent('.uk-notify-message');
  expect(message).toContain('saved');
}

/**
 * Create a new blog post
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} title - Post title
 * @param {string} content - Post content
 * @param {string} status - Post status (draft, published, pending)
 */
async function createPost(page, title, content, status = 'published') {
  // Navigate to post creation
  await page.goto('/admin/blog/post/edit');
  
  // Wait for post editor to load
  await page.waitForSelector('input[name="post[title]"]', { timeout: 10000 });
  
  // Fill in post title
  await page.fill('input[name="post[title]"]', title);
  
  // Fill in content
  const contentArea = await page.$('textarea.uk-htmleditor-code');
  if (contentArea) {
    await contentArea.fill(content);
  } else {
    // Try TinyMCE or other editor
    const frame = page.frameLocator('iframe#post_content_ifr');
    await frame.locator('body').click();
    await frame.locator('body').fill(content);
  }
  
  // Set post status
  await page.selectOption('select[name="post[status]"]', status);
  
  // Save the post
  await page.click('button.uk-button-primary');
  
  // Wait for save confirmation
  await page.waitForSelector('.uk-notify-message', { timeout: 5000 });
  
  // Verify success message
  const message = await page.textContent('.uk-notify-message');
  expect(message).toContain('saved');
}

/**
 * Upload media file
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} filePath - Path to file to upload
 * @returns {Promise<string>} - URL of uploaded file
 */
async function uploadMedia(page, filePath) {
  // Navigate to media manager
  await page.goto('/admin/system/finder');
  
  // Wait for finder to load
  await page.waitForSelector('.pk-finder', { timeout: 10000 });
  
  // Click upload button
  await page.click('button[title="Upload"]');
  
  // Wait for upload dialog
  await page.waitForSelector('input[type="file"]', { visible: true });
  
  // Upload file
  const fileInput = await page.$('input[type="file"]');
  await fileInput.setInputFiles(filePath);
  
  // Wait for upload to complete
  await page.waitForSelector('.uk-notify-message', { timeout: 10000 });
  
  // Get uploaded file URL from success message or file list
  const uploadedFile = await page.$('.pk-finder-file.uk-active');
  const fileName = await uploadedFile.getAttribute('title');
  
  return `/storage/${fileName}`;
}

/**
 * Add a widget to a position
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} type - Widget type (text, menu, login, etc.)
 * @param {string} position - Widget position
 */
async function addWidget(page, type, position) {
  // Navigate to widgets page
  await page.goto('/admin/site/widget');
  
  // Click add widget button
  await page.click('button.uk-button-primary');
  
  // Wait for widget type selection
  await page.waitForSelector('.uk-modal', { visible: true });
  
  // Select widget type
  await page.click(`[data-widget="${type}"]`);
  
  // Wait for widget edit form
  await page.waitForSelector('input[name="widget[title]"]');
  
  // Fill in widget title
  await page.fill('input[name="widget[title]"]', `Test ${type} Widget`);
  
  // Select position
  await page.selectOption('select[name="widget[position]"]', position);
  
  // Save widget
  await page.click('button.uk-button-primary');
  
  // Wait for save confirmation
  await page.waitForSelector('.uk-notify-message', { timeout: 5000 });
}

/**
 * Edit existing page
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} pageTitle - Title of page to edit
 * @param {Object} updates - Updates to apply
 */
async function editPage(page, pageTitle, updates) {
  // Navigate to pages list
  await page.goto('/admin/site/page');
  
  // Find and click on the page to edit
  await page.click(`text="${pageTitle}"`);
  
  // Wait for editor to load
  await page.waitForSelector('input[name="page[title]"]');
  
  // Apply updates
  if (updates.title) {
    await page.fill('input[name="page[title]"]', updates.title);
  }
  
  if (updates.content) {
    const contentSelector = 'textarea.uk-htmleditor-code';
    const contentArea = await page.$(contentSelector);
    if (contentArea) {
      await contentArea.fill(updates.content);
    } else {
      const frame = page.frameLocator('iframe.uk-htmleditor-iframe');
      await frame.locator('body').click();
      await frame.locator('body').fill(updates.content);
    }
  }
  
  if (updates.status) {
    await page.selectOption('select[name="page[status]"]', updates.status);
  }
  
  // Save changes
  await page.click('button.uk-button-primary');
  
  // Wait for save confirmation
  await page.waitForSelector('.uk-notify-message', { timeout: 5000 });
}

/**
 * Delete a page
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} pageTitle - Title of page to delete
 */
async function deletePage(page, pageTitle) {
  // Navigate to pages list
  await page.goto('/admin/site/page');
  
  // Select the page
  await page.check(`input[type="checkbox"][value*="${pageTitle}"]`);
  
  // Click delete button
  await page.click('button[title="Delete"]');
  
  // Confirm deletion
  await page.click('.uk-modal-dialog button.uk-button-danger');
  
  // Wait for deletion confirmation
  await page.waitForSelector('.uk-notify-message', { timeout: 5000 });
}

/**
 * Publish/unpublish content
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} contentTitle - Title of content
 * @param {boolean} publish - True to publish, false to unpublish
 */
async function togglePublish(page, contentTitle, publish = true) {
  // This would depend on the content type and UI
  // Implementation would vary based on Pagekit's actual UI
  
  // Navigate to content list
  await page.goto('/admin/site/page');
  
  // Find content and toggle status
  const row = await page.locator(`tr:has-text("${contentTitle}")`);
  const statusToggle = await row.locator('.uk-button-toggle');
  
  const isPublished = await statusToggle.getAttribute('class').then(cls => cls.includes('uk-active'));
  
  if ((publish && !isPublished) || (!publish && isPublished)) {
    await statusToggle.click();
    
    // Wait for status update
    await page.waitForTimeout(1000);
  }
}

/**
 * Search for content
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} searchTerm - Search term
 * @returns {Promise<Array>} - Array of search results
 */
async function searchContent(page, searchTerm) {
  // Navigate to content list
  await page.goto('/admin/site/page');
  
  // Enter search term
  await page.fill('input[type="search"]', searchTerm);
  await page.press('input[type="search"]', 'Enter');
  
  // Wait for results
  await page.waitForTimeout(1000);
  
  // Extract results
  const results = await page.$$eval('tbody tr', rows => {
    return rows.map(row => {
      const title = row.querySelector('td:nth-child(2)')?.textContent?.trim();
      const status = row.querySelector('td:nth-child(3)')?.textContent?.trim();
      return { title, status };
    });
  });
  
  return results;
}

module.exports = {
  createPage,
  createPost,
  uploadMedia,
  addWidget,
  editPage,
  deletePage,
  togglePublish,
  searchContent
};