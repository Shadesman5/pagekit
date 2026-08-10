<?php

declare(strict_types=1);

namespace Pagekit\Installer\Package\Lifecycle;

/**
 * Where a package keeps its schema migrations.
 *
 * A namespace and a directory are all the migration service needs to run a
 * package's migrations, and naming them is all a package has to do: the two
 * belong together, and a package that declares them is no longer the one that
 * has to execute them.
 */
final readonly class MigrationSet
{
    /**
     * @param string $namespace the namespace the migration classes are declared in
     * @param string $path      the directory holding the migration classes
     */
    public function __construct(
        public string $namespace,
        public string $path,
    ) {
    }
}
