## 🤖 Important Instructions for Background Agents

**⚠️ READ 3 TIMES - NO SKIMMING!**

**ALL AGENTS MUST**:

0. **Reading & Language Rules**:

   - READ entire task 3x before starting
   - "MIGRATION" means database schema migrations (not code migration!)
   - ALL code/commits/docs in ENGLISH
   - Communication with user in GERMAN
   - Execute EVERY step - no skipping!

1. **System & Dependency Rules**:

   - All requirements (PHP, Node.js versions, dependencies) are defined in `composer.json` and `package.json`. These files are the single source of truth.
   - Before adding any new dependency, analyze the existing ones to ensure compatibility.
   - After making changes to JS/Vue files, the frontend assets MUST be re-compiled using the appropriate `yarn` script.

2. **Branch Strategy**:

   - Before branching, ALWAYS update `develop`: `git checkout develop && git pull`
   - Always branch from `develop`
   - Use EXACT branch name from task
   - Never commit directly to `develop`

3. **Test-Driven Development (CRITICAL FOR MIGRATIONS)**:

   - **Initial Verification**: Run `npx playwright test tests/e2e/specs/01-setup/installation.spec.js` + `./app/vendor/bin/phpunit` BEFORE starting work
   - **IMPORTANT**: E2E installation test installs Pagekit with demo content and auto-login to backend
   - **Alternative**: For setup without demo content and login password: `rm pagekit.db config.php && php pagekit setup`
   - **AFTER EVERY SINGLE CHANGE**:
     - Test web: `curl http://localhost:8000` (MUST return 200, not 500!)
     - Test admin: `curl http://localhost:8000/admin` (MUST return 200 after redirect!)
     - Run PHPUnit: `./app/vendor/bin/phpunit`
   - **Database Changes**: Always test with FRESH database + existing database
   - **Rollback Testing**: Test every migration's rollback immediately after creation
   - **NEVER run all E2E tests** - only installation test + migration-specific tests
   - **Correct PHPUnit path**: Always use `./app/vendor/bin/phpunit` (vendor-dir is custom in Pagekit!)
   - Tests MUST be green before PR file
   - Work in small steps with test verification after each
   - **IF ANY TEST FAILS → STOP AND FIX BEFORE CONTINUING!**

4. **Pull Request Workflow**:

   - DON'T create PR itself
   - Create PR .md file after completion
   - PR from feature branch → `develop`
   - Brief summary only (link to details)

5. **Documentation (3 Layers)**:

   - CHANGELOG-2025.md: summary
   - migration-docs/branches/[branch].md: ALL details
   - migration-docs/pull-requests/PR\_[branch].md: Essential summary + test status
   - Update README.md if system requirements change

6. **Task Isolation**:

   - Only work on specified tasks
   - No "side-fixes" outside of scope
   - Blockers: Try 3 solutions, then continue with rest

7. **Security (CRITICAL FOR DATABASE)**:

   - NEVER commit passwords/tokens/API keys
   - NEVER commit database dumps with real data
   - Check .gitignore BEFORE every commit
   - Use environment variables for secrets
   - Remove & rotate if exposed
   - **Test with SQLite AND MySQL** - both are supported

8. **Status Reporting**:
   - ✅ = Complete
   - ⚠️ = Partial (with reason)
   - ❌ = Blocked (with detailed reason)
   - NEVER STOP - finish what's possible!

---

## TASK: Professional Database Migration System

**Status**: ⏳ Als-nächstes  
**Branch**: `feature/database-migrations`  
**Ziel**: Professional Database Migration System with Doctrine Migrations  
**Voraussetzung**: Schritt 1.11 (ORM Modernization) abgeschlossen

**Components**:

- Doctrine Migrations integration (compatible with DBAL 3.x)
- Console commands for migration management
- Automatic schema versioning
- Rollback functionality
- Integration with Pagekit installer (FRESH INSTALL ONLY)
- Extension migration support
- Migration generation tools

---

## ⚠️ CRITICAL: MIGRATION STRATEGY - READ CAREFULLY!

**NO UPGRADE PATH FROM LEGACY PAGEKIT!**

This modern Pagekit is a **complete modernization** with breaking changes:
- PHP 8.2-8.4 (was PHP 5.6-7.x)
- Symfony 6.4 (was Symfony 2.x/3.x)  
- Doctrine DBAL 3.x (was DBAL 2.x)
- PSR Standards (PSR-11, PSR-6, PSR-7)
- Modern architecture

**Migration Strategy:**
1. ❌ **NO UPGRADE** from legacy Pagekit (<2.0) to modern Pagekit (2.x)
2. ✅ **FRESH INSTALLATION REQUIRED** for modern Pagekit
3. ✅ **FUTURE UPDATES** will work WITHIN modern Pagekit:
   - Modern Pagekit 2.0.0 → 2.1.0 ✅ (via migrations)
   - Modern Pagekit 2.1.0 → 2.2.0 ✅ (via migrations)
4. ✅ **MIGRATION SYSTEM** handles schema updates between modern Pagekit versions

**What this means for implementation:**
- Focus ONLY on fresh installation scenario
- No detection/migration of legacy databases
- No upgrade hooks from old versions
- Migration system is for FUTURE updates (2.x → 2.y)

---

### 🔧 YOUR WORKFLOW:

#### **STEP 1: CREATE THE PLAN FIRST** ⚠️ MANDATORY

Before you start coding, use the `create_plan` tool to create a structured plan with TODOs.

**⚠️ CRITICAL: VERIFY PLAN ACCURACY**

This task description is based on the current understanding of the system - **YOU must verify it during analysis!**

When creating your plan, include a **Step 2: Analysis & Verification** where you:

- ✓ Check that all mentioned files exist at the specified paths
- ✓ Verify the current installer mechanism (`app/installer/src/Installer.php`)
- ✓ Identify existing database schema files and update mechanisms
- ✓ Check how extensions currently handle database changes
- ✓ Validate that Doctrine Migrations is compatible with DBAL version
- ✓ Check for existing migration-like mechanisms that need migration
- ✓ Identify all database creation points (installer, extension activation, etc.)

**If you discover discrepancies**: Document them and adjust your implementation approach!

---

**Your plan should include these steps (9-10 main tasks):**

##### 1. Environment Setup & Initial Verification

**Goal**: Ensure clean starting point with passing tests

- Update `develop` and create feature branch: `git checkout develop && git pull && git checkout -b feature/database-migrations`
- Install dependencies: `composer install && yarn install`
- Compile frontend: `yarn compile-js --mode=production`
  - **Initial test verification** (MANDATORY):
    - Run E2E installation: `npx playwright test tests/e2e/specs/01-setup/installation.spec.js`
    - Run PHPUnit: `./app/vendor/bin/phpunit`
    - Test web: `curl http://localhost:8000`
    - All tests MUST pass before continuing
- Create documentation file: `migration-docs/branches/feature-database-migrations.md`

##### 2. Analysis Phase & Plan Verification ⚠️ CRITICAL

**Goal**: Understand current database management and VERIFY task description accuracy

**Analyze current system**:

- Review installer mechanism:
  - `app/installer/src/Installer.php` - main installation logic
  - `app/installer/install.php` - installation entry point
  - How are tables currently created?
  - Where is schema defined?
- Check system modules for database schema:
  - `app/system/modules/*/src/` - look for schema definitions
  - `app/modules/database/` - DBAL integration
  - Are there SQL files? Schema classes? Annotations?
- Review extension mechanism:
  - `packages/pagekit/*/src/` - blog, theme-one
  - How do extensions create tables?
  - How do they handle updates?
- Check DBAL version compatibility:
  - Review `composer.json` for `doctrine/dbal` version
  - Verify Doctrine Migrations compatibility matrix
- Identify all database touch points:
  - Fresh installation
  - Extension activation
  - System updates
  - Extension updates

**Document findings**:

- Current database initialization flow
- Existing schema management approach
- Migration challenges (if any existing migration-like systems)
- DBAL version and compatible Migrations version
- All database touch points that need migration integration

**Add all findings to**: `migration-docs/branches/feature-database-migrations.md`

**ADJUST YOUR PLAN** if you found discrepancies!

##### 3. Evaluate & Install Doctrine Migrations

**Goal**: Choose and install the right migration tool

**Evaluation** (document in branch .md file):

| Aspect                 | Doctrine Migrations     | Phinx          | Decision         |
| ---------------------- | ----------------------- | -------------- | ---------------- |
| DBAL 3.x compatibility | ✅ Native               | ❌ Separate    | ✅ Doctrine      |
| Integration complexity | Low (same vendor)       | Medium         | ✅ Doctrine      |
| Feature set            | Full (diff, versioning) | Full           | Equal            |
| Community              | Large                   | Large          | Equal            |
| **Recommendation**     | **✅ RECOMMENDED**      | ⚠️ Alternative | **Use Doctrine** |

**Installation**:

```bash
composer require doctrine/migrations
```

**Configuration**:

- Create `app/config/migrations.php` with configuration
- Define migrations directory: `app/migrations/`
- Set up migrations namespace: `Pagekit\Migrations`
- Configure table name for version tracking: `pagekit_migration_versions`

**Test Checkpoint**:

- `composer show doctrine/migrations` - verify installation
- Web: `curl http://localhost:8000` - must return 200
- PHPUnit: `./app/vendor/bin/phpunit` - all green

##### 4. Create Migration Infrastructure

**Goal**: Set up migration system foundation

**Create directory structure**:

```
app/
  migrations/
    .gitkeep
  modules/
    database/
      src/
        Migration/
          MigrationService.php      # Core migration service
          Configuration.php         # Migration config provider
```

**Create MigrationService** (`app/modules/database/src/Migration/MigrationService.php`):

```php
<?php

declare(strict_types=1);

namespace Pagekit\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command;

class MigrationService
{
    private Connection $connection;
    private DependencyFactory $dependencyFactory;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        // Initialize DependencyFactory with configuration
    }

    public function migrate(string $version = 'latest'): array
    {
        // Execute migrations up to version
    }

    public function rollback(string $version): array
    {
        // Rollback to specific version
    }

    public function status(): array
    {
        // Get migration status
    }

    public function generate(string $name): string
    {
        // Generate new migration file
    }
}
```

**Create Configuration Provider** (`app/modules/database/src/Migration/Configuration.php`):

```php
<?php

declare(strict_types=1);

namespace Pagekit\Database\Migration;

use Doctrine\Migrations\Configuration\Configuration as MigrationConfiguration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\PhpFile;

class Configuration
{
    public static function create($connection): MigrationConfiguration
    {
        // Load from app/config/migrations.php
        // Return configured MigrationConfiguration
    }
}
```

**Register service** in DI container (find where services are registered)

**Test Checkpoint**:

- Service can be instantiated
- Configuration loads correctly
- Web: `curl http://localhost:8000` - still works
- PHPUnit: `./app/vendor/bin/phpunit`

##### 5. Implement Console Commands

**Goal**: Add migration console commands to Pagekit CLI

**Files to create**: `app/console/src/Commands/Migration/*.php`

**Commands to implement**:

1. **MigrateCommand.php** - `pagekit migrate`

   ```php
   <?php
   namespace Pagekit\Console\Commands\Migration;

   use Symfony\Component\Console\Command\Command;
   use Symfony\Component\Console\Input\InputInterface;
   use Symfony\Component\Console\Output\OutputInterface;

   class MigrateCommand extends Command
   {
       protected function configure()
       {
           $this->setName('migrate')
                ->setDescription('Execute database migrations');
       }

       protected function execute(InputInterface $input, OutputInterface $output)
       {
           // Run migrations via MigrationService
       }
   }
   ```

2. **StatusCommand.php** - `pagekit migrate:status`

   - Shows current version
   - Lists pending migrations
   - Shows executed migrations

3. **GenerateCommand.php** - `pagekit migrate:generate`

   - Accepts migration name
   - Creates new migration file with template

4. **RollbackCommand.php** - `pagekit migrate:rollback`

   - Rollback to specific version
   - Rollback last N migrations

**Register commands** in `app/console/app.php`

**Test each command individually**:

```bash
php pagekit migrate:status         # Should show "No migrations"
php pagekit migrate:generate test  # Should create migration file
php pagekit migrate                 # Should execute migrations
php pagekit migrate:rollback        # Should rollback
```

**After EACH command**:

- Web test: `curl http://localhost:8000`
- PHPUnit: `./app/vendor/bin/phpunit`

##### 6. Create Initial Schema Migration (CRITICAL)

**Goal**: Convert current database schema to migrations

**⚠️ THIS IS THE MOST CRITICAL STEP - TAKE YOUR TIME!**

**Analyze current schema**:

- Identify all tables created by Pagekit core
- Document table structures, indexes, foreign keys
- Check for SQLite vs MySQL differences
- Document all tables in `migration-docs/branches/feature-database-migrations.md`

**Create initial migration**:

```bash
php pagekit migrate:generate InitialSchema
```

**Edit migration file** (`app/migrations/VersionXXXX_InitialSchema.php`):

```php
<?php

declare(strict_types=1);

namespace Pagekit\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250101000000_InitialSchema extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial Pagekit database schema';
    }

    public function up(Schema $schema): void
    {
        // Create all core tables
        // Use platform-independent DBAL methods!

        // Example: Users table
        $table = $schema->createTable('pagekit_user');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('username', 'string', ['length' => 255]);
        $table->addColumn('password', 'string', ['length' => 255]);
        $table->addColumn('email', 'string', ['length' => 255]);
        $table->addColumn('status', 'integer');
        $table->addColumn('created_at', 'datetime');
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['username']);
        $table->addUniqueIndex(['email']);

        // ... all other tables
    }

    public function down(Schema $schema): void
    {
        // Drop all tables (for rollback)
        $schema->dropTable('pagekit_user');
        // ... all other tables
    }
}
```

**CRITICAL TESTS**:

1. **Fresh installation with migrations**:

   - Delete database
   - Run: `php pagekit migrate`
   - Test: `curl http://localhost:8000` - must return 200
   - Test: Login to admin panel
   - Test: Create page, user, etc.

2. **Rollback test**:

   - Run: `php pagekit migrate:rollback`
   - Verify: All tables dropped
   - Run: `php pagekit migrate`
   - Verify: Everything works again

3. **Both databases**:

   - Test with SQLite: `pagekit.db`
   - Test with MySQL: Docker container
   - Both must work identically!

4. **E2E installation test**:
   - `npx playwright test tests/e2e/specs/01-setup/installation.spec.js`
   - Must pass with new migration system

**IF ANY TEST FAILS → FIX BEFORE CONTINUING!**

##### 7. Integrate Migrations with Installer

**Goal**: Make installer use migration system instead of direct SQL

**Files to modify**:

- `app/installer/src/Installer.php`
- `app/installer/install.php` (if needed)

**Implementation**:

- Replace direct table creation with migration calls
- Add migration execution to installation flow:

  ```php
  // In installer after database connection established
  $migrationService->migrate('latest');
  ```

- Ensure installer detects if migrations already executed
- Add version tracking check before installation
- Handle ONLY fresh install (NO legacy upgrade scenarios)

**Migration detection logic**:

```php
// IMPORTANT: This is for RE-RUNNING installer, NOT for legacy upgrades!
if ($this->hasMigrationVersionTable()) {
    // Migrations already executed (re-run protection)
    // Skip migration execution or show error
    throw new \RuntimeException('Pagekit is already installed. Delete database to reinstall.');
} else {
    // Fresh installation - run all migrations
    $migrationService->migrate('latest');
}
```

**CRITICAL TESTS** (test after EACH change):

1. **Fresh installation via web installer**:

   - Delete database
   - Navigate to: `http://localhost:8000/installer`
   - Complete installation wizard
   - Verify: Dashboard accessible
   - Verify: `pagekit_migration_versions` table exists

2. **Fresh installation via CLI** (if needed):

   - Delete database: `rm pagekit.db config.php`
   - Run: `php pagekit setup`
   - Interactive setup completes
   - Verify: System works

3. **E2E installation test**:

   - `npx playwright test tests/e2e/specs/01-setup/installation.spec.js`
   - Must pass completely

4. **Re-run protection test** (verify installer doesn't break existing installation):
   - With existing installation (database + config.php exists)
   - Try to run installer again
   - Should detect existing installation and prevent re-installation
   - Error message: "Pagekit is already installed"

**IMPORTANT**: NO legacy upgrade scenarios - only fresh install!

**After ALL integration tests pass**: Proceed to next step

##### 8. Add Extension Migration Support

**Goal**: Allow extensions to use migration system

**Create base extension migration class**:

`app/modules/database/src/Migration/ExtensionMigration.php`:

```php
<?php

declare(strict_types=1);

namespace Pagekit\Database\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

abstract class ExtensionMigration extends AbstractMigration
{
    abstract public function getExtensionName(): string;

    protected function getTableName(string $name): string
    {
        return sprintf('pagekit_%s_%s', $this->getExtensionName(), $name);
    }
}
```

**Create migration service for extensions**:

- Extensions can define migrations in `src/Migrations/`
- Auto-discover extension migrations on activation
- Execute extension migrations when extension activated
- Rollback extension migrations when extension deactivated

**Example extension migration** (for blog):

```php
<?php
namespace Pagekit\Blog\Migrations;

use Pagekit\Database\Migration\ExtensionMigration;
use Doctrine\DBAL\Schema\Schema;

class Version001_CreatePostTable extends ExtensionMigration
{
    public function getExtensionName(): string
    {
        return 'blog';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable($this->getTableName('post'));
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('title', 'string', ['length' => 255]);
        // ... more columns
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable($this->getTableName('post'));
    }
}
```

**Documentation**:

- Create guide: `migration-docs/branches/feature-database-migrations.md` → "Extension Migration Guide"
- Example migration for extension developers
- Best practices for extension migrations

**Test with blog extension**:

- Create migration for blog tables
- Activate blog extension
- Verify: Migrations executed
- Deactivate blog extension
- Verify: Rollback works (if implemented)

##### 9. Comprehensive Testing & Validation

**Goal**: Ensure migration system is bulletproof

**PHPUnit Tests** (create `tests/unit/Database/MigrationTest.php`):

```php
<?php
namespace Pagekit\Tests\Database;

use PHPUnit\Framework\TestCase;

class MigrationTest extends TestCase
{
    public function testMigrationServiceInitialization()
    {
        // Test service can be created
    }

    public function testMigrationExecution()
    {
        // Test migrations run successfully
    }

    public function testMigrationRollback()
    {
        // Test rollback works
    }

    public function testMigrationStatus()
    {
        // Test status reporting
    }

    public function testDuplicateMigrationPrevention()
    {
        // Test migrations don't run twice
    }
}
```

**E2E Tests** (create `tests/e2e/specs/04-migrations/migration-system.spec.js`):

```javascript
const { test, expect } = require('@playwright/test');

test.describe('Database Migration System', () => {
  test('fresh installation uses migrations', async ({ page }) => {
    // Test installation wizard
    // Verify migration_versions table exists
    // Verify all tables created
  });

  test('migration status command works', async () => {
    // Test via exec: php pagekit migrate:status
  });

  test('migration rollback works', async ({ page }) => {
    // Execute migration
    // Rollback
    // Verify tables gone
    // Migrate again
    // Verify system works
  });
});
```

**Complete Test Suite**:

1. **Unit tests**: `./app/vendor/bin/phpunit`
2. **E2E installation**: `npx playwright test tests/e2e/specs/01-setup/installation.spec.js`
3. **E2E migrations**: `npx playwright test tests/e2e/specs/04-migrations/migration-system.spec.js`
4. **Manual tests**:
   - Fresh install via web installer
   - Fresh install via E2E test (with demo content + auto-login)
   - Run migrations manually
   - Rollback and re-migrate
   - Extension activation with migrations

**Database Compatibility Tests**:

- ✅ SQLite: Test all scenarios
- ✅ MySQL: Test all scenarios (via Docker)
- Both must work identically!

**Performance Validation**:

- Measure migration execution time
- Should be < 5 seconds for initial schema
- Document in branch .md file

**ALL TESTS MUST BE GREEN BEFORE PR!**

##### 10. Documentation & PR Preparation

**Goal**: Create comprehensive documentation for migration system

**Update documentation files**:

1. **`migration-docs/branches/feature-database-migrations.md`**:

   - Complete technical details
   - Current schema analysis findings
   - Migration system architecture
   - All console commands with examples
   - Extension migration guide
   - Rollback procedures
   - Before/after comparison
   - Performance metrics
   - Any plan adjustments during analysis

2. **`CHANGELOG-2025.md`**:

   ```markdown
   ### 🗄️ Database Migration System

   - **feat: Add professional database migration system**
     - ✅ Doctrine Migrations integration (compatible with DBAL 3.x)
     - 📦 Console commands: migrate, rollback, status, generate
     - 🔄 Automatic schema versioning
     - 🔙 Rollback functionality
     - 🔧 Installer integration
     - 🧩 Extension migration support
     - 📊 Migration status tracking
     - ✅ SQLite and MySQL support
   ```

3. **`migration-docs/pull-requests/PR_database_migrations.md`**:

   ````markdown
   # PR: Database Migration System

   ## Summary

   Professional database migration system using Doctrine Migrations.

   ## Key Features

   - ✅ Doctrine Migrations 3.x integration
   - ✅ Console commands (migrate, rollback, status, generate)
   - ✅ Installer integration
   - ✅ Extension migration support
   - ✅ SQLite + MySQL compatibility

   ## Console Commands

   ```bash
   php pagekit migrate              # Run migrations
   php pagekit migrate:status       # Show status
   php pagekit migrate:rollback     # Rollback
   php pagekit migrate:generate     # Create new migration
   ```
   ````

   ## Test Status

   - ✅ PHPUnit: All tests passing
   - ✅ E2E Installation: Passing
   - ✅ E2E Migrations: Passing
   - ✅ SQLite: Tested ✅
   - ✅ MySQL: Tested ✅

   ## Performance

   - Initial schema migration: < 5 seconds
   - Extension migrations: < 2 seconds

   ## Documentation

   See: `migration-docs/branches/feature-database-migrations.md`

   ```

   ```

4. **`README.md`** (if needed):

   - Add migration system to features
   - Link to migration documentation
   - Update database requirements if changed
   - **IMPORTANT**: Add section about migration strategy:
     - Clarify: Fresh installation required for modern Pagekit
     - Clarify: NO upgrade path from legacy Pagekit (<2.0)
     - Clarify: Migration system for future updates (2.x → 2.y)

5. **Create migration guide**: `docs/developer/migrations.md` (new file):

   ```markdown
   # Database Migrations Guide

   ## Migration Strategy

   **IMPORTANT**: Modern Pagekit requires fresh installation!
   
   - ❌ NO upgrade from legacy Pagekit (<2.0) to modern Pagekit (2.x)
   - ✅ Fresh installation required for modern Pagekit
   - ✅ Migration system handles updates WITHIN modern Pagekit (2.0 → 2.1 → 2.2)

   ## For Core Developers

   [How to create core migrations for future Pagekit updates]

   ## For Extension Developers

   [How to create extension migrations]

   ## Best Practices

   [Migration best practices]

   ## Commands

   [All migration commands with examples]
   ```

**DO NOT**: Create actual GitHub PR (will be done manually by user)

---

#### **STEP 2: EXECUTE THE PLAN** 🚀

After creating the plan with the `create_plan` tool:

1. **Work through each TODO systematically**
2. **Test after EVERY change** (console + web + tests)
3. **Work incrementally** - one component at a time
4. **Test with BOTH databases** (SQLite AND MySQL)
5. **Test rollback after EVERY migration**
6. **Mark TODOs as complete** as you finish them
7. **Keep debug logs visible** for troubleshooting
8. **If ANY test fails** → STOP and FIX before continuing!
9. **Document findings** as you discover them

---

### 📋 SUCCESS CRITERIA:

✅ Doctrine Migrations fully integrated with Pagekit  
✅ All console commands working (`migrate`, `rollback`, `status`, `generate`)  
✅ Initial schema migration created and tested  
✅ Installer uses migration system (fresh install works)  
✅ Extension migration support implemented  
✅ Rollback functionality tested and working  
✅ SQLite AND MySQL both working identically  
✅ All PHPUnit tests passing  
✅ All E2E tests passing (installation + migration-specific)  
✅ Fresh installation via web installer works  
✅ Fresh installation via CLI (`php pagekit setup`) works  
✅ Migration status tracking functional  
✅ Migration version table created and managed  
✅ Comprehensive documentation completed (3 layers + migration guide)  
✅ Performance metrics documented (migration execution time < 5s)

---

### ⚠️ CRITICAL MIGRATION REMINDERS:

- **DATABASE IS CRITICAL**: One mistake = data loss! Test EVERYTHING!
- **Test with BOTH databases**: SQLite AND MySQL - both must work!
- **Test rollback IMMEDIATELY**: After every migration, test rollback!
- **Fresh install EVERY time**: Test fresh installation after major changes
- **NEVER run all E2E tests** - only installation + migration-specific tests
- **Use correct PHPUnit path**: `./app/vendor/bin/phpunit` (NOT `vendor/bin/phpunit`!)
- **Test after EVERY change**:
  - Web: `curl http://localhost:8000` (must return 200!)
  - Admin: `curl http://localhost:8000/admin` (must return 200 after redirect!)
  - PHPUnit: `./app/vendor/bin/phpunit`
- **Work incrementally**: One migration/command at a time
- **Keep debug logs visible**: Never delete them until user says so
- **If tests fail**: STOP and FIX before continuing
- **Verify plan accuracy**: During analysis phase, check all assumptions!
- **Document everything**: Migrations need excellent documentation!

---

### 💾 DATABASE SAFETY CHECKLIST:

Before ANY database operation:

- [ ] Backup existing database
- [ ] Test on fresh database first
- [ ] Verify rollback works
- [ ] Test with SQLite
- [ ] Test with MySQL
- [ ] Check migration version table
- [ ] Verify no SQL injection risks
- [ ] Test foreign key constraints
- [ ] Test indexes created correctly
- [ ] Test unique constraints work

---

**START BY CREATING THE PLAN, THEN EXECUTE IT!** 🎯

**REMEMBER: Migrations are CRITICAL - take your time and test thoroughly!** 💾
