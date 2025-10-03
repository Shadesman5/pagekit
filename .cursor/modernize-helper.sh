#!/bin/bash
set -e

# Map Cursor secret to standard GitHub CLI env var
export GH_TOKEN="${PAGEKIT_BACKGROUND_AGENT}"

# Helper script for Pagekit modernization tasks
# The background agent works in the current workspace directory

echo "📍 Working in current workspace: $(pwd)"

# Find composer command
if command -v composer >/dev/null 2>&1; then
    COMPOSER_CMD="composer"
elif [ -f "/usr/bin/composer" ]; then
    COMPOSER_CMD="/usr/bin/composer"
else
    echo "❌ Error: Composer not found in system"
    echo "🔍 Note: /workspace/packages/composer is for Pagekit extensions only"
    exit 1
fi

function create_branch() {
    local branch_name=$1
    echo "🌿 Creating branch: $branch_name"
    git checkout develop
    git pull origin develop
    git checkout -b "$branch_name"
    echo "✅ Branch '$branch_name' created and checked out"
}

function run_tests() {
    echo "🧪 Running test suite..."
    ./vendor/bin/phpunit
}

function check_linting() {
    echo "🔍 Checking PHP code style..."
    # Add phpcs or other linting tools if configured
    $COMPOSER_CMD validate
}

function update_changelog() {
    echo "📝 Updating changelog..."
    local version=$(grep '"version"' composer.json | cut -d'"' -f4)
    local date=$(date +"%Y-%m-%d")
    echo "Version: $version, Date: $date"
}

function create_pr() {
    local title=$1
    local body=$2
    echo "🔀 Creating Pull Request..."
    gh pr create --base develop --title "$title" --body "$body"
}

# Task-specific functions
function upgrade_phpunit() {
    echo "📦 Upgrading PHPUnit to 11.x..."
    create_branch "feature/phpunit-11-upgrade"
    
    # Update PHP version requirement
    sed -i 's/"php": ">=7.4"/"php": ">=8.2"/' composer.json
    
    # Update PHPUnit
    $COMPOSER_CMD require --dev phpunit/phpunit:^11.0
    
    run_tests
}

function patch_security() {
    echo "🔒 Patching security vulnerabilities..."
    create_branch "feature/security-patches"
    
    # Run composer audit
    $COMPOSER_CMD audit
    
    # Update packages one by one
    # Add specific update commands here
}

function upgrade_symfony() {
    echo "🎯 Upgrading Symfony to 6.4..."
    create_branch "feature/symfony-6.4-upgrade"
    
    # Update all Symfony packages
    # Add specific update commands here
}

# Main menu
case "$1" in
    "phpunit")
        upgrade_phpunit
        ;;
    "security")
        patch_security
        ;;
    "symfony")
        upgrade_symfony
        ;;
    "test")
        run_tests
        ;;
    "branch")
        create_branch "$2"
        ;;
    "pr")
        create_pr "$2" "$3"
        ;;
    *)
        echo "Usage: $0 {phpunit|security|symfony|test|branch|pr}"
        echo ""
        echo "Available commands:"
        echo "  phpunit    - Upgrade PHPUnit to 11.x"
        echo "  security   - Patch security vulnerabilities"
        echo "  symfony    - Upgrade Symfony to 6.4"
        echo "  test       - Run test suite"
        echo "  branch     - Create a new feature branch"
        echo "  pr         - Create a pull request"
        exit 1
        ;;
esac
