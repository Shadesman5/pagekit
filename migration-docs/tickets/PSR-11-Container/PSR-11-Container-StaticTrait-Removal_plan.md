## ARCHITECT OUTPUT
- **Current Step (ROADMAP):** 2.0.1e
- **Scope:** All files containing `App::*` static calls, `$app->service()` magic calls, `App::getInstance()` bridges, EventTrait/RouterTrait/StaticTrait usage. ~260+ call sites across app/ and packages/.
- **Deferred:** EntityManager singleton (`static::$instance` in `app/modules/database/src/ORM/EntityManager.php`) — defer to Step 2.1 (Static Analysis) with TODO marker. ORM refactoring is out of scope for 2.0.1e.
- **Bridges:** IntlServiceLocator (narrow static locator for `__()`, `_c()`, `_i()` global functions) — permanent pattern, NOT a temporary bridge. Justified: global functions are a stable extension API.
- **GitHub Issue:** #166

---

### Checklist

Each step is atomic. System must remain functional (PHPUnit green) after each step. Run `./app/vendor/bin/phpunit` after each step.

---

#### Step 1: Migrate `$app->module()` and `$app->config()` instance magic calls

**What:** Replace `$app->module('name')` → `$app->get('module')->get('name')` and `$app->config('name')` → `$app->get('config')('name')` in all module index.php files, view templates, and mail templates. Also migrate `$app->request()`, `$app->url()`, `$app->view()`.

**Why:** These dispatch through `Container::__call()` which must be deleted in Step 4.

**Files to change:**
- `app/system/index.php` — 5 calls: `$app->module(...)`, `$app->config(...)`, `$app->request()`
- `app/system/modules/site/index.php` — 4 calls: `$app->config(...)`, `$app->get('theme')`
- `app/system/modules/widget/index.php` — 4 calls: `$app->config(...)`
- `app/system/modules/settings/index.php` — 3 calls: `$app->module(...)`
- `app/system/modules/theme/index.php` — 2 calls: `$app->module(...)`
- `app/system/modules/editor/index.php` — 1 call: `$app->module(...)`
- `app/system/modules/view/index.php` — 1 call: `$app->url(...)`, `$app->module(...)`
- `app/installer/index.php` — 3 calls: `$app->module(...)` (lines 33, 36 in request callback)
- `app/system/modules/site/widgets/menu.php` — 1 call: `$app->view()`
- `app/system/modules/user/views/widget-login.php` — 1 call: `$app->module(...)`
- `app/system/modules/user/views/login.php` — 1 call: `$app->module(...)`
- `app/system/modules/user/mails/approve.php` — 1 call: `$app->module(...)`
- `app/system/modules/user/mails/verification.php` — 1 call: `$app->module(...)`
- `app/system/modules/user/mails/welcome.php` — 1 call: `$app->module(...)`
- `app/system/modules/user/mails/reset.php` — 1 call: `$app->module(...)`
- `app/system/src/SystemModule.php` — 1 call: `$app->module($theme)`
- `app/system/modules/site/src/SiteModule.php` — 1 call: `$app->config(...)`
- `packages/pagekit/blog/scripts.php` — 1 call (commented out, verify)

**Migration patterns:**
```php
// $app->module('system/site') → $app->get('module')->get('system/site')
// $app->config('system') → $app->get('config')('system')
// $app->config($name) → $app->get('config')($name)
// $app->request() → $app->get('request')
// $app->url(...) → $app->get('url')->...
// $app->view() → $app->get('view')
```

**Acceptance criteria:**
- Zero `$app->module(` calls (use `$app->get('module')->get(` instead)
- Zero `$app->config(` calls (use `$app->get('config')(` instead)
- Zero `$app->request()`, `$app->url(`, `$app->view()` magic calls
- PHPUnit passes

---

#### Step 2: Migrate instance EventTrait calls + Application::boot()

**What:** Replace `$app->on()`, `$app->subscribe()`, `$app->trigger()` instance calls with explicit `$app->get('events')->on()` etc. Update `Application::boot()` to use `$this->get('events')->trigger()`.

**Why:** These are EventTrait instance methods. Must be migrated before EventTrait deletion.

**Files to change:**
- `app/modules/application/src/Application.php` — line 40: `$this->trigger('boot', [$this])` → `$this->get('events')->trigger('boot', [$this])`
- `app/system/modules/user/index.php` — `$app->subscribe(...)`
- `app/system/modules/view/index.php` — `$app->subscribe(...)`, `$app->on('view.meta', ...)`
- `app/system/modules/site/index.php` — `$app->subscribe(...)`, `$app->on('view.head/footer/init/meta', ...)`
- `app/modules/kernel/index.php` — `$app->subscribe(...)`
- `app/modules/routing/index.php` — `$app->subscribe(...)`
- `app/modules/debug/index.php` — `$app->on('view.head', ...)`, `$app->on('terminate', ...)`
- `app/modules/session/index.php` — `$app->subscribe(...)`
- `app/modules/view/index.php` — `$app->on('view.meta', ...)`
- `app/system/index.php` — `$app->subscribe(...)`, `$app->trigger(...)`
- `app/system/modules/content/index.php` — `$app->subscribe(...)`
- `app/system/modules/captcha/index.php` — `$app->subscribe(...)`
- `app/system/modules/comment/index.php` — `$app->subscribe(...)`
- `app/installer/index.php` — `$app->on('request', ...)`
- `packages/pagekit/blog/index.php` — `$app->subscribe(...)`

**Migration patterns:**
```php
// $app->on('event', $cb, $priority) → $app->get('events')->on('event', $cb, $priority)
// $app->subscribe($listener) → $app->get('events')->subscribe($listener)
// $app->trigger('event', $args) → $app->get('events')->trigger('event', $args)
// $this->trigger('boot', [$this]) → $this->get('events')->trigger('boot', [$this])
```

**Important:** In `main` callbacks (before boot), `$app->on()` used EventTrait's deferred registration logic (`extend('events', ...)`). Since these are already in `events` callbacks (which fire during/after boot) or in `main` where the events service is already defined, direct `$app->get('events')->on()` works. Verify each call site is in a boot/events context.

**Acceptance criteria:**
- Zero `$app->on(`, `$app->subscribe(`, `$app->trigger(` instance calls on Application
- `$this->trigger()` replaced in Application::boot()
- PHPUnit passes

---

#### Step 3: Migrate `$app->error()` and `$app->redirect()` instance calls

**What:** Replace `$app->error()` (RouterTrait) with explicit event listener registration. Replace `$app->redirect()` with explicit router call.

**Files to change:**
- `app/modules/routing/index.php`:
  - Line 54: `$app->error(function (HttpException $e) use ($app) { ... })` → `$app->get('events')->on('exception', new ExceptionListenerWrapper(function (HttpException $e) use ($app) { ... }), -10)`
  - Line 70: `$app->redirect($redirect)` → `$app->get('router')->redirect($redirect)`
- `app/installer/index.php`:
  - Line 41: `$app->error(fn(NotFoundException $e) => ...)` → `$app->get('events')->on('exception', new ExceptionListenerWrapper(fn(NotFoundException $e) => ...), -8)`
- `app/modules/kernel/index.php`:
  - Line 49: `$app->redirect(...)` → `$app->get('router')->redirect(...)`

**Import needed:** `use Pagekit\Kernel\Event\ExceptionListenerWrapper;` where not already imported.

**Acceptance criteria:**
- Zero `$app->error(` and `$app->redirect(` instance calls
- PHPUnit passes

---

#### Step 4: Delete `Container::__call()` magic method

**What:** Remove the `__call()` method from `app/modules/application/src/Container.php` entirely.

**Prerequisites:** Steps 1-3 complete (all `$app->service()` magic calls, `$app->on/subscribe/trigger/error/redirect()` calls migrated).

**Files to change:**
- `app/modules/application/src/Container.php` — delete `__call()` method (lines 40-54) and its TODO comment (line 40)

**Verification before deletion:**
```bash
rg '\$app->(db|cache|config|module|request|router|events|kernel|url|view|mailer|auth|user|path|debug|log|filter|feed|content|response|error|redirect|on|subscribe|trigger)\(' app/ packages/ --type php
```
Must return zero results (except test files).

**Acceptance criteria:**
- `Container::__call()` deleted
- No `$app->service()` magic calls remain
- PHPUnit passes

---

#### Step 5: Create IntlServiceLocator + migrate intl global functions

**What:** Create a lightweight service locator for the `__()`, `_c()`, `_i()` global functions that replaces `App::translator()` and `App::intl()`.

**Files to create:**
- `app/system/modules/intl/src/IntlServiceLocator.php`

```php
<?php
namespace Pagekit\Intl;

use Symfony\Contracts\Translation\TranslatorInterface;

final class IntlServiceLocator
{
    private static ?TranslatorInterface $translator = null;
    private static mixed $intl = null;

    public static function setTranslator(TranslatorInterface $translator): void
    {
        self::$translator = $translator;
    }

    public static function getTranslator(): TranslatorInterface
    {
        if (self::$translator === null) {
            throw new \RuntimeException('Translator not initialized. Was IntlModule booted?');
        }
        return self::$translator;
    }

    public static function setIntl(mixed $intl): void
    {
        self::$intl = $intl;
    }

    public static function getIntl(): mixed
    {
        if (self::$intl === null) {
            throw new \RuntimeException('Intl service not initialized. Was IntlModule booted?');
        }
        return self::$intl;
    }
}
```

**Files to change:**
- `app/system/modules/intl/index.php` (or IntlModule boot) — add wiring:
  ```php
  IntlServiceLocator::setTranslator($app->get('translator'));
  IntlServiceLocator::setIntl($app->get('intl'));
  ```
- `app/system/modules/intl/functions.php` — replace `App::translator()` → `IntlServiceLocator::getTranslator()` (3 calls)
- `app/system/modules/intl/functions-pagekit-namespace.php` — replace `App::translator()` → `IntlServiceLocator::getTranslator()` (2 calls), `App::intl()` → `IntlServiceLocator::getIntl()` (1 call)
- Remove `use Pagekit\Application as App;` from both function files

**Acceptance criteria:**
- Zero `App::translator()` and `App::intl()` calls in functions files
- `__()`, `_c()`, `_i()`, `Pagekit\__()`, `Pagekit\_c()`, `Pagekit\_n()` still work
- PHPUnit passes

---

#### Step 6: Replace `App::abort()` in app/system/ controllers + listeners (~50 calls)

**What:** Replace `App::abort($code, $msg)` with `throw new HttpException($code, $msg)` or typed exceptions. In controllers that already have constructor DI, no new DI needed — just throw the exception directly. Update `use` statements.

**Exception mapping:**
| Code | Class |
|------|-------|
| 400  | `BadRequestHttpException($msg)` |
| 403  | `AccessDeniedHttpException($msg)` |
| 404  | `NotFoundHttpException($msg)` |
| 500  | `HttpException(500, $msg)` |
| Other | `HttpException($code, $msg)` |

**Use:** `Symfony\Component\HttpKernel\Exception\*` classes (already in vendor).

**Files to change (app/system/):**
- `app/system/src/Controller/ValidatesRequestTrait.php` — 3 calls (abort(400,...) in validateOrFail + validate)
- `app/system/src/Controller/AdminController.php` — 3 calls
- `app/system/src/Controller/MigrationController.php` — 2 calls
- `app/system/modules/widget/src/Controller/WidgetApiController.php` — 3 calls
- `app/system/modules/widget/src/Controller/WidgetController.php` — 1 call
- `app/system/modules/user/src/Event/AccessListener.php` — 2 calls
- `app/system/modules/user/src/Controller/UserController.php` — 1 call
- `app/system/modules/user/src/Controller/ResetPasswordController.php` — 6 calls
- `app/system/modules/user/src/Controller/UserApiController.php` — 9 calls
- `app/system/modules/user/src/Controller/RoleApiController.php` — 1 call
- `app/system/modules/user/src/Controller/ProfileController.php` — 3 calls
- `app/system/modules/user/src/Controller/RegistrationController.php` — 6 calls
- `app/system/modules/user/src/Controller/AuthController.php` — 1 call
- `app/system/modules/site/src/Event/MaintenanceListener.php` — 1 call
- `app/system/modules/site/src/Controller/NodeApiController.php` — 4 calls
- `app/system/modules/site/src/Controller/NodeController.php` — 4 calls
- `app/system/modules/site/src/Controller/MenuApiController.php` — 1 call
- `app/system/modules/site/src/Controller/PageController.php` — 1 call
- `app/system/modules/captcha/src/CaptchaListener.php` — 2 calls

**Pattern:**
```php
// BEFORE: App::abort(404, 'Not Found');
// AFTER:  throw new NotFoundHttpException('Not Found');
```

Remove `use Pagekit\Application as App;` from each file if no other App:: calls remain. Add `use Symfony\Component\HttpKernel\Exception\*;` as needed.

**Acceptance criteria:**
- Zero `App::abort(` calls in app/system/
- PHPUnit passes

---

#### Step 7: Replace `App::abort()` in installer/console/packages (~29 calls)

**What:** Same pattern as Step 6 but for installer, console, and blog package.

**Files to change (installer/console):**
- `app/installer/src/Controller/PackageController.php` — 8 calls (abort(400,...))
- `app/installer/src/Controller/UpdateController.php` — 2 calls (abort(400,...), abort(500,...))
- `app/console/src/Commands/SelfupdateCommand.php` — 2 calls → replace with `throw new \RuntimeException($msg)` (console context, no HTTP)

**Files to change (packages):**
- `packages/pagekit/blog/src/Controller/PostApiController.php` — 3 calls
- `packages/pagekit/blog/src/Controller/CommentApiController.php` — 9 calls
- `packages/pagekit/blog/src/Controller/BlogController.php` — 3 calls
- `packages/pagekit/blog/src/Controller/SiteController.php` — 2 calls
- `packages/pagekit/blog/src/UrlResolver.php` — 2 calls

**Special case for SelfupdateCommand:** Console has no HTTP context. Replace `App::abort(500, $msg)` with `throw new \RuntimeException($msg)`.

**Acceptance criteria:**
- Zero `App::abort(` calls in installer/, console/, packages/
- PHPUnit passes

---

#### Step 8: Replace `App::redirect()` static calls (~18 calls)

**What:** Replace `App::redirect($url, $params, $status)` with `$this->router->redirect($url, $params, $status)` in controllers. Inject `router` service via constructor DI.

**Files to change:**
- `app/system/modules/user/src/Controller/RegistrationController.php` — 4 calls (already has DI, add `router`)
- `app/system/modules/user/src/Controller/ResetPasswordController.php` — 4 calls
- `app/system/src/Controller/AdminController.php` — 2 calls
- `app/system/src/Controller/MigrationController.php` — 2 calls
- `app/system/modules/user/src/Controller/ProfileController.php` — 1 call
- `app/system/modules/user/src/Controller/AuthController.php` — 1 call
- `app/system/modules/site/src/Controller/NodeController.php` — 1 call
- `packages/pagekit/blog/src/Controller/BlogController.php` — 1 call

**Pattern:**
```php
// Constructor: private readonly mixed $router
// BEFORE: return App::redirect('@route', [], 302);
// AFTER:  return $this->router->redirect('@route', [], 302);
```

For `BlogController`, also handle `App::message()->error(...)` on line 107 (inject `message` service or use flash bag).

**Acceptance criteria:**
- Zero `App::redirect(` static calls
- PHPUnit passes

---

#### Step 9: Replace `App::trigger/on()` + remaining `App::*` in app/system/

**What:** Replace all remaining `App::*` static shortcut calls in app/system/ that aren't abort/redirect (already handled).

**Files to change:**
- `app/system/modules/cache/src/CacheModule.php`:
  - Line 120: `App::on('terminate', ...)` → use `$this->app->get('events')->on('terminate', ...)` (CacheModule already stores `$this->app`)
  - Line 130: `App::getInstance()` fallback → just use `$this->app` (require it in clearCache context)
- `app/system/modules/content/src/ContentHelper.php`:
  - Line 19: `App::trigger(new ContentEvent(...))` → inject `events` service via constructor, use `$this->events->trigger(...)`
  - ContentHelper is instantiated in `content/index.php` — pass events service
- `app/system/modules/content/src/Plugin/MarkdownPlugin.php`:
  - Line 23: `App::markdown()` → inject `markdown` service via constructor
  - MarkdownPlugin is subscribed in `content/index.php` — pass markdown service
- `app/system/modules/finder/src/Controller/FinderController.php`:
  - `App::trigger(...)` → inject `events` via constructor DI
- `app/system/modules/site/src/SiteModule.php`:
  - `App::trigger(...)` → use `$this->app->get('events')->trigger(...)` (SiteModule has `$app` in `main()`)
- `app/system/modules/user/src/UserModule.php`:
  - `App::trigger(...)` → use `$this->app->get('events')->trigger(...)` or inject events
- `app/installer/src/SelfUpdater.php`:
  - Line 47: `App::path()` → accept `$path` as constructor parameter (SelfUpdater is created in UpdateController, pass `$app->get('path')`)
- `app/installer/src/Controller/PackageController.php`:
  - `App::debug()` (2 calls) → inject `debug` config value: `$this->debug` (set from `$app->get('config')->get('app.debug')`)
  - `App::log(...)` (1 call) → inject `log` service via constructor
- `app/modules/application/src/Application/Console/Application.php`:
  - Line with `App::` reference — verify and fix

**Acceptance criteria:**
- Zero `App::trigger(`, `App::on(`, `App::markdown(`, `App::path(`, `App::debug(`, `App::log(` calls in app/
- PHPUnit passes

---

#### Step 10: Replace all `App::*` static calls in blog package + theme-one

**What:** Expand constructor DI in blog controllers (already have `module` from 2.0.1d) to cover ALL remaining App:: calls. Fix RouteListener and UrlResolver.

**Files to change:**

**Blog Controllers (expand constructor DI):**
- `packages/pagekit/blog/src/Controller/PostApiController.php` (~17 App:: calls):
  - Inject: `user`, `request`, `filter`, `db`, `module` (already has module)
  - Replace: `App::user()` → `$this->user`, `App::request()` → `$this->request`, `App::filter(...)` → `$this->filter->filter(...)`, `App::db()` → `$this->db`
- `packages/pagekit/blog/src/Controller/CommentApiController.php` (~15 App:: calls):
  - Inject: `user`, `request`, `content`, `db`
  - Replace all App:: patterns
- `packages/pagekit/blog/src/Controller/BlogController.php` (~9 remaining App:: calls):
  - Inject: `user`, `db`, `message`, `router` (already added in Step 8 if applicable)
  - Already has `module` from 2.0.1d
- `packages/pagekit/blog/src/Controller/SiteController.php` (~22 App:: calls):
  - Inject: `user`, `content`, `feed`, `url`, `response`, `module` (already has module), `db`
  - This is the heaviest file

**Blog RouteListener:**
- `packages/pagekit/blog/src/Event/RouteListener.php` (3 calls):
  - `App::router()` → inject `router` via constructor
  - `App::routes()` → inject `routes` via constructor
  - `App::cache()` → inject `cache` via constructor
  - RouteListener is instantiated in blog/index.php — pass services

**Blog UrlResolver:**
- `packages/pagekit/blog/src/UrlResolver.php` (5 calls):
  - Inject `cache` and `module` via constructor
  - App::abort() already handled in Step 7
  - `App::cache()->fetch/save()` → `$this->cache->fetch/save()`
  - `App::module('blog')` → `$this->module`
  - UrlResolver is likely instantiated via routing config — check how and pass services

**Theme-one:**
- `packages/pagekit/theme-one/functions.php`:
  - Line 85: `App::view()->url($url)` → inject `view` or `url` service (check how functions.php is loaded)

**Acceptance criteria:**
- Zero `App::` calls in packages/ (except `use` statements and class references)
- PHPUnit passes

---

#### Step 11: Resolve `App::getInstance()` bridges in system area (~20 calls)

**What:** Replace all `App::getInstance()->get('service')` patterns with proper constructor DI. These are TEMPORARY BRIDGE markers from 2.0.1c/d.

**Files to change:**

**Controllers (already have constructor DI — expand params):**
- `app/system/modules/settings/src/Controller/SettingsController.php` — 1 call → add `config` to constructor
- `app/system/modules/dashboard/src/Controller/DashboardController.php` — 1 call → add `module` to constructor
- `app/system/modules/user/src/Controller/ProfileController.php` — 1 call → add `user` to constructor
- `app/system/modules/user/src/Controller/RegistrationController.php` — 1 call → add `user` to constructor
- `app/system/modules/user/src/Controller/ResetPasswordController.php` — 1 call → add `mailer` to constructor
- `app/system/modules/user/src/Controller/UserApiController.php` — 1 call → add `user` to constructor

**Helpers/Services:**
- `app/system/modules/site/src/MenuHelper.php` — 2 calls (`user`, `node`):
  - Add `user` and `node` to constructor (MenuHelper is instantiated in site/index.php)
- `app/system/modules/view/src/Asset/FileLocatorAsset.php` — 3 calls (`file`, `locator`):
  - FileLocatorAsset is dynamically instantiated by AssetFactory via `new $class()`. **Strategy:** Add a static service locator for `file` and `locator` services, set during view module boot. Or modify AssetFactory to support DI. Simplest: add static setters on FileLocatorAsset initialized during boot.
- `app/system/modules/dashboard/src/DashboardModule.php` — 2 calls (`module`, `user`):
  - DashboardModule extends Module, has `main($app)`. Store and use `$this->app`.
- `app/system/modules/cache/src/CacheModule.php` — 1 call (already handled in Step 9)

**Validators:**
- `app/system/src/Validator/Constraints/UniqueValidator.php` — 1 call (`db`):
  - Inject `db` via constructor (UniqueValidator is registered with Symfony Validator, check how to pass DI — may need ConstraintValidatorFactory)
- `app/system/src/Controller/ValidatesRequestTrait.php` — 2 calls (`validator`):
  - Convert to pass validator explicitly: require controllers using this trait to inject `validator` and pass it. OR: add abstract method `getValidator(): ValidatorInterface` that using classes must implement.

**Acceptance criteria:**
- Zero `App::getInstance()` calls in app/system/
- All TEMPORARY BRIDGE comments removed from system area
- PHPUnit passes

---

#### Step 12: Resolve `App::getInstance()` bridges in installer area (~18 calls)

**What:** Replace all `App::getInstance()` patterns in installer package with constructor DI.

**Files to change:**

**PackageManager (heaviest — 9 calls):**
- `app/installer/src/Package/PackageManager.php`:
  - Refactor constructor to accept `$app` (ContainerInterface) as parameter instead of fetching via `App::getInstance()`
  - All methods then use `$this->app->get(...)` instead of `App::getInstance()->get(...)`
  - Update callers: `PackageController`, `UpdateController`, etc. to pass `$app` when creating `new PackageManager($app)`

**PackageFactory:**
- `app/installer/src/Package/PackageFactory.php` — 1 call:
  - Inject `url` service via constructor or setter. PackageFactory is created in installer/index.php — pass `$app->get('url')`.

**PackageScripts:**
- `app/installer/src/Package/PackageScripts.php` — 1 call (line 103: `App::getInstance()`):
  - Accept `$app` parameter in `run()` method or constructor. Pass it from PackageManager which now has `$app`.

**Controllers:**
- `app/installer/src/Controller/InstallerController.php` — 1 call:
  - Inject `app` (the container) via constructor DI, pass to `new Installer($this->app)`
- `app/installer/src/Controller/MarketplaceController.php` — 2 calls (`system.api`):
  - Inject `system.api` value via constructor DI
- `app/installer/src/Controller/UpdateController.php` — 2 calls (`system.api`, `path.temp`):
  - Inject these via constructor DI
- `app/installer/src/Controller/PackageController.php` — 2 calls (`system.api`):
  - Inject via constructor DI

**Install scripts:**
- `app/installer/install.php` — 1 call:
  - This file is `require`d in a context where `$app` should already be available. Check the caller (PackageScripts or Installer) and pass `$app` via scope.
- `app/installer/install-demo.php` — 1 call:
  - Same as above.

**Acceptance criteria:**
- Zero `App::getInstance()` calls in app/installer/
- All TEMPORARY BRIDGE comments removed from installer area
- PHPUnit passes

---

#### Step 13: Models — eliminate static service access (Post, Node)

**What:** Remove `App::*` and `App::getInstance()` calls from model classes. Models must be pure data objects.

**Files to change:**

**Post model (`packages/pagekit/blog/src/Model/Post.php`):**
- `isCommentable()` (line 124): `App::module('blog')` → accept blog module config as parameter. Callers must pass it. OR move `isCommentable()` to a `PostService`/`PostRepository`.
- `isAccessible()` (line 142): `App::user()` → already accepts `?User $user = null`. Remove the fallback to `App::user()`. Require callers to always pass the user. Change signature to require `User $user`.
- `jsonSerialize()` (line 151): `App::url(...)` → compute URL externally. Strategy: add a `$url` public property set by the controller/repository before serialization. In jsonSerialize, use `$this->url ?? ''`.
- Remove `use Pagekit\Application as App;`

**Node model (`app/system/modules/site/src/Model/Node.php`):**
- `getUrl()` (line 93): `App::getInstance()->get('url')` → accept url service as parameter: `getUrl(mixed $referenceType = false, ?object $urlService = null)`. Or use static locator. Simplest: set a static `$urlService` on the Node class, initialized during site module boot.
- `isAccessible()` (line 99): `App::getInstance()->get('user')` → require callers to pass user. Already has `?User $user = null` param, just remove the fallback.
- Remove `use Pagekit\Application as App;`

**For Node::getUrl() and Post::jsonSerialize() — recommended pattern:**
Create a static `ModelServiceLocator` (similar to IntlServiceLocator):
```php
final class ModelServiceLocator {
    private static mixed $url = null;
    private static mixed $user = null;
    public static function setUrl(mixed $url): void { self::$url = $url; }
    public static function getUrl(): mixed { ... }
    public static function setUser(mixed $user): void { self::$user = $user; }
    public static function getUser(): mixed { ... }
}
```
Wire in site module + blog module boot. Use in Node::getUrl() and Post::jsonSerialize(). Mark with: `// TODO: Must be refactored in Step 2.1 (Static Analysis) — replace ModelServiceLocator with proper DTO/presenter pattern`

Alternatively, if the refactorer can trace ALL callers of `jsonSerialize()` and `getUrl()`, convert them to use a presenter/DTO instead. This is the cleaner approach but more invasive.

**Acceptance criteria:**
- Zero `App::` or `App::getInstance()` calls in Post.php and Node.php
- Post serialization still produces correct `url` field
- Node::getUrl() still works
- PHPUnit passes

---

#### Step 14: Fix SymfonyEventDispatcherBridge::dispatch() + EntityManager singleton

**What:**

**A) Fix bridge dispatch:**
- `app/modules/application/src/Event/SymfonyEventDispatcherBridge.php` line 27-32:
  - Current: `return $event;` (no-op)
  - Fix: Forward to Pagekit's trigger:
    ```php
    public function dispatch(object $event, ?string $eventName = null): object
    {
        $name = $eventName ?? get_class($event);
        $this->dispatcher->trigger($name, [$event]);
        return $event;
    }
    ```
  - If no Symfony component consumes this bridge, consider deletion (DELETE OVER WRAP). Check for references to `symfony.event_dispatcher` service.

**B) EntityManager singleton — DEFER:**
- `app/modules/database/src/ORM/EntityManager.php` has `static::$instance = $this` (line 37) and `protected static ?self $instance = null` (line 22).
- This is used internally by the ORM's static model methods (`ModelTrait::query()`, etc.) and is deeply entangled with the ORM layer.
- **Decision: DEFER.** Add TODO marker: `// TODO: Must be refactored in Step 2.1 (Static Analysis) — remove EntityManager singleton pattern`
- Do NOT remove it now — it would break all model queries.

**Acceptance criteria:**
- Bridge dispatch() forwards events (or bridge is deleted if unused)
- EntityManager has TODO marker for future refactoring
- PHPUnit passes

---

#### Step 15: Delete traits + clean Application class

**What:** Delete all three trait files, remove trait usage from Application, remove `static::$instance` from Container.

**Prerequisites:** ALL `App::*` static calls eliminated (Steps 5-12), ALL `App::getInstance()` calls eliminated, ALL `$app->on/subscribe/trigger/error/redirect()` calls eliminated.

**Verification before deletion:**
```bash
# Must all return zero results:
rg 'App::' app/ packages/ --type php | grep -v '^.*:.*use ' | grep -v '\\\\App' | grep -v 'class App' | grep -v '// ' | grep -v 'namespace'
rg 'App::getInstance\(\)' app/ packages/ --type php
rg 'TEMPORARY BRIDGE' app/ packages/ --type php
```

**Files to delete:**
- `app/modules/application/src/Application/Traits/StaticTrait.php` — DELETE
- `app/modules/application/src/Application/Traits/EventTrait.php` — DELETE
- `app/modules/application/src/Application/Traits/RouterTrait.php` — DELETE
- `app/modules/application/src/Application/Traits/` directory — DELETE (if empty)

**Files to change:**
- `app/modules/application/src/Application.php`:
  - Remove: `use Pagekit\Application\Traits\EventTrait;`
  - Remove: `use Pagekit\Application\Traits\RouterTrait;`
  - Remove: `use Pagekit\Application\Traits\StaticTrait;`
  - Remove: `use StaticTrait, EventTrait, RouterTrait;` from class body
- `app/modules/application/src/Container.php`:
  - Remove lines 28-30 from constructor:
    ```php
    if (in_array('Pagekit\Application\Traits\StaticTrait', class_uses($this))) {
        static::$instance = $this;
    }
    ```

**Acceptance criteria:**
- Traits directory deleted
- No trait imports in Application.php
- No `static::$instance` in Container.php
- Application class is clean: extends Container, has boot(), run(), inConsole()
- PHPUnit passes

---

#### Step 16: Final verification + test updates

**What:** Run comprehensive verification. Update/remove tests that tested magic methods. Add tests for new patterns.

**Verification commands:**
```bash
# Zero App:: static calls (except use/namespace/class declarations):
rg 'App::' app/ packages/ --type php -n | grep -v 'use ' | grep -v '\\App' | grep -v 'namespace' | grep -v 'class '

# Zero __call magic:
rg '__call\(' app/modules/application/src/Container.php

# Zero __callStatic magic:
rg '__callStatic\(' app/modules/application/src/ --type php

# Zero trait files:
ls app/modules/application/src/Application/Traits/ 2>/dev/null

# Zero $app->service() dynamic calls:
rg '\$app->(db|cache|config|module|request|router|events|kernel|url|view|mailer|auth|user|path|debug|log|filter|feed|content|response|on|subscribe|trigger|error|redirect)\(' app/ packages/ --type php

# Zero App::getInstance():
rg 'App::getInstance\(\)' app/ packages/ --type php

# Zero TEMPORARY BRIDGE markers:
rg 'TEMPORARY BRIDGE' app/ packages/ --type php
```

**Test updates:**
- Remove/update any tests that directly test `__call()`, `__callStatic()`, `StaticTrait`, `EventTrait`, `RouterTrait`
- Add test for IntlServiceLocator
- Verify all 261 PHPUnit tests pass

**Smoke tests:**
```bash
php pagekit setup
php pagekit list
```

**E2E tests (once at end):**
```bash
npx playwright test tests/e2e/specs/01-setup/installation.spec.js
npx playwright test tests/e2e/specs/02-core/authentication.spec.js
npx playwright test tests/e2e/specs/02-core/dashboard.spec.js
```

**Acceptance criteria:**
- ALL verification commands return zero matches
- All PHPUnit tests pass (261 tests)
- `php pagekit setup` works
- `php pagekit list` works
- E2E tests pass (if dev server is available)
- Zero `TEMPORARY BRIDGE` TODO markers remain
- Container is pure PSR-11: get(), has(), set(), factory(), extend(), raw(), keys()
