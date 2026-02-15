---
name: github-issue-creator
description: Creates GitHub issues on Shadesman5/pagekit with correct labels, milestones, PR linking, and parent/sub-issue relationships. Use when any agent (architect, refactorer, verifier, tester, or user) needs to create a GitHub issue — whether from a TODO/phase markdown file, a discovered bug, a missing ROADMAP step, deferred work, or any other reason.
---

# GitHub Issue Creator

Universal skill for creating GitHub issues on `Shadesman5/pagekit`.
Any agent or user can trigger this.

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

## What Gets Set on Every Issue

Every issue should have as many of these as applicable:

| Field | How to set | When |
|-------|-----------|------|
| **Title** | `--title` | Always |
| **Labels** | `--label "phase-2,migration,backend"` | Always (see formula below) |
| **Milestone** | `--milestone "Phase 2: Developer Experience"` | Always for ROADMAP steps |
| **Body** | `--body-file` | Always |
| **PR Link** | `Closes #17` or `Related: #17` in body | When a PR exists or will exist |
| **Parent Issue** | Add as sub-issue after creation | When step has sub-steps in ROADMAP |
| **Assignee** | `--assignee Shadesman5` | Optional |

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

```markdown
## Goal

[One clear sentence describing what needs to happen]

## Context

- **ROADMAP Step**: [X.Y]
- **Phase**: [Phase number and name]
- **Discovered by**: [User / Architect / Tester / Verifier / Refactorer]
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
```

**Body rules:**
- Do NOT link to agent-prompt files
- Do NOT include internal agent workflow details
- Keep readable for any developer (human or agent)
- Reference ROADMAP step IDs when applicable
- Link related PRs in the "Related" section

## PR Linking

### Existing PR (already merged)

If a PR already exists for the step, add it to the body:
```markdown
## Related

- PR: #17
```

### Future PR (will be created)

When creating a PR later, include in the PR body:
```markdown
Closes #42
```
This auto-closes the issue when the PR merges.

### Multiple PRs

Some steps may have multiple PRs:
```markdown
## Related

- PR: #60 (Symfony HttpFoundation upgrade)
- PR: #61 (Symfony HttpKernel upgrade)
```

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
3. Link sub-issues to parent using the GitHub CLI:

```bash
# Add sub-issue to parent
gh issue edit PARENT_NUMBER --repo Shadesman5/pagekit --add-sub-issue SUB_ISSUE_URL
```

Or mention in the parent body:
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
EOF

# Create issue
gh issue create --repo Shadesman5/pagekit \
  --title "Step 1.1: Mailer Migration" \
  --body-file "temp-issue-body.md" \
  --label "phase-1,migration,backend" \
  --milestone "Phase 1: Foundation"

# Clean up
rm temp-issue-body.md
```

## Batch Mode

1. Read source file (e.g. `MODERNISING_PAGEKIT_TODO_LIST.md` or `ROADMAP.md`)
2. Parse each step: number, title, status, sub-steps, related PR
3. **Skip** `✅` completed steps unless user says otherwise
4. **Show preview table** to user before creating
5. Create issues one by one
6. For steps with sub-steps: create parent first, then sub-issues, then link
7. Report summary with issue numbers and URLs

## GitHub Project Integration (fully automatic)

No manual project management needed. Two automations handle everything:

1. **"Auto-add to project"** (built-in Project workflow) — adds every new issue to the board with Status: "Todo"
2. **`project-auto-phase.yml`** (GitHub Action) — reads the `phase-X` label and sets the Phase field automatically (with retry for race conditions)

The `phase-X` label on the issue is the only input needed. Everything else is derived from it.

## Check Before Creating

Always verify the issue doesn't already exist:
```bash
gh issue list --repo Shadesman5/pagekit --search "Step 2.0.5" --json number,title --limit 5
```

## Sub-Agent Examples

**Tester finds regression:**
> Creating issue: "Bug: UserController loginAction missing CSRF validation"
> Labels: phase-2, bug, security, backend. Milestone: Phase 2.

**Verifier finds legacy debt:**
> Creating issue: "Step 2.0.5b: Config service still uses array-access"
> Labels: phase-2, migration, backend. Milestone: Phase 2. Parent: Step 2.0.5 issue.

**Architect adds missing sub-step:**
> Creating issue: "Step 2.0.5b: Config Service Modernization"
> Labels: phase-2, migration, backend. Sub-issue of Step 2.0.5.
