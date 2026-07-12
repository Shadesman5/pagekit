# Events

<p class="uk-article-lead">Pagekit's <code>EventDispatcher</code> is the platform extension API. Modules and extensions hook into core behavior by listening to named events that are triggered during a request lifecycle, around model operations, and around route execution.</p>

An event is identified by a unique string (e.g. `boot` for the event triggered during the boot phase of the Pagekit application). Listeners receive a `Pagekit\Event\Event` instance plus any additional arguments passed by the dispatcher.

<ul class="uk-list">
    <li><a href="#system-events">System Events</a></li>
    <li><a href="#auth-events">Auth Events</a></li>
    <li><a href="#database-entitymanager-events">Database / EntityManager</a></li>
    <li><a href="#router-events">Router</a></li>
    <li><a href="#registering-listeners">Registering Listeners</a></li>
</ul>

## System Events

Pagekit triggers the following events during the lifecycle of a page request:

- `boot`: The boot phase of the application has started.
- `request`: The kernel has started handling a request.
- `controller`: A controller action is about to be called.
- `response`: The response is about to be sent to the browser.
- `terminate`: The response has been sent successfully.
- `exception`: An exception has been raised during handling.

## Auth Events

All authorization events are defined as constants on `Pagekit\Auth\AuthEvents`.

## Database / EntityManager

For each entity that is loaded, updated, or saved, a specific event is triggered. For example, when a widget is loaded a `model.widget.init` event is fired.

Entity event names follow the schema `model.<entity_short_name>.<event_name>`, which lets you listen on a single entity type. The full list of EntityManager events is defined in `Pagekit\Database\Events`.

## Router Events

The router triggers an event before and after a route is executed. Each event name includes the executed route name: `before@site/api/node/save` or `after@system/settings/save`.

## Registering Listeners

There are two idiomatic ways to attach a listener: inline in a module's `events` map, and via an `EventSubscriberInterface` implementation.

### Inline listeners

Define listeners directly in your package's `index.php` under the `events` key. Each entry registers a closure on the named event:

```php
return [

    'name' => 'hello',

    'events' => [

        'boot' => function ($event, $app) {
            $app->get('events')->subscribe(new \Acme\Hello\Listener\PostSaveListener());
        },

        'model.page.saved' => function ($event, $page) {
            // react to the saved page
        },

    ],

];
```

### Subscriber classes

For listeners that group related callbacks, implement `Pagekit\Event\EventSubscriberInterface` and return a map of event names to method names from `subscribe()`:

```php
namespace Acme\Hello\Listener;

use Pagekit\Event\Event;
use Pagekit\Event\EventSubscriberInterface;

class PostSaveListener implements EventSubscriberInterface
{
    public function subscribe(): array
    {
        return [
            'model.page.saved' => 'onSaved',
        ];
    }

    public function onSaved(Event $event, object $model): void
    {
        // react to the saved model
    }
}
```

Subscribers are attached to the dispatcher via `$app->get('events')->subscribe($subscriber)`.

### Listener priorities

Both `on()` and the array form of `subscribe()` accept a numeric priority. Higher priorities run first; the default is `0`.

```php
$app->get('events')->on('boot', function ($event) {
    // runs early
}, 100);
```

In a subscriber, return `['method', priority]` instead of a plain method name to set the priority:

```php
public function subscribe(): array
{
    return [
        'model.page.saved' => ['onSaved', 100],
    ];
}
```
