# Database

<p class="uk-article-lead">This chapter covers the database connection, schema management with migrations, and the query APIs available to extensions and themes.</p>

**Note** To map application data to database tables in a structured way, the recommended approach is the [Pagekit Object-relational mapper (ORM)](orm.md), which is described in its own chapter.

<ul class="uk-list">
    <li><a href="#configuration">Configuration</a></li>
    <li><a href="#working-with-database-prefixes">Working with database prefixes</a></li>
    <li><a href="#database-utility">Database utility</a></li>
    <li><a href="#database-migrations">Database Migrations</a></li>
    <li><a href="#queries">Queries</a></li>
    <li><a href="#insert">Insert</a></li>
    <li><a href="#orm">ORM</a></li>
</ul>

## Configuration

Database credentials are stored in `config.php`. Pagekit supports `mysql` and `sqlite`.

```php
'database' => [
    'connections' => [
        'mysql' => [
            'host'     => 'localhost',
            'user'     => 'root',
            'password' => 'PASSWORD',
            'dbname'   => 'DATABASE',
            'prefix'   => 'PREFIX_',
        ],
    ],
],
```

## Working with database prefixes

All table names include the prefix of your Pagekit installation. To address tables dynamically, use the table name with the `@` symbol as a placeholder for the prefix. By convention, table names start with the extension name — e.g. the `options` table for the `foobar` extension is referenced as `@foobar_option`.

## Database utility

The database service exposes a utility for inspecting the current schema:

```php
$util = $this->db->getUtility();

if ($util->tablesExist(['@foobar_option', '@foobar_meta'])) {
    // tables exist
}
```

`Utility` is an instance of `Pagekit\Database\Utility`. Use it for read-only schema introspection — schema changes are made through migrations (see below), not at runtime.

## Database Migrations

Pagekit manages all database schema changes with **Doctrine Migrations 3.x**. Migration files are PHP classes that extend `Doctrine\Migrations\AbstractMigration` (for core) or `Pagekit\Migration\ExtensionMigration` (for extensions). Each class defines `up()` and `down()` methods that apply and revert a single schema change.

- **Core migrations** live in `app/migrations/<year>/` (e.g. `app/migrations/2025/`).
- **Extension migrations** live in `src/Migrations/<year>/` inside the extension package.

The migration runner is registered as the `migration` service. From a module bootstrap or a service:

```php
$result = $app->get('migration')->migrate();              // run all pending core migrations
$status = $app->get('migration')->status();
```

For extensions, pass the namespace and path of the migration directory:

```php
$result = $app->get('migration')->migrateExtension(
    'YourVendor\\YourExtension\\Migrations',
    __DIR__ . '/src/Migrations'
);
```

`MigrationService` returns an associative array with at least `success` (bool), `migrations` (the list applied) and, on failure, `error`.

### Extension lifecycle (`scripts.php`)

The `scripts.php` file handles **extension lifecycle events** — it is not where you write SQL. A complete example is in the [Hello extension](https://github.com/pagekit/extension-hello/blob/master/scripts.php). Link the file from your `composer.json`:

```json
"extra": {
    "scripts": "scripts.php"
}
```

Within `scripts.php`, hook into the lifecycle:

| Event hook  | Description |
|-------------|-------------|
| `install`   | Called when the extension is installed. Run migrations via `$app->get('migration')->migrateExtension(...)`. |
| `enable`    | Called when the extension is enabled in the admin area. Used for configuration setup, not for schema changes. |
| `uninstall` | Called when the extension is removed. Roll back migrations via `$app->get('migration')->rollbackExtension(...)`. |
| `updates`   | A version-keyed map. Each key is the version *from* which the closure runs. Run any newly added migrations here. |

Schema changes belong in migration classes; `scripts.php` is for orchestration and configuration.

```php
return [

    'install' => function ($app) {
        $result = $app->get('migration')->migrateExtension(
            'YourVendor\\YourExtension\\Migrations',
            __DIR__ . '/src/Migrations'
        );

        if (!$result['success']) {
            throw new \RuntimeException('Extension installation failed: ' . $result['error']);
        }
    },

    'uninstall' => function ($app) {
        $result = $app->get('migration')->rollbackExtension(
            'YourVendor\\YourExtension\\Migrations',
            __DIR__ . '/src/Migrations',
            '0'
        );

        if (!$result['success']) {
            throw new \RuntimeException('Extension uninstallation failed: ' . $result['error']);
        }
    },

    'updates' => [
        '2.1.0' => function ($app) {
            $app->get('migration')->migrateExtension(
                'YourVendor\\YourExtension\\Migrations',
                __DIR__ . '/src/Migrations'
            );
        },
    ],

];
```

### Creating and applying migrations

Generate a new migration scaffold:

```bash
php pagekit migration:generate AddTitleColumn
```

Edit the generated file:

```php
<?php

namespace Pagekit\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250127120000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $table = $schema->getTable('pk_my_table');
        $table->addColumn('title', 'string', ['length' => 255, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('pk_my_table');
        $table->dropColumn('title');
    }
}
```

Apply pending migrations:

```bash
php pagekit migration:migrate
```

The `$schema` object is a `Doctrine\DBAL\Schema\Schema` instance. Refer to the [Doctrine DBAL schema representation reference](http://docs.doctrine-project.org/projects/doctrine-dbal/en/3.x/reference/schema-representation.html) and the [list of column types](http://docs.doctrine-project.org/projects/doctrine-dbal/en/3.x/reference/types.html) when defining columns.

For extension developers: place migrations under `src/Migrations/` and execute them through `$app->get('migration')->migrateExtension(...)` from your `scripts.php` `install` and `updates` hooks.

## Queries

Pagekit's database layer is built on **Doctrine DBAL 3.x**. There are three idiomatic ways to query the database, listed from highest-level to lowest-level: ORM, the query builder, and raw SQL.

### 1. Query builder

The [QueryBuilder](https://github.com/pagekit/pagekit/blob/develop/app/modules/database/src/Query/QueryBuilder.php) provides a fluent API for assembling SQL queries.

```php
$result = $this->db->createQueryBuilder()
    ->select('*')
    ->from('@blog_post')
    ->where('id = :id', ['id' => 1])
    ->execute()
    ->fetchAllAssociative();
```

#### Get a query builder object

In a controller or service, inject `Pagekit\Database\Connection`:

```php
use Pagekit\Database\Connection;

class PostRepository
{
    public function __construct(private readonly Connection $db) {}

    public function all(): array
    {
        return $this->db->createQueryBuilder()
            ->select('*')
            ->from('@blog_post')
            ->fetchAllAssociative();
    }
}
```

In a module bootstrap closure, resolve the connection from the container:

```php
'main' => function (Pagekit\Application $app) {
    $query = $app->get('db')->createQueryBuilder();
},
```

#### Basic selects and conditions

| Method | Description |
|--------|-------------|
| `select($columns = ['*'])` | Adds a "select" clause. |
| `from($table)` | Sets the "from" clause. |
| `where($condition, array $params = [])` | Adds a "where" clause. |
| `orWhere($condition, array $params = [])` | Adds an "or where" clause. |

Example:

```php
$comments = $this->db->createQueryBuilder()
    ->select(['title', 'content'])
    ->from('@blog_post')
    ->where('comment_count = ?', [0])
    ->get();
```

#### Query execution

| Method | Description |
|--------|-------------|
| `get($columns = ['*'])` | Execute the query and return all results. |
| `first($columns = ['*'])` | Execute the query and return the first result. |
| `count($column = '*')` | Execute the query and return the count. |
| `execute($columns = ['*'])` | Execute the SELECT and return the result statement. |
| `update(array $values)` | Execute an UPDATE with the given values. |
| `delete()` | Execute a DELETE. |

#### Aggregate functions

| Method | Description |
|--------|-------------|
| `min($column)` | Get the minimum value. |
| `max($column)` | Get the maximum value. |
| `sum($column)` | Get the sum. |
| `avg($column)` | Get the average. |

Example:

```php
$count = $this->db->createQueryBuilder()
    ->select(['comment_count'])
    ->from('@blog_post')
    ->sum('comment_count');
```

#### Advanced query methods

| Method | Description |
|--------|-------------|
| `whereIn($column, $values, $not = false, $type = null)` | Adds a "where in" clause. |
| `orWhereIn($column, $values, $not = false)` | Adds an "or where in" clause. |
| `whereExists($callback, $not = false, $type = null)` | Adds a "where exists" clause. |
| `orWhereExists(Closure $callback, $not = false)` | Adds an "or where exists" clause. |
| `whereInSet($column, $values, $not = false, $type = null)` | Adds a `FIND_IN_SET` equivalent. |
| `groupBy($groupBy)` | Adds a "group by" clause. |
| `having($having, $type = null)` | Adds a "having" clause. |
| `orHaving($having)` | Adds an "or having" clause. |
| `orderBy($sort, $order = null)` | Adds an "order by" clause. |
| `offset($offset)` | Sets the result offset. |
| `limit($limit)` | Sets the maximum number of results. |
| `getSQL()` | Returns the assembled SQL string. |

#### Joins

| Method | Description |
|--------|-------------|
| `join($table, $condition = null, $type = 'inner')` | Adds a join clause. |
| `innerJoin($table, $condition = null)` | Adds an inner join clause. |
| `leftJoin($table, $condition = null)` | Adds a left join clause. |
| `rightJoin($table, $condition = null)` | Adds a right join clause. |

### 2. ORM Queries

When you have set up the [ORM](orm.md) for an extension, you can write very readable queries through the model class:

```php
$result = Role::where(['id <> ?'], [Role::ROLE_ANONYMOUS])
    ->orderBy('priority')
    ->get();
```

The methods below are defined in the [`ModelTrait`](https://github.com/pagekit/pagekit/blob/develop/app/modules/database/src/ORM/ModelTrait.php).

| Method | Description |
|--------|-------------|
| `create($data = [])` | Create a new model instance from the given data. |
| `where($condition, array $params = [])` | Add a where condition. Returns a `QueryBuilder`. Example: `User::where(['name = ?'], ['peter'])`. |
| `find($id)` | Retrieve a model entity by identifier. |
| `findAll()` | Retrieve all entities. |
| `save(array $data = [])` | Persist the entity. |
| `delete()` | Delete the entity. |
| `toArray(array $data = [], array $ignore = [])` | Return the model as an array. Pass `$data` to whitelist properties or `$ignore` to exclude them. |
| `query()` | Return an `ORM\QueryBuilder` exposing all base methods plus ORM extras. |

#### ORM QueryBuilder extras

| Method | Description |
|--------|-------------|
| `get()` | Execute and return all results. |
| `first()` | Execute and return the first result. |
| `related($related)` | Eager-load the given relations. |
| `getRelations()` | Return all configured relations. |
| `getNestedRelations($relation)` | Return nested relations for a given relation. |

Example with nested eager loading:

```php
$comments = Comment::query()
    ->related(['post' => function ($query) {
        return $query->related('comments');
    }])
    ->get();
```

### 3. Raw queries

For one-off SQL statements, call `executeQuery()` on the connection:

```php
$result = $this->db
    ->executeQuery('SELECT * FROM @blog_post')
    ->fetchAllAssociative();

$result = $this->db
    ->executeQuery('SELECT * FROM @blog_post WHERE id = :id', ['id' => 1])
    ->fetchAllAssociative();
```

## Insert

Use `Connection::insert($tableExpression, array $data, array $types = [])` to insert a row:

```php
$this->db->insert('@system_page', [
    'title'   => 'Home',
    'content' => '<p>Hello World</p>',
    'data'    => '{"title":true}',
]);
```

When working with a model defined via the [ORM](#orm), instantiate it and call `save()` instead.

## ORM

With the Object-relational mapping (ORM) in Pagekit you can bind a model class to a database table. While this takes a few more lines to set up than the query builder, the ORM removes a lot of boilerplate and is the recommended way to manage application data. Read more in the [Pagekit ORM](orm.md) chapter.
