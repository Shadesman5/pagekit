# Playwright Runtime Patterns

## Test Execution

### Run Commands

```bash
# All tests
npm run test:e2e

# With browser visible
npm run test:e2e:headed

# Debug mode (step through)
npx playwright test --debug

# Specific test file
npx playwright test tests/e2e/specs/02-core/authentication.spec.js

# Specific test by name
npx playwright test -g "Admin login"

# Smoke tests only
npx playwright test --config=playwright.smoke.config.js

# Single browser
npx playwright test --project=chromium
```

### Docker Environment

```bash
./scripts/e2e-start.sh   # Start containers
./scripts/e2e-reset.sh   # Reset database
./scripts/e2e-stop.sh    # Stop containers
```

## Essential Imports

```javascript
const { test, expect } = require('@playwright/test');
const testConfig = require('../../helpers/test-config');
const { 
    waitForVue, 
    navigateAndWaitForVue, 
    fillVueInput,
    waitForUIkitModal,
    closeUIkitModal 
} = require('../../helpers/vue-helpers');
```

## Vue.js Wait Strategies

### CRITICAL: Always Wait for Vue

```javascript
// Navigation with Vue wait
await navigateAndWaitForVue(page, url);

// After any navigation
await waitForVue(page);

// After clicking something that triggers Vue update
await page.click('[data-testid="button"]');
await waitForVue(page);
```

### Vue Input Handling

```javascript
// WRONG: Standard fill doesn't trigger v-model
await page.fill('input', 'value');

// CORRECT: Triggers input + change events
await fillVueInput(page, 'input[data-testid="username"]', 'value');
```

### UIkit Modal Handling

```javascript
// Open modal
await page.click('[data-testid="open-modal"]');
await waitForUIkitModal(page);

// Close modal
await closeUIkitModal(page);
```

## Common Test Patterns

### Login Helper

```javascript
async function loginAsAdmin(page) {
    await navigateAndWaitForVue(page, testConfig.getAdminUrl() + '/login');
    
    const creds = testConfig.getAdminCredentials();
    await fillVueInput(page, 'input[name="credentials[username]"]', creds.username);
    await fillVueInput(page, 'input[name="credentials[password]"]', creds.password);
    
    await Promise.all([
        page.waitForURL(/\/admin(?!\/login)/),
        page.click('[data-testid="login-submit-button"], .js-login button')
    ]);
}
```

### Form Submission

```javascript
// Fill form
await fillVueInput(page, '[data-testid="title"]', 'Test Title');
await fillVueInput(page, '[data-testid="content"]', 'Test Content');

// Submit and wait for response
await Promise.all([
    page.waitForResponse(resp => resp.url().includes('/api/') && resp.status() === 200),
    page.click('[data-testid="save-button"]')
]);
```

### Assert Visibility

```javascript
// Element visible
await expect(page.locator('[data-testid="element"]')).toBeVisible();

// Text content
await expect(page.locator('[data-testid="message"]')).toContainText('Success');

// URL contains
expect(page.url()).toContain('/admin/dashboard');
```

### Wait for Network

```javascript
// Wait for all requests to finish
await page.waitForLoadState('networkidle');

// Wait for specific API response
const response = await page.waitForResponse(
    resp => resp.url().includes('/api/user') && resp.request().method() === 'GET'
);
const data = await response.json();
```

## testConfig Methods

| Method | Returns | Purpose |
|--------|---------|---------|
| `getSiteUrl()` | `string` | Base URL |
| `getAdminUrl()` | `string` | Admin panel URL |
| `getAdminCredentials()` | `{username, password, email}` | Login credentials |
| `getActionTimeout()` | `number` | Timeout for actions |
| `getNavigationTimeout()` | `number` | Timeout for navigation |
| `startTestTimer()` | `void` | Start timing |
| `getFormattedTestDuration()` | `string` | Readable duration |
| `testConnectivity()` | `Promise` | Check server is up |
| `log(msg, icon)` | `void` | Console output |
| `success(msg)` | `void` | Success log |
| `error(msg)` | `void` | Error log |

## Debugging

### Trace Viewer

```bash
npx playwright show-trace test-results/*/trace.zip
```

### Record Tests

```bash
npx playwright codegen http://localhost:8180
```

### Screenshots

```javascript
// Manual screenshot
await page.screenshot({ path: 'debug.png', fullPage: true });

// Auto-captured on failure in test-results/
```

### Console Logs

```javascript
page.on('console', msg => {
    if (msg.type() === 'error') {
        console.log('Browser error:', msg.text());
    }
});
```

## Selector Priority

1. **`[data-testid="..."]`** - Best, explicit
2. **`[name="..."]`** - Good, tied to data
3. **`#id`** - OK if stable
4. **`.js-*` classes** - OK, intended for JS
5. **Role + name** - `getByRole('button', { name: /save/i })`
6. **Text** - AVOID, especially translations
