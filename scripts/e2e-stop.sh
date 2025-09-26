#!/bin/bash
#
# Stop E2E test environment for Pagekit
#

echo "🛑 Stopping Pagekit E2E Test Environment..."

# Check if Docker is installed
if ! command -v docker &> /dev/null; then
    echo "⚠️  Docker is not installed. Nothing to stop."
    exit 0
fi

# Check if docker-compose is installed
if ! command -v docker-compose &> /dev/null; then
    echo "⚠️  docker-compose is not installed. Nothing to stop."
    exit 0
fi

# Stop and remove containers
echo "🐳 Stopping Docker containers..."
docker-compose -f docker-compose.e2e.yml down

# Optional: Remove volumes (uncomment if you want to clean volumes too)
# echo "🗑️  Removing Docker volumes..."
# docker-compose -f docker-compose.e2e.yml down -v

echo ""
echo "✅ E2E Test Environment stopped successfully!"
echo ""
echo "💡 Note: Test data in storage-e2e/ and tmp-e2e/ has been preserved."
echo "   Run ./scripts/e2e-reset.sh to clean test data."