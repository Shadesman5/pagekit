#!/bin/bash
set -e

# GitHub Token mapping
if [[ -n "${PAGEKIT_BACKGROUND_AGENT:-}" ]]; then
    export GH_TOKEN="$PAGEKIT_BACKGROUND_AGENT"
fi

# PHP dependencies (lock file is tracked — install exact versions)
composer install --no-interaction --optimize-autoloader --working-dir=.

# Node dependencies (--frozen-lockfile prevents silent lockfile mutation;
# postinstall hook also triggers the production build)
yarn install --frozen-lockfile

# Writable directories (tmp/sessions is required by the session handler)
mkdir -p tmp/logs tmp/cache tmp/temp tmp/packages tmp/sessions storage

# Refresh Playwright browser after dependency updates (snapshot already ships
# chromium; this re-applies it whenever the package version changes)
npx playwright install chromium

# Verify critical tools are available
echo "--- Tool verification ---"
php -v | head -1
./app/vendor/bin/phpunit --version 2>/dev/null || echo "WARNING: PHPUnit not available"
./app/vendor/bin/phpstan --version 2>/dev/null || echo "WARNING: PHPStan not available"
php pagekit list 2>/dev/null | head -1 || echo "WARNING: pagekit CLI not available (config.php may be missing)"
echo "--- Setup complete ---"
