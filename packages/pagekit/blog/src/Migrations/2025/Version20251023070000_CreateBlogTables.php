<?php

declare(strict_types=1);

namespace Pagekit\Blog\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Pagekit\Migration\ExtensionMigration;

/**
 * Blog Extension Initial Schema
 *
 * Creates the blog extension database tables.
 * This migration represents the baseline schema from packages/pagekit/blog/scripts.php.
 *
 * Tables created:
 * - {prefix}blog_post: Blog posts
 * - {prefix}blog_comment: Post comments
 */
// Upgrade note: blog installations created before the Doctrine migration rename carry the old
// `Version001_CreateBlogTables` id in their `migration_versions` table. That row must be updated
// to this class name so the baseline schema is not re-applied on upgrade.
final class Version20251023070000_CreateBlogTables extends ExtensionMigration
{
    /**
     * {@inheritdoc}
     */
    public function getExtensionName(): string
    {
        return 'blog';
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'Create blog extension tables (posts and comments)';
    }

    /**
     * Migrate up - Create blog tables
     */
    public function up(Schema $schema): void
    {
        $postTable = $this->createTableIfNotExists($schema, $this->getTableName('post'));
        if ($postTable !== null) {
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
            $postTable->addIndex(['title'], $this->getIndexName('post', 'title'));
            $postTable->addIndex(['user_id'], $this->getIndexName('post', 'user_id'));
            $postTable->addIndex(['date'], $this->getIndexName('post', 'date'));
        }

        $commentTable = $this->createTableIfNotExists($schema, $this->getTableName('comment'));
        if ($commentTable !== null) {
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
            $commentTable->addIndex(['author'], $this->getIndexName('comment', 'author'));
            $commentTable->addIndex(['created'], $this->getIndexName('comment', 'created'));
            $commentTable->addIndex(['status'], $this->getIndexName('comment', 'status'));
            $commentTable->addIndex(['post_id'], $this->getIndexName('comment', 'post_id'));
            $commentTable->addIndex(['post_id', 'status'], $this->getIndexName('comment', 'post_id_status'));
        }
    }

    /**
     * Migrate down - Drop blog tables
     */
    public function down(Schema $schema): void
    {
        // Drop tables in reverse order
        $this->dropTableIfExists($schema, $this->getTableName('comment'));
        $this->dropTableIfExists($schema, $this->getTableName('post'));
    }
}
