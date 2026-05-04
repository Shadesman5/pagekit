<?php

declare(strict_types=1);

namespace Pagekit\Filesystem\Archive;

interface ArchiveInterface
{
    /**
     * Extracts an archive to a destination path.
     *
     * @throws \UnexpectedValueException
     * @throws \RuntimeException
     */
    public static function extract(string $archive, string $path): bool|int;
}
