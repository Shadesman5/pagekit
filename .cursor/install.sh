#!/bin/bash
set -e

# Map Cursor secret to standard GitHub CLI env var
export GH_TOKEN="${PAGEKIT_BACKGROUND_AGENT}"

# Robust lock to prevent duplicate execution (e.g. parallel agent invocations)
# Only remove lock file when WE acquired it; otherwise we'd unlink the file the
# holder has open, freeing the path for a third process to create a new file and bypass the lock.
LOCK_FILE="/tmp/pagekit-setup.lock"
LOCK_OWNER=
cleanup() {
    [ -n "$LOCK_FD" ] && flock -u "$LOCK_FD" 2>/dev/null || true
    [ -n "$LOCK_OWNER" ] && rm -f "$LOCK_FILE"
}
trap cleanup EXIT

if command -v flock >/dev/null 2>&1; then
    exec 200>"$LOCK_FILE"
    if ! flock -n 200; then
        echo "⚠️ Setup already running elsewhere, skipping..."
        exit 0
    fi
    LOCK_FD=200
    LOCK_OWNER=1
else
    # Fallback for systems without flock (e.g. minimal Snapshot)
    if [ -f "$LOCK_FILE" ]; then
        echo "⚠️ Setup already running, skipping..."
        exit 0
    fi
    echo "$$" > "$LOCK_FILE"
    LOCK_OWNER=1
fi

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
# Use composer update (not install) so lock stays in sync with composer.json after git pull/merges.
# composer install would fail on stale lock; update resolves fresh and keeps reproducible lock for next run.
echo "📚 Installing PHP dependencies..."

# Detect environment: Dockerfile (tools pre-installed) vs Snapshot (need to install)
echo "🔍 Detecting environment..."
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
    echo "⚠️ Composer not found, attempting installation..."
    
    # Check if we have sudo rights (Snapshot scenario)
    if command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
        echo "🔧 Snapshot environment detected - installing dependencies with sudo"
        
        # Install PHP if not available
        if ! command -v php >/dev/null 2>&1; then
            echo "📦 Installing PHP with required extensions..."
            sudo apt-get update
            sudo apt-get install -y \
                php \
                php-cli \
                php-curl \
                php-zip \
                php-mbstring \
                php-xml \
                php-mysql \
                php-sqlite3 \
                php-pdo \
                php-gd \
                php-bcmath
            echo "✅ PHP installed with PDO drivers (MySQL, SQLite)"
        else
            # PHP exists but check if PDO drivers are available
            echo "✅ PHP found: $(php -v | head -n 1)"
            echo "🔍 Checking PDO drivers..."
            
            # Check for PDO MySQL
            if ! php -m | grep -q pdo_mysql; then
                echo "⚠️  PDO MySQL driver missing, installing..."
                if command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
                    sudo apt-get update
                    sudo apt-get install -y php-mysql
                fi
            fi
            
            # Check for PDO SQLite
            if ! php -m | grep -q pdo_sqlite; then
                echo "⚠️  PDO SQLite driver missing, installing..."
                if command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
                    sudo apt-get update
                    sudo apt-get install -y php-sqlite3
                fi
            fi
            
            echo "✅ PDO drivers check complete"
        fi
        
        # Install Composer globally
        if command -v php >/dev/null 2>&1; then
            echo "📦 Installing Composer globally..."
            curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
            sudo chmod +x /usr/local/bin/composer
            COMPOSER_CMD="composer"
            echo "✅ Composer installed globally"
        else
            echo "❌ Error: PHP installation failed"
            echo "🔍 Debugging information:"
            echo "  - Current user: $(whoami)"
            echo "  - Current directory: $(pwd)"
            echo "  - PATH: $PATH"
            which php || echo "  - php not in PATH"
            which composer || echo "  - composer not in PATH"
            exit 1
        fi
    else
        # No sudo - try local installation (fallback)
        echo "🔧 No sudo access - installing Composer locally"
        
        if ! command -v php >/dev/null 2>&1; then
            echo "❌ Error: PHP not found and cannot install without sudo"
            echo "🔍 Debugging information:"
            echo "  - Current user: $(whoami)"
            echo "  - Current directory: $(pwd)"
            echo "  - PATH: $PATH"
            echo "  - sudo available: $(command -v sudo >/dev/null 2>&1 && echo 'yes' || echo 'no')"
            which php || echo "  - php not in PATH"
            which composer || echo "  - composer not in PATH"
            echo ""
            echo "💡 Solutions:"
            echo "  1. Use Dockerfile environment (recommended)"
            echo "  2. Install PHP manually: apt-get install php php-cli"
            echo "  3. Grant sudo rights to current user"
            exit 1
        fi
        
        # Install Composer locally in /tmp
        echo "📦 Installing Composer to /tmp..."
        curl -sS https://getcomposer.org/installer | php -- --install-dir=/tmp --filename=composer
        chmod +x /tmp/composer
        COMPOSER_CMD="/tmp/composer"
        echo "✅ Composer installed at /tmp/composer"
    fi
fi

echo "📦 Running: $COMPOSER_CMD update --no-interaction"
$COMPOSER_CMD update --no-interaction

# Install Node dependencies
echo "📦 Installing Node dependencies..."

# Check if Node.js and Yarn are available
if ! command -v node >/dev/null 2>&1; then
    echo "⚠️ Node.js not found, attempting installation..."
    
    if command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
        echo "📦 Installing Node.js..."
        sudo apt-get update
        sudo apt-get install -y nodejs npm
    else
        echo "❌ Error: Node.js not found and cannot install without sudo"
        echo "🔍 Debugging information:"
        echo "  - Current user: $(whoami)"
        echo "  - Current directory: $(pwd)"
        echo "  - PATH: $PATH"
        echo "  - sudo available: $(command -v sudo >/dev/null 2>&1 && echo 'yes' || echo 'no')"
        which node || echo "  - node not in PATH"
        which npm || echo "  - npm not in PATH"
        which yarn || echo "  - yarn not in PATH"
        echo ""
        echo "💡 Solutions:"
        echo "  1. Use Dockerfile environment (recommended)"
        echo "  2. Install Node.js manually: apt-get install nodejs npm"
        echo "  3. Use NVM: curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.0/install.sh | bash"
        exit 1
    fi
fi

if ! command -v yarn >/dev/null 2>&1; then
    echo "⚠️ Yarn not found, attempting installation..."
    
    if command -v npm >/dev/null 2>&1; then
        if command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
            echo "📦 Installing Yarn globally..."
            sudo npm install -g yarn@1.22
        else
            echo "📦 Installing Yarn locally..."
            npm install -g yarn@1.22
        fi
    else
        echo "❌ Error: npm not found, cannot install Yarn"
        echo "🔍 Debugging information:"
        echo "  - Current user: $(whoami)"
        echo "  - Node.js: $(node --version 2>/dev/null || echo 'not available')"
        which npm || echo "  - npm not in PATH"
        which yarn || echo "  - yarn not in PATH"
        echo ""
        echo "💡 Solutions:"
        echo "  1. Use Dockerfile environment (recommended)"
        echo "  2. Install npm first: apt-get install npm"
        exit 1
    fi
fi

echo "✅ Node.js: $(node --version)"
echo "✅ Yarn: $(yarn --version)"
echo "📦 Running: yarn install"
yarn install

# Install Playwright and browsers for E2E testing
echo "🎭 Setting up Playwright for E2E testing..."
if command -v npx >/dev/null 2>&1; then
    echo "📦 Installing Playwright browsers..."
    npx playwright install chromium
    echo "✅ Playwright browsers installed"
else
    echo "⚠️ npx not found, skipping Playwright browser installation"
fi

# Create E2E test configuration if not exists
echo "⚙️ Setting up E2E test configuration..."
if [ ! -f "tests/e2e/config/test-config.json" ]; then
    if [ -f "tests/e2e/config/test-config.example.json" ]; then
        echo "📝 Creating test-config.json from example..."
        cp tests/e2e/config/test-config.example.json tests/e2e/config/test-config.json
        echo "✅ test-config.json created"
        echo "⚠️ IMPORTANT: Edit tests/e2e/config/test-config.json with YOUR credentials!"
        echo "⚠️ Replace all 'YOUR_*' placeholders with actual values"
    else
        echo "⚠️ test-config.example.json not found, skipping test config creation"
    fi
else
    echo "✅ test-config.json already exists"
fi

# Compile frontend assets for initial setup
echo "🔨 Compiling frontend assets..."
if [ -f "package.json" ] && command -v yarn >/dev/null 2>&1; then
    yarn compile-js --mode=production
    echo "✅ Frontend assets compiled"
else
    echo "⚠️ Cannot compile assets, yarn or package.json not available"
fi

# Run initial tests to verify setup
echo "🧪 Running initial test suite..."
if [ -f "./app/vendor/bin/phpunit" ]; then
    ./app/vendor/bin/phpunit --version
    echo "✅ PHPUnit available at ./app/vendor/bin/phpunit"
elif [ -f "./vendor/bin/phpunit" ]; then
    ./vendor/bin/phpunit --version
    echo "✅ PHPUnit available at ./vendor/bin/phpunit"
else
    echo "⚠️ PHPUnit not found, but setup completed successfully"
fi

# Verify Playwright installation
if command -v npx >/dev/null 2>&1; then
    echo "🎭 Verifying Playwright..."
    npx playwright --version || echo "⚠️ Playwright verification failed"
fi

echo ""
echo "✅ Setup complete! Ready for modernization tasks."
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📋 Environment Summary:"
echo "  • Environment: $([ -f /.dockerenv ] && echo 'Dockerfile' || echo 'Snapshot')"
echo "  • PHP version: $(php -v | head -n 1)"
echo "  • Composer: $COMPOSER_CMD ($(${COMPOSER_CMD} --version))"
echo "  • PDO MySQL: $(php -m | grep -q pdo_mysql && echo '✅ available' || echo '❌ missing')"
echo "  • PDO SQLite: $(php -m | grep -q pdo_sqlite && echo '✅ available' || echo '❌ missing')"
echo "  • Node version: $(node --version)"
echo "  • Yarn version: $(yarn --version)"
echo "  • Playwright: $(npx playwright --version 2>/dev/null || echo 'Not available')"
echo "  • PHPUnit: $(./app/vendor/bin/phpunit --version 2>/dev/null | head -n 1 || echo 'Not available')"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "🎯 E2E Testing Setup:"
echo "  • Test config: tests/e2e/config/test-config.json"
echo "  • ⚠️  EDIT test-config.json with YOUR credentials before running tests!"
echo "  • MCP Playwright tools available for browser automation"
echo ""
echo "🚀 To start Pagekit server for E2E tests:"
echo "   php pagekit start -s localhost:8080 --no-ansi"
echo ""
echo "🧪 To run fresh installation test:"
echo "   npx playwright test tests/e2e/specs/01-setup/installation.spec.js --project=chromium"
echo ""
