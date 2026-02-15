#!/usr/bin/env bash
# ============================================================================
# Set Labels & Milestones for Issues #124-#136
# Based on ROADMAP.md and github-issue-creator SKILL.md
#
# Usage:
#   GITHUB_TOKEN=ghp_xxx bash .cursor/scripts/set-issue-metadata.sh
#
# Or if gh is already authenticated with a token that has issues:write:
#   bash .cursor/scripts/set-issue-metadata.sh
# ============================================================================

set -euo pipefail

REPO="Shadesman5/pagekit"
MILESTONE="Phase 1: Foundation"

# If GITHUB_TOKEN or PAGEKIT_BACKGROUND_AGENT is set, use it for gh
if [[ -n "${PAGEKIT_BACKGROUND_AGENT:-}" ]]; then
  export GH_TOKEN="$PAGEKIT_BACKGROUND_AGENT"
  echo "Using PAGEKIT_BACKGROUND_AGENT token"
elif [[ -n "${GITHUB_TOKEN:-}" ]]; then
  export GH_TOKEN="$GITHUB_TOKEN"
  echo "Using GITHUB_TOKEN"
else
  echo "Using default gh auth token"
fi

# Label mapping: issue_number|labels (comma-separated)
# Formula: 1 phase label + 1 type label + 1-2 area labels
declare -A ISSUE_LABELS=(
  [124]="phase-1,migration,backend"                  # Step 1.4: Safe Minor Updates
  [125]="phase-1,migration,database,backend"          # Step 1.5: Doctrine DBAL 3.x
  [126]="phase-1,migration,backend"                  # Step 1.6: PSR-11 Container Compatibility
  [127]="phase-1,migration,backend"                  # Step 1.7: Event System Compatibility
  [128]="phase-1,migration,backend"                  # Step 1.8: Routing System Compatibility
  [129]="phase-1,migration,backend"                  # Step 1.9: Symfony 6.4 LTS Components
  [130]="phase-1,migration,backend"                  # Step 1.10: PSR-6 Cache
  [131]="phase-1,migration,database,backend"          # Step 1.11: ORM Modernization
  [132]="phase-1,migration,database"                 # Step 1.12: DB Migration System
  [133]="phase-1,migration,backend"                  # Step 1.13: Validation Update
  [134]="phase-1,migration,database,backend"          # Step 1.14: Doctrine Attributes
  [135]="phase-1,enhancement,frontend"               # Step 1.10.5: E2E Testing with Playwright
  [136]="phase-1,security,backend,frontend"          # Step 1.13.5: Template Security Hardening (CSP)
)

SUCCESS=0
FAIL=0

for ISSUE_NUM in $(echo "${!ISSUE_LABELS[@]}" | tr ' ' '\n' | sort -n); do
  LABELS="${ISSUE_LABELS[$ISSUE_NUM]}"
  echo ""
  echo "━━━ #${ISSUE_NUM}: Labels=[${LABELS}] Milestone=[${MILESTONE}] ━━━"

  if gh issue edit "$ISSUE_NUM" --repo "$REPO" \
    --add-label "$LABELS" \
    --milestone "$MILESTONE" 2>&1; then
    echo "  ✅ Success"
    ((SUCCESS++))
  else
    echo "  ❌ Failed"
    ((FAIL++))
  fi
done

echo ""
echo "════════════════════════════════════"
echo "Done: ${SUCCESS} succeeded, ${FAIL} failed"
echo "════════════════════════════════════"
