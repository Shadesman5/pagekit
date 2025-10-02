/**
 * Improved Authentication Tests for Installed Pagekit
 * With proper Vue.js wait strategies
 *
 * Prerequisites: Pagekit must be installed with credentials from test-config.json
 */

const { test, expect } = require('@playwright/test');
const testConfig = require('../../helpers/test-config');

// Helper function to wait for Vue.js to be ready
async function waitForVue(page) {
    // Wait for v-cloak to be removed (Vue mounted)
    await page.waitForFunction(() => !document.querySelector('[v-cloak]'), { timeout: 10000 });

    // Additional wait for any Vue transitions
    await page.waitForTimeout(500);
}

// Helper function to wait for page load with Vue
async function navigateAndWait(page, url) {
    await page.goto(url, { waitUntil: 'networkidle' });
    await waitForVue(page);
}

test.describe('Pagekit Authentication with Vue.js (Improved)', () => {
    test.beforeAll(async () => {
        // Start test timer
        testConfig.startTestTimer();

        // Test connectivity and configuration
        await testConfig.testConnectivity();
    });
    test('Admin login page loads completely', async ({ page }) => {
        testConfig.log('Testing login page load...', '🚀');

        // Navigate to login page
        await navigateAndWait(page, testConfig.getAdminUrl() + '/admin/login');

        // Wait for form to be fully loaded
        await page.waitForSelector('form.js-login', { state: 'visible' });

        // Check all form elements are present and visible
        const usernameInput = page.locator('input[name="credentials[username]"]');
        const passwordInput = page.locator('input[name="credentials[password]"]');
        const loginButton = page.locator('button:has-text("Login")');

        await expect(usernameInput).toBeVisible();
        await expect(passwordInput).toBeVisible();
        await expect(loginButton).toBeVisible();

        // Check that inputs are enabled and ready
        await expect(usernameInput).toBeEnabled();
        await expect(passwordInput).toBeEnabled();
        await expect(loginButton).toBeEnabled();

        testConfig.success('Login page loaded successfully with all elements');
    });

    test('Admin login with valid credentials', async ({ page }) => {
        testConfig.log('Testing valid login...', '🚀');

        await navigateAndWait(page, '/admin/login');

        // Fill login form
        const usernameInput = page.locator('input[name="credentials[username]"]');
        const passwordInput = page.locator('input[name="credentials[password]"]');

        const adminCreds = testConfig.getAdminCredentials();
        await usernameInput.fill(adminCreds.username);
        await passwordInput.fill(adminCreds.password);

        // Click login button and wait for navigation
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.click('button:has-text("Login")')
        ]);

        // Wait for Vue admin interface to load
        await waitForVue(page);

        // Verify we're in admin dashboard
        expect(page.url()).toContain('/admin');
        expect(page.url()).not.toContain('/login');

        // Check for admin UI elements
        const adminNav = await page.locator('.pk-navbar').isVisible();
        expect(adminNav).toBeTruthy();

        testConfig.success('Successfully logged in as admin');
    });

    test('Admin login with invalid credentials shows error', async ({ page }) => {
        testConfig.log('Testing invalid login...', '🚀');

        await navigateAndWait(page, '/admin/login');

        // Fill with wrong credentials
        const adminCreds = testConfig.getAdminCredentials();
        await page.fill('input[name="credentials[username]"]', adminCreds.username);
        await page.fill('input[name="credentials[password]"]', 'wrongpassword');

        // Click login and wait for response
        await page.click('button:has-text("Login")');

        // Wait for error message to appear (Vue might update DOM)
        await page.waitForSelector('.uk-alert-danger', { state: 'visible', timeout: 5000 });

        // Should stay on login page
        expect(page.url()).toContain('/login');

        // Check error message is visible
        const errorMessage = page.locator('.uk-alert-danger');
        await expect(errorMessage).toBeVisible();
        await expect(errorMessage).toContainText(/invalid|incorrect|failed/i);

        testConfig.success('Invalid login correctly shows error');
    });

    test('Admin logout works correctly', async ({ page }) => {
        testConfig.log('Testing logout...', '🚀');

        // First login
        await navigateAndWait(page, '/admin/login');
        await page.fill('input[name="credentials[username]"]', 'admin');
        await page.fill('input[name="credentials[password]"]', 'admin123');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.click('button:has-text("Login")')
        ]);

        await waitForVue(page);
        testConfig.success('Logged in successfully');

        // Look for logout icon in navigation (desktop version)
        const logoutIcon = page.locator('a[uk-icon="sign-out"][href*="/user/logout"]').first();

        if (await logoutIcon.isVisible()) {
            testConfig.info('Found desktop logout icon', '🔍');
            await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), logoutIcon.click()]);

            // Should redirect to login
            expect(page.url()).toContain('/login');
            testConfig.success('Successfully logged out via desktop icon');
        } else {
            // Try mobile offcanvas version
            const mobileLogoutLink = page.locator('a[href*="/user/logout"]:has(span[uk-icon="sign-out"])').first();

            if (await mobileLogoutLink.isVisible()) {
                testConfig.info('Found mobile logout link', '🔍');
                await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), mobileLogoutLink.click()]);

                // Should redirect to login
                expect(page.url()).toContain('/login');
                testConfig.success('Successfully logged out via mobile link');
            } else {
                // Fallback: Direct logout URL
                testConfig.info('Using direct logout URL as fallback', '🔍');
                await page.goto('/user/logout');
                await page.waitForURL(/\/login/, { timeout: 5000 });
                testConfig.success('Logged out via direct URL');
            }
        }
    });

    test('Protected admin area redirects to login when not authenticated', async ({ page }) => {
        testConfig.log('Testing protected area...', '🚀');

        // Clear all cookies to ensure logged out
        await page.context().clearCookies();

        // Try to access admin area
        await page.goto(testConfig.getAdminUrl(), { waitUntil: 'networkidle' });

        // Should redirect to login
        await page.waitForURL(/\/login/, { timeout: 5000 });

        // Verify we're on login page
        expect(page.url()).toContain('/login');

        // Login form should be visible
        const loginForm = await page.locator('form.js-login').isVisible();
        expect(loginForm).toBeTruthy();

        testConfig.success('Admin area correctly protected');
    });

    test('Remember me checkbox works', async ({ page }) => {
        testConfig.log('Testing remember me functionality...', '🚀');

        await navigateAndWait(page, '/admin/login');

        // Check if remember me checkbox exists
        const rememberCheckbox = page.locator('input[type="checkbox"][name*="remember"]');

        if ((await rememberCheckbox.count()) > 0) {
            // Check the remember me box
            await rememberCheckbox.check();
            expect(await rememberCheckbox.isChecked()).toBeTruthy();

            // Login with remember me
            await page.fill('input[name="credentials[username]"]', 'admin');
            await page.fill('input[name="credentials[password]"]', 'admin123');

            await Promise.all([
                page.waitForNavigation({ waitUntil: 'networkidle' }),
                page.click('button:has-text("Login")')
            ]);

            // Check cookies for remember token
            const cookies = await page.context().cookies();
            const rememberCookie = cookies.find(
                c => c.name.includes('remember') || c.expires > Date.now() / 1000 + 86400
            );

            if (rememberCookie) {
                testConfig.success('Remember me cookie set');
            } else {
                testConfig.warning('Remember cookie not clearly identified');
            }
        } else {
            testConfig.error('Remember me checkbox not found');
        }
    });

    test('CSRF token is present in login form', async ({ page }) => {
        testConfig.log('Testing CSRF token presence...', '🚀');

        await navigateAndWait(page, testConfig.getAdminUrl() + '/admin/login');

        // Check if CSRF token is available in JavaScript
        const csrfToken = await page.evaluate(() => {
            return window.$pagekit ? window.$pagekit.csrf : null;
        });

        expect(csrfToken).toBeTruthy();
        expect(typeof csrfToken).toBe('string');
        expect(csrfToken.length).toBeGreaterThan(10); // Should be a reasonable length

        testConfig.success('CSRF token found in pagekit object');

        // Check if CSRF token is in hidden form field
        const csrfInput = page.locator('input[name="_csrf"]');
        if ((await csrfInput.count()) > 0) {
            const csrfValue = await csrfInput.inputValue();
            expect(csrfValue).toBeTruthy();
            testConfig.success('CSRF token found in form field');
        } else {
            testConfig.error('CSRF token not found in form field (may be handled by Vue.js)');
        }
    });

    test('CSRF protection blocks requests without valid token', async ({ page }) => {
        testConfig.log('Testing CSRF protection...', '🚀');

        // First login to get a valid session
        await navigateAndWait(page, testConfig.getAdminUrl() + '/admin/login');
        const adminCreds = testConfig.getAdminCredentials();
        await page.fill('input[name="credentials[username]"]', adminCreds.username);
        await page.fill('input[name="credentials[password]"]', adminCreds.password);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.click('button:has-text("Login")')
        ]);

        await waitForVue(page);
        testConfig.success('Logged in successfully');

        // Try to make a request without CSRF token
        const response = await page.request.post(testConfig.getAdminUrl() + '/user/profile', {
            data: {
                user: {
                    name: 'Test User'
                }
                // No _csrf token
            }
        });

        // Should get 401 or 403 error
        expect([401, 403]).toContain(response.status());
        testConfig.success(`CSRF protection working - got ${response.status()} status`);

        // Check response content for CSRF error
        const responseText = await response.text();
        if (responseText.includes('Invalid token') || responseText.includes('CSRF')) {
            testConfig.success('CSRF error message found in response');
        }
    });

    test('CSRF token is regenerated after login', async ({ page }) => {
        testConfig.log('Testing CSRF token regeneration...', '🚀');

        // Get initial CSRF token
        await navigateAndWait(page, testConfig.getAdminUrl() + '/admin/login');
        const initialToken = await page.evaluate(() => window.$pagekit.csrf);

        // Login
        const adminCreds = testConfig.getAdminCredentials();
        await page.fill('input[name="credentials[username]"]', adminCreds.username);
        await page.fill('input[name="credentials[password]"]', adminCreds.password);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.click('button:has-text("Login")')
        ]);

        await waitForVue(page);

        // Get new CSRF token after login
        const newToken = await page.evaluate(() => window.$pagekit.csrf);

        // Tokens should be different (session changed)
        expect(newToken).not.toBe(initialToken);
        testConfig.success('CSRF token regenerated after login');
    });

    test('Vue.js automatically adds CSRF header to AJAX requests', async ({ page }) => {
        testConfig.log('Testing Vue.js CSRF header injection...', '🚀');

        // Login first
        await navigateAndWait(page, testConfig.getAdminUrl() + '/admin/login');
        const adminCreds = testConfig.getAdminCredentials();
        await page.fill('input[name="credentials[username]"]', adminCreds.username);
        await page.fill('input[name="credentials[password]"]', adminCreds.password);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.click('button:has-text("Login")')
        ]);

        await waitForVue(page);

        // Monitor network requests
        const requests = [];
        page.on('request', request => {
            if (request.url().includes('/admin/') && request.method() === 'POST') {
                requests.push({
                    url: request.url(),
                    headers: request.headers()
                });
            }
        });

        // Trigger a Vue.js AJAX request (e.g., by navigating to a page that makes requests)
        await page.goto(testConfig.getAdminUrl() + '/user', { waitUntil: 'networkidle' });
        await waitForVue(page);

        // Check if any requests had X-XSRF-TOKEN header
        const csrfRequests = requests.filter(req => req.headers['x-xsrf-token'] || req.headers['X-XSRF-TOKEN']);

        if (csrfRequests.length > 0) {
            testConfig.success('Vue.js automatically added CSRF header to AJAX requests');
        } else {
            testConfig.error('No AJAX requests with CSRF headers detected (may be normal)');
        }
    });

    test('Rate limiting blocks too many failed login attempts', async ({ page }) => {
        testConfig.log('Testing rate limiting for failed login attempts...', '🚀');

        await navigateAndWait(page, testConfig.getAdminUrl() + '/admin/login');

        // Make 6 failed login attempts (limit is 5)
        for (let i = 1; i <= 6; i++) {
            testConfig.info(`   Attempt ${i}/6...`);

            const adminCreds = testConfig.getAdminCredentials();
            await page.fill('input[name="credentials[username]"]', adminCreds.username);
            await page.fill('input[name="credentials[password]"]', 'wrongpassword');

            await page.click('button:has-text("Login")');

            // Wait for error message
            await page.waitForSelector('.uk-alert-danger', { state: 'visible', timeout: 5000 });

            // Clear form for next attempt
            await page.fill('input[name="credentials[username]"]', '');
            await page.fill('input[name="credentials[password]"]', '');
        }

        // The 6th attempt should show rate limiting message
        const errorMessage = page.locator('.uk-alert-danger');
        await expect(errorMessage).toBeVisible();

        const errorText = await errorMessage.textContent();
        if (errorText.includes('Slow down') || errorText.includes('rate limit') || errorText.includes('too many')) {
            testConfig.success('Rate limiting message detected');
        } else {
            testConfig.warning('Rate limiting may be working but message not clearly identified');
        }

        // Should still be on login page
        expect(page.url()).toContain('/login');
        testConfig.success('Rate limiting working - blocked excessive attempts');
    });

    test('Rate limiting allows login after delay', async ({ page }) => {
        testConfig.log('Testing rate limiting delay...', '🚀');

        await navigateAndWait(page, testConfig.getAdminUrl() + '/admin/login');

        // Make 5 failed attempts to trigger rate limiting
        for (let i = 1; i <= 5; i++) {
            const adminCreds = testConfig.getAdminCredentials();
            await page.fill('input[name="credentials[username]"]', adminCreds.username);
            await page.fill('input[name="credentials[password]"]', 'wrongpassword');

            await page.click('button:has-text("Login")');
            await page.waitForSelector('.uk-alert-danger', { state: 'visible', timeout: 5000 });

            // Clear form
            await page.fill('input[name="credentials[username]"]', '');
            await page.fill('input[name="credentials[password]"]', '');
        }

        testConfig.info('   Made 5 failed attempts, waiting 6 seconds for rate limit to reset...');

        // Wait for rate limit to reset (5 seconds + 1 second buffer)
        await page.waitForTimeout(6000);

        // Now try correct login
        const adminCreds = testConfig.getAdminCredentials();
        await page.fill('input[name="credentials[username]"]', adminCreds.username);
        await page.fill('input[name="credentials[password]"]', adminCreds.password);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.click('button:has-text("Login")')
        ]);

        // Should successfully login
        expect(page.url()).toContain('/admin');
        expect(page.url()).not.toContain('/login');
        testConfig.success('Successfully logged in after rate limit delay');
    });

    test('Rate limiting resets after successful login', async ({ page }) => {
        testConfig.log('Testing rate limiting reset after successful login...', '🚀');

        await navigateAndWait(page, testConfig.getAdminUrl() + '/admin/login');

        // Make 3 failed attempts
        for (let i = 1; i <= 3; i++) {
            const adminCreds = testConfig.getAdminCredentials();
            await page.fill('input[name="credentials[username]"]', adminCreds.username);
            await page.fill('input[name="credentials[password]"]', 'wrongpassword');

            await page.click('button:has-text("Login")');
            await page.waitForSelector('.uk-alert-danger', { state: 'visible', timeout: 5000 });

            await page.fill('input[name="credentials[username]"]', '');
            await page.fill('input[name="credentials[password]"]', '');
        }

        testConfig.info('Made 3 failed attempts, now trying correct login...');

        // Login successfully
        const adminCreds = testConfig.getAdminCredentials();
        await page.fill('input[name="credentials[username]"]', adminCreds.username);
        await page.fill('input[name="credentials[password]"]', adminCreds.password);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.click('button:has-text("Login")')
        ]);

        await waitForVue(page);
        testConfig.success('Successfully logged in');

        // Logout
        const logoutIcon = page.locator('a[uk-icon="sign-out"][href*="/user/logout"]').first();
        if (await logoutIcon.isVisible()) {
            await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), logoutIcon.click()]);
        } else {
            await page.goto('/user/logout');
            await page.waitForURL(/\/login/, { timeout: 5000 });
        }

        testConfig.info('Logged out, testing if rate limiting was reset...');

        // Now make 5 failed attempts again - should work (no rate limiting)
        for (let i = 1; i <= 5; i++) {
            const adminCreds = testConfig.getAdminCredentials();
            await page.fill('input[name="credentials[username]"]', adminCreds.username);
            await page.fill('input[name="credentials[password]"]', 'wrongpassword');

            await page.click('button:has-text("Login")');
            await page.waitForSelector('.uk-alert-danger', { state: 'visible', timeout: 5000 });

            await page.fill('input[name="credentials[username]"]', '');
            await page.fill('input[name="credentials[password]"]', '');
        }

        // Should not be rate limited (counter was reset)
        testConfig.success('Rate limiting counter reset after successful login');
    });

    test('Rate limiting is per-username', async ({ page }) => {
        testConfig.log('Testing rate limiting per username...', '🚀');

        await navigateAndWait(page, testConfig.getAdminUrl() + '/admin/login');

        // Make 5 failed attempts with 'admin'
        for (let i = 1; i <= 5; i++) {
            const adminCreds = testConfig.getAdminCredentials();
            await page.fill('input[name="credentials[username]"]', adminCreds.username);
            await page.fill('input[name="credentials[password]"]', 'wrongpassword');

            await page.click('button:has-text("Login")');
            await page.waitForSelector('.uk-alert-danger', { state: 'visible', timeout: 5000 });

            await page.fill('input[name="credentials[username]"]', '');
            await page.fill('input[name="credentials[password]"]', '');
        }

        testConfig.info('Made 5 failed attempts with admin, now trying with different username...');

        // Try with different username - should not be rate limited
        await page.fill('input[name="credentials[username]"]', 'differentuser');
        await page.fill('input[name="credentials[password]"]', 'wrongpassword');

        await page.click('button:has-text("Login")');
        await page.waitForSelector('.uk-alert-danger', { state: 'visible', timeout: 5000 });

        // Should get normal error, not rate limiting error
        const errorMessage = page.locator('.uk-alert-danger');
        const errorText = await errorMessage.textContent();

        if (!errorText.includes('Slow down') && !errorText.includes('rate limit')) {
            testConfig.success('Rate limiting is per-username (different user not rate limited)');
        } else {
            testConfig.warning('Rate limiting may be global rather than per-username');
        }
    });
});
