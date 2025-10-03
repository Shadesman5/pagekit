#!/bin/bash
set -e

# Map Cursor secret to standard GitHub CLI env var
export GH_TOKEN="${PAGEKIT_BACKGROUND_AGENT}"

# Check if already running to prevent duplicate execution
if [ -f "/tmp/pagekit-setup-running" ]; then
    echo "⚠️ Setup already running, skipping..."
    exit 0
fi

# Create lock file
touch /tmp/pagekit-setup-running

echo "🚀 Starting Pagekit Background Agent Setup..."

# The background agent already has the workspace mounted
# We're working in the current directory (the workspace root)
echo "📍 Working in current workspace: $(pwd)"

# Ensure we're in a git repository
if [ ! -d ".git" ]; then
    echo "❌ Error: Not in a git repository. Background agent should have workspace mounted."
    exit 1
fi

# Configure git
echo "⚙️ Configuring Git..."
git config --global user.name "Pagekit Background Agent"
git config --global user.email "agent@pagekit.local"

# Ensure we're on develop branch
echo "🔄 Switching to develop branch..."
git checkout develop
git pull origin develop

# Install PHP dependencies
echo "📚 Installing PHP dependencies..."

# Debug: Check composer availability
echo "🔍 Checking composer availability..."
if command -v composer >/dev/null 2>&1; then
    echo "✅ Composer found in PATH: $(which composer)"
    COMPOSER_CMD="composer"
elif [ -f "/usr/bin/composer" ]; then
    echo "✅ Composer found at /usr/bin/composer"
    COMPOSER_CMD="/usr/bin/composer"
else
    echo "❌ Error: Composer not found"
    echo "🔍 Available composer locations:"
    which composer || echo "  - composer not in PATH"
    find /usr -name "composer" 2>/dev/null || echo "  - no composer found in /usr"
    echo "🔍 Checking if composer is in different location..."
    find / -name "composer" 2>/dev/null | head -5
    exit 1
fi

echo "📦 Running: $COMPOSER_CMD install --no-interaction"
$COMPOSER_CMD install --no-interaction

# Install Node dependencies
echo "📦 Installing Node dependencies..."
yarn install

# Run initial tests to verify setup
echo "🧪 Running initial test suite..."
./vendor/bin/phpunit --version

echo "✅ Setup complete! Ready for modernization tasks."
echo "📋 Current PHP version: $(php -v | head -n 1)"
echo "📋 Current Composer version: $($COMPOSER_CMD --version)"
echo "📋 Current Node version: $(node --version)"
echo "📋 Current Yarn version: $(yarn --version)"

# Clean up lock file
rm -f /tmp/pagekit-setup-running
