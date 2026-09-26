<?php

declare(strict_types=1);

namespace Pagekit\Package;

/**
 * An enabled module still requires a package that was about to be switched off.
 */
final class RemovalBlockedException extends \RuntimeException
{
}
