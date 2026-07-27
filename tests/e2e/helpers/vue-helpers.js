/**
 * Vue.js specific helpers for Pagekit E2E tests
 * Handles Vue 2.6 specific waiting and interaction patterns
 */

/**
 * Wait for Vue.js to be fully mounted and ready
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {number} timeout - Maximum wait time in milliseconds
 */
async function waitForVue(page, timeout = 10000) {
  // Wait for v-cloak attributes to be removed (indicates Vue is mounted)
  await page
    .waitForFunction(() => !document.querySelector('[v-cloak]'), { timeout })
    .catch(() => {
      console.log('⚠️  No v-cloak found or timeout waiting for Vue');
    });

  // Wait for any Vue transitions to complete
  await page.waitForTimeout(300);

  // Check if Vue is available globally
  const vueExists = await page.evaluate(() => typeof window.Vue !== 'undefined');
  if (!vueExists) {
    console.log('⚠️  Vue not found in window object');
  }
}

/**
 * Wait for a specific Vue component to be ready
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} selector - CSS selector for the component root
 * @param {number} timeout - Maximum wait time
 */
async function waitForVueComponent(page, selector, timeout = 10000) {
  // Wait for element to exist
  await page.waitForSelector(selector, { state: 'attached', timeout });

  // Wait for Vue to process it
  await page
    .waitForFunction(
      sel => {
        const el = document.querySelector(sel);
        return el && el.__vue__ !== undefined;
      },
      selector,
      { timeout: timeout / 2 }
    )
    .catch(() => {
      console.log(`⚠️  Vue component not found for ${selector}`);
    });
}

/**
 * Navigate to a page and wait for Vue to be ready
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} url - URL to navigate to
 */
async function navigateAndWaitForVue(page, url) {
  await page.goto(url, { waitUntil: 'networkidle' });
  await waitForVue(page);
}

/**
 * Click an element and wait for Vue to update
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} selector - Element to click
 */
async function clickAndWaitForVue(page, selector) {
  await page.click(selector);
  // Wait for Vue's next tick
  await page.waitForTimeout(100);
  await waitForVue(page, 5000);
}

/**
 * Fill a Vue-controlled input and trigger proper events
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} selector - Input selector
 * @param {string} value - Value to fill
 */
async function fillVueInput(page, selector, value) {
  const input = page.locator(selector);

  // Clear existing value
  await input.click();
  await page.keyboard.press('Control+A');
  await page.keyboard.press('Delete');

  // Type new value
  await input.type(value);

  // Trigger Vue update events
  await input.dispatchEvent('input');
  await input.dispatchEvent('change');

  // Small wait for Vue to process
  await page.waitForTimeout(100);
}

/**
 * Wait for Vue router navigation to complete
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {number} timeout - Maximum wait time
 */
async function waitForVueRouter(page, timeout = 5000) {
  // Wait for URL change
  await page.waitForLoadState('networkidle');

  // Wait for Vue router to be ready
  await page
    .waitForFunction(
      () => {
        if (window.Vue && window.Vue.$route) {
          return true;
        }
        // For Pagekit's specific router setup
        if (window.$pagekit && window.$pagekit.url) {
          return true;
        }
        return false;
      },
      { timeout }
    )
    .catch(() => {
      console.log('⚠️  Vue router not detected');
    });

  // Additional wait for components to mount
  await waitForVue(page, timeout);
}

/**
 * Wait for AJAX requests to complete (useful for Vue data loading)
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {number} timeout - Maximum wait time
 */
async function waitForAjax(page, timeout = 5000) {
  // Wait for no pending XHR/fetch requests
  await page.waitForLoadState('networkidle', { timeout });

  // Additional check for jQuery AJAX if present
  await page
    .waitForFunction(
      () => {
        if (typeof jQuery !== 'undefined') {
          return jQuery.active === 0;
        }
        return true;
      },
      { timeout: timeout / 2 }
    )
    .catch(() => {});
}

/**
 * Wait for UIkit modal to be ready (Pagekit uses UIkit)
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} modalSelector - Modal selector
 */
async function waitForUIkitModal(page, modalSelector = '.uk-modal') {
  // Wait for modal to be visible
  await page.waitForSelector(modalSelector, { state: 'visible' });

  // Wait for animation to complete
  await page.waitForTimeout(400);

  // Wait for Vue components inside modal
  await waitForVue(page, 5000);
}

/**
 * Close UIkit modal
 * @param {import('@playwright/test').Page} page - Playwright page object
 */
async function closeUIkitModal(page) {
  // Try close button first
  const closeButton = page.locator('.uk-modal-close, button:has-text("Close")').first();
  if (await closeButton.isVisible()) {
    await closeButton.click();
  } else {
    // Click outside modal
    await page.keyboard.press('Escape');
  }

  // Wait for modal to disappear
  await page.waitForTimeout(400);
}

module.exports = {
  waitForVue,
  waitForVueComponent,
  navigateAndWaitForVue,
  clickAndWaitForVue,
  fillVueInput,
  waitForVueRouter,
  waitForAjax,
  waitForUIkitModal,
  closeUIkitModal
};
