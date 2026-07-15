# Users &amp; Permissions

<p class="uk-article-lead">Pagekit ships with a complete sign-up flow and a role-based permission system. Extensions integrate with this model by declaring permissions and consulting the current user via the <code>user</code> service.</p>

<ul class="uk-list">
    <li><a href="#concepts">Concepts</a></li>
    <li><a href="#show-content-to-specific-role-only">Show content to specific role only</a></li>
    <li><a href="#register-permissions-from-a-module-definition">Register permissions from a module definition</a></li>
    <li><a href="#checking-roles-and-permissions">Checking roles and permissions</a></li>
    <li><a href="#permission-expressions">Permission expressions</a></li>
</ul>

## Concepts

A **user** is a representation of a person registered with your site, identified by a username. A user account can be *active*, *blocked* or *new*. Users may log in to the frontend or the admin area; not every account has admin access.

**Permissions** define the actions a user can perform. A permission is identified by a name, for example `system: access admin area`. Permission names should be descriptive and start with the name of the owning module — `user:` for the user module, `system:` for system modules.

**Roles** group user accounts. All users with the same role share the same permissions. Roles also drive content access: a piece of content can be restricted to one or more roles. Pagekit ships with the default roles **Anonymous**, **Authenticated** and **Administrator**, and you can create as many additional roles as you need.

## Show content to specific role only

Roles are very flexible. You can publish content that is visible only to a chosen group of users.

1. Create a new role called **Premium** in *Users > Roles*. Don't assign any permissions to this role.
2. In *Users > List*, click a user account to edit their profile and enable the new **Premium** role for this user.
3. On every page in the *Site* area, you can see a *Restrict access* section in the sidebar. Make sure to select the **Premium** role and nothing else.

This item will now be visible only to logged-in users of the **Premium** role.

**Note** Your administrator account won't see this content either, unless you add yourself to the **Premium** role or also enable **Administrator** in the *Restrict access* settings.

## Register permissions from a module definition

To add a permission that can be assigned to a role, use the `permissions` key in your extension's `index.php`.

Use descriptive permission names. The convention is to start with the extension name, followed by a short phrase describing the action, all lowercase. The `title` is the string shown in the admin UI; wrap it in `_()` so it can be translated.

```php
'permissions' => [
    'hello: manage settings' => [
        'title' => _('Manage settings'),
    ],
],
```

## Checking roles and permissions

The currently authenticated user is exposed as the `user` service. From a controller, inject `Pagekit\User\Model\User` directly:

```php
namespace Pagekit\Hello\Controller;

use Pagekit\Routing\Attribute\Route;
use Pagekit\User\Attribute\Access;
use Pagekit\User\Model\User;

#[Route('/hello')]
#[Access('hello: manage settings')]
class HelloController
{
    public function __construct(
        private readonly User $user,
    ) {}

    public function indexAction(): array
    {
        return [
            'isAdmin' => $this->user->isAdministrator(),
        ];
    }
}
```

From a module bootstrap closure, resolve the user via the container:

```php
'main' => function (Pagekit\Application $app) {
    $user = $app->get('user');

    if ($user->hasRole(4)) {
        // role id 4 is granted
    }

    if ($user->hasAccess('hello: manage settings')) {
        // permission is granted
    }
},
```

To resolve a role by name and check membership:

```php
use Pagekit\User\Model\Role;

$role = Role::where('name = ?', ['Editor'])->first();

if ($role !== null && $app->get('user')->hasRole($role->id)) {
    // user is in the Editor role
}
```

## Permission expressions

`User::hasAccess(?string $expression): bool` accepts either a single permission name or a boolean expression composed of permission names and the operators `!`, `&&` (or `&`), and `||` (or `|`), with parentheses for grouping. Whitespace inside permission names is preserved.

Examples:

```php
$user->hasAccess('hello: manage settings');

$user->hasAccess('hello: edit article || hello: manage settings');

$user->hasAccess('(hello: edit article && hello: publish) || hello: manage settings');

$user->hasAccess('!hello: locked');
```

Operator precedence is `!` > `&&` > `||`. Administrators always pass; an empty expression is treated as granted.
