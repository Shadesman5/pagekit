<?php

declare(strict_types=1);

/**
 * Bootstrap for the blog package unit tests (PostModelTrait, PostRepository,
 * PostPresenter, UrlResolver, the event listeners, the content plugin the
 * package boot subscribes and the blog controllers).
 *
 * Two things are missing under PHPUnit and supplied here:
 *
 *  1. Translation helpers. composer.json declares no `autoload.files`, so neither
 *     the global `\__()` (intl functions.php — used by the entities' getStatuses()
 *     and by BlogController, which call the unqualified `__()`) nor `Pagekit\__()`
 *     (functions-pagekit-namespace.php — imported by the Post/Comment/Site API
 *     controllers via `use function Pagekit\__;`) is loaded. Both are stubbed with
 *     a param-substituting no-op, guarded by function_exists so a real helper
 *     loaded first stays authoritative (matches the site/user/widget bootstraps).
 *
 *  2. Runtime-loaded classes. The blog package namespace (Pagekit\Blog\), the base
 *     comment module (Pagekit\Comment\) and the content module (Pagekit\Content\)
 *     are NOT registered in composer's autoload map, so PHPUnit cannot autoload the
 *     entities, the PostRepository/PostPresenter, the UrlResolver, the event
 *     listeners, the content plugin, the controllers under test or the
 *     ContentHelper two of them constructor-inject. A test that runs the package's
 *     own boot definition needs every class that boot instantiates, which is why
 *     they are required here rather than per test.
 *     They are required here in dependency order (traits + parents before the classes
 *     that use/extend them).
 *
 * Mirrors tests/Unit/Package/bootstrap.php and app/system/modules/<module>/src/Tests/
 * bootstrap.php, which pull in their own non-autoloaded fixtures the same way.
 */

namespace Pagekit {
    if (!function_exists('Pagekit\__')) {
        /**
         * Translation stub for unit tests: returns the message with parameter
         * substitution (no actual translation).
         *
         * @param array<string, string|int|float> $args
         */
        function __(string $message, array $args = []): string
        {
            return strtr($message, $args);
        }
    }
}

namespace {
    if (!function_exists('__')) {
        /**
         * Global translation stub for unit tests: the entities' getStatuses() and
         * BlogController call the unqualified `__()`, which falls back to the global
         * namespace.
         *
         * @param array<string, string|int|float> $args
         */
        function __(string $message, array $args = []): string
        {
            return strtr($message, $args);
        }
    }

    $root = dirname(__DIR__, 3);

    require_once $root . '/app/system/modules/comment/src/Model/CommentModelTrait.php';
    require_once $root . '/app/system/modules/comment/src/Model/Comment.php';
    require_once $root . '/app/system/modules/content/src/ContentHelper.php';
    require_once $root . '/packages/pagekit/blog/src/Model/Comment.php';
    require_once $root . '/packages/pagekit/blog/src/Model/PostModelTrait.php';
    require_once $root . '/packages/pagekit/blog/src/Model/Post.php';
    require_once $root . '/packages/pagekit/blog/src/Model/PostRepository.php';
    require_once $root . '/packages/pagekit/blog/src/PostPresenter.php';
    require_once $root . '/packages/pagekit/blog/src/UrlResolver.php';
    require_once $root . '/packages/pagekit/blog/src/Event/PostListener.php';
    require_once $root . '/packages/pagekit/blog/src/Event/RouteListener.php';
    require_once $root . '/packages/pagekit/blog/src/Content/ReadmorePlugin.php';
    require_once $root . '/packages/pagekit/blog/src/Controller/BlogController.php';
    require_once $root . '/packages/pagekit/blog/src/Controller/PostApiController.php';
    require_once $root . '/packages/pagekit/blog/src/Controller/CommentApiController.php';
    require_once $root . '/packages/pagekit/blog/src/Controller/SiteController.php';
}
