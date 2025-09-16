# Symfony 6.4 LTS Migration Strategy for Pagekit CMS

## Executive Summary

Strategic preparation guide for migrating Pagekit CMS from Symfony 5.4 to 6.4 LTS while maintaining system stability and compatibility.

## System Environment & Constraints

### Current Stack

-   **PHP Support**: 7.4 to 8.4 (flexible - can be adjusted)
-   **Symfony**: ~5.4 (EOL since November 2024)
-   **Frontend Stack**:
    -   Vue.js 2.6 (EOL but stable)
    -   Webpack 4 (legacy build system)
    -   UIkit 3.5 (2018-2019 version)
-   **Package Managers**:
    -   Composer 2
    -   Yarn 1.22.22 (Classic)
-   **Testing**:
    -   PHPUnit 9.x (outdated)

### Target Requirements

-   **Symfony 6.4 LTS**: Requires PHP ≥8.1
-   **PHPUnit 11**: Requires PHP ≥8.2
-   **Security**: Must address EOL components

## Recommended Migration Order

### Phase 0: Foundation Updates (DO FIRST) ⭐

#### Step 1: PHPUnit 9.x → 11.x

**Priority**: HIGH - Do this FIRST!
**Reason**:

-   Tests are your safety net for all other changes
-   PHPUnit 11 requires PHP 8.2+
-   Once tests are modern, you can safely refactor

**Actions**:

```bash
composer require --dev phpunit/phpunit:^11.0
```

#### Step 2: PHP Minimum Version 7.4 → 8.2

**Priority**: HIGH - Do immediately after PHPUnit
**Reason**:

-   Enables PHPUnit 11
-   Enables Symfony 6.4
-   PHP 8.2 is current stable with long support
-   Maintains compatibility up to PHP 8.4

**Changes Required**:

1. Update `composer.json`: `"php": "^8.2"`
2. Update `index.php` version check
3. Test all modules on PHP 8.2
4. Update CI/CD pipelines

### Phase 1: Symfony Preparation (After PHP 8.2)

#### Dependency Analysis

1. Create complete dependency graph of Symfony component usage
2. Identify all deprecated Symfony 5.4 features in use
3. Document breaking changes between 5.4 and 6.4

#### Code Audit Checklist

For each module:

-   [ ] `app/modules/*`
-   [ ] `app/system/modules/*`
-   [ ] `packages/*`

Check for:

-   Deprecated method calls
-   Changed interfaces/contracts
-   Removed classes or namespaces
-   Parameter/return type changes
-   Service configuration changes

### Phase 2: Compatibility Implementation

#### Strategy: Clean Migration

Since we're updating PHP to 8.2+, we can do a clean migration without compatibility layers:

1. **Direct Updates**:

    - Update deprecated calls to new syntax
    - Implement new interfaces/contracts
    - Update service configurations

2. **No Need For**:
    - Version detection code
    - Compatibility shims
    - Dual-version support

### Phase 3: Symfony Upgrade Execution

Once all code is prepared:

```bash
composer require symfony/mailer:^6.4
composer require symfony/console:^6.4
composer require symfony/framework-bundle:^6.4
# ... other symfony components
```

## Migration Timeline

### Week 1-2: Foundation

-   [ ] Update PHPUnit to 11.x
-   [ ] Update minimum PHP to 8.2
-   [ ] Run full test suite
-   [ ] Fix any PHP 8.2 compatibility issues

### Week 3-4: Analysis

-   [ ] Complete Symfony dependency analysis
-   [ ] Document all required changes
-   [ ] Create migration checklist

### Week 5-6: Implementation

-   [ ] Update deprecated code
-   [ ] Implement new patterns
-   [ ] Test each module

### Week 7: Upgrade

-   [ ] Update Symfony dependencies
-   [ ] Final testing
-   [ ] Deploy

## Risk Mitigation

1. **Backup Strategy**: Full system backup before each phase
2. **Rollback Plan**: Git branches for each phase
3. **Testing Protocol**: Full test suite after each change
4. **Gradual Rollout**: Test on staging first

## Future Considerations

### After Symfony 6.4 Success:

1. Vue.js 2.6 → 2.7 (compatibility bridge to Vue 3)
2. Webpack 4 → Webpack 5 or Vite
3. UIkit 3.5 → Latest 3.x
4. Consider Symfony 7.x (if stable)

## Version Decision

### Recommended Approach:

1. **Pagekit 1.0.28**: PHPUnit 11 + PHP 8.2 minimum
2. **Pagekit 1.1.0**: Symfony 6.4 LTS migration complete
3. **Pagekit 1.2.0**: Frontend modernization

This provides clear version milestones and user expectations.

## Deliverables

1. This strategy document
2. `PHP_82_COMPATIBILITY.md` - All PHP 8.2 specific changes
3. `SYMFONY_64_CHANGES.md` - All Symfony migration changes
4. Updated test suite for PHPUnit 11
5. Migration scripts/tools
6. Updated documentation

## Success Criteria

-   [ ] All tests pass on PHPUnit 11
-   [ ] System runs on PHP 8.2+
-   [ ] Symfony 6.4 fully integrated
-   [ ] No functionality regression
-   [ ] Performance maintained or improved
-   [ ] Security vulnerabilities addressed

---

**Created**: September 16, 2025  
**Status**: Planning Phase  
**Target Completion**: Pagekit 1.1.0 Release
