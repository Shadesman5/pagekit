<?php

declare(strict_types=1);

namespace Pagekit\Installer;

/**
 * The shape a table prefix has to have for an installation to be created with it.
 *
 * One expression, read wherever a prefix is chosen - the web installer and the
 * setup command both arrive here through Installer::check() - because a prefix
 * that gets past one entry point and not the other leaves a database the rest
 * of the system cannot reason about. Each part of the shape earns its place:
 *
 *  - It starts with a letter, so the whole underscore-led namespace stays
 *    reserved and no installation can own a name in it. That is the namespace
 *    the snapshot machinery takes the table names it has to invent from, and
 *    the reservation is what keeps those from ever naming a real table.
 *  - It ends with an underscore, because the prefix is a delimiter. Without
 *    one, "pk" claims "pkusers" as readily as "pk_users", and one site's tables
 *    can no longer be told from a neighbour's in a shared database.
 *  - It holds letters, digits and underscores only. A dot or a hyphen is not an
 *    identifier character in unquoted MySQL, and a table name reaches SQL that
 *    the schema tools did not write.
 */
final class TablePrefix
{
    private const SHAPE = '/^[A-Za-z][A-Za-z0-9_]*_$/';

    /**
     * The name without its trailing delimiter, which is the one mistake worth
     * answering with the correction rather than the rule.
     */
    private const MISSING_DELIMITER = '/^[A-Za-z][A-Za-z0-9_]*$/';

    /**
     * Why an installation cannot be created with this prefix, or null when it
     * can.
     *
     * Whoever is installing reads the answer, in the browser or on the command
     * line, so it names what to type instead of the rule that was broken.
     */
    public static function refusal(string $prefix): ?string
    {
        if (preg_match(self::SHAPE, $prefix) === 1) {
            return null;
        }

        if ($prefix === '') {
            return __('A table prefix is required. Use "pk_", or another name that starts with a letter, holds letters, digits and underscores, and ends with an underscore.');
        }

        if (preg_match(self::MISSING_DELIMITER, $prefix) === 1) {
            return __(
                'The table prefix "%prefix%" has to end in an underscore, which is what separates it from the table names behind it. Use "%corrected%".',
                ['%prefix%' => $prefix, '%corrected%' => $prefix.'_'],
            );
        }

        return __(
            'The table prefix "%prefix%" cannot be used. A prefix starts with a letter, holds letters, digits and underscores, and ends with an underscore - "pk_" for example.',
            ['%prefix%' => $prefix],
        );
    }
}
