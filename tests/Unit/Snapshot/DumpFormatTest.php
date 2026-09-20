<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\DB2Platform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Pagekit\Database\Connection;
use Pagekit\Installer\Package\Snapshot\DumpFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The agreement between the half of a snapshot that writes a database out and
 * the half that reads it back, weeks later and after an upgrade.
 *
 * Two things about it are not conveniences. A dump is the only copy of the
 * database of a package that has been removed, so a value it cannot carry has to
 * be reported rather than substituted: text where a column held bytes restores a
 * thumbnail as three replacement characters and a password hash as one that
 * matches nothing. And a dump is only ever replayed into the kind of database it
 * came out of, because what is in it is the schema that engine renders - so the
 * engine is something the format states rather than something a restore infers.
 */
final class DumpFormatTest extends TestCase
{
    /**
     * The key bytes travel under. Named here because a reader has to be able to
     * tell a tagged value from a value a column actually held, and the test for
     * that is the only place the name belongs outside the format itself.
     */
    private const BINARY = 'b64';

    // ------------------------------------------------------------------
    // Which database a dump belongs to
    // ------------------------------------------------------------------

    #[DataProvider('provideSupportedPlatforms')]
    public function testTheDatabasesADumpCanBeTakenOfAreNamedByTheirFamily(AbstractPlatform $platform, string $family): void
    {
        // The family rather than the version, because that is the level the DDL
        // and the foreign-key handling agree at: a dump taken on one MySQL goes
        // back into the next, and into MariaDB.
        self::assertSame($family, DumpFormat::platform($platform));
    }

    /**
     * @return array<string, array{0: AbstractPlatform, 1: string}>
     */
    public static function provideSupportedPlatforms(): array
    {
        return [
            'sqlite' => [new SqlitePlatform(), DumpFormat::SQLITE],
            'mysql' => [new MySQLPlatform(), DumpFormat::MYSQL],
            'mysql 8.0' => [new MySQL80Platform(), DumpFormat::MYSQL],
            'mysql 8.4' => [new MySQL84Platform(), DumpFormat::MYSQL],
            'mariadb' => [new MariaDBPlatform(), DumpFormat::MYSQL],
        ];
    }

    #[DataProvider('provideUnsupportedPlatforms')]
    public function testADatabaseNoDumpIsWrittenForIsRefusedRatherThanApproximated(AbstractPlatform $platform): void
    {
        // Nothing about the rest of a snapshot depends on the database, so this
        // is the one place an installation on a third engine can be told that
        // removing a package cannot be made undoable for it.
        try {
            DumpFormat::platform($platform);

            self::fail('A database no dump can be taken of must not be treated as one that can');
        } catch (\RuntimeException $e) {
            // Named, because the operator reading this has to know which engine
            // the installation is on to do anything about it.
            self::assertStringContainsString($platform::class, $e->getMessage());
        }
    }

    /**
     * @return array<string, array{0: AbstractPlatform}>
     */
    public static function provideUnsupportedPlatforms(): array
    {
        return [
            'postgres' => [new PostgreSQLPlatform()],
            'oracle' => [new OraclePlatform()],
            'sql server' => [new SQLServerPlatform()],
            'db2' => [new DB2Platform()],
        ];
    }

    public function testADumpIsDescribedByTheDriverThePlatformAndThePrefixOfTheInstallationItCameFrom(): void
    {
        $description = DumpFormat::describe($this->connection('pk_'));

        // All three are read back before a restore runs: the prefix says which
        // tables the dump was allowed to name, and the other two say which
        // database it can go back into.
        self::assertSame(['driver' => 'pdo_sqlite', 'platform' => 'sqlite', 'prefix' => 'pk_'], $description);
    }

    public function testAnInstallationThatOwnsItsWholeDatabaseIsDescribedAsHavingNoPrefix(): void
    {
        // A prefix is optional, and a description carrying nothing for it is not
        // the same as one carrying a prefix a restore would then look for.
        self::assertSame('', DumpFormat::describe($this->connection(null))['prefix']);
    }

    public function testAnInstallationOnADatabaseNoDumpFitsCannotBeDescribed(): void
    {
        $this->expectException(\RuntimeException::class);

        DumpFormat::describe($this->connection('pk_', ConnectionOnAnUnsupportedDatabase::class));
    }

    // ------------------------------------------------------------------
    // A record on its way to disk
    // ------------------------------------------------------------------

    public function testARecordIsOneLineOfItsOwn(): void
    {
        $line = DumpFormat::line(['type' => DumpFormat::ROW, 'values' => [1, 'two']]);

        // The reader takes a line at a time and never holds more than the record
        // it is on, which only works while a record is exactly one line.
        self::assertSame("\n", substr($line, -1));
        self::assertSame(1, substr_count($line, "\n"));
        self::assertSame(['type' => 'row', 'values' => [1, 'two']], json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testALineKeepsAPathReadableRatherThanSpellingItOut(): void
    {
        // Schema statements and stored values carry paths and URLs. Escaped
        // slashes would restore identically and make every dump unreadable to
        // the operator who has to look at one.
        $line = DumpFormat::line(['type' => DumpFormat::ROW, 'values' => ['/var/www/storage/logo.png']]);

        self::assertStringContainsString('/var/www/storage/logo.png', $line);
    }

    public function testARecordThatCannotBeWrittenDownIsReportedRatherThanWrittenWrong(): void
    {
        // Encoding answers what it cannot represent with false rather than by
        // raising, and a dumper writing that out would put the word "false" in
        // the file where a record belongs. A table's name and its schema are the
        // parts of a record that do not go through the value encoding, so a
        // database whose introspection hands back bytes that are no text is what
        // arrives here.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not be encoded');

        DumpFormat::line(['type' => DumpFormat::TABLE, 'name' => "pk_\xff", 'ddl' => ['CREATE TABLE x'], 'columns' => ['id']]);
    }

    // ------------------------------------------------------------------
    // A column value, out of the database and back into it
    // ------------------------------------------------------------------

    #[DataProvider('provideValuesJsonCarriesAsThemselves')]
    public function testAValueJsonCanCarryGoesIntoTheDumpAsItself(mixed $value): void
    {
        self::assertSame($value, DumpFormat::encode($value));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function provideValuesJsonCarriesAsThemselves(): array
    {
        return [
            'an empty column' => [null],
            'a flag that is set' => [true],
            'a flag that is not' => [false],
            'a count' => [42],
            'a zero' => [0],
            'a measurement' => [1.5],
            'text' => ['a title'],
            'text in another script' => ['Überschrift ’zwei’'],
            'an empty string, which is not an empty column' => [''],
        ];
    }

    public function testBytesNoTextTheyCouldBeMistakenForGoIntoTheDumpTagged(): void
    {
        $bytes = "\x00\x01\x02\xff\xfe";

        $encoded = DumpFormat::encode($bytes);

        // An object where a scalar belongs, so nothing can read it back as a
        // string the column held - and base64 rather than the bytes, because
        // JSON is text and these are not.
        self::assertSame([self::BINARY => base64_encode($bytes)], $encoded);
    }

    public function testAColumnHandedOverAsAStreamIsReadRatherThanRecordedAsAHandle(): void
    {
        // Large columns arrive as streams from some drivers and as strings from
        // others, and which one it was is not something a dump may depend on.
        self::assertSame('a stored document', DumpFormat::encode($this->stream('a stored document')));
        self::assertSame([self::BINARY => base64_encode("\x00\xff")], DumpFormat::encode($this->stream("\x00\xff")));
    }

    #[DataProvider('provideValuesNoColumnHolds')]
    public function testAValueNoColumnCouldHaveHeldIsReportedRatherThanGuessedAt(mixed $value, string $named): void
    {
        // A driver handing back one of these means the dump would restore
        // something other than what was in the column, which is worse than a
        // removal that is called off.
        try {
            DumpFormat::encode($value);

            self::fail('A value that cannot be dumped must not be reported as dumped');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($named, $e->getMessage());
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function provideValuesNoColumnHolds(): array
    {
        return [
            'an object' => [new \stdClass(), 'stdClass'],
            'a list' => [[1, 2], 'array'],
        ];
    }

    #[DataProvider('provideValuesAndHowTheyAreBound')]
    public function testAValueComesBackOutOfTheDumpWithTheTypeItHasToBeBoundAs(mixed $carried, mixed $value, int $type): void
    {
        // The type is not decoration: an integer bound as text and a null bound
        // as an empty string are both rows that come back different from the
        // ones that were dumped.
        self::assertSame([$value, $type], DumpFormat::decode($carried));
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed, 2: int}>
     */
    public static function provideValuesAndHowTheyAreBound(): array
    {
        return [
            'an empty column' => [null, null, ParameterType::NULL],
            'a flag' => [true, true, ParameterType::BOOLEAN],
            'a count' => [42, 42, ParameterType::INTEGER],
            'text' => ['a title', 'a title', ParameterType::STRING],
            'bytes' => [[self::BINARY => 'AAH//g=='], "\x00\x01\xff\xfe", ParameterType::BINARY],
        ];
    }

    public function testAMeasurementGoesBackIntoItsColumnWithEveryDigitItCameOutWith(): void
    {
        // Cast to a string a float is written at whatever precision the ini
        // happens to be set to, which is a value that comes back a little
        // different from the one that was dumped.
        [$value, $type] = DumpFormat::decode(0.30000000000000004);

        self::assertSame(ParameterType::STRING, $type);
        self::assertSame('0.30000000000000004', $value);
        self::assertSame(0.30000000000000004, (float) $value);
    }

    public function testBytesThatCannotBeReadBackAreReportedRatherThanRestoredAsSomethingElse(): void
    {
        // A dump is a file on disk for weeks. One whose bytes were damaged has
        // to be refused, not written into the column as whatever survived.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('binary column value in the dump cannot be read');

        DumpFormat::decode([self::BINARY => '!! not base64 !!']);
    }

    #[DataProvider('provideShapesTheFormatDoesNotDefine')]
    public function testAValueShapedLikeNothingTheFormatDefinesIsRefused(mixed $value): void
    {
        // Whoever wrote the file is not necessarily the dumper: a snapshot
        // directory is on disk, and what comes out of it is bound into the
        // database of a live installation.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('shape this format does not define');

        DumpFormat::decode($value);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function provideShapesTheFormatDoesNotDefine(): array
    {
        return [
            'a set of fields' => [['title' => 'a title']],
            'a list of values' => [[1, 2]],
            'nothing at all' => [[]],
            'bytes under another name' => [['base64' => 'AAH//g==']],
            'bytes with something alongside them' => [[self::BINARY => 'AAH//g==', 'length' => 4]],
            'bytes that are not text' => [[self::BINARY => 42]],
        ];
    }

    #[DataProvider('provideValuesThatSurviveTheWholeWay')]
    public function testWhatWentIntoADumpIsWhatComesOutOfTheLineItWasWrittenAs(mixed $value): void
    {
        // The two halves are only ever this far apart: encoded into a line by
        // the removal, decoded out of it by a restore weeks later. Testing them
        // together is the only way the pair is tested rather than each side's
        // idea of the other.
        $line = DumpFormat::line(['type' => DumpFormat::ROW, 'values' => [DumpFormat::encode($value)]]);

        /** @var array{values: array<int, mixed>} $record */
        $record = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);

        [$bound] = DumpFormat::decode($record['values'][0]);

        // A measurement comes back as the text that reads as the same number,
        // which is what keeps its last digits; everything else comes back as
        // itself.
        self::assertSame($value, is_float($value) ? (float) $bound : $bound);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function provideValuesThatSurviveTheWholeWay(): array
    {
        return [
            'an empty column' => [null],
            'a count' => [42],
            'text' => ['a title'],
            'text in another script' => ['Überschrift ’zwei’'],
            'text with a line break in it' => ["line one\nline two"],
            'text that looks like a record' => ['{"type":"end"}'],
            'a measurement' => [0.30000000000000004],
            'a thumbnail' => ["\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR"],
            'a password hash as bytes' => ["\x00\x9f\x92\x96\xff"],
        ];
    }

    /**
     * A connection as an installation has one: what the driver is called, and
     * what the tables it owns are named with.
     */
    private function connection(?string $prefix, string $wrapper = Connection::class): Connection
    {
        $params = ['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => $wrapper];

        if ($prefix !== null) {
            $params['prefix'] = $prefix;
        }

        $connection = DriverManager::getConnection($params);

        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * A column value as a driver that streams large ones hands it over.
     *
     * @return resource
     */
    private function stream(string $content)
    {
        $stream = fopen('php://memory', 'r+b');

        self::assertIsResource($stream);

        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
