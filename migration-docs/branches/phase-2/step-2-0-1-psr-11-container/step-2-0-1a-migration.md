# PSR-11 Container Migration Documentation

## Overview
This document tracks the migration of Pagekit's Container to be fully PSR-11 compatible.

**Date Started**: September 23, 2025  
**Branch**: `feature/psr11-container-compatibility`  
**PSR-11 Specification**: https://www.php-fig.org/psr/psr-11/

## Current Container Analysis

### Container Implementation Location
- **Main Container**: `app/modules/application/src/Container.php`
- **Application Class**: `app/modules/application/src/Application.php`

### Current Service Registration Methods
- Services are registered via module `index.php` files
- Uses ArrayAccess interface for service access
- Custom service factory patterns

## Changes Made

### 1. PSR-11 Compatible Implementation
- Created PSR-11 compatible methods `getService()` and `hasService()` to avoid naming conflicts
- Created `Psr11Adapter` class that implements `ContainerInterface` properly
- Added `getPsr11Adapter()` method to get a fully PSR-11 compliant adapter
- Created separate exception classes: `NotFoundException` and `ContainerException`
- Maintained full backward compatibility with ArrayAccess interface

### 2. Service Registration Updates
- Service registration patterns remain unchanged
- All existing services work with PSR-11 methods
- Factory pattern support maintained

### 3. Application Class Updates
- Application class automatically inherits PSR-11 compatibility from Container
- PSR-11 methods available on Application instance

### 4. StaticTrait Updates
- Implemented static method handling via `__callStatic()` magic method
- Static calls like `App::get()`, `App::has()`, `App::db()` continue to work
- No breaking changes for existing code

## Breaking Changes

None - Full backward compatibility maintained. All existing static calls continue to work through the `__callStatic()` magic method.

## Migration Guide for Extensions

### Before (ArrayAccess only)
```php
// Service access
$service = $app['service.name'];

// Check existence
if (isset($app['service.name'])) {
    // ...
}
```

### After (PSR-11 compatible)
```php
// Get PSR-11 adapter for full compliance
$psr11 = $app->getPsr11Adapter();
$service = $psr11->get('service.name');

// Or use the compatible methods directly
$service = $app->getService('service.name');
if ($app->hasService('service.name')) {
    // ...
}

// ArrayAccess still works for backward compatibility
$service = $app['service.name'];

// Static calls still work
$service = Application::get('service.name');
```

## Testing

### Test Coverage
- PSR-11 compliance tests (`ContainerPsr11Test.php`)
- Basic container functionality tests (`ContainerTest.php`)
- Service resolution tests
- Backward compatibility tests
- Exception handling tests

### Test Results
- ✅ All 25 tests passing
- ✅ 62 assertions successful
- ✅ No service resolution errors
- ✅ Full backward compatibility maintained
- ✅ Pagekit console working correctly

## Performance Impact
Minimal - PSR-11 methods are thin wrappers around existing functionality

## Future Considerations
- Consider deprecating ArrayAccess in future major version
- Potential for PSR-11 compatible service providers
- Integration with other PSR-11 containers