#!/bin/bash
#
# Reset E2E test environment to clean state
#

echo "🔄 Resetting Pagekit E2E Test Environment..."

# Stop containers first
echo "🛑 Stopping containers..."
if command -v docker-compose &> /dev/null; then
    docker-compose -f docker-compose.e2e.yml down -v
fi

# Clean test directories
echo "🗑️  Cleaning test directories..."
rm -rf storage-e2e/*
rm -rf tmp-e2e/*

# Recreate directories
echo "📁 Recreating clean directories..."
mkdir -p storage-e2e
mkdir -p tmp-e2e
mkdir -p tests/e2e/fixtures

# Set proper permissions
chmod 777 storage-e2e
chmod 777 tmp-e2e

# Remove any test artifacts
echo "🧹 Cleaning test artifacts..."
rm -rf test-results/
rm -rf playwright-report/
rm -rf .playwright/

echo ""
echo "✅ E2E Test Environment has been reset to clean state!"
echo ""
echo "💡 Run ./scripts/e2e-start.sh to start fresh test environment."