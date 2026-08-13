<?php

declare(strict_types=1);

/**
 * A module that fails below another package's declaration, where the system's
 * own modules are found.
 */

throw new \RuntimeException('The nested module file could not be executed');
