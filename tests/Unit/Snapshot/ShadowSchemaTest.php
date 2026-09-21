<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Pagekit\Installer\Package\Snapshot\RestoreTableNames;
use Pagekit\Installer\Package\Snapshot\ShadowSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Turning the schema a dump holds for a table into the schema that creates the
 * copy of it a restore fills.
 *
 * This is where the bytes of a file decide what SQL runs against the database
 * that file is about to replace, and both ways of getting it wrong lose data.
 * Rewriting too little leaves a copy pointing at the table it is a copy of, so
 * the swap puts back a table whose foreign keys tie it to the tables that were
 * set aside. Rewriting too much reaches what the dump was carrying instead of
 * what it was about: a default value spelling a table name is a value the
 * database was given, and a column renamed for being named after a table is a
 * copy whose columns no longer line up with the rows dumped against them.
 *
 * So what is asserted is byte for byte. The statements that come out are the ones
 * that went in with nothing changed but the table each works on, the targets of
 * the foreign keys the dump itself carries, and the constraint names MySQL keeps
 * one of per schema. Anything else is refused rather than passed on, these being
 * statements that go to the server as they stand.
 */
final class ShadowSchemaTest extends TestCase
{
    // ------------------------------------------------------------------
    // The copy is what the statements work on
    // ------------------------------------------------------------------

    public function testEveryStatementOfATablesSchemaWorksOnTheCopyAndIsOtherwiseAsItWasDumped(): void
    {
        // What a platform hands over for one table is several statements - the
        // table, its indexes, its foreign keys - and all of them have to arrive at
        // the copy, in the order they were given, with the options and collations
        // that came with them. A restore is not the place to have opinions about
        // the schema it is putting back.
        $schema = new ShadowSchema(['pk_a_child', 'pk_b_parent']);

        $statements = $schema->rewrite('pk_a_child', [
            'CREATE TABLE pk_a_child (id INT NOT NULL, parent_id INT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
            'CREATE INDEX IDX_PARENT ON pk_a_child (parent_id)',
            'ALTER TABLE pk_a_child ADD CONSTRAINT FK_PARENT FOREIGN KEY (parent_id) REFERENCES pk_b_parent (id) ON DELETE CASCADE',
        ]);

        self::assertCount(3, $statements);
        self::assertSame(
            'CREATE TABLE `_r_pk_a_child` (id INT NOT NULL, parent_id INT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
            $statements[0],
        );
        self::assertSame('CREATE INDEX IDX_PARENT ON `_r_pk_a_child` (parent_id)', $statements[1]);

        $expected = sprintf(
            'ALTER TABLE `_r_pk_a_child` ADD CONSTRAINT `%s` FOREIGN KEY (parent_id) REFERENCES `_r_pk_b_parent` (id) ON DELETE CASCADE',
            $this->constraintIn($statements[2]),
        );

        self::assertSame($expected, $statements[2]);
    }

    public function testAStatementIsRecognisedInEveryShapeItMayBeWrittenIn(): void
    {
        // A statement is recognised by the keywords it opens with rather than by
        // the table name somewhere in it, so every optional word one may carry has
        // to be read past - taken for the name of the table, it is a schema whose
        // copy cannot be created at all.
        $schema = new ShadowSchema(['pk_items']);

        $shapes = [
            'CREATE TEMPORARY TABLE pk_items (id INT NOT NULL)' => 'CREATE TEMPORARY TABLE `_r_pk_items` (id INT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS pk_items (id INT NOT NULL)' => 'CREATE TABLE IF NOT EXISTS `_r_pk_items` (id INT NOT NULL)',
            'CREATE FULLTEXT INDEX IDX_BODY ON pk_items (body)' => 'CREATE FULLTEXT INDEX IDX_BODY ON `_r_pk_items` (body)',
            'CREATE SPATIAL INDEX IDX_AREA ON pk_items (area)' => 'CREATE SPATIAL INDEX IDX_AREA ON `_r_pk_items` (area)',
            'ALTER TABLE pk_items ADD INDEX IDX_SLUG (slug)' => 'ALTER TABLE `_r_pk_items` ADD INDEX IDX_SLUG (slug)',
        ];

        foreach ($shapes as $dumped => $expected) {
            self::assertSame([$expected], $schema->rewrite('pk_items', [$dumped]));
        }
    }

    public function testACopyIsNamedInBackticksWhetherOrNotTheDumpNamedTheTableThatWay(): void
    {
        // A platform quotes an identifier only where it has to, so a dump holds
        // both spellings. What is written in is a name this machinery made up
        // rather than one a schema tool rendered, and a name in backticks is read
        // as a name whatever it spells.
        $schema = new ShadowSchema(['pk_items']);

        self::assertSame(
            ['CREATE TABLE `_r_pk_items` (id INT NOT NULL)'],
            $schema->rewrite('pk_items', ['CREATE TABLE pk_items (id INT NOT NULL)']),
        );
        self::assertSame(
            ['CREATE TABLE `_r_pk_items` (id INT NOT NULL)'],
            $schema->rewrite('pk_items', ['CREATE TABLE `pk_items` (id INT NOT NULL)']),
        );
    }

    public function testANameCarryingABacktickIsWrittenBackAsANameRatherThanClosingTheOneItIsIn(): void
    {
        // A table name is whatever the installation called its table, and the
        // copy's name is that name behind a marker. Written out without the quote
        // inside it doubled, it would close the identifier and leave the rest of
        // the name standing as SQL.
        $table = 'pk_it`ems';
        $schema = new ShadowSchema([$table]);

        self::assertSame(
            ['CREATE TABLE `_r_pk_it``ems` (id INT NOT NULL)'],
            $schema->rewrite($table, ['CREATE TABLE `pk_it``ems` (id INT NOT NULL)']),
        );
    }

    // ------------------------------------------------------------------
    // What a statement holds that is no name
    // ------------------------------------------------------------------

    public function testAValueThatSpellsATableNameIsLeftAsTheValueItIs(): void
    {
        // The reason this reads the statement rather than replacing text in it. A
        // column whose default is another table's name holds a value the database
        // was given, and rewritten it is a restore putting back a row that differs
        // from the one that was dumped. A semicolon inside a value is a character
        // of it too, and no second statement.
        $schema = new ShadowSchema(['pk_items', 'pk_meta']);

        // Both quotes a value may be written in, the server reading either as one
        // unless it has been told that double quotes name things instead - in which
        // case what stands there is a name where no table belongs, and left alone
        // for that reason.
        $columns = "slug VARCHAR(191) DEFAULT 'pk_meta' NOT NULL, "
            .'kind VARCHAR(32) DEFAULT "pk_meta" NOT NULL, '
            ."note TEXT DEFAULT 'REFERENCES pk_meta (id); DROP TABLE pk_meta' NOT NULL, "
            .'PRIMARY KEY(slug)';

        self::assertSame(
            ['CREATE TABLE `_r_pk_items` ('.$columns.')'],
            $schema->rewrite('pk_items', ['CREATE TABLE pk_items ('.$columns.')']),
        );
    }

    public function testAQuoteInsideAValueIsPartOfItRatherThanTheEndOfIt(): void
    {
        // Read as the end of the value, everything after it is read as SQL and the
        // reader is a quote out of step with the statement for the rest of it -
        // which is how the reference further along goes unnoticed and the copy is
        // left tied to the table it was made from. Both ways of writing one,
        // because a value arrives in whichever of them the dump was written with.
        $schema = new ShadowSchema(['pk_items', 'pk_meta']);

        $columns = [
            'escaped by a backslash' => "note TEXT DEFAULT 'it\\'s pk_meta' NOT NULL, item_id INT NOT NULL, ",
            'written twice over' => "note TEXT DEFAULT 'it''s pk_meta' NOT NULL, item_id INT NOT NULL, ",
        ];

        foreach ($columns as $why => $written) {
            $statement = $schema->rewrite('pk_items', [
                'CREATE TABLE pk_items ('.$written.'CONSTRAINT fk_item FOREIGN KEY (item_id) REFERENCES pk_meta (id))',
            ])[0];

            $expected = sprintf(
                'CREATE TABLE `_r_pk_items` (%sCONSTRAINT `%s` FOREIGN KEY (item_id) REFERENCES `_r_pk_meta` (id))',
                $written,
                $this->constraintIn($statement),
            );

            self::assertSame($expected, $statement, $why);
        }
    }

    public function testATableNameStandingWhereNoTableBelongsIsLeftAlone(): void
    {
        // Only where the grammar says a table stands. A column named after another
        // table is a column, and a copy whose columns were renamed is one the
        // dump's rows - bound by position against the column names the dump
        // recorded - no longer fit.
        $schema = new ShadowSchema(['pk_items', 'pk_meta']);

        self::assertSame(
            ['CREATE TABLE `_r_pk_items` (pk_meta INT NOT NULL, INDEX pk_meta (pk_meta))'],
            $schema->rewrite('pk_items', ['CREATE TABLE pk_items (pk_meta INT NOT NULL, INDEX pk_meta (pk_meta))']),
        );
    }

    public function testACommentIsReadPastRatherThanThrough(): void
    {
        // A table named in a comment is no table the statement works on, and a
        // lone quote in one would take the rest of the statement with it. All
        // three spellings, because a schema tool writes the type of a column it
        // cannot express in SQL as a comment beside it.
        $schema = new ShadowSchema(['pk_items', 'pk_meta']);

        $columns = " -- REFERENCES pk_meta (id)\n"
            ."  id INT NOT NULL, # CONSTRAINT fk_item FOREIGN KEY (id)\n"
            .'  /* REFERENCES pk_meta ; */ PRIMARY KEY(id)';

        self::assertSame(
            ['CREATE TABLE `_r_pk_items` ('.$columns.')'],
            $schema->rewrite('pk_items', ['CREATE TABLE pk_items ('.$columns.')']),
        );
    }

    public function testANameInBackticksIsNoKeywordHoweverItIsSpelled(): void
    {
        // Which is what the backticks say. Read as the keyword it spells, the
        // column named below puts the reader where a table name has to stand and a
        // closing bracket does - so a column an installation is free to have would
        // have its table refused.
        $schema = new ShadowSchema(['pk_items']);

        $columns = '`references` INT NOT NULL, `constraint` INT NOT NULL, UNIQUE (`references`), PRIMARY KEY(`constraint`)';

        self::assertSame(
            ['CREATE TABLE `_r_pk_items` ('.$columns.')'],
            $schema->rewrite('pk_items', ['CREATE TABLE pk_items ('.$columns.')']),
        );
    }

    // ------------------------------------------------------------------
    // Which tables a copy's foreign keys point at
    // ------------------------------------------------------------------

    public function testAForeignKeyOntoATableTheDumpCarriesIsPointedAtThatTablesCopy(): void
    {
        // The tables the dump carries are swapped together, so among the copies
        // the references have to be among the copies too. Left pointing at the
        // live table, a copy is swapped in still tied to the table that is on its
        // way out.
        $schema = new ShadowSchema(['pk_a_child', 'pk_b_parent']);

        $statement = $schema->rewrite('pk_a_child', [
            'ALTER TABLE pk_a_child ADD CONSTRAINT fk_parent FOREIGN KEY (parent_id) REFERENCES pk_b_parent (id)',
        ])[0];

        self::assertStringContainsString('REFERENCES `_r_pk_b_parent` (id)', $statement);
    }

    public function testAForeignKeyOntoATableOutsideTheDumpIsLeftPointingWhereItPointed(): void
    {
        // Nothing is swapping that table, so no copy of it is ever made: pointed
        // at one, the copy could not be created at all. The reference itself is
        // part of the schema the restore is putting back rather than something for
        // it to drop.
        $schema = new ShadowSchema(['pk_a_child']);

        $statement = $schema->rewrite('pk_a_child', [
            'ALTER TABLE pk_a_child ADD CONSTRAINT fk_neighbour FOREIGN KEY (thing_id) REFERENCES other_things (id)',
        ])[0];

        self::assertStringContainsString('REFERENCES other_things (id)', $statement);
        self::assertStringNotContainsString(RestoreTableNames::shadow('other_things'), $statement);
    }

    public function testAForeignKeyOntoATableInAnotherSchemaIsNoneOfThisRestoresBusiness(): void
    {
        // A name in front of a dot is a schema, and a restore reaches only the one
        // it runs in: neither the name such a reference qualifies nor a name that
        // merely reads like one of the dump's is a table being swapped here.
        $schema = new ShadowSchema(['pk_a_child', 'pk_items']);

        $references = [
            'the dump carries the name behind the dot' => 'REFERENCES elsewhere.pk_items (id)',
            'the dump carries the name in front of it' => 'REFERENCES pk_items.things (id)',
        ];

        foreach ($references as $why => $reference) {
            $statement = $schema->rewrite('pk_a_child', [
                'ALTER TABLE pk_a_child ADD CONSTRAINT fk_elsewhere FOREIGN KEY (item_id) '.$reference,
            ])[0];

            self::assertStringContainsString($reference, $statement, $why);
        }
    }

    // ------------------------------------------------------------------
    // The names MySQL keeps one of per schema
    // ------------------------------------------------------------------

    public function testTheNameOfAForeignKeyOrACheckIsReplacedBecauseMysqlKeepsOneOfThosePerSchema(): void
    {
        // The copy stands beside the table it was made from, so it cannot carry
        // that table's constraint names - and once the swap has happened there is
        // no putting them right, MySQL having no statement that renames one.
        $schema = new ShadowSchema(['pk_items']);

        $statement = $schema->rewrite('pk_items', [
            'CREATE TABLE pk_items (id INT NOT NULL, score INT NOT NULL, CONSTRAINT chk_score CHECK (score > 0), PRIMARY KEY(id))',
        ])[0];

        self::assertStringNotContainsString('chk_score', $statement);
        self::assertStringContainsString('CHECK (score > 0)', $statement);
        self::assertStringContainsString('PRIMARY KEY(id)', $statement);
    }

    public function testTheNameOfAnIndexOrAUniqueConstraintIsTheTablesOwnAndStaysAsDumped(): void
    {
        // MySQL keeps those per table and a copy is a table of its own, so there
        // is nothing for them to collide with. Replaced, a restored installation
        // would carry invented names for keys an operator knows by the names they
        // were created with.
        $schema = new ShadowSchema(['pk_items']);

        $statements = $schema->rewrite('pk_items', [
            'CREATE TABLE pk_items (id INT NOT NULL, slug VARCHAR(191) NOT NULL, INDEX IDX_SLUG (slug), CONSTRAINT uniq_pair UNIQUE (id, slug), PRIMARY KEY(id))',
            'CREATE UNIQUE INDEX UNIQ_SLUG ON pk_items (slug)',
        ]);

        self::assertStringContainsString('INDEX IDX_SLUG (slug)', $statements[0]);
        self::assertStringContainsString('CONSTRAINT uniq_pair UNIQUE (id, slug)', $statements[0]);
        self::assertSame('CREATE UNIQUE INDEX UNIQ_SLUG ON `_r_pk_items` (slug)', $statements[1]);
    }

    public function testOneConstraintAskedAboutTwiceIsGivenOneName(): void
    {
        // A table's schema may name the same constraint in more than one
        // statement, and two names for it would leave the second statement talking
        // about a constraint that was never created.
        $schema = new ShadowSchema(['pk_items', 'pk_meta']);

        self::assertSame(
            $this->constraintIn($schema->rewrite('pk_items', [$this->foreignKey('pk_items', 'fk_item')])[0]),
            $this->constraintIn($schema->rewrite('pk_items', [$this->foreignKey('pk_items', 'fk_item')])[0]),
        );
    }

    public function testTwoRestoresDoNotGiveTheirCopiesConstraintsTheSameNames(): void
    {
        // Restoring one snapshot twice is ordinary, and the names the first
        // restore invented are on the live tables for good. Derived from the dump
        // alone, the second restore's names would be the ones already in use and
        // its copies could not be created at all.
        $ddl = [$this->foreignKey('pk_items', 'fk_item')];

        self::assertNotSame(
            $this->constraintIn((new ShadowSchema(['pk_items', 'pk_meta']))->rewrite('pk_items', $ddl)[0]),
            $this->constraintIn((new ShadowSchema(['pk_items', 'pk_meta']))->rewrite('pk_items', $ddl)[0]),
        );
    }

    public function testNoTwoConstraintsOfOneRestoreAreGivenTheSameName(): void
    {
        // Per schema means across every copy a restore makes, so two of them under
        // one name is the second copy refusing to be created. The last pair is the
        // one that is easy to lose: the table "pk_a" with a constraint called "bc"
        // and the table "pk_ab" with one called "c" are two constraints, and run
        // together into one string they are the same.
        $schema = new ShadowSchema(['pk_a', 'pk_ab', 'pk_meta']);

        $names = [
            $this->constraintIn($schema->rewrite('pk_a', [$this->foreignKey('pk_a', 'fk_one')])[0]),
            $this->constraintIn($schema->rewrite('pk_a', [$this->foreignKey('pk_a', 'fk_two')])[0]),
            $this->constraintIn($schema->rewrite('pk_ab', [$this->foreignKey('pk_ab', 'fk_one')])[0]),
            $this->constraintIn($schema->rewrite('pk_a', [$this->foreignKey('pk_a', 'bc')])[0]),
            $this->constraintIn($schema->rewrite('pk_ab', [$this->foreignKey('pk_ab', 'c')])[0]),
        ];

        self::assertSame($names, array_unique($names), 'Every constraint of a restore is named once');
    }

    public function testTheNameACopysConstraintGetsIsShortWhateverTheDumpedOneWasCalled(): void
    {
        // MySQL stops at 64 characters for a constraint name as well, and a dumped
        // name is already allowed to be that long - so the copy's name is derived
        // rather than decorated, and its length says nothing about what it was
        // derived from.
        $schema = new ShadowSchema(['pk_items', 'pk_meta']);

        $short = $this->constraintIn($schema->rewrite('pk_items', [$this->foreignKey('pk_items', 'fk_item')])[0]);
        $long = $this->constraintIn($schema->rewrite('pk_items', [$this->foreignKey('pk_items', str_repeat('c', 64))])[0]);

        self::assertSame(strlen($short), strlen($long));
        self::assertLessThanOrEqual(64, strlen($long));

        // In the namespace the copies themselves are named in, which no
        // installation can be created into - so an invented name can never be one
        // a site gave something of its own.
        self::assertStringStartsWith(RestoreTableNames::SHADOW, $long);
    }

    // ------------------------------------------------------------------
    // What no copy can be created from
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideStatementsNoCopyCanBeCreatedFrom(): array
    {
        return [
            'a table made out of another table' => ['CREATE TABLE pk_items LIKE pk_meta'],
            'a table renamed' => ['ALTER TABLE pk_items RENAME TO pk_gone'],
            'a column dropped' => ['ALTER TABLE pk_items DROP COLUMN title'],
            'a table dropped' => ['DROP TABLE pk_items'],
            'rows written' => ['INSERT INTO pk_items (id) VALUES (1)'],
            'a second statement behind a semicolon' => ['CREATE TABLE pk_items (id INT NOT NULL); DROP TABLE pk_meta'],
            'a table in another schema' => ['CREATE TABLE elsewhere.pk_items (id INT NOT NULL)'],
            'another table than the one whose schema this is' => ['CREATE TABLE pk_meta (id INT NOT NULL)'],
            'a value that is never closed' => ["CREATE TABLE pk_items (title VARCHAR(191) DEFAULT 'pk_items)"],
            'a name in backticks that is never closed' => ['CREATE TABLE `pk_items (id INT NOT NULL)'],
            'a comment that is never closed' => ['CREATE TABLE pk_items (id INT NOT NULL) /* and then'],
            'a reference onto nothing' => ['ALTER TABLE pk_items ADD CONSTRAINT fk_item FOREIGN KEY (id) REFERENCES (id)'],
            'a constraint named by a value' => ["ALTER TABLE pk_items ADD CONSTRAINT 'fk_item' FOREIGN KEY (id) REFERENCES pk_meta (id)"],
            'nothing at all' => [''],
        ];
    }

    /**
     * Recognised rather than searched for trouble. What comes out of here runs
     * against the database the dump is about to replace, so a statement that is
     * not one of the few a dump has any business carrying is refused rather than
     * handed over to see what the server makes of it - a file is no reason to run
     * arbitrary SQL.
     */
    #[DataProvider('provideStatementsNoCopyCanBeCreatedFrom')]
    public function testAStatementNoCopyCanBeCreatedFromIsRefusedRatherThanRun(string $ddl): void
    {
        $schema = new ShadowSchema(['pk_items', 'pk_meta']);

        $this->expectException(\RuntimeException::class);

        $schema->rewrite('pk_items', [$ddl]);
    }

    public function testASemicolonEndingAStatementIsNoSecondStatement(): void
    {
        // The other side of the same rule: a dump whose statements are written
        // with the terminator on them is one a copy can still be created from.
        $schema = new ShadowSchema(['pk_items']);

        self::assertSame(
            ['CREATE TABLE `_r_pk_items` (id INT NOT NULL);'],
            $schema->rewrite('pk_items', ['CREATE TABLE pk_items (id INT NOT NULL);']),
        );
    }

    public function testTheRefusalNamesTheTableAndShowsAsMuchOfTheStatementAsIsWorthReading(): void
    {
        // It reaches an operator through the snapshots panel. Which table the file
        // was stopped at is what says whether the dump is worth anything, and a
        // whole table's schema in a message is not something anybody reads.
        $schema = new ShadowSchema(['pk_items']);

        try {
            $schema->rewrite('pk_items', ["CREATE TABLE pk_items (id INT NOT NULL);\n  DROP TABLE pk_meta"]);

            self::fail('A statement no copy can be created from must be refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('"pk_items"', $e->getMessage());
            self::assertStringContainsString('CREATE TABLE pk_items (id INT NOT NULL); DROP TABLE pk_meta', $e->getMessage());
        }

        try {
            $schema->rewrite('pk_items', ['DROP TABLE '.str_repeat('a', 400)]);

            self::fail('A statement no copy can be created from must be refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('DROP TABLE '.str_repeat('a', 100), $e->getMessage());
            self::assertStringNotContainsString(str_repeat('a', 200), $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Reading a rewritten statement back
    // ------------------------------------------------------------------

    /**
     * A foreign key as a dump carries one, which is the statement the constraint
     * naming above is read out of.
     */
    private function foreignKey(string $table, string $constraint): string
    {
        return sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (item_id) REFERENCES pk_meta (id)',
            $table,
            $constraint,
        );
    }

    /**
     * The name a rewritten statement gives a constraint. It is invented rather
     * than dumped, so there is no writing it into an expectation - what can be
     * asserted of it is read back off the statement it was written into.
     */
    private function constraintIn(string $sql): string
    {
        self::assertSame(
            1,
            preg_match('/CONSTRAINT `([^`]+)`/', $sql, $matched),
            sprintf('"%s" names a constraint of the copy', $sql),
        );

        return $matched[1];
    }
}
