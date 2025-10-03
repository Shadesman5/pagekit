#!/bin/bash
set -e

# Map Cursor secret to standard GitHub CLI env var
export GH_TOKEN="${PAGEKIT_BACKGROUND_AGENT}"

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
composer install --no-interaction

# Install Node dependencies
echo "📦 Installing Node dependencies..."
yarn install

# Run initial tests to verify setup
echo "🧪 Running initial test suite..."
./vendor/bin/phpunit --version

echo "✅ Setup complete! Ready for modernization tasks."
echo "📋 Current PHP version: $(php -v | head -n 1)"
echo "📋 Current Composer version: $(composer --version)"
echo "📋 Current Node version: $(node --version)"
echo "📋 Current Yarn version: $(yarn --version)"
