<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

/**
 * A MySQL server that will not say whether the lock a restore runs under is to be
 * had.
 *
 * Asked for a lock, a server says it is yours, or that another session is holding
 * it, or nothing at all - it ran out of memory, or the session waiting was killed.
 * Nothing at all is not a lock, and a restore that took it for one would be the
 * second one running: two restores filling the same copies of the same tables,
 * which is what the lock exists to make impossible.
 */
final class ConnectionThatWillNotSayWhoHoldsTheLock extends ConnectionThatAnswersForAMysqlServer
{
    /**
     * What the server hands back in place of an answer.
     */
    public mixed $answer = null;

    protected function answerAboutTheLock(): mixed
    {
        return $this->answer;
    }
}
