#!/usr/bin/env bash
# ============================================================================
# Adds <!-- metadata --> blocks to existing issue bodies (#124-#136)
# This is a ONE-TIME migration script.
#
# The metadata block enables issue-metadata-sync.yml to automatically
# apply labels and milestones.
#
# Usage (local, with authenticated gh CLI):
#   bash .cursor/scripts/add-metadata-to-issues.sh
#
# Or with explicit token:
#   GH_TOKEN=ghp_xxx bash .cursor/scripts/add-metadata-to-issues.sh
#
# Add --dry-run to preview without making changes:
#   bash .cursor/scripts/add-metadata-to-issues.sh --dry-run
# ============================================================================

set -euo pipefail

REPO="Shadesman5/pagekit"
DRY_RUN=false

if [[ "${1:-}" == "--dry-run" ]]; then
  DRY_RUN=true
  echo "=== DRY RUN MODE ==="
fi

# Issue metadata mapping: number|labels|milestone|pr
ISSUES=(
  "124|phase-1, migration, backend|Phase 1: Foundation|#53"
  "125|phase-1, migration, database, backend|Phase 1: Foundation|#54"
  "126|phase-1, migration, backend|Phase 1: Foundation|#55"
  "127|phase-1, migration, backend|Phase 1: Foundation|#56"
  "128|phase-1, migration, backend|Phase 1: Foundation|#57"
  "129|phase-1, migration, backend|Phase 1: Foundation|#60, #61"
  "130|phase-1, migration, backend|Phase 1: Foundation|#62"
  "131|phase-1, migration, database, backend|Phase 1: Foundation|#97"
  "132|phase-1, migration, database|Phase 1: Foundation|#107"
  "133|phase-1, migration, backend|Phase 1: Foundation|#108"
  "134|phase-1, migration, database, backend|Phase 1: Foundation|#111"
  "135|phase-1, enhancement, frontend|Phase 1: Foundation|#67"
  "136|phase-1, security, backend, frontend|Phase 1: Foundation|#110"
)

SUCCESS=0
SKIPPED=0
FAIL=0

for ENTRY in "${ISSUES[@]}"; do
  IFS='|' read -r NUM LABELS MILESTONE PR <<< "$ENTRY"

  echo ""
  echo "━━━ #${NUM} ━━━"

  # Get current body
  BODY=$(gh issue view "$NUM" --repo "$REPO" --json body --jq '.body')

  # Check if metadata block already exists
  if echo "$BODY" | grep -q '<!-- metadata' ; then
    echo "  Metadata block already exists, skipping"
    SKIPPED=$((SKIPPED + 1))
    continue
  fi

  # Build metadata block
  METADATA_BLOCK="
<!-- metadata
labels: ${LABELS}
milestone: ${MILESTONE}
-->"

  # Append metadata block to body
  NEW_BODY="${BODY}${METADATA_BLOCK}"

  if [[ "$DRY_RUN" == "true" ]]; then
    echo "  [DRY RUN] Would append:"
    echo "  ${METADATA_BLOCK}"
    SUCCESS=$((SUCCESS + 1))
    continue
  fi

  # Write to temp file (avoids shell escaping issues)
  TMPFILE=$(mktemp)
  printf '%s' "$NEW_BODY" > "$TMPFILE"

  if gh issue edit "$NUM" --repo "$REPO" --body-file "$TMPFILE" 2>&1; then
    echo "  Metadata block added"
    SUCCESS=$((SUCCESS + 1))
  else
    echo "  Failed to update"
    FAIL=$((FAIL + 1))
  fi

  rm -f "$TMPFILE"

  # Small delay to avoid rate limiting
  sleep 0.5
done

echo ""
echo "════════════════════════════════════"
echo "Done: ${SUCCESS} updated, ${SKIPPED} skipped, ${FAIL} failed"
echo "════════════════════════════════════"
