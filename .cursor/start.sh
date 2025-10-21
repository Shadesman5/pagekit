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

# Optional: Start a simple HTTP server for testing
# cd /home/ubuntu/pagekit && php -S 0.0.0.0:8080 -t . &

# Keep container alive
tail -f /dev/null
