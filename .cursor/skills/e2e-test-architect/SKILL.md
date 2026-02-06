---
name: e2e-test-architect
description: Analyzes Pagekit codebase to generate and maintain E2E tests. Scans PHP views, Vue components, and routes to derive selectors and test cases. Use when creating new E2E tests, modernizing existing tests, or when the user asks about test coverage, selectors, or data-testid.
---

# E2E Test Architect for Pagekit

Analyzes codebase structure to generate reliable, maintainable E2E tests.

## Workflow Overview

```
1. ANALYZE    → Scan codebase for testable surfaces
2. SELECTORS  → Identify/recommend stable selectors
3. GENERATE   → Create test specs from analysis
4. VALIDATE   → Ensure tests match current code
```

## 1. Codebase Analysis

### What to Scan

| Source | Path Pattern | Extracts |
|--------|--------------|----------|
| **PHP Views** | `app/system/modules/*/views/**/*.php` | Forms, inputs, buttons, links |
| **Vue Components** | `**/*.vue` | Interactive elements, v-model bindings |
| **Module Routes** | `app/**/index.php` (routes array) | URL patterns, controllers |
| **Controllers** | `**/src/Controller/*.php` | Actions, API endpoints |

### Quick Analysis Commands

```bash
# List all PHP views
find app -path "*/views/*.php" -type f

# List all Vue components
find . -name "*.vue" -type f | head -20

# Find forms in views
grep -r "<form" app/system/modules/*/views/ --include="*.php"

# Find Vue inputs with v-model
grep -r "v-model" --include="*.vue" -l
```

### Key Files to Analyze

For any feature, always check:
1. **Module index.php** - Routes and permissions
2. **Controller** - Actions and their views
3. **View/Template** - Forms and UI elements
4. **Vue Component** - Dynamic interactions

## 2. Selector Strategy

### Priority Order (Best → Worst)

1. **`data-testid`** - Explicit, stable, never changes with UI
2. **`name` attribute** - Tied to form data, rarely changes
3. **`id` attribute** - If unique and meaningful
4. **Semantic role** - `getByRole('button', { name: ... })`
5. **CSS class** - Only `.js-*` prefixed classes
6. **Text content** - LAST RESORT, avoid translations

### NEVER Use

- Translated text (`{{ 'Login' | trans }}`, `<?= __('Username') ?>`)
- UIkit classes (`.uk-button`, `.uk-input`) - styling, not identity
- Position-based (`:nth-child`, `:first`)
- Generated IDs

### Adding data-testid

When modifying views/components, add `data-testid`:

**PHP View:**
```php
<input 
    data-testid="login-username"
    name="credentials[username]" 
    type="text" 
    placeholder="<?= __('Username') ?>"
>
```

**Vue Component:**
```vue
<input
    data-testid="login-password"
    v-model="credentials.password"
    type="password"
    :placeholder="'Password' | trans"
/>
```

### Naming Convention

```
[page]-[element]-[qualifier]

Examples:
- login-username-input
- login-submit-button
- dashboard-widget-feed
- blog-post-title
- user-edit-save-button
```

## 3. Test Generation

### From Route Analysis

```javascript
// Route: '/user' → '@user'
// Controller: AuthController
// Actions: login, logout, authenticate

test.describe('User Authentication', () => {
  test('login page accessible', async ({ page }) => {
    await page.goto('/admin/login');
    await expect(page.locator('[data-testid="login-form"]')).toBeVisible();
  });
});
```

### From Form Analysis

```javascript
// Form found in: app/system/modules/theme/views/login.php
// Inputs: credentials[username], credentials[password]
// Submit: .js-login button

test('login form submission', async ({ page }) => {
  await navigateAndWaitForVue(page, testConfig.getAdminUrl() + '/login');
  
  // Use name attributes as stable selectors
  await page.fill('input[name="credentials[username]"]', testConfig.getAdminCredentials().username);
  await page.fill('input[name="credentials[password]"]', testConfig.getAdminCredentials().password);
  
  await Promise.all([
    page.waitForURL(/\/admin(?!\/login)/),
    page.click('.js-login button')
  ]);
});
```

### From Vue Component Analysis

```javascript
// Component: modal-login.vue
// v-model: credentials.username, credentials.password
// Events: @submit.prevent="login"

test('modal login handles session expiry', async ({ page }) => {
  // Trigger session expiry scenario
  // Check modal appears
  // Fill and submit
});
```

## 4. Modernizing Existing Tests

### Audit Checklist

For each existing test, check:

- [ ] Uses `data-testid` or `name` attributes (not text)
- [ ] Uses `waitForVue()` before interactions
- [ ] Uses `fillVueInput()` for Vue-controlled inputs
- [ ] Uses `testConfig` for credentials (not hardcoded)
- [ ] No hardcoded German labels
- [ ] No brittle selectors (nth-child, position)

### Migration Pattern

**Before (Bad):**
```javascript
// Hardcoded German, brittle selector
const usernameInput = page.getByRole('textbox', { name: 'Benutzername' });
await usernameInput.fill('admin');
```

**After (Good):**
```javascript
// Stable selector, config-driven
await fillVueInput(page, 'input[name="credentials[username]"]', testConfig.getAdminCredentials().username);
```

## 5. Test File Structure

### Standard Template

```javascript
/**
 * [Feature] E2E Tests
 * 
 * Codebase Analysis:
 * - View: app/system/modules/[module]/views/[view].php
 * - Component: app/system/modules/[module]/app/components/[name].vue
 * - Route: /[path] (@[name])
 * - Controller: [Module]Controller::[action]
 */

const { test, expect } = require('@playwright/test');
const testConfig = require('../../helpers/test-config');
const { waitForVue, navigateAndWaitForVue, fillVueInput } = require('../../helpers/vue-helpers');

test.describe('[Feature Name]', () => {
  test.beforeAll(async () => {
    testConfig.startTestTimer();
    await testConfig.testConnectivity();
  });

  test('[test description]', async ({ page }) => {
    // Arrange
    await navigateAndWaitForVue(page, testConfig.getSiteUrl() + '/path');
    
    // Act
    await page.locator('[data-testid="element"]').click();
    
    // Assert
    await expect(page.locator('[data-testid="result"]')).toBeVisible();
  });
});
```

## 6. Common Pagekit Patterns

### Admin Navigation

```javascript
// All admin pages require login first
async function loginAsAdmin(page) {
  await navigateAndWaitForVue(page, testConfig.getAdminUrl() + '/login');
  const creds = testConfig.getAdminCredentials();
  await fillVueInput(page, 'input[name="credentials[username]"]', creds.username);
  await fillVueInput(page, 'input[name="credentials[password]"]', creds.password);
  await Promise.all([
    page.waitForURL(/\/admin(?!\/login)/),
    page.click('.js-login button')
  ]);
}
```

### Modal Interactions

```javascript
// UIkit modals need animation wait
await page.click('[data-testid="open-modal-button"]');
await page.waitForSelector('.uk-modal.uk-open', { state: 'visible' });
await page.waitForTimeout(300); // Animation
await waitForVue(page);
```

### Form Submissions with Vue

```javascript
// Vue forms need proper event triggers
await fillVueInput(page, '[data-testid="title-input"]', 'Test Title');
await fillVueInput(page, '[data-testid="content-input"]', 'Test Content');

// Wait for both navigation AND click
await Promise.all([
  page.waitForResponse(resp => resp.url().includes('/api/') && resp.status() === 200),
  page.click('[data-testid="save-button"]')
]);
```

## Reference Files

- **Selector Reference**: See [selectors.md](selectors.md) for complete mapping
- **Analysis Scripts**: See `scripts/` for automated analysis helpers
- **Playwright Skill**: See `../playwright-testing/SKILL.md` for runtime patterns
