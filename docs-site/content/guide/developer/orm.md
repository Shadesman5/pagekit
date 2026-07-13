# ORM
<p class="uk-article-lead">The Pagekit Object-relational mapper (ORM) lets you build model classes whose typed properties are mapped to columns of a database table. It also defines relations between your entities and the entities exposed by Pagekit (for example users).</p>

The ORM runs on **PHP 8.2+** with typed properties, lazy relation loading and PSR-6 query-result caching.

## Setup

### Create the table

Schema definitions live in [database migrations](database.md#database-migrations), not in `scripts.php`. Generate a migration scaffold and define the table inside its `up()` / `down()` methods:

```bash
php pagekit migration:generate CreateForumTopics
```

```php
<?php

namespace Pagekit\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250127100000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('pk_forum_topics');
        $table->addColumn('id', 'integer', ['unsigned' => true, 'length' => 10, 'autoincrement' => true]);
        $table->addColumn('user_id', 'integer', ['unsigned' => true, 'length' => 10, 'default' => 0]);
        $table->addColumn('title', 'string', ['length' => 255, 'default' => '']);
        $table->addColumn('date', 'datetime');
        $table->addColumn('modified', 'datetime', ['notnull' => false]);
        $table->addColumn('content', 'text');
        $table->addIndex(['user_id'], 'FORUM_TOPIC_USER_ID');
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('pk_forum_topics');
    }
}
```

Run the migration via `php pagekit migration:migrate` (core) or via the `install`/`updates` hooks in `scripts.php` for extensions — see the [database chapter](database.md#database-migrations) for the complete migration workflow.

### Define a model class

Pagekit's ORM uses native **PHP 8 attributes** to map properties to columns and to declare relations. All attributes live in `Pagekit\Database\ORM\Attribute` and are typically imported as `ORM`.

Example:

```php
<?php

namespace Pagekit\Forum\Model;

use Pagekit\Database\ORM\ModelTrait;
use Pagekit\Database\ORM\Attribute as ORM;

#[ORM\Entity(tableClass: '@forum_topics')]
class Topic
{
    use ModelTrait;

    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    public ?int $id = null;

    #[ORM\Column]
    public string $title = '';

    #[ORM\Column(type: 'datetime')]
    public ?\DateTime $date = null;

    #[ORM\Column(type: 'text')]
    public string $content = '';

    #[ORM\Column(type: 'integer')]
    public ?int $user_id = null;

    #[ORM\BelongsTo(targetEntity: 'Pagekit\User\Model\User', keyFrom: 'user_id')]
    public ?User $user = null;
}
```

A model is a plain PHP class that uses the trait `Pagekit\Database\ORM\ModelTrait`. Traits allow to include certain behaviour into a class - similar to simple class inheritance. The main difference is that a class can use multiple traits while it could only inherit from one single class.

**Note** If you are unfamiliar with traits, have a quick look at the [official PHP documentation on traits](http://php.net/manual/en/language.oop5.traits.php).

The attribute `#[ORM\Entity(tableClass: '@my_table')]` binds the Model to the database table `pk_my_table` (`@` is automatically replaced by the database prefix of your installation).

When defining a property in a class, you can bind that variable to a table column by using the `#[ORM\Column]` attribute. You can use any types supported by [Doctrine DBAL 3.x](http://docs.doctrine-project.org/projects/doctrine-dbal/en/3.x/reference/types.html).

Use **typed properties** for every mapped column. The ORM relies on the property type to coerce values to and from the database:

```php
#[ORM\Column(type: 'integer')]
#[ORM\Id]
public ?int $id = null;

#[ORM\Column]
public string $title = '';

#[ORM\Column(type: 'datetime')]
public ?\DateTime $date = null;
```

The table referenced in your model class must exist in the database — define it in a migration as shown above.

## Relations

The application data you represent in your database model has certain relations amongst its instances. A blog post has a number of comments related to it and it belongs to exactly one User instance. The Pagekit ORM offers mechanisms to define these relations and also to query them in a programmatic manner.

### Belongs-to relation

The basic attribute that is used across the different relation types is the `#[ORM\BelongsTo]` attribute above a model property. In the following example (taken from the `Post` model of the Blog) we specify a `$user` property, which is defined to point to the instance of the Pagekit `User` model.

The `keyFrom` parameter specifies which source property is used to point to the user id. Note how we also need to define the according `user_id` property in order for the relationship to be resolved by a query.

Example:

```php
#[ORM\Column(type: 'integer')]
public ?int $user_id = null;

#[ORM\BelongsTo(targetEntity: 'Pagekit\User\Model\User', keyFrom: 'user_id')]
public ?User $user = null;
```

### One-to-many relation

In this relationship, a single model instance has references to an arbitrary
amount of instances of another model. A classic example for this is a `Post`
which has any number of `Comment` instances that belong to it. On the inverse
side, a comment belongs exactly one `Post`.

Example from the blog package, in `Pagekit\Blog\Model\Post`.

```php
#[ORM\HasMany(targetEntity: 'Comment', keyFrom: 'id', keyTo: 'post_id')]
public array $comments = [];
```

Define the inverse of the relation in `Pagekit\Blog\Model\Comment`:

```php
#[ORM\Column(type: 'integer')]
public ?int $post_id = null;

#[ORM\BelongsTo(targetEntity: 'Post', keyFrom: 'post_id')]
public ?Post $post = null;
```

To query the Model, you can use the ORM class.

```
use Pagekit\Blog\Post;

// ...

// fetch posts without related comments
$posts = Post::findAll();
var_dump($posts);
```

Output:

```
array (size=6)
  1 =>
    object(Pagekit\Blog\Model\Post)[4513]
      public 'id' => int 1
      public 'title' => string 'Hello Pagekit' (length=13)
      public 'comments' => null
      // ...

  2 =>
    object(Pagekit\Blog\Model\Post)[3893]
      public 'id' => int 2
      public 'title' => string 'Hello World' (length=11)
      public 'comments' => null
      // ...

  // ...
```

```
use Pagekit\Blog\Post;

// ...

// fetch posts including related comments
$posts = Post::query()->related('comments')->get();
var_dump($posts);
```

Output:

```
array (size=6)

  1 =>
    object(Pagekit\Blog\Model\Post)[4512]
      public 'id' => int 1
      public 'title' => string 'Hello Pagekit' (length=13)
      public 'comments' =>
        array (size=0)
          empty
      // ...

  2 =>
    object(Pagekit\Blog\Model\Post)[3433]
      public 'id' => int 2
      public 'title' => string 'Hello World' (length=11)
      public 'comments' =>
        array (size=1)
          6 =>
            object(Pagekit\Blog\Model\Comment)[4509]
              ...
      // ...

  // ...
```

### One-to-one relation

A very simple relationship is the one-to-one relation. A `ForumUser` might have exactly one `Avatar` assigned to it. While you simply include all information about the avatar inside the `ForumUser` model, it sometimes makes sense to split these in separate models.

To implement the one-to-one relation, you can use the `#[ORM\BelongsTo]` attribute in each model class.

`#[ORM\BelongsTo(targetEntity: 'Avatar', keyFrom: 'avatar_id', keyTo: 'id')]`

- `targetEntity`: The target model class
- `keyFrom`: foreign key in this table pointing to the related model
- `keyTo`: primary key in the related model

Example model `ForumUser`:

```php
<?php

namespace Pagekit\Forum\Model;

use Pagekit\Database\ORM\ModelTrait;
use Pagekit\Database\ORM\Attribute as ORM;

#[ORM\Entity(tableClass: '@forum_user')]
class ForumUser
{
    use ModelTrait;

    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    public ?int $id = null;

    #[ORM\Column]
    public string $name = '';

    #[ORM\Column(type: 'integer')]
    public ?int $avatar_id = null;

    #[ORM\BelongsTo(targetEntity: 'Avatar', keyFrom: 'avatar_id', keyTo: 'id')]
    public ?Avatar $avatar = null;
}
```

Example model `Avatar`:

```php
<?php

namespace Pagekit\Forum\Model;

use Pagekit\Database\ORM\ModelTrait;
use Pagekit\Database\ORM\Attribute as ORM;

#[ORM\Entity(tableClass: '@forum_avatars')]
class Avatar
{
    use ModelTrait;

    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $path = '';

    #[ORM\Column(type: 'integer')]
    public ?int $user_id = null;

    #[ORM\BelongsTo(targetEntity: 'ForumUser', keyFrom: 'user_id', keyTo: 'id')]
    public ?ForumUser $user = null;
}
```

To make sure the related model is included in a query result, fetch the `QueryBuilder` instance from the model class and explicitly list the relation property in the `related()` method.

```php
<?php

use Pagekit\Forum\Model\ForumUser;
use Pagekit\Forum\Model\Avatar;

// ...

// get all users including their related $avatar object
$users = ForumUser::query()->related('avatar')->get();
foreach ($users as $user) {
    var_dump($user->avatar->path);
}

// get all avatars including their related $user object
$avatars = Avatar::query()->related('user')->get();
foreach ($avatars as $avatar) {
    var_dump($avatar->user);
}
```


### Many-to-many relation

Sometimes, two models are in a relation where there are potentially *many instances* on both sides of the relation. An example would be a relation between tags and posts: One post can have several tags assigned to it. At the same time, one tag can be assigned to multiple posts.

A different example that is listed below, is the scenario of favorite topics in a discussion forum. A user can have multiple favorite topics. One topic can be favorited by multiple users.

To implement the many-to-many relation, you need an additional database table. Each entry in that table represents a connection from a `Topic` instance to a `ForumUser` instance and vice versa. In database modelling, this is called a [junction table](https://en.wikipedia.org/wiki/Associative_entity).

Define all three tables — both entity tables and the junction table — in a migration:

```php
public function up(Schema $schema): void
{
    $users = $schema->createTable('pk_forum_users');
    $users->addColumn('id', 'integer', ['unsigned' => true, 'length' => 10, 'autoincrement' => true]);
    $users->addColumn('name', 'string', ['length' => 255, 'default' => '']);
    $users->setPrimaryKey(['id']);

    $topics = $schema->createTable('pk_forum_topics');
    $topics->addColumn('id', 'integer', ['unsigned' => true, 'length' => 10, 'autoincrement' => true]);
    $topics->addColumn('title', 'string', ['length' => 255, 'default' => '']);
    $topics->addColumn('content', 'text');
    $topics->setPrimaryKey(['id']);

    $favorites = $schema->createTable('pk_forum_favorites');
    $favorites->addColumn('id', 'integer', ['unsigned' => true, 'length' => 10, 'autoincrement' => true]);
    $favorites->addColumn('user_id', 'integer', ['unsigned' => true, 'length' => 10, 'default' => 0]);
    $favorites->addColumn('topic_id', 'integer', ['unsigned' => true, 'length' => 10, 'default' => 0]);
    $favorites->setPrimaryKey(['id']);
}
```

The relation itself is then defined in each Model class where you want to be able to query it. If you only want to list the favorite posts for a specific user, but you do not lists all user who have favorited a given post, you would only define the relation in one model. In the following example however, the `#[ORM\ManyToMany]` attribute is located in both model classes.

The `#[ORM\ManyToMany]` attribute takes the following parameters.

Argument         | Description
---------------- | -----------
`targetEntity`   | The target model class
`tableThrough`   | Name of the junction table
`keyThroughFrom` | Name of the foreign key in "from" direction
`keyThroughTo`   | Name of the foreign key in "to" direction
`orderBy`        | (optional) Order by statement

Example attribute:

```php
#[ORM\ManyToMany(targetEntity: 'ForumUser', tableThrough: '@forum_favorites', keyThroughFrom: 'topic_id', keyThroughTo: 'forum_user_id')]
public array $users = [];
```

Example model `Topic`:

```php
<?php

namespace Pagekit\Forum\Model;

use Pagekit\Database\ORM\ModelTrait;
use Pagekit\Database\ORM\Attribute as ORM;

#[ORM\Entity(tableClass: '@forum_topics')]
class Topic
{
    use ModelTrait;

    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    public ?int $id = null;

    #[ORM\Column]
    public string $title = '';

    #[ORM\Column(type: 'text')]
    public string $content = '';

    #[ORM\ManyToMany(targetEntity: 'ForumUser', tableThrough: '@forum_favorites', keyThroughFrom: 'topic_id', keyThroughTo: 'forum_user_id')]
    public array $users = [];
}
```

Example model `ForumUser`:

```php
<?php

namespace Pagekit\Forum\Model;

use Pagekit\Database\ORM\ModelTrait;
use Pagekit\Database\ORM\Attribute as ORM;

#[ORM\Entity(tableClass: '@forum_user')]
class ForumUser
{
    use ModelTrait;

    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    public ?int $id = null;

    #[ORM\Column]
    public string $name = '';

    #[ORM\ManyToMany(targetEntity: 'Topic', tableThrough: '@forum_favorites', keyThroughFrom: 'forum_user_id', keyThroughTo: 'topic_id')]
    public array $topics = [];
}
```

Example queries:

```php
// resolve many-to-many relation in query

// fetch favorite ropics for given user
$user_id = 1;
$user = ForumUser::query()->where('id = ?', [$user_id])->related('topics')->first();

foreach ($user->topics as $topic) {
    //
}

// fetch users that have favorited a given topic
$topic_id = 1;
$topic = Topic::query()->where('id = ?', [$topic_id])->related('users')->first();

foreach ($topic->users as $user) {
    // ...
}
```

## ORM Queries

Fetch a model instance with a given id.

```
$post = Post::find(23)
```

Fetch all instances of a model.

```
$posts = Post::findAll();
```

With the above queries, relations will not be expanded to include related instances. In above example, the `Post` instance will not have its `$comments` property initialized.

```
// related objects are not fetched by default
$post->comments == null;
```

The reason is performance: relation queries cost extra round-trips to the database, and most reads do not need them. The ORM uses lazy loading and PSR-6 query-result caching to keep repeated reads cheap. When you do need related objects, call `related()` on the `QueryBuilder` to declare which relations should be resolved in this query.

So, to fetch a `Post` instance and include the associated `Comment` instances, you need to build a query which fetches the related objects.

```
// fetch all, including related objects
$posts = Post::query()->related('comments')->get();

// fetch single instance, include related objects
$id = 23;
$post = Post::query()->related('comments')->where('id = ?', [$id])->first();
```

Note how the `find(23)` has been replaced with `->where('id = ?', [$id])->first()`. This is because `find()` is a method defined on the Model. In the second example however, we have an instance of `Pagekit\Database\ORM\QueryBuilder`.

For more details on ORM queries and the regular queries, check out the documentation on [database queries](database.md#queries)

## Create new model instance

You can create and save a new model by calling the `save()` method on a fresh model instance.

```php
$user = new ForumUser();
$user->name = "bruce";
$user->save();
```

Alternatively you can call the `create()` method on the model class directly and provide an array of existing data to initialize the instance. Call `save()` afterwards to store the instance to the database.

```php
$user = ForumUser::create(["name" => "peter"]);
$user->save();
```

## Modify existing instance

Fetch an existing instance, perform any changes on the object and then call the `save()` method to store changes to the database.

```php
$user = ForumUser::find(2);
$user->name = "david";
$user->save();
```

## Delete existing instance

Fetch an existing model instance and call the `delete()` method to remove this instance from the database.

```php
$user = ForumUser::find(2);
$user->delete();
```
