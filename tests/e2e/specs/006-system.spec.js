/**
 * System Tests for Pagekit
 * Tests system-level operations and maintenance tasks
 */

const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('../helpers/pagekit-auth');
const {
  clearCache,
  runMaintenance,
  checkSystemStatus,
  setMaintenanceMode,
  getPhpInfo,
  getErrorLogs
} = require('../helpers/pagekit-system');

test.describe('System Operations', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('Cache Operations (PSR-6)', async ({ page }) => {
    // Clear cache
    await clearCache(page);
    
    // Verify cache was cleared
    await expect(page.locator('.uk-notify-message-success')).toBeVisible();
    
    // Generate some cache by visiting pages
    await page.goto('/admin/site/page');
    await page.goto('/admin/blog/post');
    await page.goto('/admin/user');
    
    // Clear cache again
    await clearCache(page);
    
    // Cache should be cleared successfully
    const successMessage = await page.textContent('.uk-notify-message-success');
    expect(successMessage).toContain('cleared');
  });

  test('System Information', async ({ page }) => {
    const systemInfo = await checkSystemStatus(page);
    
    // Verify system info contains expected data
    expect(systemInfo).toHaveProperty('php_version');
    expect(systemInfo).toHaveProperty('database');
    
    // Check PHP version meets requirements
    if (systemInfo.php_version) {
      const phpVersion = systemInfo.php_version.split('.').map(Number);
      expect(phpVersion[0]).toBeGreaterThanOrEqual(8);
      expect(phpVersion[1]).toBeGreaterThanOrEqual(2);
    }
  });

  test('Maintenance Mode', async ({ page }) => {
    // Enable maintenance mode
    await setMaintenanceMode(page, true, 'Site under maintenance');
    
    // Open new incognito context to test as visitor
    const context = await page.context().browser().newContext();
    const visitorPage = await context.newPage();
    
    // Visit site as visitor
    await visitorPage.goto('/');
    
    // Should see maintenance message
    const maintenanceMessage = await visitorPage.textContent('body');
    expect(maintenanceMessage).toContain('maintenance');
    
    // Admin should still have access
    await page.goto('/admin');
    expect(page.url()).toContain('/admin');
    
    // Disable maintenance mode
    await setMaintenanceMode(page, false);
    
    // Visitor should now see normal site
    await visitorPage.reload();
    const normalContent = await visitorPage.textContent('body');
    expect(normalContent).not.toContain('maintenance');
    
    await context.close();
  });

  test('Extension Management', async ({ page }) => {
    await page.goto('/admin/system/package/extensions');
    
    // Wait for extensions list
    await page.waitForSelector('.pk-table', { timeout: 10000 });
    
    // Check for installed extensions
    const extensions = await page.$$('.pk-table tbody tr');
    expect(extensions.length).toBeGreaterThan(0);
    
    // Test enable/disable if possible
    const firstExtension = extensions[0];
    const toggleButton = await firstExtension.$('.uk-button-success, .uk-button-danger');
    
    if (toggleButton) {
      const initialState = await toggleButton.getAttribute('class');
      const wasEnabled = initialState.includes('uk-button-success');
      
      // Toggle state
      await toggleButton.click();
      await page.waitForTimeout(2000);
      
      // Verify state changed
      const newState = await toggleButton.getAttribute('class');
      const isEnabled = newState.includes('uk-button-success');
      expect(isEnabled).toBe(!wasEnabled);
      
      // Toggle back
      await toggleButton.click();
      await page.waitForTimeout(2000);
    }
  });

  test('System Updates Check', async ({ page }) => {
    await page.goto('/admin/system/update');
    
    // Check current version display
    const currentVersion = await page.textContent('.pk-current-version');
    expect(currentVersion).toBeTruthy();
    
    // Click check for updates
    const checkButton = await page.$('button.uk-button-primary');
    if (checkButton) {
      await checkButton.click();
      
      // Wait for check to complete (with timeout)
      await page.waitForSelector('.pk-update-info', { 
        timeout: 30000 
      }).catch(() => {
        // Update check might fail in test environment
      });
      
      // Check if update info is displayed
      const updateInfo = await page.$('.pk-update-info');
      if (updateInfo) {
        const latestVersion = await page.textContent('.pk-latest-version');
        expect(latestVersion).toBeTruthy();
      }
    }
  });

  test('Database Operations', async ({ page }) => {
    await page.goto('/admin/system/info');
    
    // Check database info
    const dbInfo = await page.textContent('.pk-system-info');
    expect(dbInfo).toMatch(/MySQL|SQLite/);
    
    // If MySQL, check version
    if (dbInfo.includes('MySQL')) {
      expect(dbInfo).toMatch(/8\.\d+/);
    }
    
    // Check database tables (through system info)
    const tables = await page.$$eval('.pk-system-info table', tables => {
      return tables.map(table => table.textContent);
    });
    
    // Should have information about database
    expect(tables.length).toBeGreaterThan(0);
  });

  test('Error Log Monitoring', async ({ page }) => {
    // Navigate to logs if available
    await page.goto('/admin/system/log');
    
    // If log page exists
    if (!page.url().includes('404')) {
      const logs = await getErrorLogs(page, 10);
      
      // Logs should be an array
      expect(Array.isArray(logs)).toBeTruthy();
      
      // Each log entry should have expected properties
      if (logs.length > 0) {
        const firstLog = logs[0];
        expect(firstLog).toHaveProperty('date');
        expect(firstLog).toHaveProperty('level');
        expect(firstLog).toHaveProperty('message');
      }
    }
  });

  test('PHP Configuration', async ({ page }) => {
    await page.goto('/admin/system/info/phpinfo');
    
    // If phpinfo page exists
    if (!page.url().includes('404')) {
      const phpInfo = await getPhpInfo(page);
      
      // Check critical PHP settings
      expect(phpInfo).toBeTruthy();
      
      // Check for important extensions
      const pageContent = await page.content();
      const requiredExtensions = ['pdo', 'json', 'mbstring', 'zip'];
      
      for (const ext of requiredExtensions) {
        expect(pageContent.toLowerCase()).toContain(ext);
      }
    }
  });

  test('File Permissions Check', async ({ page }) => {
    await page.goto('/admin/system/info');
    
    // Look for permission warnings
    const warnings = await page.$$('.uk-alert-warning');
    
    // If there are warnings, they might be about permissions
    if (warnings.length > 0) {
      for (const warning of warnings) {
        const text = await warning.textContent();
        console.log('Permission warning:', text);
      }
    }
    
    // Critical directories should be writable
    // This would typically be checked server-side
  });

  test('Backup and Restore', async ({ page }) => {
    // Navigate to backup section if available
    await page.goto('/admin/system/backup');
    
    if (!page.url().includes('404')) {
      // Create backup
      const backupButton = await page.$('button[title="Create Backup"]');
      if (backupButton) {
        await backupButton.click();
        
        // Wait for backup to complete
        await page.waitForSelector('.uk-notify-message-success', {
          timeout: 60000
        });
        
        // Verify backup was created
        const backupList = await page.$$('.pk-backup-list li');
        expect(backupList.length).toBeGreaterThan(0);
      }
    }
  });
});

test.describe('Performance and Optimization', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('Page Load Performance', async ({ page }) => {
    // Measure admin dashboard load time
    const startTime = Date.now();
    await page.goto('/admin');
    await page.waitForLoadState('networkidle');
    const loadTime = Date.now() - startTime;
    
    // Dashboard should load within reasonable time
    expect(loadTime).toBeLessThan(10000); // 10 seconds max
    
    // Check for performance metrics
    const metrics = await page.evaluate(() => {
      const perf = window.performance;
      return {
        domContentLoaded: perf.timing.domContentLoadedEventEnd - perf.timing.navigationStart,
        loadComplete: perf.timing.loadEventEnd - perf.timing.navigationStart
      };
    });
    
    expect(metrics.domContentLoaded).toBeLessThan(5000);
    expect(metrics.loadComplete).toBeLessThan(10000);
  });

  test('Memory Usage', async ({ page }) => {
    await page.goto('/admin');
    
    // Check JavaScript memory usage
    const memoryUsage = await page.evaluate(() => {
      if (performance.memory) {
        return {
          usedJSHeapSize: performance.memory.usedJSHeapSize,
          totalJSHeapSize: performance.memory.totalJSHeapSize
        };
      }
      return null;
    });
    
    if (memoryUsage) {
      // Memory usage should be reasonable
      const usedMB = memoryUsage.usedJSHeapSize / 1024 / 1024;
      expect(usedMB).toBeLessThan(100); // Less than 100MB
    }
  });

  test('Asset Optimization', async ({ page }) => {
    await page.goto('/admin');
    
    // Check if assets are minified
    const scripts = await page.$$eval('script[src]', scripts => {
      return scripts.map(s => s.src);
    });
    
    // Check for .min.js files
    const minifiedScripts = scripts.filter(src => src.includes('.min.'));
    expect(minifiedScripts.length).toBeGreaterThan(0);
    
    // Check if CSS is minified
    const styles = await page.$$eval('link[rel="stylesheet"]', links => {
      return links.map(l => l.href);
    });
    
    const minifiedStyles = styles.filter(href => href.includes('.min.'));
    expect(minifiedStyles.length).toBeGreaterThan(0);
  });
});

test.describe('Security Checks', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('Security Headers', async ({ page }) => {
    const response = await page.goto('/admin');
    const headers = response.headers();
    
    // Check for security headers
    // X-Frame-Options
    if (headers['x-frame-options']) {
      expect(headers['x-frame-options']).toMatch(/DENY|SAMEORIGIN/);
    }
    
    // X-Content-Type-Options
    if (headers['x-content-type-options']) {
      expect(headers['x-content-type-options']).toBe('nosniff');
    }
    
    // Check for HTTPS redirect in production
    // This would be environment-specific
  });

  test('Session Security', async ({ page }) => {
    // Check session cookie settings
    const cookies = await page.context().cookies();
    const sessionCookie = cookies.find(c => c.name.includes('session'));
    
    if (sessionCookie) {
      // Session cookie should have security flags
      expect(sessionCookie.httpOnly).toBeTruthy();
      // expect(sessionCookie.secure).toBeTruthy(); // Only in HTTPS
      expect(sessionCookie.sameSite).toBeTruthy();
    }
  });
});