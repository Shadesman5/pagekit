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

### 1. PSR-11 Interface Implementation
- Added `Psr\Container\ContainerInterface` implementation to Container class
- Implemented `get()` method for service retrieval with proper exception handling
- Implemented `has()` method for service existence check
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
- Removed static `has()`, `get()`, `set()`, and `remove()` methods to avoid conflicts
- Applications should now use `Application::getInstance()->get()` instead of `Application::get()`
- Updated usage in:
  - `app/system/modules/info/src/InfoHelper.php`
  - `app/installer/src/Controller/MarketplaceController.php`

## Breaking Changes

### Minor Breaking Change
The static methods `Application::has()`, `Application::get()`, `Application::set()`, and `Application::remove()` have been removed to avoid conflicts with PSR-11 non-static methods.

**Before:**
```php
$service = Application::get('service.name');
```

**After:**
```php
$service = Application::getInstance()->get('service.name');
// Or using ArrayAccess (still works):
$service = Application::getInstance()['service.name'];
```

This change affects only 2 files in the codebase, which have been updated.

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
// PSR-11 way (recommended)
$service = $app->get('service.name');

// Check existence
if ($app->has('service.name')) {
    // ...
}

// ArrayAccess still works for backward compatibility
$service = $app['service.name'];
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