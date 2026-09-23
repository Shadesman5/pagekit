<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Pagekit\Installer\TablePrefix;
use Pagekit\Package\Snapshot\RestoreTableNames;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The names a restore invents for the copies it makes of an installation's
 * tables, and the reading that tells one of those from a table the installation
 * owns.
 *
 * Both halves destroy data when they are wrong, and in opposite directions. A
 * name read as the installation's when a restore invented it is a half-filled
 * copy carried into a dump, to be replayed over the table it was a copy of. A
 * name read as a restore's when the installation owns it is a table left out of
 * the dump an uninstall puts back - and, once the restorer drops what it thinks
 * it left behind, a table dropped outright.
 *
 * So what is asserted here is not the spelling of the markers but the properties
 * the rest of the machinery leans on: that the live name can still be read out of
 * an invented one, that both names a table gets measure the same, that nothing an
 * installation can be created with reads as invented, and that the reading is
 * about the front of a name rather than about underscores anywhere in it.
 */
final class RestoreTableNamesTest extends TestCase
{
    /**
     * A table of an installation created with the prefix the installer offers,
     * which the names below are made out of.
     */
    private const LIVE = 'pk_users';

    public function testTheCopyAndTheTableItReplacesAreBothTheLiveNameUnderSomethingElse(): void
    {
        // Readable rather than hashed, because a list of table names is all an
        // operator who finds a restore stuck has to work out which table the
        // leftovers in front of them belong to.
        self::assertStringEndsWith(self::LIVE, RestoreTableNames::shadow(self::LIVE));
        self::assertStringEndsWith(self::LIVE, RestoreTableNames::backup(self::LIVE));

        // The swap names both at once - the copy taking the live name, the table
        // that was live taking the other - so the two cannot be one name.
        self::assertNotSame(RestoreTableNames::shadow(self::LIVE), RestoreTableNames::backup(self::LIVE));
    }

    public function testTheLiveNameIsWhatIsLeftOfAnInventedOneOnceTheMarkerIsTakenOff(): void
    {
        // Which table a leftover was a copy of is read back off its own name,
        // and that is what says whether the restorer that finds it may drop it
        // or has to refuse - so the remainder has to be the live name exactly,
        // not the live name with something else done to it.
        self::assertSame(self::LIVE, substr(RestoreTableNames::shadow(self::LIVE), strlen(RestoreTableNames::SHADOW)));
        self::assertSame(self::LIVE, substr(RestoreTableNames::backup(self::LIVE), strlen(RestoreTableNames::BACKUP)));
    }

    public function testBothNamesATableGetsAreTheSameLengthSoOneMeasurementAnswersForBoth(): void
    {
        // MySQL stops at 64 characters for a table name, and a restore that has
        // to refuse a name too long to rewrite measures it once. That single
        // measurement only stands for both names while the markers are equally
        // far from the live name.
        self::assertSame(
            strlen(RestoreTableNames::shadow(self::LIVE)),
            strlen(RestoreTableNames::backup(self::LIVE)),
        );
        self::assertSame(strlen(self::LIVE) + strlen(RestoreTableNames::SHADOW), strlen(RestoreTableNames::shadow(self::LIVE)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function namesARestoreInvented(): array
    {
        return [
            'a copy being filled' => [RestoreTableNames::shadow(self::LIVE)],
            'a table kept beside the copy that replaced it' => [RestoreTableNames::backup(self::LIVE)],
            'a copy of a table of an installation with no prefix' => [RestoreTableNames::shadow('users')],
            'a copy of a name a restore had already invented' => [RestoreTableNames::shadow(RestoreTableNames::backup(self::LIVE))],
            'a marker on its own' => [RestoreTableNames::SHADOW],
        ];
    }

    /**
     * The doubled case is the one that is easy to miss: a dump naming a table a
     * restore invented is not a dump of an installation, and the reading has to
     * say so rather than letting a copy of a copy be made.
     */
    #[DataProvider('namesARestoreInvented')]
    public function testANameARestoreInventedIsRecognisedAsOneRatherThanAsATableOfTheInstallation(string $table): void
    {
        self::assertTrue(
            RestoreTableNames::isReserved($table),
            sprintf('"%s" is a name a restore gave itself, not a table an installation owns', $table),
        );

        // The two readings are one reading. A dump decides what to leave out
        // from whether the name is invented at all, and a restorer decides what
        // it may clear away from the table the name was invented for - so a name
        // only one of them recognised would be dropped as a leftover by the one
        // while the other carried it into a dump as a table.
        self::assertNotNull(
            RestoreTableNames::live($table),
            sprintf('"%s" was invented for a table, and which one has to be readable off it', $table),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function namesNoRestoreInvented(): array
    {
        return [
            'a prefixed table' => ['pk_users'],
            'a table of an installation with no prefix' => ['users'],
            'a prefix that carries a marker inside it' => ['a_b_users'],
            'a name carrying a marker past its first character' => ['pk__r_users'],
            'a name that only begins like a marker' => ['_migrations'],
            'a marker in a case no restore writes' => ['_R_pk_users'],
        ];
    }

    /**
     * Underscores are not the marker. An installation created with no prefix
     * owns every table in its database, one whose name leads with an underscore
     * included, and a prefix may carry a marker's spelling in the middle of it
     * ("a_b_" is installable) - so a reading that claimed the underscore-led
     * namespace wholesale, or looked for a marker anywhere in a name, would
     * take real tables for leftovers.
     *
     * The case that differs is a name this machinery never wrote: on a server
     * that keeps table names as they were given, it is a different table
     * altogether and belongs to whoever made it.
     */
    #[DataProvider('namesNoRestoreInvented')]
    public function testANameNoRestoreInventedIsLeftToWhoeverItBelongsTo(string $table): void
    {
        self::assertFalse(
            RestoreTableNames::isReserved($table),
            sprintf('"%s" is a table name of somebody\'s own, and a restore may neither skip nor drop it', $table),
        );
        self::assertNull(
            RestoreTableNames::live($table),
            sprintf('"%s" was invented for nothing, so there is no table to read off it', $table),
        );
    }

    public function testWhichTableACopyWasMadeForIsReadOffWhatIsLeftWhenTheMarkerComesOff(): void
    {
        // Whose copy a leftover is decides what may be done about it: a copy of
        // one of this installation's tables is its own to clear away, and a copy
        // of a table it does not own is one it may not touch. That is read off
        // the remainder, so the remainder has to be the live name and nothing
        // near it.
        self::assertSame(self::LIVE, RestoreTableNames::live(RestoreTableNames::shadow(self::LIVE)));
        self::assertSame(self::LIVE, RestoreTableNames::live(RestoreTableNames::backup(self::LIVE)));

        // One marker off, not every marker: a copy of a copy is left saying it
        // was made for a table no installation owns, which is what keeps it out
        // of the set a restore treats as its own.
        self::assertSame(
            RestoreTableNames::backup(self::LIVE),
            RestoreTableNames::live(RestoreTableNames::shadow(RestoreTableNames::backup(self::LIVE))),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function installablePrefixes(): array
    {
        return [
            'the default' => ['pk_'],
            'a word' => ['pagekit_'],
            'a single letter' => ['p_'],
            'upper case' => ['PK_'],
            'digits behind the letter' => ['pk2_'],
            'underscores inside' => ['a_b_'],
        ];
    }

    /**
     * The reservation is only a reservation because of the shape a prefix has to
     * have, so the two rules are asserted against each other rather than each on
     * its own: whatever an installation can be created with, none of the tables
     * it then owns may read as a name a restore invented.
     */
    #[DataProvider('installablePrefixes')]
    public function testNoTableOfAnInstallationThatCouldBeCreatedReadsAsANameARestoreInvented(string $prefix): void
    {
        self::assertNull(TablePrefix::refusal($prefix), sprintf('"%s" is a prefix an installation can be created with', $prefix));

        foreach (['users', 'r_users', 'b_users', '_r_users'] as $table) {
            self::assertFalse(
                RestoreTableNames::isReserved($prefix.$table),
                sprintf('an installation created with "%s" owns "%s"', $prefix, $prefix.$table),
            );
        }
    }

    /**
     * The other half of the same rule, read from the marker rather than from the
     * prefix: a site installed under one would own the names a restore takes for
     * copies of its tables, and the next restore would drop them.
     */
    public function testAMarkerARestoreNamesItsOwnTablesWithIsNoPrefixAnInstallationCanBeCreatedWith(): void
    {
        foreach ([RestoreTableNames::SHADOW, RestoreTableNames::BACKUP] as $marker) {
            self::assertIsString(
                TablePrefix::refusal($marker),
                sprintf('"%s" is what a restore names its own tables with', $marker),
            );
            self::assertIsString(
                TablePrefix::refusal($marker.'pk_'),
                sprintf('"%s" names tables a restore would take for its own', $marker.'pk_'),
            );
        }
    }
}
