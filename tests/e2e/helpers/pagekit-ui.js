/**
 * UI helper functions for Pagekit E2E tests
 * Handles Vue.js 2.6 components and UIkit 3.5 interactions
 */

const { expect } = require('@playwright/test');

/**
 * Wait for Vue component to be mounted and ready
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} selector - Component selector
 * @param {number} timeout - Maximum wait time in milliseconds
 */
async function waitForVueComponent(page, selector, timeout = 10000) {
  // Wait for element to exist
  await page.waitForSelector(selector, { timeout });
  
  // Wait for Vue to finish rendering
  await page.evaluate((sel) => {
    return new Promise((resolve) => {
      const checkVue = () => {
        const element = document.querySelector(sel);
        if (element && element.__vue__ && element.__vue__.$el) {
          // Vue component is mounted
          if (window.Vue) {
            window.Vue.nextTick(() => resolve());
          } else {
            setTimeout(resolve, 100);
          }
        } else {
          setTimeout(checkVue, 100);
        }
      };
      checkVue();
    });
  }, selector);
}

/**
 * Wait for UIkit modal to appear
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {number} timeout - Maximum wait time
 */
async function waitForUIkitModal(page, timeout = 5000) {
  // Wait for modal to be visible
  await page.waitForSelector('.uk-modal.uk-open', { 
    visible: true, 
    timeout 
  });
  
  // Wait for animation to complete
  await page.waitForTimeout(300);
}

/**
 * Check and verify UIkit notification
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} text - Expected notification text
 * @param {string} type - Notification type (success, danger, warning)
 */
async function checkUIkitNotification(page, text, type = 'success') {
  // Wait for notification to appear
  const notificationSelector = `.uk-notify-message.uk-notify-message-${type}`;
  await page.waitForSelector(notificationSelector, { 
    visible: true, 
    timeout: 5000 
  });
  
  // Verify notification text
  const notificationText = await page.textContent(notificationSelector);
  expect(notificationText).toContain(text);
  
  // Return notification element for further assertions
  return page.locator(notificationSelector);
}

/**
 * Close all UIkit modals
 * @param {import('@playwright/test').Page} page - Playwright page object
 */
async function closeUIkitModals(page) {
  // Close all open modals
  const modals = await page.$$('.uk-modal.uk-open');
  
  for (const modal of modals) {
    // Try to click close button
    const closeButton = await modal.$('.uk-modal-close');
    if (closeButton) {
      await closeButton.click();
    } else {
      // Click backdrop to close
      await page.keyboard.press('Escape');
    }
    
    // Wait for modal to close
    await page.waitForTimeout(300);
  }
}

/**
 * Interact with UIkit dropdown
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} triggerSelector - Dropdown trigger selector
 * @param {string} itemText - Text of item to select
 */
async function selectFromDropdown(page, triggerSelector, itemText) {
  // Click trigger to open dropdown
  await page.click(triggerSelector);
  
  // Wait for dropdown to open
  await page.waitForSelector('.uk-dropdown.uk-open', { 
    visible: true,
    timeout: 5000 
  });
  
  // Click item in dropdown
  await page.click(`.uk-dropdown.uk-open >> text="${itemText}"`);
  
  // Wait for dropdown to close
  await page.waitForSelector('.uk-dropdown.uk-open', { 
    state: 'hidden',
    timeout: 5000 
  });
}

/**
 * Handle UIkit tab navigation
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} tabText - Text of tab to click
 */
async function switchTab(page, tabText) {
  // Click on tab
  await page.click(`.uk-tab >> text="${tabText}"`);
  
  // Wait for tab content to be visible
  const tabLink = await page.$(`.uk-tab a:has-text("${tabText}")`);
  const href = await tabLink.getAttribute('href');
  const tabId = href.replace('#', '');
  
  await page.waitForSelector(`#${tabId}`, { 
    visible: true,
    timeout: 5000 
  });
}

/**
 * Handle UIkit sortable lists
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} itemSelector - Selector for item to drag
 * @param {string} targetSelector - Selector for drop target
 */
async function dragAndDrop(page, itemSelector, targetSelector) {
  const item = await page.$(itemSelector);
  const target = await page.$(targetSelector);
  
  if (!item || !target) {
    throw new Error('Could not find drag source or target');
  }
  
  // Get bounding boxes
  const itemBox = await item.boundingBox();
  const targetBox = await target.boundingBox();
  
  // Perform drag and drop
  await page.mouse.move(itemBox.x + itemBox.width / 2, itemBox.y + itemBox.height / 2);
  await page.mouse.down();
  await page.mouse.move(targetBox.x + targetBox.width / 2, targetBox.y + targetBox.height / 2);
  await page.mouse.up();
  
  // Wait for sortable to update
  await page.waitForTimeout(500);
}

/**
 * Wait for Vue.js data binding to update
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {Function} checkFunction - Function to check if update is complete
 * @param {number} timeout - Maximum wait time
 */
async function waitForVueUpdate(page, checkFunction, timeout = 5000) {
  const startTime = Date.now();
  
  while (Date.now() - startTime < timeout) {
    const result = await page.evaluate(checkFunction);
    if (result) {
      return true;
    }
    await page.waitForTimeout(100);
  }
  
  throw new Error('Vue update timeout');
}

/**
 * Interact with Vue.js form components
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} vModel - v-model name
 * @param {any} value - Value to set
 */
async function setVueFormValue(page, vModel, value) {
  // Find input with v-model
  const input = await page.$(`[v-model="${vModel}"], [v-model^="${vModel}."]`);
  
  if (!input) {
    throw new Error(`Could not find input with v-model="${vModel}"`);
  }
  
  // Get input type
  const type = await input.getAttribute('type');
  
  switch (type) {
    case 'checkbox':
      if (value) {
        await input.check();
      } else {
        await input.uncheck();
      }
      break;
    case 'radio':
      await input.check();
      break;
    case 'select':
      await input.selectOption(value);
      break;
    default:
      await input.fill(String(value));
  }
  
  // Trigger Vue update
  await input.dispatchEvent('input');
  await input.dispatchEvent('change');
  
  // Wait for Vue to process
  await page.waitForTimeout(100);
}

/**
 * Get Vue component data
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} selector - Component selector
 * @returns {Promise<Object>} - Component data object
 */
async function getVueComponentData(page, selector) {
  return await page.evaluate((sel) => {
    const element = document.querySelector(sel);
    if (element && element.__vue__) {
      return element.__vue__.$data;
    }
    return null;
  }, selector);
}

/**
 * Trigger Vue component method
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} selector - Component selector
 * @param {string} methodName - Method name to call
 * @param {Array} args - Method arguments
 */
async function callVueMethod(page, selector, methodName, ...args) {
  return await page.evaluate((sel, method, methodArgs) => {
    const element = document.querySelector(sel);
    if (element && element.__vue__ && element.__vue__[method]) {
      return element.__vue__[method](...methodArgs);
    }
    throw new Error(`Method ${method} not found on component`);
  }, selector, methodName, args);
}

/**
 * Wait for UIkit accordion to expand/collapse
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} accordionSelector - Accordion item selector
 * @param {boolean} expand - True to expand, false to collapse
 */
async function toggleAccordion(page, accordionSelector, expand = true) {
  const accordion = await page.$(accordionSelector);
  const isExpanded = await accordion.evaluate(el => el.classList.contains('uk-open'));
  
  if (expand !== isExpanded) {
    await accordion.click();
    
    // Wait for animation
    if (expand) {
      await page.waitForSelector(`${accordionSelector}.uk-open`, { timeout: 5000 });
    } else {
      await page.waitForSelector(`${accordionSelector}:not(.uk-open)`, { timeout: 5000 });
    }
  }
}

/**
 * Handle UIkit tooltip interactions
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} elementSelector - Element with tooltip
 * @returns {Promise<string>} - Tooltip text
 */
async function getTooltipText(page, elementSelector) {
  // Hover over element to show tooltip
  await page.hover(elementSelector);
  
  // Wait for tooltip to appear
  await page.waitForSelector('.uk-tooltip', { 
    visible: true,
    timeout: 5000 
  });
  
  // Get tooltip text
  const tooltipText = await page.textContent('.uk-tooltip');
  
  return tooltipText;
}

module.exports = {
  waitForVueComponent,
  waitForUIkitModal,
  checkUIkitNotification,
  closeUIkitModals,
  selectFromDropdown,
  switchTab,
  dragAndDrop,
  waitForVueUpdate,
  setVueFormValue,
  getVueComponentData,
  callVueMethod,
  toggleAccordion,
  getTooltipText
};