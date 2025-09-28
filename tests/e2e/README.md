# Pagekit E2E Testing Quick Start Guide

## Prerequisites

-   Node.js 18+ installed
-   Pagekit running locally (port 8080 for development, 8180 for tests)
-   Modern browser (Chrome, Firefox, Safari, or Edge)

## Installation

1. Install Playwright and dependencies:

```bash
npm install --save-dev @playwright/test dotenv
npx playwright install chromium firefox webkit
```

2. Verify installation:

```bash
npx playwright --version
```

## Configuration

Before running E2E tests, you need to create a test configuration file:

1. **Copy the example configuration:**

```bash
cp tests/e2e/config/test-config.example.json tests/e2e/config/test-config.json
```

2. **Update the configuration with your actual test data:**

    - Admin credentials (username, password, email)
    - Site URL (where Pagekit is running)
    - Database settings (if using MySQL)

3. **Ensure Pagekit is installed** with the specified admin credentials

## Example Configuration

```json
{
    "auth": {
        "admin": {
            "username": "YOUR_ADMIN_USERNAME",
            "password": "YOUR_ADMIN_PASSWORD",
            "email": "YOUR_ADMIN_EMAIL"
        }
    },
    "site": {
        "title": "YOUR_SITE_TITLE",
        "url": "http://localhost:8180",
        "adminUrl": "http://localhost:8180/admin"
    },
    "installation": {
        "language": "en_US",
        "demoContent": true
    }
}
```

**Important**: Replace all `YOUR_*` placeholders with your actual values!

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
# Installation tests
npx playwright test tests/e2e/specs/01-setup/installation.spec.js

# Core functionality tests
npx playwright test tests/e2e/specs/02-core/authentication.spec.js
npx playwright test tests/e2e/specs/02-core/dashboard.spec.js

# Content management tests
npx playwright test tests/e2e/specs/03-content/pages.spec.js
npx playwright test tests/e2e/specs/03-content/blog.spec.js
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
import { TestConfig } from '../helpers/test-config.js';

const config = new TestConfig();

test.describe('My Feature Tests', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto(config.getSiteUrl());
    });

    test('should perform action', async ({ page }) => {
        // Use config helpers for authentication
        const adminCreds = config.getAdminCredentials();
        // Your test code here
        await expect(page).toHaveTitle(/Pagekit/);
    });
});
```

### Using Helper Functions

```javascript
import { TestConfig } from '../helpers/test-config.js';
import { VueHelpers } from '../helpers/vue-helpers.js';

const config = new TestConfig();
const vueHelpers = new VueHelpers();

test('create a new page', async ({ page }) => {
    // Use centralized configuration
    await page.goto(config.getAdminUrl());
    
    // Use modern helper functions
    await vueHelpers.waitForVueComponent(page, '.pk-page-component');
    
    // Test implementation...
});
```

## Test Organization

```
tests/e2e/
├── specs/                    # Test specifications (organized by category)
│   ├── 01-setup/            # Installation and setup tests
│   │   └── installation.spec.js
│   ├── 02-core/             # Core functionality tests
│   │   ├── authentication.spec.js
│   │   ├── dashboard.spec.js
│   │   └── settings.spec.js
│   ├── 03-content/          # Content management tests
│   │   ├── blog.spec.js
│   │   ├── media.spec.js
│   │   └── pages.spec.js
│   ├── 04-frontend/         # Frontend functionality tests
│   │   └── public-pages.spec.js
│   └── 05-features/         # Advanced feature tests
│       ├── menu-system.spec.js
│       ├── user-management.spec.js
│       └── widgets.spec.js
├── helpers/                  # Helper functions and utilities
│   ├── test-config.js       # Central configuration management
│   └── vue-helpers.js       # Vue.js specific helpers
├── config/                   # Test configuration files
│   ├── test-config.example.json  # Template configuration
│   └── test-config.json          # Local configuration (ignored by git)
└── README.md                # This documentation
```

## Viewing Test Results

### HTML Report

```bash
npm run test:e2e:report
```

### Console Output

Tests show real-time progress in the console with:

-   ✓ Passed tests (green)
-   ✗ Failed tests (red)
-   ○ Skipped tests (yellow)

### Screenshots and Videos

Failed tests automatically capture:

-   Screenshots: `test-results/*/screenshots/`
-   Videos: `test-results/*/videos/`
-   Traces: `test-results/*/traces/`

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

# Smoke tests (quick validation)
npx playwright test --config=playwright.smoke.config.js

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

-   Increase timeout in `playwright.config.js`
-   Add explicit waits for slow operations
-   Check if application is running

### Cannot Find Elements

-   Use Playwright Inspector to debug selectors
-   Check if elements are loaded dynamically
-   Use more specific selectors

### Tests Pass Locally but Fail in CI

-   Ensure same browser versions
-   Check environment variables
-   Verify database state

### Permission Errors

-   Ensure test user has correct permissions
-   Check file/folder permissions for uploads
-   Verify database user privileges

## Security Note

The `test-config.json` file contains sensitive information and is automatically ignored by git. Never commit this file to the repository.

## Support

For issues or questions:

1. Check the troubleshooting section
2. Review test logs and traces
3. Consult E2E_TESTING_FOUNDATION.md for architecture details
4. Check Playwright documentation: https://playwright.dev
