<?php

declare(strict_types=1);

namespace Pagekit\System\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * Constraint to validate that a value is unique in the database.
 *
 * Usage:
 * ```php
 * #[Unique(
 *     table: '@system_user',
 *     column: 'username',
 *     message: 'Username is not available.'
 * )]
 * public ?string $username = '';
 * ```
 *
 * Note: This constraint uses Pagekit's QueryBuilder (DBAL 3.x compatible).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
class Unique extends Constraint
{
    public string $message = 'This value already exists.';
    public string $table;
    public string $column;
    public ?string $idProperty = 'id';

    /**
     * @param string $table The database table name (can use @prefix syntax)
     * @param string $column The column to check for uniqueness
     * @param string|null $message Custom error message
     * @param string|null $idProperty The property name that holds the entity ID (for update exclusion)
     * @param array|null $groups Validation groups
     * @param mixed $payload Additional payload data
     */
    public function __construct(
        string $table,
        string $column,
        ?string $message = null,
        ?string $idProperty = 'id',
        ?array $groups = null,
        mixed $payload = null
    ) {
        parent::__construct([], $groups, $payload);
        $this->table = $table;
        $this->column = $column;
        $this->idProperty = $idProperty;

        if ($message !== null) {
            $this->message = $message;
        }
    }
}
