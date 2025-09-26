/**
 * Authentication helper functions for Pagekit E2E tests
 */

const { expect } = require('@playwright/test');

/**
 * Login as admin user
 * @param {import('@playwright/test').Page} page - Playwright page object
 */
async function loginAsAdmin(page) {
  await loginAsUser(page, 'admin', 'admin123');
}

/**
 * Login with specific credentials
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} username - Username or email
 * @param {string} password - Password
 */
async function loginAsUser(page, username, password) {
  // Navigate to login page
  await page.goto('/admin/login');
  
  // Wait for login form
  await page.waitForSelector('form[name="form"]', { timeout: 10000 });
  
  // Fill in credentials
  await page.fill('input[name="credentials[username]"]', username);
  await page.fill('input[name="credentials[password]"]', password);
  
  // Check remember me checkbox if present
  const rememberMe = await page.$('input[name="remember_me"]');
  if (rememberMe) {
    await rememberMe.check();
  }
  
  // Submit form
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type="submit"]')
  ]);
  
  // Verify successful login - should redirect to dashboard
  const url = page.url();
  expect(url).toContain('/admin');
  
  // Wait for dashboard to load
  await page.waitForSelector('.pk-width-content', { timeout: 10000 });
}

/**
 * Logout current user
 * @param {import('@playwright/test').Page} page - Playwright page object
 */
async function logout(page) {
  // Click on user menu
  await page.click('.pk-navbar-toggle');
  
  // Wait for dropdown menu
  await page.waitForSelector('.uk-dropdown', { visible: true });
  
  // Click logout link
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('a[href*="/user/logout"]')
  ]);
  
  // Verify logout - should redirect to login page or home
  const url = page.url();
  expect(url).not.toContain('/admin');
}

/**
 * Check if user has specific permission
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} permission - Permission to check
 * @returns {Promise<boolean>} - True if user has permission
 */
async function checkPermission(page, permission) {
  // Navigate to user profile/permissions page
  await page.goto('/admin/user/edit');
  
  // Check if permission checkbox is checked
  const permissionSelector = `input[name*="${permission}"]`;
  const hasPermission = await page.isChecked(permissionSelector).catch(() => false);
  
  return hasPermission;
}

/**
 * Request password reset
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} email - Email address for reset
 */
async function requestPasswordReset(page, email) {
  // Navigate to password reset page
  await page.goto('/user/resetpassword');
  
  // Fill in email
  await page.fill('input[name="email"]', email);
  
  // Submit form
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type="submit"]')
  ]);
  
  // Check for success message
  await expect(page.locator('.uk-alert-success')).toBeVisible();
}

/**
 * Complete password reset with token
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} token - Reset token
 * @param {string} newPassword - New password
 */
async function completePasswordReset(page, token, newPassword) {
  // Navigate to reset confirmation page with token
  await page.goto(`/user/resetpassword/confirm?key=${token}`);
  
  // Fill in new password
  await page.fill('input[name="password"]', newPassword);
  await page.fill('input[name="password_confirmation"]', newPassword);
  
  // Submit form
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type="submit"]')
  ]);
  
  // Verify success - should redirect to login
  await expect(page).toHaveURL(/\/login/);
}

/**
 * Register new user
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {Object} userData - User registration data
 */
async function registerUser(page, userData) {
  // Navigate to registration page
  await page.goto('/user/registration');
  
  // Fill in registration form
  await page.fill('input[name="user[username]"]', userData.username);
  await page.fill('input[name="user[name]"]', userData.name || userData.username);
  await page.fill('input[name="user[email]"]', userData.email);
  await page.fill('input[name="user[password][first]"]', userData.password);
  await page.fill('input[name="user[password][second]"]', userData.password);
  
  // Submit form
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type="submit"]')
  ]);
  
  // Check for success message or redirect
  const url = page.url();
  expect(url).toMatch(/\/(login|admin|user)/);
}

/**
 * Check if user is logged in
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<boolean>} - True if user is logged in
 */
async function isLoggedIn(page) {
  // Try to access admin area
  await page.goto('/admin');
  
  // If redirected to login, user is not logged in
  const url = page.url();
  return !url.includes('/login');
}

/**
 * Get current user info
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<Object|null>} - User info object or null
 */
async function getCurrentUser(page) {
  if (!(await isLoggedIn(page))) {
    return null;
  }
  
  // Navigate to user profile
  await page.goto('/admin/user/edit');
  
  // Extract user info
  const username = await page.inputValue('input[name="user[username]"]');
  const email = await page.inputValue('input[name="user[email]"]');
  const name = await page.inputValue('input[name="user[name]"]');
  
  return {
    username,
    email,
    name
  };
}

module.exports = {
  loginAsAdmin,
  loginAsUser,
  logout,
  checkPermission,
  requestPasswordReset,
  completePasswordReset,
  registerUser,
  isLoggedIn,
  getCurrentUser
};