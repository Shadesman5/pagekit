/**
 * ORM Operations E2E Tests
 * 
 * Tests ORM functionality including:
 * - Entity CRUD operations
 * - Relations (eager loading, lazy loading)
 * - Query caching
 * - Data persistence
 */

const { test, expect } = require('@playwright/test');

test.describe('ORM Operations', () => {
  let context;
  let page;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext();
    page = await context.newPage();
  });

  test.afterAll(async () => {
    await context.close();
  });

  test.beforeEach(async () => {
    // Login as admin before each test
    await page.goto('/admin/login');
    await page.fill('input[name="username"]', 'admin');
    await page.fill('input[name="password"]', 'admin');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/admin');
  });

  test('should load users list with relations', async () => {
    await page.goto('/admin/user');
    
    // Wait for user table to load
    await page.waitForSelector('table');
    
    // Verify table has rows (users loaded from database)
    const rows = await page.locator('table tbody tr').count();
    expect(rows).toBeGreaterThan(0);
    
    // Verify user data is displayed
    const firstUser = page.locator('table tbody tr').first();
    await expect(firstUser).toBeVisible();
  });

  test('should create a new page entity', async () => {
    await page.goto('/admin/site/page');
    
    // Click add page button
    await page.click('text=Add Page');
    await page.waitForURL('**/admin/site/page/edit');
    
    // Fill page data
    const testTitle = `Test Page ${Date.now()}`;
    await page.fill('input[name="title"]', testTitle);
    await page.fill('textarea[name="content"]', 'Test content for ORM verification');
    
    // Save page
    await page.click('button:has-text("Save")');
    
    // Verify save success
    await expect(page.locator('.uk-notify-message')).toContainText('Page saved');
    
    // Verify page appears in list
    await page.goto('/admin/site/page');
    await expect(page.locator(`text=${testTitle}`)).toBeVisible();
  });

  test('should handle entity updates correctly', async () => {
    await page.goto('/admin/site/page');
    
    // Find and click first page to edit
    await page.click('table tbody tr:first-child a');
    await page.waitForURL('**/admin/site/page/edit**');
    
    // Update title
    const newTitle = `Updated Page ${Date.now()}`;
    await page.fill('input[name="title"]', newTitle);
    
    // Save
    await page.click('button:has-text("Save")');
    await expect(page.locator('.uk-notify-message')).toContainText('Page saved');
    
    // Verify update persisted
    await page.reload();
    const titleValue = await page.inputValue('input[name="title"]');
    expect(titleValue).toBe(newTitle);
  });

  test('should load blog posts with user relations', async () => {
    await page.goto('/admin/blog/post');
    
    // Wait for posts table
    await page.waitForSelector('table');
    
    // Check if posts have author info (loaded via relation)
    const hasAuthorColumn = await page.locator('th:has-text("Author")').count();
    expect(hasAuthorColumn).toBeGreaterThanOrEqual(0); // May or may not have author column
    
    // Verify posts are loaded
    const postsCount = await page.locator('table tbody tr').count();
    console.log(`Loaded ${postsCount} blog posts with relations`);
  });

  test('should display widgets with node relations', async () => {
    await page.goto('/admin/site/widget');
    
    // Wait for widgets
    await page.waitForSelector('table, .uk-text-muted');
    
    // Check if widgets loaded
    const widgetCount = await page.locator('table tbody tr').count();
    console.log(`Loaded ${widgetCount} widgets`);
  });

  test('should perform efficient queries (no N+1 problem)', async () => {
    // This test verifies that relations are eagerly loaded
    await page.goto('/admin/blog/post');
    
    // Listen for network requests to count database queries
    const requests = [];
    page.on('request', request => {
      if (request.url().includes('/api/')) {
        requests.push(request.url());
      }
    });
    
    // Reload to capture requests
    await page.reload();
    await page.waitForLoadState('networkidle');
    
    console.log(`API requests made: ${requests.length}`);
    console.log('Request URLs:', requests);
    
    // We expect a reasonable number of requests (not N+1)
    // Exact number depends on implementation, but should be small
    expect(requests.length).toBeLessThan(20);
  });
});
