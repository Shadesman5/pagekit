<?php

use Pagekit\Application as App;

/**
 * Menucards Extension - Lifecycle Scripts
 * Handles install, uninstall, enable, disable hooks
 */
return [
    'install' => function ($app) {
        // Called on initial install
        $app['log']->info('Menucards: Install hook called');
    },

    'uninstall' => function ($app) {
        // Drop all tables on uninstall
        $util = $app['db']->getUtility();

        // Drop tables in reverse order for FK constraints
        if ($util->tableExists('@menucards_category_product')) {
            $util->dropTable('@menucards_category_product');
            $app['log']->info('Menucards: Dropped category_product pivot table');
        }
        if ($util->tableExists('@menucards_product')) {
            $util->dropTable('@menucards_product');
            $app['log']->info('Menucards: Dropped product table');
        }
        if ($util->tableExists('@menucards_category')) {
            $util->dropTable('@menucards_category');
            $app['log']->info('Menucards: Dropped category table');
        }
        if ($util->tableExists('@menucards_menu')) {
            $util->dropTable('@menucards_menu');
            $app['log']->info('Menucards: Dropped menu table');
        }

        $app['log']->info('Menucards: Uninstall completed - all tables removed');
    },

    'enable' => function ($app) {
        // Create database tables on enable
        $util = $app['db']->getUtility();

        // Create menus table
        if (!$util->tableExists('@menucards_menu')) {
            $util->createTable('@menucards_menu', function ($table) {
                $table->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
                $table->addColumn('title', 'string', ['length' => 255]);
                $table->addColumn('slug', 'string', ['length' => 255]);
                $table->addColumn('description', 'text', ['notnull' => false]);
                $table->addColumn('status', 'smallint', ['default' => 0]);
                $table->addColumn('created', 'datetime');
                $table->setPrimaryKey(['id']);
                $table->addUniqueIndex(['slug'], 'MENUCARDS_MENU_SLUG');
            });
            $app['log']->info('Menucards: Created menu table');
        }

        // Create categories table
        if (!$util->tableExists('@menucards_category')) {
            $util->createTable('@menucards_category', function ($table) {
                $table->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
                $table->addColumn('menu_id', 'integer', ['unsigned' => true]);
                $table->addColumn('title', 'string', ['length' => 255]);
                $table->addColumn('priority', 'integer', ['default' => 0]);
                $table->setPrimaryKey(['id']);
                $table->addIndex(['menu_id'], 'MENUCARDS_CATEGORY_MENU');
            });
            $app['log']->info('Menucards: Created category table');
        }

        // Create products table
        if (!$util->tableExists('@menucards_product')) {
            $util->createTable('@menucards_product', function ($table) {
                $table->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
                $table->addColumn('name', 'string', ['length' => 255]);
                $table->addColumn('description', 'text', ['notnull' => false]);
                $table->addColumn('price', 'decimal', ['precision' => 10, 'scale' => 2]);
                $table->addColumn('image', 'string', ['length' => 255, 'notnull' => false]);
                $table->addColumn('created', 'datetime');
                $table->setPrimaryKey(['id']);
            });
            $app['log']->info('Menucards: Created product table');
        }

        // Create pivot table (Category <-> Product Many-to-Many)
        if (!$util->tableExists('@menucards_category_product')) {
            $util->createTable('@menucards_category_product', function ($table) {
                $table->addColumn('category_id', 'integer', ['unsigned' => true]);
                $table->addColumn('product_id', 'integer', ['unsigned' => true]);
                $table->addColumn('priority', 'integer', ['default' => 0]);
                $table->setPrimaryKey(['category_id', 'product_id']);
                $table->addIndex(['category_id'], 'MENUCARDS_PIVOT_CATEGORY');
                $table->addIndex(['product_id'], 'MENUCARDS_PIVOT_PRODUCT');
            });
            $app['log']->info('Menucards: Created category_product pivot table');
        }

        $app['log']->info('Menucards: Enable completed - all tables created');
    },

    'disable' => function ($app) {
        // Optional: Clear cache, etc.
        $app['log']->info('Menucards: Disable hook called');
    }
];
