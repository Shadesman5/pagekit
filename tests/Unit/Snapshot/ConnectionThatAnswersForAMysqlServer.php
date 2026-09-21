<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Pagekit\Database\Connection;

/**
 * An installation that reads as being on MySQL, and a server that answers the
 * questions a restore's refusals put to one.
 *
 * What a restore refuses on MySQL it has to refuse before it has made anything,
 * and part of what it needs in order to decide is the server's own: whether table
 * names are matched without regard to case, which foreign key points at which
 * table, and whether the lock one restore of an installation runs under is to be
 * had. None is something an installation can be set up to say, and on a run
 * against SQLite none has an answer at all - so all of them are answered here, and
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
 *
 * A restore that comes through the refusals asks a server more than this, which is
 * what {@see ConnectionThatWillNotSwapTables} answers.
 */
class ConnectionThatAnswersForAMysqlServer extends Connection
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
     * Whether another session already holds the lock a restore of this
     * installation runs under. One restore at a time is the server's to keep,
     * and a run with no server behind it has no second session to take it with.
     */
    public bool $locked = false;

    /**
     * Whether the driver hands the numbers the server answers with back as text.
     * One preparing its statements on the server gives a number back as a number,
     * one emulating prepares gives the same answer back as "1" - the same lock
     * either way, and a restore reading only the first would refuse every restore
     * of every installation behind the second.
     */
    public bool $handsNumbersBackAsText = false;

    /**
     * Every lock the restore asked the server for, by name and in order.
     *
     * The name never appears in the statement that asks for it - the connection
     * substitutes this installation's table prefix for an @-led name anywhere
     * outside quotes, so it has to arrive as a value - which leaves this the only
     * place a test can read what a restore locks on.
     *
     * @var list<string>
     */
    public array $locksAskedFor = [];

    /**
     * How long the restore said it would wait for a lock another session holds,
     * each time it asked. Nought is an answer whoever is waiting on a page can be
     * given; a wait is one nobody is there for.
     *
     * @var list<int>
     */
    public array $waitsAskedFor = [];

    /**
     * Every lock the restore gave up again, by name and in order. A lock held past
     * the request that took it is every later restore of the installation refused.
     *
     * @var list<string>
     */
    public array $locksGivenUp = [];

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
        return $this->platformUnderneath()->createSchemaManager($this);
    }

    /**
     * The lock one restore of an installation runs under, which only a server can
     * hand out: two restores at once are two processes, and neither sees the
     * other. Taken unless the test says another session is holding it.
     *
     * @param array<int|string, mixed> $params
     * @param array<int|string, mixed> $types
     */
    public function fetchOne(string $query, array $params = [], array $types = []): mixed
    {
        if (str_contains($query, 'GET_LOCK')) {
            $this->asked[] = $query;
            $this->locksAskedFor[] = self::nameIn($params);
            $this->waitsAskedFor[] = is_int($params[1] ?? null) ? $params[1] : -1;

            return $this->answerAboutTheLock();
        }

        if (str_contains($query, 'RELEASE_LOCK')) {
            $this->asked[] = $query;
            $this->locksGivenUp[] = self::nameIn($params);

            return 1;
        }

        return parent::fetchOne($query, $params, $types);
    }

    /**
     * What the server says when the lock is asked for: the lock itself, or nought
     * where the test says another session is holding it - spelled the way the
     * driver under the connection spells a number.
     */
    protected function answerAboutTheLock(): mixed
    {
        $taken = $this->locked ? 0 : 1;

        return $this->handsNumbersBackAsText ? (string) $taken : $taken;
    }

    /**
     * The lock a statement names, which it names as a value.
     *
     * @param array<int|string, mixed> $params
     */
    private static function nameIn(array $params): string
    {
        $name = $params[0] ?? null;

        return is_string($name) ? $name : '';
    }

    /**
     * Which database the connection is on, asked the way the platform underneath
     * asks it: the schema manager above is that platform's, and MySQL's way of
     * asking is not a question SQLite answers.
     */
    public function getDatabase(): ?string
    {
        $database = $this->fetchOne('SELECT '.$this->platformUnderneath()->getCurrentDatabaseExpression());

        return is_string($database) ? $database : null;
    }

    /**
     * The platform of the database that is really there: MySQL itself on a run
     * with a server behind it, and SQLite on one without. What is answered as a
     * server's is answered above; what is carried out has to be carried out by
     * whichever of the two is under the connection.
     */
    protected function platformUnderneath(): AbstractPlatform
    {
        return parent::getDatabasePlatform();
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
