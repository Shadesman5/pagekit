<?php

declare(strict_types=1);

namespace Pagekit\Container;

use Psr\Container\ContainerExceptionInterface;

/**
 * PSR-11 Exception for general container errors.
 */
class ContainerException extends \RuntimeException implements ContainerExceptionInterface
{
}
