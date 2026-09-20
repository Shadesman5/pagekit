<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Installer;

use Pagekit\Installer\TablePrefix;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shape a prefix has to have before an installation is created with it.
 *
 * Every way of installing asks here, so a prefix refused in the browser is
 * refused on the command line too. Each part of the shape answers a way the
 * database ends up unreadable afterwards: tables nothing can tell apart when
 * there is no prefix at all, a prefix claiming names beyond its own when the
 * delimiter is missing, and table names the snapshot machinery has to be free
 * to invent when the underscore-led namespace is installed into.
 *
 * What is asserted of a refusal is that it names the prefix and what to type
 * instead. The person reading it is installing a site, and the rule alone
 * leaves them to guess which field and which spelling.
 */
final class TablePrefixTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function installablePrefixes(): array
    {
        return [
            'the default' => ['pk_'],
            'a single letter' => ['p_'],
            'a word' => ['pagekit_'],
            'digits behind the letter' => ['pk2_'],
            'underscores inside' => ['a_b_'],
            'upper case' => ['PK_'],
            'a doubled delimiter' => ['pk__'],
        ];
    }

    #[DataProvider('installablePrefixes')]
    public function testAPrefixOfTheRightShapeIsNotRefused(string $prefix): void
    {
        self::assertNull(
            TablePrefix::refusal($prefix),
            sprintf('"%s" is a name an installation has to be creatable with', $prefix),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedPrefixes(): array
    {
        return [
            'nothing at all' => [''],
            'no delimiter' => ['pk'],
            'a leading underscore' => ['_pk_'],
            'a leading digit' => ['2pk_'],
            'a trailing dot' => ['pk.'],
            'a dot inside' => ['pk.x_'],
            'a trailing hyphen' => ['pk-'],
            'a hyphen inside' => ['pk-x_'],
            'a space inside' => ['pk table_'],
            'a trailing space' => ['pk_ '],
            'a backtick' => ['pk`_'],
            'a quote' => ["pk'_"],
            'a semicolon' => ['pk;_'],
            'letters from outside ASCII' => ['präfix_'],
        ];
    }

    /**
     * A dot or a hyphen is not an identifier character in unquoted MySQL, and a
     * backtick, a quote or a semicolon carries the name out of the identifier
     * altogether - the schema tools write table names without quoting them.
     */
    #[DataProvider('refusedPrefixes')]
    public function testAPrefixOfAnyOtherShapeIsRefusedWithAReason(string $prefix): void
    {
        $refusal = TablePrefix::refusal($prefix);

        self::assertIsString($refusal, sprintf('"%s" must not be installable', $prefix));
        self::assertNotSame('', $refusal, 'A refusal with nothing to read is no refusal');
    }

    /**
     * The one mistake worth answering with the correction rather than the rule.
     * Whoever typed "pagekit" meant the prefix and not the delimiter behind it,
     * so what they have to type instead is spelled out for them.
     */
    public function testAPrefixMissingItsDelimiterIsAnsweredWithTheCorrectedOne(): void
    {
        $refusal = (string) TablePrefix::refusal('pagekit');

        self::assertStringContainsString('"pagekit_"', $refusal);
    }

    /**
     * An empty field has nothing to correct, so the answer names a prefix that
     * works instead of describing the shape and leaving one to be invented.
     */
    public function testAnEmptyPrefixIsAnsweredWithOneThatWorks(): void
    {
        self::assertStringContainsString('pk_', (string) TablePrefix::refusal(''));
    }

    /**
     * An installation can name a prefix per connection. A refusal that only
     * described the rule would leave whoever is installing to work out which of
     * the fields it was about.
     */
    public function testTheRefusalNamesThePrefixItRefused(): void
    {
        self::assertStringContainsString('"my-site_"', (string) TablePrefix::refusal('my-site_'));
    }

    /**
     * The leading letter is what keeps the underscore-led namespace out of
     * reach. The snapshot machinery takes the table names it has to invent from
     * there, and an installation owning names in it would have its own tables
     * taken for something left behind to clear away.
     */
    public function testTheReservedUnderscoreNamespaceCannotBeInstalledInto(): void
    {
        foreach (['_', '__', '_r_', '_b_', '_r_pk_', '_b_site_'] as $prefix) {
            self::assertIsString(
                TablePrefix::refusal($prefix),
                sprintf('"%s" names tables the snapshot machinery has to be free to invent', $prefix),
            );
        }
    }

    /**
     * An installation that names no prefix is created with the one its
     * connection ships, so every connection the database module offers has to
     * ship a prefix this rule accepts. The two disagreeing is how a site ended
     * up with tables carrying no prefix at all while the same site on the other
     * connection got one.
     */
    public function testEveryPrefixTheDatabaseModuleShipsCanBeInstalledWith(): void
    {
        /** @var array{config: array{connections: array<string, array<string, mixed>>}} $definition */
        $definition = require dirname(__DIR__, 3).'/app/modules/database/index.php';

        $connections = $definition['config']['connections'];

        self::assertNotSame([], $connections);

        foreach ($connections as $name => $params) {
            $prefix = $params['prefix'] ?? null;

            self::assertIsString($prefix, sprintf('the "%s" connection has to ship a prefix', $name));
            self::assertNull(
                TablePrefix::refusal($prefix),
                sprintf('an installation choosing "%s" and typing nothing is created with this prefix', $name),
            );
        }
    }
}
