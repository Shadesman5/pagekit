<?php

declare(strict_types=1);

namespace Pagekit\Filesystem;

/**
 * Leaves a private runtime directory present and owner-only.
 */
final class RuntimeDirectories
{
    /**
     * Owner-only. mkdir on a directory that already exists does not change its mode.
     */
    public const int MODE = 0700;

    /**
     * Creates the directory when it is missing, then sets {@see self::MODE}.
     *
     * @throws \RuntimeException When the directory is still missing, or its mode is not owner-only
     */
    public static function ensure(string $directory): void
    {
        if (!is_dir($directory)) {
            error_clear_last();

            // Recursive only so this one directory's parents exist. A mkdir that
            // loses a race to another creator is not a failure; the mode is set next.
            if (!@mkdir($directory, self::MODE, true) && !is_dir($directory)) {
                throw self::failure($directory);
            }
        }

        error_clear_last();

        if (!@chmod($directory, self::MODE)) {
            throw self::failure($directory);
        }

        $mode = @fileperms($directory);
        $perms = is_int($mode) ? $mode & 0777 : null;

        // chmod can report success on a filesystem that does not keep the mode.
        if ($perms !== self::MODE) {
            throw self::failure($directory);
        }
    }

    /**
     * The directory, plus the warning the failed call left, when it left one.
     */
    private static function failure(string $directory): \RuntimeException
    {
        $error = error_get_last();

        if (is_array($error) && $error['message'] !== '') {
            return new \RuntimeException($directory.': '.$error['message']);
        }

        return new \RuntimeException($directory);
    }
}
