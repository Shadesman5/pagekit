#!/bin/bash

# Symfony 6.4 Upgrade Test Script
# This script tests all critical functionality after each change

echo "================================================"
echo "Pagekit Symfony 6.4 Upgrade Test Suite"
echo "================================================"
echo ""

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test results
TESTS_PASSED=0
TESTS_FAILED=0

# Function to run a test
run_test() {
    local test_name="$1"
    local test_command="$2"
    local expected_exit_code="${3:-0}"
    
    echo -n "Testing: $test_name... "
    
    # Run the command and capture output
    output=$(eval "$test_command" 2>&1)
    exit_code=$?
    
    if [ $exit_code -eq $expected_exit_code ]; then
        echo -e "${GREEN}✓ PASSED${NC}"
        TESTS_PASSED=$((TESTS_PASSED + 1))
        return 0
    else
        echo -e "${RED}✗ FAILED${NC}"
        echo "  Command: $test_command"
        echo "  Expected exit code: $expected_exit_code, Got: $exit_code"
        echo "  Output: $output" | head -n 5
        TESTS_FAILED=$((TESTS_FAILED + 1))
        return 1
    fi
}

# Function to check HTTP response
check_http_response() {
    local url="$1"
    local expected_code="${2:-200}"
    local test_name="$3"
    
    echo -n "Testing: $test_name... "
    
    # Check if curl is available
    if ! command -v curl &> /dev/null; then
        echo -e "${YELLOW}⚠ SKIPPED (curl not available)${NC}"
        return 0
    fi
    
    # Get HTTP status code
    http_code=$(curl -s -o /dev/null -w "%{http_code}" "$url" 2>/dev/null)
    
    if [ "$http_code" = "$expected_code" ]; then
        echo -e "${GREEN}✓ PASSED (HTTP $http_code)${NC}"
        TESTS_PASSED=$((TESTS_PASSED + 1))
        return 0
    else
        echo -e "${RED}✗ FAILED (Expected HTTP $expected_code, Got HTTP $http_code)${NC}"
        TESTS_FAILED=$((TESTS_FAILED + 1))
        return 1
    fi
}

echo "1. CONSOLE TESTS"
echo "----------------"

# Test console commands
run_test "Console - Check Symfony version" "php pagekit about 2>&1 | grep -i symfony | head -1"
run_test "Console - List commands" "php pagekit list"
run_test "Console - Database status" "php pagekit migrate:status"

echo ""
echo "2. COMPOSER TESTS"
echo "-----------------"

# Test composer dependencies
run_test "Composer - Validate composer.json" "composer validate --no-check-publish"
run_test "Composer - Check for security issues" "composer audit"

echo ""
echo "3. PHP SYNTAX TESTS"
echo "-------------------"

# Check PHP syntax in key files
run_test "PHP Syntax - Application.php" "php -l app/modules/application/src/Application.php"
run_test "PHP Syntax - Container.php" "php -l app/modules/application/src/Container.php"
run_test "PHP Syntax - Router.php" "php -l app/modules/routing/src/Router.php"
run_test "PHP Syntax - index.php" "php -l index.php"

echo ""
echo "4. WEB SERVER TESTS (if server is running)"
echo "------------------------------------------"

# Start a test server if not running
SERVER_PID=""
if ! curl -s http://localhost:8000 > /dev/null 2>&1; then
    echo -e "${YELLOW}Starting test server on port 8000...${NC}"
    php -S localhost:8000 -t . > /dev/null 2>&1 &
    SERVER_PID=$!
    sleep 3
fi

# Test web endpoints
check_http_response "http://localhost:8000" "200" "Web - Homepage"
check_http_response "http://localhost:8000/admin" "302" "Web - Admin redirect"
check_http_response "http://localhost:8000/api/system/auth/user" "401" "Web - API endpoint"

# Kill test server if we started it
if [ -n "$SERVER_PID" ]; then
    echo -e "${YELLOW}Stopping test server...${NC}"
    kill $SERVER_PID 2>/dev/null
fi

echo ""
echo "5. PHPUNIT TESTS"
echo "----------------"

# Run PHPUnit tests if available
if [ -f "vendor/bin/phpunit" ]; then
    echo "Running PHPUnit tests (this may take a moment)..."
    vendor/bin/phpunit --testsuite unit --no-coverage --stop-on-failure 2>&1 | grep -E "(OK|FAILURES|ERRORS|Tests:|Assertions:)" | head -5
else
    echo -e "${YELLOW}⚠ PHPUnit not installed${NC}"
fi

echo ""
echo "================================================"
echo "TEST RESULTS SUMMARY"
echo "================================================"
echo -e "Tests Passed: ${GREEN}$TESTS_PASSED${NC}"
echo -e "Tests Failed: ${RED}$TESTS_FAILED${NC}"

if [ $TESTS_FAILED -eq 0 ]; then
    echo -e "${GREEN}✓ ALL TESTS PASSED!${NC}"
    exit 0
else
    echo -e "${RED}✗ SOME TESTS FAILED!${NC}"
    echo "Please fix the issues before continuing with the Symfony upgrade."
    exit 1
fi