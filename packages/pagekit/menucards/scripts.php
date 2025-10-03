<?php

use Pagekit\Application as App;

/**
 * Installation and update scripts for Menucards extension
 */
return [

    /**
     * Install hook - Creates database tables
     */
    'install' => function ($app) {
        // Debug: Install script called
        error_log('[Menucards] Install script called');

        $util = $app['db']->getUtility();

        // Create Menu table
        if ($util->tableExists('@menucards_menu') === false) {
            $util->createTable('@menucards_menu', function ($table) {
                $table->addColumn('id', 'integer', ['unsigned' => true, 'length' => 10, 'autoincrement' => true]);
                $table->addColumn('title', 'string', ['length' => 255]);
                $table->addColumn('slug', 'string', ['length' => 255]);
                $table->addColumn('description', 'text', ['notnull' => false]);
                $table->addColumn('status', 'smallint', ['default' => 0]);
                $table->addColumn('data', 'json_array', ['notnull' => false]);
                $table->addColumn('created', 'datetime', ['notnull' => false]);
                $table->addColumn('modified', 'datetime', ['notnull' => false]);
                $table->setPrimaryKey(['id']);
                $table->addUniqueIndex(['slug'], '@menucards_menu_slug');
            });
            error_log('[Menucards] Created table: @menucards_menu');
        }

        // Create Category table
        if ($util->tableExists('@menucards_category') === false) {
            $util->createTable('@menucards_category', function ($table) {
                $table->addColumn('id', 'integer', ['unsigned' => true, 'length' => 10, 'autoincrement' => true]);
                $table->addColumn('menu_id', 'integer', ['unsigned' => true, 'length' => 10]);
                $table->addColumn('title', 'string', ['length' => 255]);
                $table->addColumn('description', 'text', ['notnull' => false]);
                $table->addColumn('priority', 'integer', ['default' => 0]);
                $table->addColumn('data', 'json_array', ['notnull' => false]);
                $table->setPrimaryKey(['id']);
                $table->addIndex(['menu_id'], '@menucards_category_menu_id');
            });
            error_log('[Menucards] Created table: @menucards_category');
        }

        // Create Product table
        if ($util->tableExists('@menucards_product') === false) {
            $util->createTable('@menucards_product', function ($table) {
                $table->addColumn('id', 'integer', ['unsigned' => true, 'length' => 10, 'autoincrement' => true]);
                $table->addColumn('name', 'string', ['length' => 255]);
                $table->addColumn('description', 'text', ['notnull' => false]);
                $table->addColumn('price', 'decimal', ['precision' => 10, 'scale' => 2, 'notnull' => false]);
                $table->addColumn('image', 'string', ['length' => 255, 'notnull' => false]);
                $table->addColumn('allergens', 'text', ['notnull' => false]);
                $table->addColumn('data', 'json_array', ['notnull' => false]);
                $table->addColumn('created', 'datetime', ['notnull' => false]);
                $table->addColumn('modified', 'datetime', ['notnull' => false]);
                $table->setPrimaryKey(['id']);
            });
            error_log('[Menucards] Created table: @menucards_product');
        }

        // Create Category-Product relationship table (Many-to-Many)
        if ($util->tableExists('@menucards_category_product') === false) {
            $util->createTable('@menucards_category_product', function ($table) {
                $table->addColumn('category_id', 'integer', ['unsigned' => true, 'length' => 10]);
                $table->addColumn('product_id', 'integer', ['unsigned' => true, 'length' => 10]);
                $table->addColumn('priority', 'integer', ['default' => 0]);
                $table->setPrimaryKey(['category_id', 'product_id']);
                $table->addIndex(['category_id'], '@menucards_cp_category_id');
                $table->addIndex(['product_id'], '@menucards_cp_product_id');
            });
            error_log('[Menucards] Created table: @menucards_category_product');
        }
    },

    /**
     * Uninstall hook - Removes database tables
     */
    'uninstall' => function ($app) {
        // Debug: Uninstall script called
        error_log('[Menucards] Uninstall script called');

        $util = $app['db']->getUtility();

        // Drop tables in correct order (relationships first)
        if ($util->tableExists('@menucards_category_product')) {
            $util->dropTable('@menucards_category_product');
            error_log('[Menucards] Dropped table: @menucards_category_product');
        }

        if ($util->tableExists('@menucards_category')) {
            $util->dropTable('@menucards_category');
            error_log('[Menucards] Dropped table: @menucards_category');
        }

        if ($util->tableExists('@menucards_product')) {
            $util->dropTable('@menucards_product');
            error_log('[Menucards] Dropped table: @menucards_product');
        }

        if ($util->tableExists('@menucards_menu')) {
            $util->dropTable('@menucards_menu');
            error_log('[Menucards] Dropped table: @menucards_menu');
        }
    },

    /**
     * Update scripts for future versions
     */
    'updates' => [
        // Future update scripts go here
    ]

];
