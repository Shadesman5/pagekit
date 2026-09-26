<?php

declare(strict_types=1);

namespace Pagekit\Routing\Attribute;

/**
 * Access control on a controller class or method.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Access
{
    private ?string $expression;
    private ?bool $admin;

    /**
     * @param string|null $expression Access permission expression
     * @param bool|null $admin Whether admin access is required
     */
    public function __construct(
        ?string $expression = null,
        ?bool $admin = null
    ) {
        $this->expression = $expression;
        $this->admin = $admin;
    }

    /**
     * Gets the access expression.
     */
    public function getExpression(): ?string
    {
        return $this->expression;
    }

    /**
     * Gets admin option.
     */
    public function getAdmin(): ?bool
    {
        return $this->admin;
    }
}
