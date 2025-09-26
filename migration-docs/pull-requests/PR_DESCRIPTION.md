# Pull Request Template for Pagekit Modernization

## Overview

This document serves as a template for creating comprehensive pull requests during the Pagekit modernization process.

## PR Template Structure

### Title Format
```
[Type]: Brief description of changes
```

Types:
- `feat`: New features
- `fix`: Bug fixes
- `refactor`: Code refactoring
- `docs`: Documentation updates
- `test`: Test improvements
- `chore`: Maintenance tasks

### Description Template

```markdown
## Description

Brief description of what this PR accomplishes.

## Changes Made

- [ ] List specific changes
- [ ] Include file paths when relevant
- [ ] Mention any breaking changes

## Testing

- [ ] Unit tests pass
- [ ] Integration tests pass
- [ ] Manual testing completed
- [ ] Browser testing (if applicable)

## Breaking Changes

- [ ] None
- [ ] List breaking changes if any

## Migration Guide

If applicable, provide migration steps for users/developers.

## Screenshots

Include screenshots for UI changes.

## Checklist

- [ ] Code follows project standards
- [ ] Tests added/updated
- [ ] Documentation updated
- [ ] Changelog updated
- [ ] No breaking changes (or documented)
```

## PR Labels

### Priority Labels
- `high-priority`: Critical security fixes
- `medium-priority`: Important features
- `low-priority`: Nice-to-have improvements

### Type Labels
- `security`: Security-related changes
- `dependencies`: Dependency updates
- `performance`: Performance improvements
- `ui/ux`: User interface changes
- `backend`: Backend changes
- `frontend`: Frontend changes
- `documentation`: Documentation updates

### Status Labels
- `ready-for-review`: Ready for code review
- `needs-testing`: Requires additional testing
- `breaking-change`: Contains breaking changes
- `work-in-progress`: Still being developed

## Review Guidelines

### For Reviewers
1. **Code Quality**: Check for adherence to coding standards
2. **Security**: Verify no security vulnerabilities introduced
3. **Performance**: Consider performance implications
4. **Tests**: Ensure adequate test coverage
5. **Documentation**: Verify documentation is updated

### For Authors
1. **Small PRs**: Keep PRs focused and manageable
2. **Clear Description**: Provide clear, detailed descriptions
3. **Testing**: Include comprehensive testing information
4. **Self-Review**: Review your own PR before requesting review

## Automation Integration

### GitHub Actions
- Automated testing on PR creation
- Code quality checks
- Security scanning
- Documentation generation

### Required Checks
- [ ] All tests pass
- [ ] Code coverage maintained
- [ ] No security vulnerabilities
- [ ] Documentation updated

## Example PRs

### Security Patch Example
```markdown
## Description
Updates doctrine/dbal to version 3.8 to resolve critical SQL injection vulnerability.

## Changes Made
- Updated composer.json: doctrine/dbal ~2.13 → ^3.8
- Fixed deprecated query builder methods
- Updated database connection handling

## Breaking Changes
- Custom database drivers need updates
- Query builder API changes

## Migration Guide
See MIGRATION_GUIDE.md for detailed upgrade instructions.
```

### Feature Addition Example
```markdown
## Description
Adds Docker development environment setup.

## Changes Made
- Added docker-compose.yml
- Added Dockerfile
- Added setup scripts (PowerShell/Bash)
- Updated README.md with Docker instructions

## Testing
- [x] Docker containers start successfully
- [x] Application accessible on localhost
- [x] Database connection works
- [x] All services healthy

## Breaking Changes
None - purely additive changes.
```
