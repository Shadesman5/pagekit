# Configuration File

<p class="uk-article-lead">The Pagekit configuration file is automatically created when you install Pagekit. If you want to change configuration settings manually, this article explains syntax and content of the file.</p>

Usually, you do not need to fiddle with the configuration file `config.php` after it has been created by the installer. The normal way of changing configuration is through _System > Settings_ in the Pagekit admin panel.

Sometimes manually editing this file is still necessary and useful, for example when troubleshooting a broken installation or when moving an existing Pagekit installation to a new server.

**Note** The `config.php` file is located in the Pagekit root directory. It is merged with the system defaults from `app/system/config.php`. Only values you define in `config.php` override the defaults.

## File structure

The configuration file returns a PHP array. In the following example you see the most common settings that can be overridden.

Usually you only have one database connection present. The example includes both SQLite and MySQL to show how configuration works for different database drivers. Only the `default` connection will be used by Pagekit.

```php
<?php

return [
    'database' => [
        'default' => 'sqlite',     // default database connection
        'connections' => [
            'sqlite' => [
                'prefix' => 'pk_',     // prefix in front of every table
            ],
            'mysql' => [
                'host' => 'localhost', // server host name (use 'mysql' for Docker)
                'port' => 3306,        // optional, defaults to 3306
                'user' => 'user',      // server user name
                'password' => 'pass',  // server user password
                'dbname' => 'pagekit', // database name
                'prefix' => 'pk_',     // prefix in front of every table
            ],
        ],
    ],
    'system' => [
        'secret' => 'secret',       // a secret string generated during installation (do not change)
    ],
    'system/cache' => [
        'caches' => [
            'cache' => [
                'storage' => 'auto',  // cache backend: 'auto', 'file', 'phpfile', or 'apcu' (PSR-6)
            ],
        ],
        'nocache' => false,         // disable cache entirely by setting to true
    ],
    'system/finder' => [
        'storage' => '/storage',    // relative path for uploads (default: /storage)
    ],
    'application' => [
        'debug' => false,           // enable for debug output during development
    ],
    'debug' => [
        'enabled' => false,         // enable debug toolbar (requires SQLite for storage)
    ],
];
```

## Configuration keys

| Key | Description |
|-----|-------------|
| `database` | Database connection settings. Required for installation. |
| `system.secret` | Random string for security (CSRF, sessions). Generated during installation. |
| `system/cache` | PSR-6 cache backend. Use `auto` to let Pagekit choose the best available option. |
| `system/finder` | Path for file uploads and media storage. |
| `application.debug` | Enable to show detailed error messages. **Disable in production.** |
| `debug.enabled` | Enable the debug toolbar (requests, routes, database queries). **Disable in production.** |

**Security warning:** Never enable `application.debug` or `debug.enabled` on a production server. Both expose sensitive information.
