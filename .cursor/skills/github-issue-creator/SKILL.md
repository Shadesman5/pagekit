---
name: github-issue-creator
description: Creates GitHub issues on Shadesman5/pagekit with correct labels, milestones, PR linking, and parent/sub-issue relationships. Use when any agent (architect, refactorer, verifier, tester, or user) needs to create a GitHub issue — whether from a TODO/phase markdown file, a discovered bug, a missing ROADMAP step, deferred work, or any other reason.
---

# GitHub Issue Creator

Creates issues on `Shadesman5/pagekit`. The body is the skeleton below. Labels and the milestone come from the `<!-- metadata -->` block. `sync-metadata.yml` applies them. Do not pass `--label` or `--milestone`. The Cloud Agent token ignores both, and it cannot edit, label, close, or comment on an issue after creation.

## Limits

| Rule | Limit |
|------|-------|
| Max per session | 10, then stop and ask |
| Batch | Preview table, then wait |
| Duplicate check | Before every `gh issue create` |
| Cleanup | `.github/workflows/issue-cleanup.yml` from the GitHub UI |

## When

A user asks for one issue or a batch. A tester has a regression. A verifier has legacy debt. An architect is missing a ROADMAP step. A refactorer hit something out of scope.

## Labels and milestone

One phase label, one type label, one or two area labels. Full list: `.cursor/rules/github-labels.mdc`.

| Steps | Phase label | Milestone |
|-------|-------------|-----------|
| 0.x–1.x | `phase-1` | `Phase 1: Foundation` |
| 2.x | `phase-2` | `Phase 2: Developer Experience` |
| 3.x | `phase-3` | `Phase 3: Frontend Modernization & Cross-Stack Alignment` |
| 4.x | `phase-4` | `Phase 4: Production Ready` |
| 5.x | `phase-5` | `Phase 5: Advanced Features` |

Type: `migration` (modernization), `enhancement`, `bug`, `performance`, `security`, `documentation`, `breaking-change`.

Area: `backend` (PHP, Symfony, ORM, auth, API, Docker, CI), `frontend` (Vue, UIkit, JS, CSS, Vite), `database`, `module`, `theme`.

`Phase 2` in the metadata block expands to the full milestone name. Create a missing milestone with `gh api repos/Shadesman5/pagekit/milestones`. No version numbers in milestone titles.

`sync-metadata.yml` reads the block, then `auto-add-to-project-phase.yml` sets the project Phase. The same block works on PR bodies (push skill), including `closes: #42`.

## Skeleton

Title for a ROADMAP step: `Step X.Y: Name`. That form is for issues only. PR titles follow `.cursor/skills/push/SKILL.md`.

Sections in this order. Drop a section or a Context line that has nothing to say. No other headings. No task list, no acceptance checklist, no `- [ ]` / `- [x]` anywhere in the body.

```markdown
## Problem

What is wrong now. A few paragraphs. Name the mechanism, not a history of how it was found.

## Outcome

What is true when the work is done. End with one sentence that starts `This is done when`.

## Constraints

The rules the change must not break. Short prose.

## Out of scope

- One plain bullet per excluded piece of work

## Context

- **ROADMAP Step**: X.Y
- **Phase**: Phase N - Name
- **Depends on**: #N, or a one-line fact
- **Parent**: #N
- **Before**: what must land first, in one line
- **Risk**: Low, Medium, or High, then one sentence on why

## Related

- #N one line on why it is related

<!-- metadata
labels: phase-2, migration, backend
milestone: Phase 2: Developer Experience
-->
```

A bug with no ROADMAP step uses the same sections. Title: `Bug: short failure`. Context keeps Phase and Risk. Drop ROADMAP Step.

## Writing

- Problem is the present behaviour. Outcome is the result, not a procedure.
- Out of scope and Related are plain bullets. A related issue is `#N` plus a few words. A parent link is the Context line and the GraphQL sub-issue link, not a checkbox.
- No `migration-docs/` paths, no `PHASE_*.md`, no ticket or agent-prompt paths, no branch names unless the branch is the subject of the issue.
- Deferred work is named in words, or as `#N`. A step id belongs in **ROADMAP Step**, not as a cross-link.
- A file path only when it is the bug site.
- The metadata block is last. It is an HTML comment. Fields: `labels` (required), `milestone` (required for a ROADMAP step), `pr` (`#number`, optional).
- After the work ships, do not convert the body into a checked-off list. The PR is the record. `Closes #N` in the PR body is what fills GitHub's Development link. A `Related` line in the issue does not.

## Parent and sub-issues

Use this when a ROADMAP step has sub-steps. Create the parent, then each sub-issue, then link. Standalone steps have no parent.

```bash
PARENT_ID=$(gh issue view PARENT_NUMBER --repo Shadesman5/pagekit --json id -q .id)
SUB_ID=$(gh issue view SUB_NUMBER --repo Shadesman5/pagekit --json id -q .id)
echo "{\"query\":\"mutation { addSubIssue(input: { issueId: \\\"$PARENT_ID\\\", subIssueId: \\\"$SUB_ID\\\" }) { issue { id } } }\"}" > temp-graphql.json
gh api graphql --input temp-graphql.json
rm temp-graphql.json
```

`gh issue edit --add-sub-issue` does not exist. On PowerShell, pass GraphQL with `--input`, not `-f`.

## Create

```bash
gh issue list --repo Shadesman5/pagekit --search "Step 2.7.5" --json number,title --limit 5

cat > temp-issue-body.md << 'EOF'
## Problem
…

## Outcome
…
This is done when …

## Constraints
…

## Out of scope

- …

## Context

- **ROADMAP Step**: 2.7.5
- **Phase**: Phase 2 - Developer Experience

<!-- metadata
labels: phase-2, migration, backend
milestone: Phase 2: Developer Experience
-->
EOF

gh issue create --repo Shadesman5/pagekit \
  --title "Step 2.7.5: Data Directory" \
  --body-file temp-issue-body.md
rm temp-issue-body.md
```

## Batch

1. Read the source. Skip steps already marked done unless the user says otherwise.
2. Duplicate-check each title.
3. Show a preview table. Wait.
4. Create at most 10. Ask again before the next 10.
5. Parents first, then sub-issues, then the GraphQL link.
6. Report number and URL for each issue.
