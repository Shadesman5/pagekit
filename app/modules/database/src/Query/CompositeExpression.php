<?php

declare(strict_types=1);

namespace Pagekit\Database\Query;

class CompositeExpression implements \Countable
{
    /**
     * Constant that represents an AND composite expression.
     */
    public const TYPE_AND = 'AND';

    /**
     * Constant that represents an OR composite expression.
     */
    public const TYPE_OR = 'OR';

    /**
     * The instance type of composite expression.
     */
    protected string $type;

    /**
     * Each expression part of the composite expression.
     *
     * @var array<int, mixed>
     */
    protected array $parts = [];

    /**
     * Constructor.
     *
     * @param array<int, mixed> $parts
     */
    public function __construct(string $type, array $parts = [])
    {
        $this->type = $type;
        $this->addMultiple($parts);
    }

    /**
     * Returns the type of this composite expression (AND/OR).
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Adds an expression to composite expression.
     */
    public function add(mixed $part): self
    {
        if (!empty($part) || ($part instanceof self && $part->count() > 0)) {
            $this->parts[] = $part;
        }

        return $this;
    }

    /**
     * Adds multiple parts to composite expression.
     *
     * @param array<int, mixed> $parts
     */
    public function addMultiple(array $parts = []): self
    {
        foreach ((array) $parts as $part) {
            $this->add($part);
        }

        return $this;
    }

    /**
     * Retrieves the amount of expressions on composite expression.
     */
    public function count(): int
    {
        return count($this->parts);
    }

    /**
     * Retrieves the string representation of this composite expression.
     *
     * @return string
     */
    public function __toString()
    {
        if (count($this->parts) === 1) {
            return (string) $this->parts[0];
        }

        return '(' . implode(') ' . $this->type . ' (', $this->parts) . ')';
    }
}
