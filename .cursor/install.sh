#!/bin/bash
set -e

# Map Cursor secret to standard GitHub CLI env var
export GH_TOKEN="${PAGEKIT_BACKGROUND_AGENT}"

echo "🚀 Starting Pagekit Background Agent Setup..."

# Clone the repository if not exists
if [ ! -d "/home/ubuntu/pagekit" ]; then
    echo "📦 Cloning Pagekit repository..."
    git clone https://github.com/Shadesman5/pagekit.git /home/ubuntu/pagekit
fi

cd /home/ubuntu/pagekit

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
