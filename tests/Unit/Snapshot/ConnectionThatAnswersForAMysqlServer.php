<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Pagekit\Database\Connection;

/**
 * An installation that reads as being on MySQL, and a server that answers the two
 * questions a restore's refusals put to one.
 *
 * What a restore refuses on MySQL it has to refuse before it has made anything,
 * and part of what it needs in order to decide is the server's own: whether table
 * names are matched without regard to case, and which foreign key points at which
 * table. Neither is something an installation can be set up to say, and on a run
 * against SQLite neither has an answer at all - so both are answered here, and
 * what the server would say is the test's to choose. A run with a real server
 * behind it answers them the same way, which is what makes the refusals
 * assertable on either.
 *
 * Nothing else is stood in for. The tables are real tables in the database the
 * test opened, listed through the platform that is actually under the connection,
 * and what an assertion reads back afterwards is what is there - so "the
 * installation was left whole" is read off the database rather than off a
 * recording. Each answer is noted down as it is asked for, which is how a test
 * tells a refusal that came before the server was ever reached from one that
 * needed it.
 */
final class ConnectionThatAnswersForAMysqlServer extends Connection
{
    /**
     * What a restore is told when it asks the server for anything past the
     * refusals. Getting that far means it came through all of them and went on to
     * put the tables back, which is the one thing no stand-in can be asked for.
     */
    private const PAST_THE_REFUSALS = 'This connection answers for a MySQL server as far as a restore\'s refusals reach, and this restore has gone past them.';

    /**
     * What the server says when asked whether it matches table names without
     * regard to case: "1" and "2" are the two settings that do, and null is a
     * server that gives no answer at all.
     */
    public ?string $folding = '0';

    /**
     * The foreign keys the server reports, each one as its catalogue hands it
     * over: the constraint, the table holding it, and the table it points at.
     *
     * @var list<array{name: string, child: string, parent: string}>
     */
    public array $references = [];

    /**
     * Every question a restore put to the server here, in the order it asked
     * them.
     *
     * @var list<string>
     */
    public array $asked = [];

    public function getDatabasePlatform(): AbstractPlatform
    {
        return new MySQL80Platform();
    }

    /**
     * Tables are listed out of the database that is really there, so a test
     * plants the ones it wants found rather than declaring them.
     */
    public function createSchemaManager(): AbstractSchemaManager
    {
        return parent::getDatabasePlatform()->createSchemaManager($this);
    }

    /**
     * Which database the connection is on, asked the way the platform underneath
     * asks it: the schema manager above is that platform's, and MySQL's way of
     * asking is not a question SQLite answers.
     */
    public function getDatabase(): ?string
    {
        $platform = parent::getDatabasePlatform();
        $database = $this->fetchOne('SELECT '.$platform->getCurrentDatabaseExpression());

        return is_string($database) ? $database : null;
    }

    /**
     * How the server matches table names, and a refusal to answer anything else
     * only a server could answer.
     *
     * @param  array<int|string, mixed>   $params
     * @param  array<int|string, mixed>   $types
     * @return array<string, mixed>|false
     */
    public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
    {
        if (str_contains($query, 'lower_case_table_names')) {
            $this->asked[] = $query;

            return $this->folding === null ? false : ['Variable_name' => 'lower_case_table_names', 'Value' => $this->folding];
        }

        if (str_starts_with($query, 'SHOW')) {
            throw new \RuntimeException(self::PAST_THE_REFUSALS);
        }

        return parent::fetchAssociative($query, $params, $types);
    }

    /**
     * The foreign keys the catalogue reports, which on a real server is every one
     * in the schema and here is the set the test named.
     *
     * @param  array<int|string, mixed>        $params
     * @param  array<int|string, mixed>        $types
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        if (str_contains($query, 'REFERENTIAL_CONSTRAINTS')) {
            $this->asked[] = $query;

            return $this->references;
        }

        return parent::fetchAllAssociative($query, $params, $types);
    }
}
