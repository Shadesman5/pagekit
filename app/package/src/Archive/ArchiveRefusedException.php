<?php

declare(strict_types=1);

namespace Pagekit\Package\Archive;

/**
 * An archive the installation will not take, with the reason written for the administrator.
 */
final class ArchiveRefusedException extends \RuntimeException
{
}
