# Database Migration System Implementation

**Branch**: `feature/database-migrations`  
**Status**: 🚧 In Progress  
**Started**: 2025-10-22

## 📋 Overview

Implementation of a professional database migration system for Pagekit CMS using Doctrine Migrations. This system provides automatic schema versioning, rollback functionality, and seamless integration with the Pagekit installer and extension system.

## 🎯 Goals

- ✅ Integrate Doctrine Migrations (compatible with DBAL 3.x)
- ✅ Create console commands for migration management
- ✅ Implement automatic schema versioning
- ✅ Add rollback functionality
- ✅ Integrate with Pagekit installer
- ✅ Support extension migrations
- ✅ Provide migration generation tools

## 📊 Initial Test Results

### Environment Setup (2025-10-22)

**Dependencies**:
- ✅ Composer dependencies installed
- ✅ Node.js dependencies installed  
- ✅ Frontend assets compiled

**Initial Test Results**:
- ✅ PHPUnit: 227/252 tests passing (some pre-existing failures in Mail/Auth modules)
- ⚠️ E2E tests: Not run (will be executed after migration implementation)
- ⚠️ Web server: Not active during initial check (will be tested during implementation)

### Pre-existing Test Issues
The following test failures existed before starting this implementation:
- Auth module: Mock configuration issues (3 failures)
- Database Connection: DBAL platform detection issues (6 failures)
- Mail module: Symfony Mime API compatibility issues (8 failures)
- Session: ID setting after session start (1 failure)
- Cache: fetchMultiple method missing (1 failure)

These issues are **not** related to the migration system implementation.

---

## 📖 Implementation Log

### Phase 1: Environment Setup ✅ COMPLETE

#### 1.1 Branch & Dependencies
- ✅ Switched to `develop` branch
- ✅ Updated `develop` from remote
- ✅ Created `feature/database-migrations` branch
- ✅ Installed PHP dependencies (`composer install`)
- ✅ Installed Node.js dependencies (`yarn install`)
- ✅ Compiled frontend assets automatically

#### 1.2 Initial Testing
- ✅ Ran PHPUnit test suite
- ✅ Verified system baseline

---

## 📝 Analysis Phase

### Current Database Management ✅ ANALYZED

#### DBAL Compatibility ✅
- **Current DBAL version**: `3.10.2` (installed via composer)
- **Required DBAL**: `^3.8` (defined in `composer.json`)
- **Compatible Migrations version**: Doctrine Migrations `^3.0` ✅
- **Status**: Fully compatible with Doctrine Migrations 3.x

#### Installer Mechanism ✅
**Location**: `app/installer/src/Installer.php`  
**Entry point**: `app/installer/install.php`

**Installation Flow**:
1. `Installer::install()` checks database connection
2. Loads `PackageScripts` with `app/system/scripts.php`
3. Executes `scripts['install']` callback
4. Creates admin user via direct DB insert
5. Enables all packages (extensions & themes)
6. Includes `install.php` for demo content (or `install-demo.php`)
7. Writes `config.php` file

**Tables Creation Method**:
- Uses DBAL Schema Builder via `$util->createTable()`
- Defined in `app/system/scripts.php`
- Uses closure-based table definitions
- Example:
```php
$util->createTable('@system_user', function ($table) {
    $table->addColumn('id', 'integer', ['autoincrement' => true]);
    $table->addColumn('username', 'string', ['length' => 255]);
    // ...
    $table->setPrimaryKey(['id']);
    $table->addUniqueIndex(['username']);
});
```

#### System Core Tables ✅

**Location**: `app/system/scripts.php`

1. **`@system_auth`** - Authentication tokens
   - Columns: `id` (string), `user_id`, `access`, `status`, `data` (json)
   - Primary: `id`

2. **`@system_config`** - System configuration
   - Columns: `id`, `name`, `value` (text)
   - Primary: `id`
   - Unique: `name`

3. **`@system_node`** - Content nodes/menu items
   - Columns: `id`, `parent_id`, `priority`, `status`, `title`, `slug`, `path`, `link`, `type`, `menu`, `roles`, `data` (json)
   - Primary: `id`

4. **`@system_page`** - Page content
   - Columns: `id`, `title`, `content` (text), `data` (json)
   - Primary: `id`

5. **`@system_role`** - User roles
   - Columns: `id`, `name`, `priority`, `permissions` (simple_array)
   - Primary: `id`
   - Unique: `name`
   - Index: `name, priority`
   - Default roles: Anonymous (1), Authenticated (2), Administrator (3)

6. **`@system_session`** - Session storage
   - Columns: `id` (string), `time`, `data` (text)
   - Primary: `id`

7. **`@system_user`** - Users
   - Columns: `id`, `name`, `username`, `email`, `password`, `url`, `status`, `registered`, `login`, `activation`, `roles`, `data` (json)
   - Primary: `id`
   - Unique: `username`, `email`

8. **`@system_widget`** - Widgets
   - Columns: `id`, `title`, `type`, `status`, `nodes`, `roles`, `data` (json)
   - Primary: `id`

#### Extension Mechanism ✅

**Example**: Blog Extension (`packages/pagekit/blog/`)

**Structure**:
- `index.php` - Main module definition
- `scripts.php` - Database schema definitions

**Extension `scripts.php` Structure**:
```php
return [
    'install' => function ($app) {
        // Create tables with $app['db']->getUtility()->createTable()
    },
    'uninstall' => function ($app) {
        // Drop tables
    },
    'updates' => [
        '0.11.2' => function ($app) {
            // Version-specific updates
            $util->migrate(); // Schema sync
        }
    ]
];
```

**Blog Extension Tables**:
1. **`@blog_post`** - Blog posts
   - 12 columns including `id`, `user_id`, `slug`, `title`, `status`, `date`, `content`, etc.
   - Indexes on `slug` (unique), `title`, `user_id`, `date`

2. **`@blog_comment`** - Comments
   - 10 columns including `id`, `parent_id`, `post_id`, `user_id`, `author`, `email`, `content`, etc.
   - Indexes on `author`, `created`, `status`, `post_id`

**Activation Flow**:
1. Package detected via `composer.json`
2. `PackageManager::enable()` called
3. Loads `scripts.php`
4. Executes `scripts['install']` callback
5. Creates extension tables

#### Current "Migration" System ✅

⚠️ **Important Discovery**: Pagekit has a **simple update system**, NOT Doctrine Migrations!

**Existing Files**:
- `app/system/src/Controller/MigrationController.php` - Web interface for updates
- `app/console/src/Commands/MigrationCommand.php` - CLI command `php pagekit migrate`
- Both use `PackageScripts` class, NOT Doctrine Migrations

**How It Works**:
1. **Version Tracking**: Via `system.version` in config (not DB table)
2. **Updates**: Defined in `scripts.php` `updates` array with version keys
3. **Execution**: `PackageScripts::update()` runs pending updates
4. **Schema Sync**: `$util->migrate()` uses DBAL Comparator to sync schema

**`Utility::migrate()` Method** (line 241-248):
```php
public function migrate(): void {
    $comparator = new Comparator();
    $diff = $comparator->compareSchemas($this->manager->createSchema(), $this->schema);
    
    foreach ($diff->toSaveSql($this->connection->getDatabasePlatform()) as $query) {
        $this->connection->executeQuery($query);
    }
}
```
- Compares current DB schema with in-memory schema
- Generates SQL diffs
- Executes SQL to sync
- **NO version history table**
- **NO migration files**
- **NO rollback support**

#### Database Touch Points ✅

1. **Fresh Installation**:
   - Installer → `PackageScripts` → `scripts['install']` → `$util->createTable()`
   - All system tables created
   - Extensions activated → extension tables created

2. **Extension Activation**:
   - `PackageManager::enable()` → `scripts['install']`
   - Creates extension-specific tables

3. **Extension Deactivation**:
   - `PackageManager::disable()` → optionally `scripts['uninstall']`
   - Can drop extension tables (but often doesn't for data preservation)

4. **System Updates**:
   - User navigates to `/admin/system/migration` OR runs `php pagekit migrate`
   - `MigrationController/MigrationCommand` → `PackageScripts::update()`
   - Executes version-specific callbacks from `updates` array
   - Often ends with `$util->migrate()` for schema sync

5. **Extension Updates**:
   - Same as system updates
   - Each extension can have `updates` array in `scripts.php`

#### Key Findings & Implications ✅

**What Exists**:
- ✅ DBAL 3.10.2 (compatible with Doctrine Migrations 3.x)
- ✅ Schema Builder (DBAL-based table definitions)
- ✅ Simple version-based update system
- ✅ Schema sync tool (`Utility::migrate()`)
- ✅ Update UI & CLI command (name conflict with new system!)

**What's Missing** (Our Implementation Goals):
- ❌ Doctrine Migrations integration
- ❌ Dedicated migration version tracking table
- ❌ Individual migration files with timestamps
- ❌ Rollback functionality
- ❌ Migration generation tools
- ❌ Professional migration history
- ❌ Database-independent migration format

**Critical Challenges Identified**:

1. **Namespace Conflict**: 
   - `Pagekit\Migration\` already defined in `composer.json` but directory doesn't exist
   - Solution: Use this existing namespace

2. **Command Name Conflict**:
   - Existing command: `php pagekit migrate` (runs simple updates)
   - New commands need different names to avoid conflict
   - Solution: Use namespaced commands like `migrate:run`, `migrate:status`, etc.

3. **Coexistence Strategy**:
   - Old system must continue to work (backward compatibility)
   - New system should handle NEW migrations
   - Consider migration path from old to new system

4. **Table Prefix Handling**:
   - All tables use `@` placeholder (e.g., `@system_user`)
   - Replaced with actual prefix (default: `pk_`)
   - Doctrine Migrations must handle this

#### Plan Adjustments ✅

Based on analysis, I'm adjusting the implementation plan:

1. **Use existing `Pagekit\Migration\` namespace** (already in composer.json)
2. **Create `app/modules/migration/src/` directory** (matches composer autoload)
3. **Rename console commands** to avoid conflict:
   - ~~`migrate`~~ → `migrate:run` or `migration:migrate`
   - `migrate:status` → Keep as is
   - `migrate:rollback` → Keep as is
   - `migrate:generate` → Keep as is
4. **Keep old system functional** for backward compatibility
5. **Document migration path** from old to new system

---

## 🏗️ Architecture Design

### Migration System Components

```
app/
├── migrations/                          # Migration files
│   └── VersionYYYYMMDDHHMMSS_*.php    # Timestamped migrations
├── config/
│   └── migrations.php                  # Migration configuration
└── modules/
    └── database/
        └── src/
            └── Migration/
                ├── MigrationService.php       # Core service
                ├── Configuration.php          # Config provider
                └── ExtensionMigration.php     # Base class for extensions
```

### Console Commands

```
php pagekit migrate              # Execute pending migrations
php pagekit migrate:status       # Show migration status
php pagekit migrate:generate     # Create new migration
php pagekit migrate:rollback     # Rollback migrations
```

---

## 🔍 Doctrine Migrations vs Phinx Evaluation

| Aspect                 | Doctrine Migrations     | Phinx          | Decision         |
| ---------------------- | ----------------------- | -------------- | ---------------- |
| DBAL 3.x compatibility | ✅ Native               | ❌ Separate    | ✅ Doctrine      |
| Integration complexity | Low (same vendor)       | Medium         | ✅ Doctrine      |
| Feature set            | Full (diff, versioning) | Full           | Equal            |
| Community              | Large                   | Large          | Equal            |
| **Recommendation**     | **✅ RECOMMENDED**      | ⚠️ Alternative | **Use Doctrine** |

**Decision**: Use Doctrine Migrations for seamless DBAL integration.

---

## 📚 Database Schema Documentation

### Core Tables (To Be Documented)

This section will be filled during Phase 6 (Initial Schema Migration):

1. **User Management**
   - Table: `pagekit_user`
   - Structure: TBD

2. **Content Management**
   - Tables: TBD

3. **Extension System**
   - Tables: TBD

4. **Configuration & Settings**
   - Tables: TBD

---

## 🔧 Implementation Details

### Migration Configuration

**File**: `app/config/migrations.php`

```php
<?php

return [
    'migrations_paths' => [
        'Pagekit\\Migrations' => __DIR__ . '/../migrations',
    ],
    'table_storage' => [
        'table_name' => 'pagekit_migration_versions',
    ],
];
```

### Version Tracking Table

- **Name**: `pagekit_migration_versions`
- **Purpose**: Track executed migrations
- **Managed by**: Doctrine Migrations

---

## 🧪 Testing Strategy

### Unit Tests
- Migration service initialization
- Migration execution
- Rollback functionality
- Status reporting
- Duplicate migration prevention

### Integration Tests
- Fresh installation with migrations
- Existing installation upgrade
- Extension activation with migrations
- Rollback and re-migration

### E2E Tests
- Web installer flow
- CLI installation flow
- Migration commands
- System functionality after migration

### Database Compatibility
- ✅ SQLite testing
- ✅ MySQL testing (via Docker)

---

## ⚠️ Known Issues & Challenges

None yet - will be documented as discovered.

---

## 📈 Performance Metrics

Will be measured during testing phase:

- Initial schema migration time: Target < 5 seconds
- Extension migration time: Target < 2 seconds
- Rollback time: Target < 3 seconds

---

## 🎓 Extension Migration Guide

### For Extension Developers

#### 1. Create Extension Migration Directory

```
packages/your-extension/
└── src/
    └── Migrations/
        └── Version001_CreateTables.php
```

#### 2. Extend ExtensionMigration Base Class

```php
<?php
namespace YourVendor\YourExtension\Migrations;

use Pagekit\Database\Migration\ExtensionMigration;
use Doctrine\DBAL\Schema\Schema;

class Version001_CreateTables extends ExtensionMigration
{
    public function getExtensionName(): string
    {
        return 'your_extension';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable($this->getTableName('items'));
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('title', 'string', ['length' => 255]);
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable($this->getTableName('items'));
    }
}
```

#### 3. Migration Lifecycle

- **On activation**: Migrations are automatically discovered and executed
- **On deactivation**: Rollback may be executed (if implemented)
- **On update**: Pending migrations are executed

---

## 📋 Rollback Procedures

### Rollback Last Migration

```bash
php pagekit migrate:rollback
```

### Rollback to Specific Version

```bash
php pagekit migrate:rollback --to=VERSION
```

### Rollback All Migrations

```bash
php pagekit migrate:rollback --to=0
```

---

## 🔄 Before/After Comparison

### Before: Manual Schema Management
- ❌ Direct SQL execution
- ❌ No version tracking
- ❌ Manual schema updates
- ❌ No rollback capability
- ❌ Inconsistent extension schemas

### After: Professional Migration System
- ✅ Automated migrations
- ✅ Version tracking in database
- ✅ Automatic schema updates
- ✅ Full rollback support
- ✅ Consistent extension integration

---

## 🎯 Success Criteria

- [ ] Doctrine Migrations fully integrated
- [ ] All console commands working
- [ ] Initial schema migration created
- [ ] Installer uses migration system
- [ ] Extension migration support implemented
- [ ] Rollback functionality working
- [ ] SQLite and MySQL both working
- [ ] All PHPUnit tests passing
- [ ] E2E installation test passing
- [ ] Fresh web installation working
- [ ] CLI installation working
- [ ] Migration status tracking functional
- [ ] Documentation complete
- [ ] Performance targets met

---

## 📝 Notes & Observations

- Initial test suite shows some pre-existing failures in Mail and Auth modules
- These failures are unrelated to database migration work
- System is stable enough to proceed with migration implementation
- Frontend assets are automatically compiled on `yarn install`

---

**Last Updated**: 2025-10-22  
**Status**: Phase 1 Complete - Moving to Phase 2 (Analysis)
