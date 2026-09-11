<?php

declare(strict_types=1);

namespace Pagekit\Installer\Package\Snapshot;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Pagekit\Database\Connection;

/**
 * What a database dump is made of, for the two halves that have to agree on it.
 *
 * A dump is one JSON object per line, in the order it was written:
 *
 *     {"type":"header","format":1,"created":…,"driver":…,"platform":…,"prefix":…}
 *     {"type":"table","name":"pk_system_user","ddl":[…],"columns":["id","name",…]}
 *     {"type":"row","values":[1,"admin",…]}
 *     {"type":"end","tables":8,"rows":231}
 *
 * A line at a time rather than one document, because a dump is written while a
 * package is being removed and read back weeks later: the writer never holds
 * the database in memory to encode it, and the reader never holds more than the
 * row it is on. Rows are positional against the columns of the table record
 * they follow, so a table's column names are paid for once rather than per row.
 *
 * The last line is what makes a dump a dump. A file that stops before it is one
 * a restore refuses, which is the only way it can tell an interrupted write from
 * a database that was genuinely that small.
 *
 * The header says which database the dump came out of, and a restore is refused
 * unless it is going back into the same kind: the schema in it is the DDL that
 * one platform renders, and the table names carry the prefix that installation
 * writes under. This is a recovery artefact - it puts an installation back the
 * way it was - and not a way to move a site between database engines.
 *
 * @phpstan-type Description array{driver: string, platform: string, prefix: string}
 */
final class DumpFormat
{
    /**
     * The layout above. A dump records it, and a reader that does not know the
     * number refuses the file rather than guessing at what the lines mean.
     */
    public const VERSION = 1;

    public const SQLITE = 'sqlite';

    public const MYSQL = 'mysql';

    public const HEADER = 'header';

    public const TABLE = 'table';

    public const ROW = 'row';

    public const END = 'end';

    /**
     * The key a column value is carried under when it is bytes rather than
     * text. An object where a scalar belongs, so it cannot be mistaken for a
     * value the column actually held.
     */
    private const BINARY = 'b64';

    /**
     * Which database an installation is on, as a dump records it and a restore
     * checks it.
     *
     * @return Description
     * @throws \RuntimeException where the platform is one this format has no
     *                          schema or restore path for, which makes the
     *                          installation one that cannot be snapshotted
     */
    public static function describe(Connection $connection): array
    {
        return [
            'driver' => $connection->getParams()['driver'] ?? '',
            'platform' => self::platform($connection->getDatabasePlatform()),
            'prefix' => $connection->getPrefix() ?? '',
        ];
    }

    /**
     * The family a platform belongs to - what a dump has to go back into.
     *
     * The family rather than the exact platform, because that is the level the
     * dump is portable at: the DDL and the foreign-key handling are the same
     * across a family's versions, and across MySQL and MariaDB.
     *
     * @throws \RuntimeException where the platform is not one of them
     */
    public static function platform(AbstractPlatform $platform): string
    {
        if ($platform instanceof SqlitePlatform) {
            return self::SQLITE;
        }

        if ($platform instanceof AbstractMySQLPlatform) {
            return self::MYSQL;
        }

        throw new \RuntimeException(sprintf(
            'The database platform "%s" is not one a snapshot can be taken of or restored into.',
            get_class($platform),
        ));
    }

    /**
     * One record as it goes onto disk, newline included.
     *
     * Nothing here substitutes a character it cannot encode the way a log line
     * would: what is being written is the only copy of the database, and a
     * value quietly replaced by a question mark is a restore that puts back
     * something other than what was there.
     *
     * @param  array<string, mixed> $record
     * @throws \RuntimeException    where the record holds something JSON cannot carry
     */
    public static function line(array $record): string
    {
        $json = json_encode($record, JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            throw new \RuntimeException(sprintf('A dump record could not be encoded (%s).', json_last_error_msg()));
        }

        return $json."\n";
    }

    /**
     * A column value on its way into the dump.
     *
     * JSON carries text, and a database column does not have to hold any: a
     * hash, a thumbnail, anything a driver hands back as a binary string would
     * come out the other side as replacement characters. Those are tagged and
     * carried as base64 instead, so what goes back into the column on a restore
     * is the bytes that came out of it.
     *
     * @throws \RuntimeException where the driver returned something no column
     *                          can hold, which is a dump that would restore
     *                          wrongly rather than one that is merely awkward
     */
    public static function encode(mixed $value): mixed
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);

            if ($value === false) {
                throw new \RuntimeException('A column value could not be read out of the database.');
            }
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return preg_match('//u', $value) === 1 ? $value : [self::BINARY => base64_encode($value)];
        }

        throw new \RuntimeException(sprintf('A column value of type "%s" cannot be dumped.', get_debug_type($value)));
    }

    /**
     * A column value on its way back into the database, with the type it has to
     * be bound as.
     *
     * A float goes back as the shortest text that reads as the same float:
     * casting one to a string binds it at the precision the ini happens to be
     * set to, which is a value that comes back a little different from the one
     * that was dumped.
     *
     * @return array{0: mixed, 1: int}
     * @throws \RuntimeException      where the dump holds a value this format does not define
     */
    public static function decode(mixed $value): array
    {
        if ($value === null) {
            return [null, ParameterType::NULL];
        }

        if (is_bool($value)) {
            return [$value, ParameterType::BOOLEAN];
        }

        if (is_int($value)) {
            return [$value, ParameterType::INTEGER];
        }

        if (is_float($value)) {
            return [var_export($value, true), ParameterType::STRING];
        }

        if (is_string($value)) {
            return [$value, ParameterType::STRING];
        }

        $encoded = is_array($value) && count($value) === 1 ? $value[self::BINARY] ?? null : null;

        if (is_string($encoded)) {
            $binary = base64_decode($encoded, true);

            if ($binary === false) {
                throw new \RuntimeException('A binary column value in the dump cannot be read.');
            }

            return [$binary, ParameterType::BINARY];
        }

        throw new \RuntimeException('A column value in the dump has a shape this format does not define.');
    }
}
