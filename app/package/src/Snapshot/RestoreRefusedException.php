<?php

declare(strict_types=1);

namespace Pagekit\Package\Snapshot;

/**
 * A restore an operator can refuse or fix, with the reason in the message.
 */
final class RestoreRefusedException extends \RuntimeException
{
}
