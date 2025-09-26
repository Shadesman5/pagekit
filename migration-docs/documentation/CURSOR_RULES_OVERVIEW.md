# Cursor Rules Overview for Pagekit

## Overview

This document provides an overview of the Cursor AI rules implemented for the Pagekit modernization project.

## Implemented Rules

### 1. Feature Branch Rule (`feature-branch.mdc`)

**Purpose**: Ensures proper branching strategy for feature development.

**Triggers**: When creating new features or major changes.

**Requirements**:
- Always branch from `develop`
- Use descriptive branch names
- Never commit directly to `develop`

**Example Branch Names**:
- `feature/symfony-6-upgrade`
- `feature/docker-setup`
- `feature/security-patches`

### 2. Push with README Rule (`push-with-readme.mdc`)

**Purpose**: Automatically updates README.md when configuration files change.

**Triggers**: Changes to key configuration files:
- `composer.json`
- `package.json`
- `docker-compose.yml`
- `Dockerfile`

**Actions**:
- Analyzes changes in monitored files
- Updates relevant README sections
- Keeps badges in sync with actual versions
- Creates commit: "Docs: Update README.md with latest system changes"

### 3. README Sync Rule (`readme-sync.mdc`)

**Purpose**: Maintains consistency between code and documentation.

**Triggers**: README.md modifications.

**Actions**:
- Validates README accuracy against actual code
- Suggests updates for outdated information
- Ensures version numbers match
- Checks for broken links

## Rule Configuration

### File Locations

```
.cursor/rules/
├── feature-branch.mdc
├── push-with-readme.mdc
└── readme-sync.mdc
```

### Rule Structure

Each rule follows this structure:

```markdown
# Rule Name

## Trigger
When this rule should activate

## Actions
What the rule should do

## Requirements
What must be satisfied

## Examples
Usage examples
```

## Integration with Development Workflow

### 1. Feature Development

1. **Start Feature**:
   - Rule: `feature-branch.mdc` activates
   - Creates new branch from `develop`
   - Sets up proper branch naming

2. **Development**:
   - Rules monitor for configuration changes
   - Automatic documentation updates

3. **Completion**:
   - README sync rule validates documentation
   - Push rule updates changelog

### 2. Configuration Updates

When updating dependencies or configuration:

1. **File Modified**: `composer.json`, `package.json`, etc.
2. **Rule Triggered**: `push-with-readme.mdc`
3. **Action Taken**: README.md automatically updated
4. **Commit Created**: Documentation changes committed

### 3. Documentation Maintenance

1. **README Changes**: `readme-sync.mdc` validates
2. **Accuracy Check**: Compares with actual code
3. **Suggestions**: Provides update recommendations
4. **Consistency**: Ensures version alignment

## Benefits

### 1. Automation
- Reduces manual documentation work
- Prevents forgotten updates
- Ensures consistency

### 2. Quality
- Validates documentation accuracy
- Maintains up-to-date information
- Reduces human error

### 3. Workflow
- Streamlines development process
- Enforces best practices
- Improves team collaboration

## Customization

### Adding New Rules

1. Create new `.mdc` file in `.cursor/rules/`
2. Define trigger conditions
3. Specify required actions
4. Test with sample scenarios

### Modifying Existing Rules

1. Edit the appropriate `.mdc` file
2. Update trigger conditions if needed
3. Modify actions as required
4. Test changes thoroughly

## Monitoring and Maintenance

### Rule Effectiveness

Monitor these metrics:
- Documentation update frequency
- Manual documentation tasks
- Inconsistency reports
- Developer feedback

### Regular Reviews

- Monthly rule effectiveness review
- Quarterly rule optimization
- Annual rule architecture review

## Troubleshooting

### Common Issues

1. **Rule Not Triggering**
   - Check file paths in triggers
   - Verify rule syntax
   - Test with sample changes

2. **Incorrect Actions**
   - Review action definitions
   - Check for syntax errors
   - Validate with test scenarios

3. **Performance Impact**
   - Monitor rule execution time
   - Optimize trigger conditions
   - Consider rule prioritization

## Future Enhancements

### Planned Features

1. **Advanced Triggers**
   - File content analysis
   - Commit message patterns
   - Branch name validation

2. **Enhanced Actions**
   - Automatic test generation
   - Code quality checks
   - Security scanning

3. **Integration**
   - GitHub Actions integration
   - Slack notifications
   - JIRA ticket updates

## Best Practices

### Rule Design

1. **Keep Rules Focused**: One purpose per rule
2. **Clear Triggers**: Specific, unambiguous conditions
3. **Reliable Actions**: Tested, predictable outcomes
4. **Good Documentation**: Clear usage instructions

### Rule Maintenance

1. **Regular Testing**: Validate rules periodically
2. **Version Control**: Track rule changes
3. **Team Communication**: Share rule updates
4. **Feedback Loop**: Collect user input

## Resources

- [Cursor AI Documentation](https://cursor.sh/docs)
- [Markdown Documentation](https://www.markdownguide.org/)
- [Git Workflow Best Practices](https://www.atlassian.com/git/tutorials/comparing-workflows)
