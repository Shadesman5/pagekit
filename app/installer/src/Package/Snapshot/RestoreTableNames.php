<?php

declare(strict_types=1);

namespace Pagekit\Installer\Package\Snapshot;

/**
 * The names a restore invents for itself, and what keeps them from ever being a
 * table an installation owns.
 *
 * A restore on MySQL cannot fill a table in place and still have the
 * installation whole if it stops halfway, so it fills a copy of that table under
 * a name of its own and swaps the copy in at the end, keeping the table that was
 * live under a second name until the swap has gone through. Both names are the
 * live one with a marker in front of it, so the copies of a table read as
 * belonging to it in a database an operator is looking at while a restore is
 * stuck.
 *
 * The markers lead with an underscore, and that is what makes them safe to
 * invent: a prefix an installation can be created with starts with a letter
 * ({@see \Pagekit\Installer\TablePrefix}), so no installation owns a table one of
 * these names can be confused with. They are the same length as each other, so
 * both names a table gets are equally far from the 64 characters MySQL allows an
 * identifier, and one measurement answers for both.
 */
final class RestoreTableNames
{
    /**
     * In front of the live name: the copy a restore fills with what the dump
     * holds, before anything live is touched.
     */
    public const SHADOW = '_r_';

    /**
     * In front of the live name: what the table that was live is called between
     * the swap and the point where the restore is known to have worked.
     */
    public const BACKUP = '_b_';

    /**
     * What the copy of a table is called while a restore is filling it.
     */
    public static function shadow(string $table): string
    {
        return self::SHADOW.$table;
    }

    /**
     * What the table that was live is called once the copy has taken its place.
     */
    public static function backup(string $table): string
    {
        return self::BACKUP.$table;
    }

    /**
     * Whether a name is one of these rather than a table of the installation's
     * own.
     *
     * Matched byte for byte, which is the reading these names need: they are
     * written in lower case, and a MySQL server that stores table names folded
     * hands them back folded, so a name this machinery wrote still carries its
     * marker. A marker in some other case was written by something else, and on
     * a server that does not fold it is a different table altogether.
     */
    public static function isReserved(string $table): bool
    {
        return str_starts_with($table, self::SHADOW) || str_starts_with($table, self::BACKUP);
    }
}
