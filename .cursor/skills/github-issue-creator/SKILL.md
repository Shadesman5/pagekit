---
name: github-issue-creator
description: Creates GitHub issues on Shadesman5/pagekit with correct labels, milestones, PR linking, and parent/sub-issue relationships. Use when any agent (architect, refactorer, verifier, tester, or user) needs to create a GitHub issue — whether from a TODO/phase markdown file, a discovered bug, a missing ROADMAP step, deferred work, or any other reason.
---

# GitHub Issue Creator

Universal skill for creating GitHub issues on `Shadesman5/pagekit`.
Any agent or user can trigger this.

## Safety Limits

> **The Cloud Agent can CREATE issues but CANNOT close, edit, or delete them.**
> A runaway batch can only be cleaned up manually or via `issue-cleanup.yml`.

| Rule | Limit |
|------|-------|
| **Max issues per session** | **5** without explicit user confirmation |
| **Batch mode** | ALWAYS show preview table and wait for user approval before creating |
| **Duplicate check** | MANDATORY before every `gh issue create` (see "Check Before Creating") |
| **Cleanup workflow** | `.github/workflows/issue-cleanup.yml` — trigger from GitHub UI to bulk-close |

If a batch exceeds 5 issues, the agent MUST pause and list all planned issues
for user review before proceeding. Never auto-create more than 5 issues.

## When to Use

- **User asks** to create issues from a markdown/TODO file (batch mode)
- **User asks** to create a single issue for any reason
- **Tester** discovers a bug or regression that needs its own issue
- **Verifier** finds legacy debt or compliance gap needing a future fix
- **Architect** identifies a missing ROADMAP sub-step (e.g. 2.0.5b)
- **Refactorer** encounters an out-of-scope problem during work

## Prerequisites

- `gh` CLI authenticated (`gh auth status`)
- Issues enabled on repo
- For project operations: `gh auth refresh -s read:project,project` (adds project scope)

## Cloud Agent Permission Model

The Cursor Cloud Agent `ghs_` token has asymmetric permissions:

| Operation | Works? |
|-----------|--------|
| Create issue (title + body) | YES |
| Create with `--label` / `--milestone` | NO (silently ignored) |
| Edit issue body | NO |
| Add/remove labels | NO |
| Set milestone | NO |
| Close/reopen issue | NO |
| Add comment | NO |
| Push code | YES |

**This is why the `<!-- metadata -->` block exists:** the agent writes it into the body
at creation time, and `issue-metadata-sync.yml` applies labels/milestone using `PROJECT_TOKEN`.

## What Gets Set on Every Issue

Every issue should have as many of these as applicable:

| Field | How to set | When |
|-------|-----------|------|
| **Title** | `--title` | Always |
| **Body** | `--body-file` (with `<!-- metadata -->` block) | Always |
| **Labels** | Automatic via `<!-- metadata -->` block in body | Always (see formula below) |
| **Milestone** | Automatic via `<!-- metadata -->` block in body | Always for ROADMAP steps |
| **PR Link** | `Related: #17` in body | When a PR exists or will exist |
| **Parent Issue** | Add as sub-issue after creation | When step has sub-steps in ROADMAP |
| **Assignee** | `--assignee Shadesman5` | Optional |

> **How it works:** The `issue-metadata-sync.yml` GitHub Action automatically parses the
> `<!-- metadata -->` block from the issue body and applies labels + milestone.
> Agents only need `gh issue create --title "..." --body-file "..."`.
> Do NOT use `--label` or `--milestone` flags — they are silently ignored by the Cloud Agent token.

## Label Formula

**1 phase label + 1 type label + 1-2 area labels**

Read `.cursor/rules/github-labels.mdc` for full list.

### Phase

| Step range | Label |
|------------|-------|
| 0.x – 1.x | `phase-1` |
| 2.x        | `phase-2` |
| 3.x        | `phase-3` |
| 4.x        | `phase-4` |
| 5.x        | `phase-5` |
| Unknown    | Infer from context or ask user |

### Type (pick one)

| Situation | Label |
|-----------|-------|
| Modernization / refactoring | `migration` |
| New feature | `enhancement` |
| Bug | `bug` |
| Performance | `performance` |
| Security | `security` |
| Documentation | `documentation` |

### Area (pick one or more)

| Keywords in title/description | Label |
|-------------------------------|-------|
| PHP, Controller, Service, Container, PSR, Symfony, ORM, Auth, API, Docker, CI/CD | `backend` |
| Vue, UIkit, JavaScript, TypeScript, Component, CSS, Webpack | `frontend` |
| Database, DBAL, Schema, Query | `database` |
| Extension, Module, Plugin | `module` |
| Theme, Template styling | `theme` |

## Milestones

| Phase | Milestone name |
|-------|---------------|
| Phase 1 | `Phase 1: Foundation` |
| Phase 2 | `Phase 2: Developer Experience` |
| Phase 3 | `Phase 3: Frontend Modernization` |
| Phase 4 | `Phase 4: Production Ready` |
| Phase 5 | `Phase 5: Advanced Features` |

No version numbers — versioning is dynamic (see `version-bump` SKILL).

If a milestone doesn't exist yet:
```bash
echo '{"title":"Phase X: Name","state":"open","description":"..."}' | gh api repos/Shadesman5/pagekit/milestones --input -
```

## Issue Body Template

Every issue body **MUST** end with a `<!-- metadata -->` block. This block is parsed by
`issue-metadata-sync.yml` which automatically applies labels and milestones.

```markdown
## Goal

[One clear sentence describing what needs to happen]

## Context

- **ROADMAP Step**: [X.Y]
- **Phase**: [Phase number and name]
- **Depends on**: [Previous step or "None"]
- **Branch**: `[branch-name]` (if known, otherwise omit)

## Tasks

- [ ] [Concrete task 1]
- [ ] [Concrete task 2]
- [ ] Write/update tests
- [ ] Update documentation if needed

## Acceptance Criteria

- [ ] All existing tests pass
- [ ] No regressions
- [ ] [Step-specific criteria]

<!-- metadata
labels: [phase-X], [type], [area1], [area2]
milestone: [Phase X: Name]
-->
```

### Metadata Block Reference

The `<!-- metadata -->` block is an HTML comment — **invisible** in rendered markdown but
parsed by the `issue-metadata-sync.yml` GitHub Action.

| Field | Required | Format | Example |
|-------|----------|--------|---------|
| `labels` | Yes | Comma-separated label names | `phase-2, migration, backend` |
| `milestone` | Yes (ROADMAP) | Full name or shorthand | `Phase 2: Developer Experience` or `Phase 2` |
| `pr` | No | `#number` | `#71` |

**Milestone shorthand:** `Phase 1` → `Phase 1: Foundation`, `Phase 2` → `Phase 2: Developer Experience`, etc.

### Automation chain

```
Issue created/edited with <!-- metadata --> block
  → issue-metadata-sync.yml parses body → applies labels + milestone
    → labeled event triggers project-auto-phase.yml → sets Phase in Project board
```

**Body rules:**
- Every issue body **MUST** include the `<!-- metadata -->` block at the end
- Do NOT link to agent-prompt files
- Do NOT include internal agent workflow details
- Keep readable for any developer (human or agent)
- Reference ROADMAP step IDs when applicable
- Link related PRs in the "Related" section

## PR Linking

> **Important:** GitHub's "Development" sidebar link on an issue is created by the **PR**, not the issue.
> A `Related: #17` in the issue body is only documentation — it does NOT create the Development sidebar link.
> The real linking happens when a PR body contains `Closes #X` or `Fixes #X`.

### In the Issue Body (documentation only)

Reference related PRs in the issue body for context. This is **not** the GitHub Development link:
```markdown
## Related

- PR: #17
```

### In the PR Body (creates Development link)

When creating a PR, include the issue reference in the **PR body** to create the real GitHub Development sidebar link:
```markdown
Closes #42
```
This auto-closes the issue when the PR merges and links it in the Development sidebar.

The `push.mdc` workflow (Step 6) handles this automatically when creating PRs.

### Multiple PRs

Some steps may have multiple PRs. Reference them in the issue body for documentation:
```markdown
## Related

- PR: #60 (Symfony HttpFoundation upgrade)
- PR: #61 (Symfony HttpKernel upgrade)
```
Each PR should contain `Closes #X` or `Related: #X` in its own body for the Development link.

## Parent Issues and Sub-Issues

### When to use

Use parent/sub-issue structure when a ROADMAP step has sub-steps:
- Step 3.4 (Vue 3 Migration) → parent issue
  - Step 3.4.1 (vue-resource → axios) → sub-issue
  - Step 3.4.2 (vue-event-manager → mitt) → sub-issue
  - Step 3.4.3 (Vue 3 Core) → sub-issue

### How to create

1. Create the **parent issue** first (e.g. "Step 3.4: Vue 3 Migration")
2. Create each **sub-issue** (e.g. "Step 3.4.1: HTTP Client Migration")
3. Link sub-issues to parent using the **GraphQL API** (the `gh issue edit --add-sub-issue` flag does not exist in gh CLI):

```bash
# 1. Get node IDs of parent and sub-issue
PARENT_ID=$(gh issue view PARENT_NUMBER --repo Shadesman5/pagekit --json id -q .id)
SUB_ID=$(gh issue view SUB_NUMBER --repo Shadesman5/pagekit --json id -q .id)

# 2. Write GraphQL mutation to temp file (avoids PowerShell escaping issues)
echo '{"query":"mutation { addSubIssue(input: { issueId: \"PARENT_ID_HERE\", subIssueId: \"SUB_ID_HERE\" }) { issue { id } subIssue { id } } }"}' > temp-graphql.json
# Replace PARENT_ID_HERE and SUB_ID_HERE with actual values

# 3. Execute mutation
gh api graphql --input temp-graphql.json

# 4. Clean up
rm temp-graphql.json
```

> **Note:** On PowerShell, inline JSON escaping with `gh api graphql -f query="..."` is unreliable.
> Always use `--input` with a temp file for GraphQL mutations.

Also mention sub-issues in the parent body for visibility:
```markdown
## Sub-Issues

- [ ] #43 Step 3.4.1: vue-resource → axios
- [ ] #44 Step 3.4.2: vue-event-manager → mitt
- [ ] #45 Step 3.4.3: Vue 3 Core + @vue/compat
```

The "Sub-issues progress" field in the GitHub Project then shows progress (e.g. 2/5).

### When NOT to use

Steps without sub-steps (e.g. Step 1.1 Mailer Migration, Step 2.1 Static Analysis) are standalone issues — no parent needed.

## Creating an Issue

```bash
# Write body to temp file
cat > temp-issue-body.md << 'EOF'
## Goal
Migrate Swift Mailer to Symfony Mailer 5.4.

## Context
- **ROADMAP Step**: 1.1
- **Phase**: Phase 1 - Foundation

## Tasks
- [ ] Replace Swift Mailer with Symfony Mailer
- [ ] Update transport configuration
- [ ] Write unit and integration tests

## Acceptance Criteria
- [ ] Email sending works (SMTP + Sendmail)
- [ ] 42 tests passing

## Related
- PR: #17

<!-- metadata
labels: phase-1, migration, backend
milestone: Phase 1: Foundation
-->
EOF

# Create issue (--label/--milestone are optional: issue-metadata-sync.yml reads the metadata block)
gh issue create --repo Shadesman5/pagekit \
  --title "Step 1.1: Mailer Migration" \
  --body-file "temp-issue-body.md"

# Clean up
rm temp-issue-body.md
```

> **Do NOT use** `--label` or `--milestone` flags — they are silently ignored by the
> Cloud Agent token. The `<!-- metadata -->` block is the only reliable way.

## Batch Mode

> **Safety limit: max 5 issues without explicit user approval.**
> The agent CANNOT close or delete issues it creates. A runaway batch is irreversible.
> Use `issue-cleanup.yml` (GitHub UI) to bulk-close accidental issues.

1. Read source file (e.g. `MODERNISING_PAGEKIT_TODO_LIST.md` or `ROADMAP.md`)
2. Parse each step: number, title, status, sub-steps, related PR
3. **Skip** `✅` completed steps unless user says otherwise
4. **Duplicate check** for every issue (MANDATORY, see "Check Before Creating")
5. **Show preview table** to user and **WAIT for approval** before creating
6. Create issues one by one (max 5 per batch without re-confirmation)
7. For steps with sub-steps: create parent first, then sub-issues, then link
8. Report summary with issue numbers and URLs

## GitHub Project Integration (fully automatic)

No manual project management needed. Three automations handle everything:

1. **"Auto-add to project"** (built-in Project workflow) — adds every new issue to the board with Status: "Todo"
2. **`issue-metadata-sync.yml`** (GitHub Action) — parses the `<!-- metadata -->` block from the issue body and applies labels + milestone automatically
3. **`project-auto-phase.yml`** (GitHub Action) — reads the `phase-X` label and sets the Phase field automatically (with retry for race conditions)

The `<!-- metadata -->` block in the issue body is the only input needed. Everything else is derived from it:
```
Issue body contains multi-line <!-- metadata --> block
  → issue-metadata-sync.yml parses labels + milestone from block
    → project-auto-phase.yml sets Phase field in Project board
      → Auto-add workflow sets Status: "Todo"
```

## Check Before Creating

Always verify the issue doesn't already exist:
```bash
gh issue list --repo Shadesman5/pagekit --search "Step 2.0.5" --json number,title --limit 5
```

## Sub-Agent Examples

**Tester finds regression:**
> Title: "Bug: UserController loginAction missing CSRF validation"
> Metadata block in body:
```html
<!-- metadata
labels: phase-2, bug, security, backend
milestone: Phase 2
-->
```

**Verifier finds legacy debt:**
> Title: "Step 2.0.5b: Config service still uses array-access"
> Parent: Step 2.0.5 issue.
> Metadata block in body:
```html
<!-- metadata
labels: phase-2, migration, backend
milestone: Phase 2
-->
```

**Architect adds missing sub-step:**
> Title: "Step 2.0.5b: Config Service Modernization"
> Sub-issue of Step 2.0.5.
> Metadata block in body:
```html
<!-- metadata
labels: phase-2, migration, backend
milestone: Phase 2
-->
```
