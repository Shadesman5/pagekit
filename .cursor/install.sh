#!/bin/bash
set -e

# GitHub Token mapping
if [[ -n "${PAGEKIT_BACKGROUND_AGENT:-}" ]]; then
    export GH_TOKEN="$PAGEKIT_BACKGROUND_AGENT"
fi

# CLI tooling for modernization shell workflows and agent verification sweeps:
#   - ripgrep (rg): fast code search assumed by the tooling rules and prompts
#   - jq: JSON processing for gh/Bugbot orchestrator scripts
# Installed here (not only in the Dockerfile) because snapshot-based cloud
# environments ignore the Dockerfile at runtime; install.sh runs on every cold
# boot on top of the snapshot. The command -v guard keeps it idempotent and a
# fast no-op once the tools are baked into a rebuilt image.
if ! command -v rg >/dev/null 2>&1 || ! command -v jq >/dev/null 2>&1; then
    APT_SUDO=""
    [ "$(id -u)" -ne 0 ] && APT_SUDO="sudo"
    $APT_SUDO apt-get update
    $APT_SUDO apt-get install -y ripgrep jq
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
command -v rg >/dev/null 2>&1 && rg --version | head -1 || echo "WARNING: ripgrep (rg) not available"
command -v jq >/dev/null 2>&1 && jq --version || echo "WARNING: jq not available"
php pagekit list 2>/dev/null | head -1 || echo "WARNING: pagekit CLI not available (config.php may be missing)"
echo "--- Setup complete ---"
