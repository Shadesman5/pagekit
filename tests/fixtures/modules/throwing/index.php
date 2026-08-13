<?php

declare(strict_types=1);

/**
 * A package that fails while it is being executed, the way one whose top level
 * reads a configuration file that is not there does.
 *
 * It never gets as far as declaring a name, so the site configuration is the
 * only place the name of this extension still exists - the file discovery would
 * have had to read it from is the file that failed.
 */

throw new \RuntimeException('The module file could not be executed');
