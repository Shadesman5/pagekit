<?php

use Pagekit\Application as App;

return [

    /*
     * Installation hook.
     */
    'install' => function ($app) {

        $util = $app['db']->getUtility();

        $exists = App::db()->fetchColumn("SELECT COUNT(*) FROM @system_node WHERE slug = 'hello'");
        $max = App::db()->fetchColumn("SELECT MAX(priority) FROM @system_node WHERE menu = 'main'");
        $priority = $max !== null ? $max + 1 : 1;

        if (!$exists) {
            App::db()->insert('@system_node', [
                'priority' => $priority,
                'status' => 1,
                'title'  => 'Hello',
                'slug'   => 'hello',
                'path'   => '/hello',
                'link'   => '@hello',
                'type'   => 'hello',
                'menu'   => 'main'
            ]);
        }

        if ($util->tableExists('@hello_greetings') === false) {
            $util->createTable('@hello_greetings', function ($table) {
                $table->addColumn('id', 'integer', ['unsigned' => true, 'length' => 10, 'autoincrement' => true]);
                $table->addColumn('name', 'string', ['length' => 255, 'default' => '']);
                $table->setPrimaryKey(['id']);
            });
        }
    },

    /*
     * Enable hook
     *
     */
    'enable' => function ($app) {
        $exists = App::db()->fetchColumn("SELECT COUNT(*) FROM @system_node WHERE slug = 'hello'");
        $max = App::db()->fetchColumn("SELECT MAX(priority) FROM @system_node WHERE menu = 'main'");
        $priority = $max !== null ? $max + 1 : 1;

        if (!$exists) {
            App::db()->insert('@system_node', [
                'priority' => $priority,
                'status' => 1,
                'title'  => 'Hello',
                'slug'   => 'hello',
                'path'   => '/hello',
                'link'   => '@hello',
                'type'   => 'hello',
                'menu'   => 'main'
            ]);
        }
    },

    /*
    * Disable hook
    *
    */
    'disable' => function ($app) {
        App::db()->delete('@system_node', ['slug' => 'hello']);
    },

    /*
     * Uninstall hook
     *
     */
    'uninstall' => function ($app) {

        // remove the config
        $app['config']->remove('hello');

        // remove the node
        App::db()->delete('@system_node', ['slug' => 'hello']);

        $util = $app['db']->getUtility();

        if ($util->tableExists('@hello_greetings')) {
            $util->dropTable('@hello_greetings');
        }
    },

    /*
     * Runs all updates that are newer than the current version.
     *
     */
    'updates' => [

        '0.5.0' => function ($app) {},

        '0.9.0' => function ($app) {},

    ],

];
