# 🔧 Database Migration System - Additional Requirements

**⚠️ READ CAREFULLY - Architecture Decisions**

Based on system analysis and architecture review.

---

## 🎯 The Problem

**Current Flow:**
1. Installer calls `runMigrations()` → Creates tables ✅
2. Installer calls `scripts.php` 'install' → Also creates tables ❌
3. = DUPLICATE LOGIC!

**Both Core and Extensions have this problem.**

---

## ✅ The Solution: Clean Separation

```
┌─────────────────────────────────────────────────────────────┐
│ MIGRATIONS                                                   │
│ ✅ Schema Definition (tables, columns, indexes)             │
│ ✅ STRUCTURAL defaults (system-critical data)               │
│    Example: System roles (Anonymous, Admin)                 │
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│ SCRIPTS.PHP                                                  │
│ ✅ Lifecycle hooks (install/enable/disable/uninstall/update)│
│ ✅ Configurable defaults (user preferences)                 │
│ ✅ Demo content (optional)                                  │
└─────────────────────────────────────────────────────────────┘
```

**Rule:**
- Migrations: Things that MUST exist for system to work
- scripts.php: Things that CAN be configured/changed

---

## 📋 Required Changes

### 1. Year-Based Organization

**File:** `app/config/migrations.php`

**Add:**
```php
'organize_migrations' => 'year',
```

**Result:** Migrations organized in `2025/`, `2026/`, etc.

---

### 2. Core System Changes

#### A) `app/system/scripts.php`

**Remove:**
- Lines 22-127: All `$util->createTable()` calls
- Lines 82-84: Role insertions

**Keep:**
- Lines 129-137: Dashboard + site config
- 'updates' array

**Result:**
```php
<?php
return [
    'install' => function ($app) {
        // Only configurable defaults
        $app['config']->set('system/dashboard', [...]);
        $app['config']->set('system/site', [...]);
    },

    'updates' => [
        // Execute new migrations automatically
    ]
];
```

#### B) `app/migrations/2025/Version20251023061532_InitialSchema.php`

**Verify has:**
- All 8 tables in `up()`
- System roles in `postUp()`

---

### 3. Extension Changes

#### A) `packages/pagekit/blog/scripts.php`

**Current (creates tables directly):**
```php
'install' => function ($app) {
    $util->createTable('@blog_post', ...);
    $util->createTable('@blog_comment', ...);
}
```

**Change to (uses migrations):**
```php
'install' => function ($app) {
    // Execute migrations
    $result = $app['migration']->migrate();
    
    if (!$result['success']) {
        throw new \RuntimeException('Blog installation failed');
    }
    
    // Configuration
    $app->config()->set('blog', [
        'posts_per_page' => 10,
        'allow_comments' => true
    ]);
},

'uninstall' => function ($app) {
    // Rollback migrations (optional: data retention)
    if (!$app->config('blog.data_retention', true)) {
        $app['migration']->rollback('0');
    }
    $app['cache']->clear();
},

'updates' => [
    // Execute new migrations automatically
]
```

#### B) Create `packages/pagekit/blog/src/Migrations/`

**Directory structure:**
```
packages/pagekit/blog/src/Migrations/
  └── 2025/
      └── Version001_CreateBlogTables.php
```

**Note:** Timestamp format = `Version` + YearMonthDayHourMinuteSecond OR Version from composer.json OR Custom

**Migration file:**
```php
<?php

declare(strict_types=1);

namespace Pagekit\Blog\Migrations;

use Pagekit\Migration\ExtensionMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version001_CreateBlogTables extends ExtensionMigration
{
    public function getExtensionName(): string
    {
        return 'blog';
    }

    public function getDescription(): string
    {
        return 'Create blog tables';
    }

    public function up(Schema $schema): void
    {
        // Blog post table
        $postTable = $schema->createTable($this->getTableName('post'));
        $postTable->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
        $postTable->addColumn('user_id', 'integer', ['unsigned' => true, 'default' => 0]);
        $postTable->addColumn('slug', 'string', ['length' => 255]);
        $postTable->addColumn('title', 'string', ['length' => 255]);
        $postTable->addColumn('status', 'smallint');
        $postTable->addColumn('date', 'datetime', ['notnull' => false]);
        $postTable->addColumn('modified', 'datetime');
        $postTable->addColumn('content', 'text');
        $postTable->addColumn('excerpt', 'text');
        $postTable->addColumn('comment_status', 'boolean', ['default' => false]);
        $postTable->addColumn('comment_count', 'integer', ['default' => 0]);
        $postTable->addColumn('data', 'json', ['notnull' => false]);
        $postTable->addColumn('roles', 'simple_array', ['notnull' => false]);
        $postTable->setPrimaryKey(['id']);
        $postTable->addUniqueIndex(['slug'], $this->getIndexName('post', 'slug'));
        
        // Blog comment table
        $commentTable = $schema->createTable($this->getTableName('comment'));
        $commentTable->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
        $commentTable->addColumn('parent_id', 'integer', ['unsigned' => true]);
        $commentTable->addColumn('post_id', 'integer', ['unsigned' => true]);
        $commentTable->addColumn('user_id', 'string', ['length' => 255]);
        $commentTable->addColumn('author', 'string', ['length' => 255]);
        $commentTable->addColumn('email', 'string', ['length' => 255]);
        $commentTable->addColumn('url', 'string', ['length' => 255, 'notnull' => false]);
        $commentTable->addColumn('ip', 'string', ['length' => 255]);
        $commentTable->addColumn('created', 'datetime');
        $commentTable->addColumn('content', 'text');
        $commentTable->addColumn('status', 'smallint');
        $commentTable->setPrimaryKey(['id']);
        $commentTable->addIndex(['post_id'], $this->getIndexName('comment', 'post_id'));
    }

    public function down(Schema $schema): void
    {
        $this->dropTableIfExists($schema, $this->getTableName('comment'));
        $this->dropTableIfExists($schema, $this->getTableName('post'));
    }
}
```

---

### 4. Same for theme-one (if needed, check the Pagekit-Docs)

Apply same pattern to `packages/pagekit/theme-one/` if it has database tables.

---

# Verify:
# ✅ Core tables created (8 tables)
# ✅ Blog tables created (2 tables) 
# ✅ System roles exist (3 roles)
# ✅ Dashboard config set
# ✅ System works

# Check migrations
php pagekit migration:status
```

---

## 🎯 Summary

**Changes Required:**
1. ✅ Core: Clean up scripts.php + add year organization
2. ✅ Blog: Create migrations + update scripts.php

**Result:**
- Modern migration system
- Clean separation
- Both core and extensions use migrations
- scripts.php has clear role (lifecycle + config)

---

**Keep it simple, modern, clear and modernized!** 🎯
