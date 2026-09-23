<?php

declare(strict_types=1);

namespace Pagekit\Package\Snapshot;

/**
 * Turns the schema a dump holds for a table into the schema that creates the copy
 * of it a restore fills.
 *
 * Three things change and nothing else: the table each statement works on becomes
 * the copy ({@see RestoreTableNames}); a foreign key pointing at another table the
 * dump carries is pointed at that table's copy, while one pointing outside the dump
 * stays where it is, that table not being swapped; and constraint names are
 * replaced, MySQL keeping one of those per schema rather than one per table. Index
 * names are the table's own, and a copy is a table of its own, so they are left
 * alone.
 *
 * Which is why this reads SQL rather than replacing text in it: a column whose
 * default value is 'pk_users' holds a value the database was given and not the name
 * of a table, and a restore that rewrote it would put back a row that differs from
 * the one that was dumped. So a name is rewritten only where the grammar of the
 * statement says a table belongs, everything else is copied through byte for byte,
 * and a statement that is not one of the few a dump has any business carrying is
 * refused rather than run - a file is not a reason to run arbitrary SQL against the
 * database it is about to replace.
 *
 * @phpstan-type Token array{kind: string, value: string, at: int, length: int}
 */
final class ShadowSchema
{
    /**
     * A bare word: a keyword, or a name that needed no quoting. Which of the two
     * it is depends on where in the statement it stands.
     */
    private const WORD = 'word';

    /**
     * A name in backticks, quoted because of what it spells. Never a keyword.
     */
    private const QUOTED = 'quoted';

    /**
     * A value in quotes. Never a name, so never rewritten - the whole point of
     * reading the statement rather than searching it.
     */
    private const TEXT = 'text';

    /**
     * Anything else, one character at a time.
     */
    private const MARK = 'mark';

    /**
     * How much of a statement a refusal quotes back: enough to recognise it by,
     * short of putting a table's entire schema in an error message.
     */
    private const EXCERPT = 120;

    /**
     * The tables the dump carries, as a set to ask of a foreign key's target.
     *
     * @var array<string, true>
     */
    private readonly array $swapped;

    /**
     * What tells this restore's constraint names from those of any other.
     *
     * The live table may already carry a name a run of this made - restoring the
     * same snapshot twice is ordinary, and MySQL has no way to rename a
     * constraint afterwards - so a name derived from the dump alone would collide
     * with the one still in use.
     */
    private readonly string $run;

    /**
     * @param list<string> $dumped every table the dump carries, which is what says
     *                            whether a foreign key points at a table being
     *                            swapped or at one that stays where it is
     */
    public function __construct(array $dumped)
    {
        $this->swapped = array_fill_keys($dumped, true);
        $this->run = bin2hex(random_bytes(4));
    }

    /**
     * The statements that create one table's copy.
     *
     * @param  string            $table the table as the dump names it
     * @param  list<string>      $ddl   its schema as the dump holds it
     * @return list<string>      the same statements, working on the copy
     * @throws \RuntimeException where the dump holds a statement no copy can be created from
     */
    public function rewrite(string $table, array $ddl): array
    {
        $statements = [];

        foreach ($ddl as $statement) {
            $statements[] = $this->statement($table, $statement);
        }

        return $statements;
    }

    /**
     * One statement, working on the copy of the table instead of the table.
     *
     * @throws \RuntimeException where it is not a statement that creates, extends
     *                           or indexes exactly the table whose schema it is
     */
    private function statement(string $table, string $sql): string
    {
        $tokens = $this->tokens($sql);
        $target = $tokens === null ? null : $this->target($tokens);

        if ($tokens === null || $target === null || $this->identifier($tokens, $target) !== $table) {
            throw $this->refuse($table, $sql);
        }

        // Which token to put which name in place of, collected rather than applied,
        // so that what comes out is the statement that went in with nothing but
        // those tokens changed.
        $rewrites = [$target => RestoreTableNames::shadow($table)];
        $count = count($tokens);

        for ($at = $target + 1; $at < $count; $at++) {

            // One statement per statement. A file that puts a second one behind a
            // semicolon is asking for it to be run as it stands, which is how a
            // dump would get to do something other than restore a table.
            if ($at < $count - 1 && $this->mark($tokens, $at, ';')) {
                throw $this->refuse($table, $sql);
            }

            if ($this->keyword($tokens, $at, 'REFERENCES')) {
                $referenced = $this->identifier($tokens, $at + 1);

                if ($referenced === null) {
                    throw $this->refuse($table, $sql);
                }

                // A table the dump does not carry is not being swapped, and the
                // copy's key has to go on pointing at the live one. So does a name
                // from another schema, which is a table this restore does not
                // reach at all.
                if (isset($this->swapped[$referenced]) && !$this->mark($tokens, $at + 2, '.')) {
                    $rewrites[$at + 1] = RestoreTableNames::shadow($referenced);
                }

                continue;
            }

            // Only the two kinds whose names MySQL keeps one of per schema. What
            // follows the name says which kind it is, and a constraint written
            // without a name for the server to keep has nothing to replace.
            if ($this->keyword($tokens, $at, 'CONSTRAINT')
                && ($this->keyword($tokens, $at + 2, 'FOREIGN') || $this->keyword($tokens, $at + 2, 'CHECK'))) {
                $name = $this->identifier($tokens, $at + 1);

                if ($name === null) {
                    throw $this->refuse($table, $sql);
                }

                $rewrites[$at + 1] = $this->constraint($table, $name);
            }
        }

        return $this->splice($sql, $tokens, $rewrites);
    }

    /**
     * Which token names the table the statement works on, or null where the
     * statement is not one of the three a dump may carry: creating a table, adding
     * something to one, indexing one.
     *
     * Each shape is matched to the token that follows the name as well, so that a
     * statement doing something else with a table it names - creating it from
     * another table, renaming it, dropping something off it - is not taken for one
     * of these.
     *
     * @param list<Token> $tokens
     */
    private function target(array $tokens): ?int
    {
        if ($this->keyword($tokens, 0, 'ALTER') && $this->keyword($tokens, 1, 'TABLE')) {
            return $this->keyword($tokens, 3, 'ADD') ? 2 : null;
        }

        if (!$this->keyword($tokens, 0, 'CREATE')) {
            return null;
        }

        $at = 1;

        if ($this->keyword($tokens, $at, 'TEMPORARY')) {
            $at++;
        }

        if ($this->keyword($tokens, $at, 'TABLE')) {
            $at++;

            if ($this->keyword($tokens, $at, 'IF') && $this->keyword($tokens, $at + 1, 'NOT') && $this->keyword($tokens, $at + 2, 'EXISTS')) {
                $at += 3;
            }

            return $this->mark($tokens, $at + 1, '(') ? $at : null;
        }

        foreach (['UNIQUE', 'FULLTEXT', 'SPATIAL'] as $kind) {
            if ($this->keyword($tokens, $at, $kind)) {
                $at++;

                break;
            }
        }

        if (!$this->keyword($tokens, $at, 'INDEX')) {
            return null;
        }

        // CREATE INDEX <the index's own name, which stays> ON <the table> (
        return $this->identifier($tokens, $at + 1) !== null
            && $this->keyword($tokens, $at + 2, 'ON')
            && $this->mark($tokens, $at + 4, '(')
                ? $at + 3
                : null;
    }

    /**
     * A name for one of the copy's constraints.
     *
     * Short by construction rather than the dumped name behind a marker, which
     * would push a name that is already near what MySQL allows past it. What it is
     * derived from - this run, this table, the name the dump gave it - is what
     * makes it unique across the schema, which is the scope MySQL keeps
     * constraint names in.
     */
    private function constraint(string $table, string $name): string
    {
        // The same namespace the copies themselves are named in: reserved, so no
        // name here can be one an installation owns.
        return RestoreTableNames::SHADOW.substr(hash('sha256', $this->run."\0".$table."\0".$name), 0, 24);
    }

    /**
     * The statement with the named tokens replaced, and every other byte of it
     * where it was.
     *
     * @param list<Token>        $tokens
     * @param array<int, string> $rewrites what to put in place of which token
     */
    private function splice(string $sql, array $tokens, array $rewrites): string
    {
        $written = '';
        $at = 0;

        // Walked in the order the statement is written in, so that what is copied
        // between two replacements is what stood between them.
        foreach ($tokens as $index => $token) {
            if (!isset($rewrites[$index])) {
                continue;
            }

            // Quoted on the way out whether or not it was on the way in: what is
            // being written is a name this machinery made up, and a name in
            // backticks is read as a name whatever it spells.
            $written .= substr($sql, $at, $token['at'] - $at).'`'.str_replace('`', '``', $rewrites[$index]).'`';
            $at = $token['at'] + $token['length'];
        }

        return $written.substr($sql, $at);
    }

    /**
     * The statement as the server would read it, or null where it cannot be read
     * to the end at all.
     *
     * @return list<Token>|null
     */
    private function tokens(string $sql): ?array
    {
        $tokens = [];
        $length = strlen($sql);
        $at = 0;

        while ($at < $length) {
            $character = $sql[$at];

            if (ctype_space($character)) {
                $at++;

                continue;
            }

            // Comments are read past rather than through. A table name in one is
            // not a table the statement works on, and a lone quote in one would
            // otherwise take the rest of the statement with it.
            if ($character === '#' || ($character === '-' && ($sql[$at + 1] ?? '') === '-' && ctype_space($sql[$at + 2] ?? "\n"))) {
                $end = strpos($sql, "\n", $at);
                $at = $end === false ? $length : $end + 1;

                continue;
            }

            if ($character === '/' && ($sql[$at + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $at + 2);

                if ($end === false) {
                    return null;
                }

                $at = $end + 2;

                continue;
            }

            if ($character === '`' || $character === "'" || $character === '"') {
                $end = $this->closed($sql, $at);

                if ($end === null) {
                    return null;
                }

                $tokens[] = [
                    'kind' => $character === '`' ? self::QUOTED : self::TEXT,
                    // Only a name is ever compared or rewritten, so only a name is
                    // worth carrying the text of.
                    'value' => $character === '`' ? str_replace('``', '`', substr($sql, $at + 1, $end - $at - 2)) : '',
                    'at' => $at,
                    'length' => $end - $at,
                ];
                $at = $end;

                continue;
            }

            $word = $this->word($sql, $at);

            if ($word !== '') {
                $tokens[] = ['kind' => self::WORD, 'value' => $word, 'at' => $at, 'length' => strlen($word)];
                $at += strlen($word);

                continue;
            }

            $tokens[] = ['kind' => self::MARK, 'value' => $character, 'at' => $at, 'length' => 1];
            $at++;
        }

        return $tokens;
    }

    /**
     * The bare word at this point, or nothing where what stands there is not one.
     *
     * Letters, digits, the two other characters an unquoted MySQL name may hold,
     * and anything above ASCII, which one may hold as well.
     */
    private function word(string $sql, int $at): string
    {
        $length = strlen($sql);
        $end = $at;

        while ($end < $length) {
            $character = $sql[$end];

            if (!ctype_alnum($character) && $character !== '_' && $character !== '$' && ord($character) < 0x80) {
                break;
            }

            $end++;
        }

        return substr($sql, $at, $end - $at);
    }

    /**
     * Where the quoted run that starts here ends, or null where it never does.
     */
    private function closed(string $sql, int $at): ?int
    {
        $quote = $sql[$at];
        $length = strlen($sql);
        $end = $at + 1;

        while ($end < $length) {
            $character = $sql[$end];

            // Inside a value a backslash escapes what follows it, which is how the
            // server reads one unless it has been told not to; inside a name it is
            // a character like any other.
            if ($character === '\\' && $quote !== '`') {
                $end += 2;

                continue;
            }

            if ($character !== $quote) {
                $end++;

                continue;
            }

            // Doubled, the quote is part of what it encloses rather than its end.
            if (($sql[$end + 1] ?? '') === $quote) {
                $end += 2;

                continue;
            }

            return $end + 1;
        }

        return null;
    }

    /**
     * The name at this point, or null where what stands there is not one.
     *
     * @param list<Token> $tokens
     */
    private function identifier(array $tokens, int $at): ?string
    {
        $token = $tokens[$at] ?? null;

        if ($token === null || ($token['kind'] !== self::WORD && $token['kind'] !== self::QUOTED)) {
            return null;
        }

        return $token['value'];
    }

    /**
     * Whether a given keyword stands at this point.
     *
     * A name in backticks is never one, however it is spelled - that is what the
     * backticks say. A bare word is matched without regard to case, as the server
     * matches a keyword.
     *
     * @param list<Token> $tokens
     */
    private function keyword(array $tokens, int $at, string $word): bool
    {
        $token = $tokens[$at] ?? null;

        return $token !== null && $token['kind'] === self::WORD && strcasecmp($token['value'], $word) === 0;
    }

    /**
     * Whether a given single character stands at this point.
     *
     * @param list<Token> $tokens
     */
    private function mark(array $tokens, int $at, string $character): bool
    {
        $token = $tokens[$at] ?? null;

        return $token !== null && $token['kind'] === self::MARK && $token['value'] === $character;
    }

    /**
     * Why no copy can be made from this statement.
     *
     * One answer for every way a statement can fail to be one a copy can be
     * created from, because whoever reads it has the same part in all of them:
     * this file is not one this installation can restore from.
     */
    private function refuse(string $table, string $sql): \RuntimeException
    {
        return new \RuntimeException(sprintf(
            'The schema the database dump holds for the table "%s" is not something a restore can create its copy of that table from ("%s"). A dump carries the statements that create a table, and a restore runs no others.',
            $table,
            $this->excerpt($sql),
        ));
    }

    /**
     * As much of a statement as is worth putting in a message, on one line.
     */
    private function excerpt(string $sql): string
    {
        $statement = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);

        if (mb_strlen($statement, 'UTF-8') <= self::EXCERPT) {
            return $statement;
        }

        return mb_substr($statement, 0, self::EXCERPT, 'UTF-8').'...';
    }
}
