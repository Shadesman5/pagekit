#!/bin/bash
#
# Start E2E test environment for Pagekit
#

echo "🚀 Starting Pagekit E2E Test Environment..."

# Check if Docker is installed
if ! command -v docker &> /dev/null; then
    echo "❌ Docker is not installed. Please install Docker first."
    echo "   Alternative: Run tests against local Pagekit instance on port 8180"
    exit 1
fi

# Check if docker-compose is installed
if ! command -v docker-compose &> /dev/null; then
    echo "❌ docker-compose is not installed. Please install docker-compose first."
    exit 1
fi

# Create necessary directories if they don't exist
echo "📁 Creating test directories..."
mkdir -p storage-e2e
mkdir -p tmp-e2e
mkdir -p tests/e2e/fixtures

# Set proper permissions
chmod 777 storage-e2e
chmod 777 tmp-e2e

# Stop any existing test containers
echo "🛑 Stopping any existing test containers..."
docker-compose -f docker-compose.e2e.yml down 2>/dev/null

# Start the test environment
echo "🐳 Starting Docker containers..."
docker-compose -f docker-compose.e2e.yml up -d

# Wait for MySQL to be ready
echo "⏳ Waiting for MySQL to be ready..."
MAX_TRIES=30
TRIES=0
while ! docker exec pagekit_mysql_e2e mysql -upagekit_e2e -ppagekit_e2e_pass -e "SELECT 1" &> /dev/null; do
    TRIES=$((TRIES + 1))
    if [ $TRIES -gt $MAX_TRIES ]; then
        echo "❌ MySQL failed to start within expected time."
        exit 1
    fi
    echo "   Waiting for MySQL... ($TRIES/$MAX_TRIES)"
    sleep 2
done

echo "✅ MySQL is ready!"

# Wait for web server to be ready
echo "⏳ Waiting for web server to be ready..."
MAX_TRIES=30
TRIES=0
while ! curl -s http://localhost:8180 > /dev/null; do
    TRIES=$((TRIES + 1))
    if [ $TRIES -gt $MAX_TRIES ]; then
        echo "❌ Web server failed to start within expected time."
        exit 1
    fi
    echo "   Waiting for web server... ($TRIES/$MAX_TRIES)"
    sleep 2
done

echo "✅ Web server is ready!"

echo ""
echo "🎉 E2E Test Environment is ready!"
echo ""
echo "📋 Test Environment Details:"
echo "   - Web URL: http://localhost:8180"
echo "   - MySQL Host: localhost:3307"
echo "   - Database: pagekit_e2e_test"
echo "   - phpMyAdmin: http://localhost:8181 (if debug profile enabled)"
echo ""
echo "🧪 Run tests with: npm run test:e2e"
echo "🛑 Stop environment with: ./scripts/e2e-stop.sh"
echo "🔄 Reset environment with: ./scripts/e2e-reset.sh"