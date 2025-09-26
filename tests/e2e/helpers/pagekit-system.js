/**
 * System helper functions for Pagekit E2E tests
 * Handles cache, maintenance, extensions, and system operations
 */

const { expect } = require('@playwright/test');

/**
 * Clear all caches
 * @param {import('@playwright/test').Page} page - Playwright page object
 */
async function clearCache(page) {
  // Navigate to cache settings
  await page.goto('/admin/system/cache');
  
  // Wait for cache page to load
  await page.waitForSelector('.pk-table', { timeout: 10000 });
  
  // Click clear cache button
  await page.click('button.uk-button-danger');
  
  // Confirm action if modal appears
  const modal = await page.$('.uk-modal.uk-open');
  if (modal) {
    await page.click('.uk-modal-dialog button.uk-button-danger');
  }
  
  // Wait for success notification
  await page.waitForSelector('.uk-notify-message-success', { timeout: 5000 });
  
  const message = await page.textContent('.uk-notify-message-success');
  expect(message).toContain('Cache cleared');
}

/**
 * Run maintenance task
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} task - Maintenance task to run
 */
async function runMaintenance(page, task) {
  // Navigate to maintenance page
  await page.goto('/admin/system/maintenance');
  
  // Wait for maintenance page to load
  await page.waitForSelector('.pk-maintenance', { timeout: 10000 });
  
  // Find and click the specific maintenance task
  switch (task) {
    case 'update':
      await page.click('button[data-task="update"]');
      break;
    case 'clearcache':
      await page.click('button[data-task="clearcache"]');
      break;
    case 'optimize':
      await page.click('button[data-task="optimize"]');
      break;
    default:
      throw new Error(`Unknown maintenance task: ${task}`);
  }
  
  // Wait for task to complete
  await page.waitForSelector('.uk-notify-message', { timeout: 30000 });
}

/**
 * Check system status
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<Object>} - System status information
 */
async function checkSystemStatus(page) {
  // Navigate to system info page
  await page.goto('/admin/system/info');
  
  // Wait for info page to load
  await page.waitForSelector('.pk-system-info', { timeout: 10000 });
  
  // Extract system information
  const info = await page.evaluate(() => {
    const rows = document.querySelectorAll('.uk-table tr');
    const status = {};
    
    rows.forEach(row => {
      const cells = row.querySelectorAll('td');
      if (cells.length === 2) {
        const key = cells[0].textContent.trim().toLowerCase().replace(/\s+/g, '_');
        const value = cells[1].textContent.trim();
        status[key] = value;
      }
    });
    
    return status;
  });
  
  return info;
}

/**
 * Install extension from file
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} extensionPath - Path to extension file
 */
async function installExtension(page, extensionPath) {
  // Navigate to extensions page
  await page.goto('/admin/system/package/extensions');
  
  // Click upload button
  await page.click('button[title="Upload"]');
  
  // Wait for upload modal
  await page.waitForSelector('.uk-modal.uk-open', { timeout: 5000 });
  
  // Upload extension file
  const fileInput = await page.$('input[type="file"]');
  await fileInput.setInputFiles(extensionPath);
  
  // Wait for upload to complete
  await page.waitForSelector('.uk-notify-message', { timeout: 30000 });
  
  // Check for success
  const message = await page.textContent('.uk-notify-message');
  expect(message).toContain('successfully installed');
}

/**
 * Enable/disable extension
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} extensionName - Name of extension
 * @param {boolean} enable - True to enable, false to disable
 */
async function toggleExtension(page, extensionName, enable = true) {
  // Navigate to extensions page
  await page.goto('/admin/system/package/extensions');
  
  // Find extension row
  const row = await page.locator(`tr:has-text("${extensionName}")`);
  
  // Check current status
  const statusButton = await row.locator('.uk-button-success, .uk-button-danger');
  const isEnabled = await statusButton.evaluate(el => el.classList.contains('uk-button-success'));
  
  // Toggle if needed
  if (enable !== isEnabled) {
    await statusButton.click();
    
    // Wait for status change
    await page.waitForSelector('.uk-notify-message', { timeout: 5000 });
  }
}

/**
 * Check for system updates
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<Object>} - Update information
 */
async function checkForUpdates(page) {
  // Navigate to update page
  await page.goto('/admin/system/update');
  
  // Click check for updates button
  await page.click('button.uk-button-primary');
  
  // Wait for check to complete
  await page.waitForSelector('.pk-update-info', { timeout: 30000 });
  
  // Extract update information
  const updateInfo = await page.evaluate(() => {
    const currentVersion = document.querySelector('.pk-current-version')?.textContent;
    const latestVersion = document.querySelector('.pk-latest-version')?.textContent;
    const updateAvailable = document.querySelector('.pk-update-available') !== null;
    
    return {
      currentVersion,
      latestVersion,
      updateAvailable
    };
  });
  
  return updateInfo;
}

/**
 * Enable/disable maintenance mode
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {boolean} enable - True to enable, false to disable
 * @param {string} message - Maintenance message (optional)
 */
async function setMaintenanceMode(page, enable = true, message = '') {
  // Navigate to settings page
  await page.goto('/admin/system/settings');
  
  // Switch to maintenance tab
  await page.click('a[href="#maintenance"]');
  
  // Toggle maintenance mode
  const checkbox = await page.$('input[name="maintenance[enabled]"]');
  const isChecked = await checkbox.isChecked();
  
  if (enable !== isChecked) {
    await checkbox.click();
  }
  
  // Set maintenance message if provided
  if (message && enable) {
    await page.fill('textarea[name="maintenance[message]"]', message);
  }
  
  // Save settings
  await page.click('button.uk-button-primary');
  
  // Wait for save confirmation
  await page.waitForSelector('.uk-notify-message-success', { timeout: 5000 });
}

/**
 * Run database migration
 * @param {import('@playwright/test').Page} page - Playwright page object
 */
async function runDatabaseMigration(page) {
  // This would typically be done via console command
  // For E2E testing, we might trigger it through the UI if available
  
  // Navigate to database tools
  await page.goto('/admin/system/migration');
  
  // Check for pending migrations
  const pendingMigrations = await page.$$('.pk-migration-pending');
  
  if (pendingMigrations.length > 0) {
    // Run migrations
    await page.click('button.uk-button-primary');
    
    // Wait for migrations to complete
    await page.waitForSelector('.uk-notify-message-success', { 
      timeout: 60000 // Migrations can take time
    });
  }
  
  return pendingMigrations.length;
}

/**
 * Export system configuration
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<Object>} - Configuration object
 */
async function exportConfiguration(page) {
  // Navigate to settings export
  await page.goto('/admin/system/settings/export');
  
  // Click export button
  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.click('button.uk-button-primary')
  ]);
  
  // Read downloaded file
  const path = await download.path();
  const fs = require('fs');
  const config = JSON.parse(fs.readFileSync(path, 'utf8'));
  
  return config;
}

/**
 * Import system configuration
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} configPath - Path to configuration file
 */
async function importConfiguration(page, configPath) {
  // Navigate to settings import
  await page.goto('/admin/system/settings/import');
  
  // Upload configuration file
  const fileInput = await page.$('input[type="file"]');
  await fileInput.setInputFiles(configPath);
  
  // Click import button
  await page.click('button.uk-button-primary');
  
  // Confirm import if modal appears
  const modal = await page.$('.uk-modal.uk-open');
  if (modal) {
    await page.click('.uk-modal-dialog button.uk-button-primary');
  }
  
  // Wait for import to complete
  await page.waitForSelector('.uk-notify-message-success', { timeout: 10000 });
}

/**
 * Check PHP info
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<Object>} - PHP information
 */
async function getPhpInfo(page) {
  // Navigate to system info
  await page.goto('/admin/system/info/phpinfo');
  
  // Extract PHP version and important settings
  const phpInfo = await page.evaluate(() => {
    const info = {};
    const tables = document.querySelectorAll('table');
    
    tables.forEach(table => {
      const rows = table.querySelectorAll('tr');
      rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length >= 2) {
          const key = cells[0].textContent.trim();
          const value = cells[1].textContent.trim();
          if (key && value) {
            info[key] = value;
          }
        }
      });
    });
    
    return info;
  });
  
  return phpInfo;
}

/**
 * Check error logs
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {number} limit - Number of log entries to retrieve
 * @returns {Promise<Array>} - Array of log entries
 */
async function getErrorLogs(page, limit = 10) {
  // Navigate to error logs
  await page.goto('/admin/system/logs');
  
  // Wait for logs to load
  await page.waitForSelector('.pk-log-entries', { timeout: 10000 });
  
  // Extract log entries
  const logs = await page.evaluate((maxEntries) => {
    const entries = [];
    const rows = document.querySelectorAll('.pk-log-entry');
    
    for (let i = 0; i < Math.min(rows.length, maxEntries); i++) {
      const row = rows[i];
      entries.push({
        date: row.querySelector('.pk-log-date')?.textContent,
        level: row.querySelector('.pk-log-level')?.textContent,
        message: row.querySelector('.pk-log-message')?.textContent,
        context: row.querySelector('.pk-log-context')?.textContent
      });
    }
    
    return entries;
  }, limit);
  
  return logs;
}

module.exports = {
  clearCache,
  runMaintenance,
  checkSystemStatus,
  installExtension,
  toggleExtension,
  checkForUpdates,
  setMaintenanceMode,
  runDatabaseMigration,
  exportConfiguration,
  importConfiguration,
  getPhpInfo,
  getErrorLogs
};