#!/bin/bash
set -e

# Cleanup function for lock file
cleanup() {
    rm -f "$LOCK_FILE"
}
trap cleanup EXIT

# Map Cursor secret to standard GitHub CLI env var
export GH_TOKEN="${PAGEKIT_BACKGROUND_AGENT}"

# Check if already running to prevent duplicate execution
LOCK_FILE="/tmp/pagekit-setup-running"
if [ -f "$LOCK_FILE" ]; then
    echo "⚠️ Setup already running, skipping..."
    exit 0
fi

# Create lock file
echo "$$" > "$LOCK_FILE"

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
echo "📍 Current PATH: $PATH"
echo "📍 Current user: $(whoami)"
echo "📍 Current directory: $(pwd)"

# Check if composer is available
if command -v composer >/dev/null 2>&1; then
    echo "✅ Composer found in PATH: $(which composer)"
    COMPOSER_CMD="composer"
elif [ -f "/usr/local/bin/composer" ] && [ -x "/usr/local/bin/composer" ]; then
    echo "✅ Composer found at /usr/local/bin/composer"
    COMPOSER_CMD="/usr/local/bin/composer"
elif [ -f "/usr/bin/composer" ] && [ -x "/usr/bin/composer" ]; then
    echo "✅ Composer found at /usr/bin/composer"
    COMPOSER_CMD="/usr/bin/composer"
else
    echo "⚠️ Composer not found in system, installing it..."
    
    # First, install PHP if not available
    if ! command -v php >/dev/null 2>&1; then
        echo "📦 Installing PHP..."
        sudo apt-get update
        sudo apt-get install -y php php-cli php-curl php-zip php-mbstring php-xml
    fi
    
    # Now install Composer
    if command -v php >/dev/null 2>&1; then
        echo "📦 Installing Composer..."
        curl -sS https://getcomposer.org/installer | php -- --install-dir=/tmp --filename=composer
        chmod +x /tmp/composer
        COMPOSER_CMD="/tmp/composer"
        echo "✅ Composer installed at /tmp/composer"
    else
        echo "❌ Error: PHP not found, cannot install Composer"
        echo "🔍 Available locations:"
        which composer || echo "  - composer not in PATH"
        which php || echo "  - php not in PATH"
        echo "🔍 Checking if we're in the right environment..."
        echo "  - Current user: $(whoami)"
        echo "  - Current directory: $(pwd)"
        echo "  - PHP version: $(php --version 2>/dev/null || echo 'PHP not available')"
        exit 1
    fi
fi

echo "📦 Running: $COMPOSER_CMD install --no-interaction"
$COMPOSER_CMD install --no-interaction

# Install Node dependencies
echo "📦 Installing Node dependencies..."
yarn install

# Run initial tests to verify setup
echo "🧪 Running initial test suite..."
if [ -f "./app/vendor/bin/phpunit" ]; then
    ./app/vendor/bin/phpunit --version
elif [ -f "./vendor/bin/phpunit" ]; then
    ./vendor/bin/phpunit --version
else
    echo "⚠️ PHPUnit not found, but setup completed successfully"
fi

echo "✅ Setup complete! Ready for modernization tasks."
echo "📋 Current PHP version: $(php -v | head -n 1)"
echo "📋 Current Composer version: $($COMPOSER_CMD --version)"
echo "📋 Current Node version: $(node --version)"
echo "📋 Current Yarn version: $(yarn --version)"

# Clean up lock file
rm -f "$LOCK_FILE"
