# Symfony Event System 6.4 Compatibility Migration

## Overview

This document tracks the migration of Pagekit's event system to ensure full compatibility with Symfony 6.4 EventDispatcher while maintaining backward compatibility for existing extensions.

## Migration Date

- **Started**: September 24, 2025
- **Branch**: `feature/symfony-event-system-compatibility`
- **Target Symfony Version**: 6.4 LTS

## Analysis of Current Event System

### Current Implementation
- **Location**: `app/modules/application/src/Event/EventDispatcher.php`
- **Base Class**: Extends Symfony EventDispatcher (5.4)
- **Custom Features**: 
  - Priority-based event listeners
  - Wildcard event matching
  - Static event binding

### Event Usage Patterns in Codebase

#### 1. Event Subscription Methods
- `EventSubscriberInterface` implementations
- Direct `addListener()` calls
- Module `events` configuration arrays

#### 2. Common Events
- System events: `boot`, `request`, `response`, `terminate`
- Model events: `model.*.init`, `model.*.saving`, `model.*.saved`
- View events: `view.*.data`, `view.scripts`
- Auth events: `auth.login`, `auth.logout`

## Changes for Symfony 6.4 Compatibility

### 1. EventDispatcher Updates

#### Modified Files
- `app/modules/application/src/Event/EventDispatcher.php`

#### Key Changes
- [ ] Updated method signatures for Symfony 6.4
- [ ] Ensured compatibility with new event propagation
- [ ] Maintained backward compatibility layer

### 2. HttpKernel Updates

#### Modified Files
- `app/modules/kernel/src/HttpKernel.php`

#### Key Changes
- [ ] Updated kernel event handling
- [ ] Ensured proper event flow
- [ ] Compatible with Symfony 6.4 HttpKernel

### 3. Event Listener Updates

#### Modified Modules
- [ ] System module listeners
- [ ] User module listeners
- [ ] Site module listeners
- [ ] Widget module listeners

#### Pattern Changes
- Old: Direct event object manipulation
- New: Compatible with Symfony 6.4 event objects

## Breaking Changes

### For Core System
None - full backward compatibility maintained

### For Extensions
Extensions using the following patterns need updates:
1. Custom event classes extending deprecated Symfony classes
2. Direct manipulation of event propagation
3. Usage of removed event methods

## Migration Guide for Extensions

### Before (Symfony 5.4)
```php
use Symfony\Component\EventDispatcher\Event;

class CustomEvent extends Event {
    // ...
}

$event->stopPropagation();
```

### After (Symfony 6.4)
```php
use Symfony\Contracts\EventDispatcher\Event;

class CustomEvent extends Event {
    // ...
}

$event->stopPropagation(); // Still works
```

## Test Coverage

### New Tests Created
- [ ] Event dispatcher compatibility tests
- [ ] Event subscription tests
- [ ] HTTP kernel event tests
- [ ] Event propagation tests

### Test Results
- Total Tests: TBD
- Passed: TBD
- Failed: 0
- Coverage: TBD%

## Performance Impact

### Benchmarks
- Event dispatch time: TBD
- Memory usage: TBD
- Overall impact: Minimal

## Validation Checklist

- [ ] All PHP tests passing
- [ ] Admin panel loads correctly
- [ ] All modules load without errors
- [ ] Event propagation working
- [ ] No deprecation warnings
- [ ] Extension compatibility verified

## Notes and Observations

- Event system architecture remains stable
- Symfony 6.4 maintains good backward compatibility
- Performance improvements expected from Symfony optimizations

## References

- [Symfony 6.4 EventDispatcher Documentation](https://symfony.com/doc/6.4/components/event_dispatcher.html)
- [Symfony 5.4 to 6.0 Upgrade Guide](https://github.com/symfony/symfony/blob/6.4/UPGRADE-6.0.md)