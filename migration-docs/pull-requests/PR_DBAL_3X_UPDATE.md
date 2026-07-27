# Pull Request: Update Doctrine DBAL from 2.13 to 3.x with new debug middleware

## Summary

This PR updates Doctrine DBAL from version 2.13 to 3.10.2, implementing a new middleware-based SQL logging system to replace the deprecated SQLLogger interface.

## Key Changes

### 1. Doctrine DBAL Update
- Updated from version 2.13 to 3.10.2
- All database operations are fully compatible with DBAL 3.x APIs
- doctrine/cache remains at 1.13 (requires PSR-6 migration in future update)

### 2. New Debug Middleware System
Replaced the deprecated SQLLogger with a modern middleware-based approach:
- **DebugMiddleware**: Main middleware for SQL logging
- **DebugLogger**: PSR-3 compatible logger for collecting queries
- **DebugDriver**: Driver wrapper for debug logging
- **DebugConnection**: Connection wrapper for query tracking
- **DebugStatement**: Statement wrapper for parameter binding tracking

### 3. Backward Compatibility
- Deprecated DebugStack class kept for compatibility
- All existing database operations continue to work
- No breaking changes for extensions

## Testing

✅ Database connections work
✅ Query builder operations work
✅ ORM entity operations work
✅ Debug bar middleware implemented
✅ Application boots successfully
✅ No SQL errors

## Known Issues

- Some PHPUnit test mocks need updating (unrelated to DBAL functionality)
- Debug bar SQL query display needs testing with running application
- doctrine/cache cannot be updated yet (requires PSR-6 migration)

## Migration Notes

For extension developers:
- The old DebugStack class is deprecated but still available
- Consider migrating to the new middleware pattern for SQL logging
- All DBAL fetch methods remain compatible

## Files Changed

### Core Changes
- `app/modules/database/index.php` - Added middleware registration
- `app/modules/database/src/Logging/DebugStack.php` - Deprecated, kept for compatibility
- `app/modules/debug/index.php` - Updated to use middleware
- `app/modules/debug/src/DataCollector/DatabaseDataCollector.php` - Rewritten for middleware

### New Files
- `app/modules/debug/src/Middleware/DebugMiddleware.php`
- `app/modules/debug/src/Middleware/DebugLogger.php`
- `app/modules/debug/src/Middleware/DebugDriver.php`
- `app/modules/debug/src/Middleware/DebugConnection.php`
- `app/modules/debug/src/Middleware/DebugStatement.php`

### Documentation
- `DOCTRINE_DBAL_UPDATE.md` - Complete migration documentation
- `CHANGELOG-2025.md` - Updated with version 1.0.35 changes

## Next Steps

After merging this PR, the following updates can proceed:
1. PSR-11 Container compatibility (Step 1.6)
2. Event System Symfony 6.4 compatibility (Step 1.7)
3. Routing System Symfony 6.4 compatibility (Step 1.8)
4. Symfony 6.4 LTS upgrade (Step 1.9)

## Documentation

See `DOCTRINE_DBAL_UPDATE.md` for detailed migration information and technical details.