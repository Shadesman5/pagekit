<?php

declare(strict_types=1);

namespace Pagekit\Container;

use Psr\Container\NotFoundExceptionInterface;

/**
 * PSR-11 Exception for when a requested entry is not found in the container.
 */
class NotFoundException extends \InvalidArgumentException implements NotFoundExceptionInterface
{
}
