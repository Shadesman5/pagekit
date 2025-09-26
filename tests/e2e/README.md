# Pagekit E2E Testing Quick Start Guide

## Prerequisites

- Node.js 18+ installed
- Pagekit running locally (port 8080 for development, 8180 for tests)
- Modern browser (Chrome, Firefox, Safari, or Edge)

## Installation

1. Install Playwright and dependencies:
```bash
npm install --save-dev @playwright/test @playwright/test-reporter-html dotenv
npx playwright install chromium firefox webkit
```

2. Verify installation:
```bash
npx playwright --version
```

## Running Tests

### Run All Tests
```bash
npm run test:e2e
```

### Run Tests in Headed Mode (see browser)
```bash
npm run test:e2e:headed
```

### Debug Tests
```bash
npm run test:e2e:debug
```

### Run Tests in UI Mode
```bash
npm run test:e2e:ui
```

### Run Specific Test File
```bash
npx playwright test tests/e2e/specs/001-installation.spec.js
```

### Run Tests for Specific Browser
```bash
npx playwright test --project=chromium
npx playwright test --project=firefox
npx playwright test --project=webkit
```

## Test Environment Setup

### Using Docker (Recommended)
```bash
# Start test environment
./scripts/e2e-start.sh

# Reset to clean state
./scripts/e2e-reset.sh

# Stop test environment
./scripts/e2e-stop.sh
```

### Manual Setup
1. Ensure Pagekit is running on port 8180
2. Use test database: pagekit_e2e_test
3. Use test storage: ./storage-e2e

## Writing New Tests

### Basic Test Structure
```javascript
import { test, expect } from '@playwright/test';
import { loginAsAdmin } from '../helpers/pagekit-auth.js';

test.describe('My Feature Tests', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/');
  });

  test('should perform action', async ({ page }) => {
    await loginAsAdmin(page);
    // Your test code here
    await expect(page).toHaveTitle(/Pagekit/);
  });
});
```

### Using Helper Functions
```javascript
import { createPage } from '../helpers/pagekit-content.js';
import { waitForVueComponent } from '../helpers/pagekit-ui.js';

test('create a new page', async ({ page }) => {
  await loginAsAdmin(page);
  await createPage(page, 'Test Page', 'Test content', false);
  await waitForVueComponent(page, '.pk-page-component');
});
```

## Test Organization

```
tests/e2e/
├── specs/                    # Test specifications
│   ├── 001-installation.spec.js
│   ├── 002-authentication.spec.js
│   ├── 003-content.spec.js
│   ├── 004-vue-components.spec.js
│   ├── 005-uikit.spec.js
│   └── 006-system.spec.js
├── helpers/                  # Helper functions
│   ├── pagekit-auth.js
│   ├── pagekit-content.js
│   ├── pagekit-ui.js
│   └── pagekit-system.js
├── fixtures/                 # Test data
│   ├── fresh-install.sql
│   ├── demo-content.sql
│   └── test-users.json
└── page-objects/            # Page object models (optional)
    ├── LoginPage.js
    ├── DashboardPage.js
    └── EditorPage.js
```

## Viewing Test Results

### HTML Report
```bash
npm run test:e2e:report
```

### Console Output
Tests show real-time progress in the console with:
- ✓ Passed tests (green)
- ✗ Failed tests (red)
- ○ Skipped tests (yellow)

### Screenshots and Videos
Failed tests automatically capture:
- Screenshots: `test-results/*/screenshots/`
- Videos: `test-results/*/videos/`
- Traces: `test-results/*/traces/`

## Debugging Failed Tests

1. **Use Debug Mode**:
```bash
npx playwright test --debug tests/e2e/specs/failing-test.spec.js
```

2. **View Trace**:
```bash
npx playwright show-trace test-results/*/trace.zip
```

3. **Check Screenshots**:
Look in `test-results/` folder for visual evidence

4. **Add Console Logs**:
```javascript
test('debug test', async ({ page }) => {
  await page.evaluate(() => console.log('Debug info'));
  // Check browser console in headed mode
});
```

## Best Practices

1. **Keep Tests Independent**: Each test should run in isolation
2. **Use Descriptive Names**: Test names should explain what they test
3. **Leverage Helpers**: Use helper functions for common operations
4. **Add Proper Waits**: Use Playwright's built-in wait strategies
5. **Clean Up After Tests**: Reset state when necessary
6. **Use Page Objects**: For complex pages, use page object pattern
7. **Test User Journeys**: Focus on real user workflows
8. **Handle Errors Gracefully**: Include proper error handling

## Common Commands Reference

```bash
# Install/Update Playwright
npm install -D @playwright/test@latest

# Update browsers
npx playwright install

# Run tests with specific config
npx playwright test --config=playwright.config.js

# Run tests in parallel
npx playwright test --workers=4

# Run tests with specific timeout
npx playwright test --timeout=60000

# Generate test code (recorder)
npx playwright codegen http://localhost:8180

# List all projects
npx playwright test --list

# Run specific test by name
npx playwright test -g "should create page"
```

## Troubleshooting

### Tests Fail with Timeout
- Increase timeout in `playwright.config.js`
- Add explicit waits for slow operations
- Check if application is running

### Cannot Find Elements
- Use Playwright Inspector to debug selectors
- Check if elements are loaded dynamically
- Use more specific selectors

### Tests Pass Locally but Fail in CI
- Ensure same browser versions
- Check environment variables
- Verify database state

### Permission Errors
- Ensure test user has correct permissions
- Check file/folder permissions for uploads
- Verify database user privileges

## Support

For issues or questions:
1. Check the troubleshooting section
2. Review test logs and traces
3. Consult E2E_TESTING_FOUNDATION.md for architecture details
4. Check Playwright documentation: https://playwright.dev