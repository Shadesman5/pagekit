<?php

declare(strict_types=1);

namespace Pagekit\User\Model;

/**
 * @Entity(tableClass="@system_role")
 */
class Role implements \JsonSerializable
{
    use RoleModelTrait;

    /**
     * The identifier of the anonymous role.
     *
     * @var int
     */
    const ROLE_ANONYMOUS = 1;

    /**
     * The identifier of the authenticated role.
     *
     * @var int
     */
    const ROLE_AUTHENTICATED = 2;

    /**
     * The identifier of the administrator role.
     *
     * @var int
     */
    const ROLE_ADMINISTRATOR = 3;

    /** @Column(type="integer") @Id */
    public ?int $id = null;

    /** @Column(type="string") */
    public ?string $name = null;

    /** @Column(type="integer") */
    public int $priority = 0;

    /** @Column(type="simple_array") */
    public array $permissions = [];

    protected static array $properties = [
        'locked' => 'isLocked',
        'anonymous' => 'isAnonymous',
        'authenticated' => 'isAuthenticated',
        'administrator' => 'isAdministrator'
    ];

    /**
     * {@inheritdoc}
     */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions);
    }

    /**
     * {@inheritdoc}
     */
    public function addPermission(string $permission): void
    {
        $this->permissions[] = $permission;
    }

    /**
     * {@inheritdoc}
     */
    public function clearPermissions(): void
    {
        $this->permissions = [];
    }

    /**
     * {@inheritdoc}
     */
    public function isLocked(): bool
    {
        return in_array($this->id, [self::ROLE_ANONYMOUS, self::ROLE_AUTHENTICATED, self::ROLE_ADMINISTRATOR]);
    }

    /**
     * {@inheritdoc}
     */
    public function isAnonymous(): bool
    {
        return $this->id == self::ROLE_ANONYMOUS;
    }

    /**
     * {@inheritdoc}
     */
    public function isAuthenticated(): bool
    {
        return $this->id == self::ROLE_AUTHENTICATED;
    }

    /**
     * {@inheritdoc}
     */
    public function isAdministrator(): bool
    {
        return $this->id == self::ROLE_ADMINISTRATOR;
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string {
        return (string) $this->name;
    }
}
