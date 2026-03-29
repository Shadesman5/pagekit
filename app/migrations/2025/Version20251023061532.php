<?php

declare(strict_types=1);

namespace Pagekit\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial Pagekit Core Schema
 *
 * Creates all core system tables required for Pagekit CMS.
 * This migration represents the baseline schema that was previously
 * created directly via app/system/scripts.php.
 *
 * Tables created:
 * - @system_auth: Authentication tokens
 * - @system_config: System configuration
 * - @system_node: Content nodes and menu items
 * - @system_page: Page content
 * - @system_role: User roles and permissions
 * - @system_session: Session storage
 * - @system_user: User accounts
 * - @system_widget: Widget definitions
 */
final class Version20251023061532 extends AbstractMigration
{
    /**
     * Get migration description
     */
    public function getDescription(): string
    {
        return 'Initial Pagekit core schema with all system tables';
    }

    /**
     * Migrate up - Create all core tables
     */
    public function up(Schema $schema): void
    {
        // Get table prefix from connection
        $prefix = $this->getTablePrefix();

        // 1. System Auth Table - Authentication tokens
        $authTable = $schema->createTable($prefix . 'system_auth');
        $authTable->addColumn('id', 'string', ['length' => 255]);
        $authTable->addColumn('user_id', 'integer', ['unsigned' => true, 'default' => 0]);
        $authTable->addColumn('access', 'datetime', ['notnull' => false]);
        $authTable->addColumn('status', 'smallint');
        $authTable->addColumn('data', 'json', ['notnull' => false]);
        $authTable->setPrimaryKey(['id']);

        // 2. System Config Table - System configuration
        $configTable = $schema->createTable($prefix . 'system_config');
        $configTable->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
        $configTable->addColumn('name', 'string', ['length' => 255, 'default' => '']);
        $configTable->addColumn('value', 'text');
        $configTable->setPrimaryKey(['id']);
        $configTable->addUniqueIndex(['name'], $prefix . 'SYSTEM_CONFIG_NAME');

        // 3. System Node Table - Content nodes and menu items
        $nodeTable = $schema->createTable($prefix . 'system_node');
        $nodeTable->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
        $nodeTable->addColumn('parent_id', 'integer', ['unsigned' => true, 'default' => 0]);
        $nodeTable->addColumn('priority', 'integer', ['default' => 0]);
        $nodeTable->addColumn('status', 'smallint');
        $nodeTable->addColumn('title', 'string', ['length' => 255]);
        $nodeTable->addColumn('slug', 'string', ['length' => 255]);
        $nodeTable->addColumn('path', 'string', ['length' => 1023]);
        $nodeTable->addColumn('link', 'string', ['length' => 255]);
        $nodeTable->addColumn('type', 'string', ['length' => 255]);
        $nodeTable->addColumn('menu', 'string', ['length' => 255]);
        $nodeTable->addColumn('roles', 'simple_array', ['notnull' => false]);
        $nodeTable->addColumn('data', 'json', ['notnull' => false]);
        $nodeTable->setPrimaryKey(['id']);

        // 4. System Page Table - Page content
        $pageTable = $schema->createTable($prefix . 'system_page');
        $pageTable->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
        $pageTable->addColumn('title', 'string', ['length' => 255]);
        $pageTable->addColumn('content', 'text');
        $pageTable->addColumn('data', 'json', ['notnull' => false]);
        $pageTable->setPrimaryKey(['id']);

        // 5. System Role Table - User roles and permissions
        $roleTable = $schema->createTable($prefix . 'system_role');
        $roleTable->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
        $roleTable->addColumn('name', 'string', ['length' => 255]);
        $roleTable->addColumn('priority', 'integer', ['default' => 0]);
        $roleTable->addColumn('permissions', 'simple_array', ['notnull' => false]);
        $roleTable->setPrimaryKey(['id']);
        $roleTable->addUniqueIndex(['name'], $prefix . 'SYSTEM_ROLE_NAME');
        $roleTable->addIndex(['name', 'priority'], $prefix . 'SYSTEM_ROLE_NAME_PRIORITY');

        // 6. System Session Table - Session storage
        $sessionTable = $schema->createTable($prefix . 'system_session');
        $sessionTable->addColumn('id', 'string', ['length' => 255]);
        $sessionTable->addColumn('time', 'datetime');
        $sessionTable->addColumn('data', 'text', ['length' => 65532]);
        $sessionTable->setPrimaryKey(['id']);

        // 7. System User Table - User accounts
        $userTable = $schema->createTable($prefix . 'system_user');
        $userTable->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
        $userTable->addColumn('name', 'string', ['length' => 255, 'default' => '']);
        $userTable->addColumn('username', 'string', ['length' => 255, 'default' => '']);
        $userTable->addColumn('email', 'string', ['length' => 255, 'default' => '']);
        $userTable->addColumn('password', 'string', ['length' => 255, 'default' => '']);
        $userTable->addColumn('url', 'string', ['length' => 255, 'default' => '']);
        $userTable->addColumn('status', 'smallint', ['default' => 0]);
        $userTable->addColumn('registered', 'datetime');
        $userTable->addColumn('login', 'datetime', ['notnull' => false]);
        $userTable->addColumn('activation', 'string', ['length' => 255, 'notnull' => false]);
        $userTable->addColumn('roles', 'simple_array', ['notnull' => false]);
        $userTable->addColumn('data', 'json', ['notnull' => false]);
        $userTable->setPrimaryKey(['id']);
        $userTable->addUniqueIndex(['username'], $prefix . 'SYSTEM_USER_USERNAME');
        $userTable->addUniqueIndex(['email'], $prefix . 'SYSTEM_USER_EMAIL');

        // 8. System Widget Table - Widget definitions
        $widgetTable = $schema->createTable($prefix . 'system_widget');
        $widgetTable->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
        $widgetTable->addColumn('title', 'string', ['length' => 255]);
        $widgetTable->addColumn('type', 'string', ['length' => 255]);
        $widgetTable->addColumn('status', 'smallint');
        $widgetTable->addColumn('nodes', 'simple_array', ['notnull' => false]);
        $widgetTable->addColumn('roles', 'simple_array', ['notnull' => false]);
        $widgetTable->addColumn('data', 'json', ['notnull' => false]);
        $widgetTable->setPrimaryKey(['id']);
    }

    /**
     * Post migration SQL - Insert default roles
     *
     * Inserts the three default system roles that are required for Pagekit to function.
     * These must be inserted after table creation.
     */
    public function postUp(Schema $schema): void
    {
        $prefix = $this->getTablePrefix();
        $connection = $this->connection;

        // Insert default roles (same as in app/system/scripts.php)
        $connection->insert($prefix . 'system_role', [
            'id' => 1,
            'name' => 'Anonymous',
            'priority' => 0,
        ]);

        $connection->insert($prefix . 'system_role', [
            'id' => 2,
            'name' => 'Authenticated',
            'priority' => 1,
            'permissions' => 'blog: post comments',  // simple_array format
        ]);

        $connection->insert($prefix . 'system_role', [
            'id' => 3,
            'name' => 'Administrator',
            'priority' => 2,
        ]);
    }

    /**
     * Migrate down - Drop all core tables
     */
    public function down(Schema $schema): void
    {
        $prefix = $this->getTablePrefix();

        // Drop tables in reverse order (no foreign keys, so order doesn't matter much)
        $schema->dropTable($prefix . 'system_widget');
        $schema->dropTable($prefix . 'system_user');
        $schema->dropTable($prefix . 'system_session');
        $schema->dropTable($prefix . 'system_role');
        $schema->dropTable($prefix . 'system_page');
        $schema->dropTable($prefix . 'system_node');
        $schema->dropTable($prefix . 'system_config');
        $schema->dropTable($prefix . 'system_auth');
    }

    /**
     * Get table prefix from connection
     *
     * Returns the table prefix used by Pagekit (default: 'pk_').
     * This allows migrations to work with custom table prefixes.
     */
    private function getTablePrefix(): string
    {
        // Get prefix from Pagekit connection
        if ($this->connection instanceof \Pagekit\Database\Connection) {
            return $this->connection->getPrefix();
        }

        // Default prefix (standard Pagekit installation)
        return 'pk_';
    }
}
