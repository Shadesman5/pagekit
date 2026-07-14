<?php

declare(strict_types=1);

/**
 * Bootstrap for the blog package unit tests (PostModelTrait, PostRepository,
 * PostPresenter).
 *
 * The blog package namespace (Pagekit\Blog\) is a runtime-loaded extension and
 * is NOT registered in composer's autoload map, and neither is the base comment
 * module (Pagekit\Comment\). PHPUnit therefore cannot autoload the Post/Comment
 * entities, the PostRepository or the PostPresenter under test, so we require
 * them here in dependency order (traits + parents before the classes that
 * use/extend them).
 *
 * Mirrors tests/Unit/Package/bootstrap.php and tests/Unit/Console/bootstrap.php,
 * which pull in their own non-autoloaded fixtures the same way.
 */

$root = dirname(__DIR__, 3);

require_once $root . '/app/system/modules/comment/src/Model/CommentModelTrait.php';
require_once $root . '/app/system/modules/comment/src/Model/Comment.php';
require_once $root . '/packages/pagekit/blog/src/Model/Comment.php';
require_once $root . '/packages/pagekit/blog/src/Model/PostModelTrait.php';
require_once $root . '/packages/pagekit/blog/src/Model/Post.php';
require_once $root . '/packages/pagekit/blog/src/Model/PostRepository.php';
require_once $root . '/packages/pagekit/blog/src/PostPresenter.php';
