<?php

declare(strict_types=1);

/**
 * Bootstrap for the Comment module unit tests.
 *
 * The base comment module (Pagekit\Comment\) is NOT registered in composer's
 * autoload map, so PHPUnit cannot autoload the abstract Comment entity, its
 * lifecycle trait, or the concrete test fixture. Require them here in dependency
 * order (trait + abstract parent before the concrete subclass), mirroring
 * tests/Unit/Blog/bootstrap.php.
 */

require_once __DIR__ . '/../Model/CommentModelTrait.php';
require_once __DIR__ . '/../Model/Comment.php';
require_once __DIR__ . '/Fixtures/CommentEntity.php';
