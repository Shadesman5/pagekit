# Response

<p class="uk-article-lead">Controllers return either a value that Pagekit converts into an HTTP response automatically, or a <code>Response</code> object built explicitly through the <code>response</code> service. This chapter walks through the response types your controller actions can produce.</p>

<ul class="uk-list">
    <li><a href="#string">String</a></li>
    <li><a href="#rendered-view">Rendered View</a></li>
    <li><a href="#themed">Themed</a></li>
    <li><a href="#json">JSON</a></li>
    <li><a href="#redirect">Redirect</a></li>
    <li><a href="#custom-response-and-error-pages">Custom response and error pages</a></li>
    <li><a href="#stream">Stream</a></li>
    <li><a href="#download">Download</a></li>
</ul>

The `response` service is `Pagekit\Application\Response`. Inject it into your controller via the constructor:

```php
namespace Pagekit\Hello\Controller;

use Pagekit\Application\Response;
use Pagekit\Routing\Attribute\Route;

#[Route('/hello')]
class HelloController
{
    public function __construct(
        private readonly Response $response,
    ) {}

    // actions ...
}
```

## String

To return a simple string response, call `create()` on the response service:

```php
public function indexAction()
{
    return $this->response->create('My content');
}
```

A controller action that simply returns a string is wrapped in the active theme layout — see [Themed](#themed).

## Rendered View

Pagekit can render the view for you. Return an array containing a `$view` key with `title` and `name`. All other entries in the array become parameters in the view file. Learn more in [Views and Templating](views-templating.md).

```php
public function indexAction(string $name = ''): array
{
    return [
        '$view' => [
            'title' => 'Hello World',
            'name'  => 'hello:views/index.php',
        ],
        'name' => $name,
    ];
}
```

To skip the surrounding theme layout, set `'layout' => false` inside the `$view` array.

## Themed

A themed response embeds the controller's result inside the active theme's main layout. Return a string from the action:

```php
public function indexAction(): string
{
    return 'My content';
}
```

## JSON

There are two ways to return a JSON response.

If the action returns an array or an object that implements `\JsonSerializable`, Pagekit produces a `JsonResponse` automatically:

```php
public function jsonAction(): array
{
    return ['error' => true, 'message' => 'There is nothing here. Move along.'];
}
```

Or build the response explicitly:

```php
public function jsonAction(): JsonResponse
{
    return $this->response->json([
        'error'   => true,
        'message' => 'There is nothing here. Move along.',
    ]);
}
```

## Redirect

Use a redirect response to send the user to another URL or named route:

```php
public function redirectAction(): RedirectResponse
{
    return $this->response->redirect('@hello/greet/name', ['name' => 'Someone']);
}
```

## Custom response and error pages

Return any HTTP status code with `create()`:

```php
public function forbiddenAction(): HttpResponse
{
    return $this->response->create('Permission denied.', 401);
}
```

## Stream

A streamed response sends content back to the client incrementally. It takes a callback as its first argument; calling `flush()` inside the callback flushes the buffer to the client.

```php
public function streamAction(): StreamedResponse
{
    return $this->response->stream(function () {
        echo 'Hello World';
        flush();
        echo 'Hello Pagekit';
        flush();
    });
}
```

## Download

A download response sends a file to the client and sets `Content-Disposition: attachment` so most browsers display a *Save as…* dialog:

```php
public function downloadAction(): BinaryFileResponse
{
    return $this->response->download('extensions/hello/extension.svg');
}
```
