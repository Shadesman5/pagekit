#!/bin/bash
set -e

# Map Cursor secret to standard GitHub CLI env var
export GH_TOKEN="${PAGEKIT_BACKGROUND_AGENT}"

echo "🤖 Pagekit Background Agent started!"
echo "📍 Working directory: $(pwd)"
echo "🌿 Current branch: $(git branch --show-current)"

# Keep the container running
echo "⏳ Agent is ready and waiting for tasks..."
echo "💡 Tip: Use 'push' rule to create commits automatically"

# Start Pagekit development server in background
echo "🚀 Starting Pagekit development server on port 8080..."
cd /workspace && php pagekit start -s 0.0.0.0:8080 --no-ansi > /tmp/pagekit-server.log 2>&1 &
SERVER_PID=$!
echo "✅ Pagekit server started (PID: $SERVER_PID)"
echo "📝 Server logs: /tmp/pagekit-server.log"

# Wait for server to be ready
echo "⏳ Waiting for server to be ready..."
for i in {1..30}; do
    if curl -s http://localhost:8080 > /dev/null 2>&1; then
        echo "✅ Server is ready!"
        break
    fi
    sleep 1
done

# Keep container alive
tail -f /dev/null
