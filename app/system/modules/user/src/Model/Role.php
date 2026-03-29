<?php

declare(strict_types=1);

namespace Pagekit\User\Model;

use Pagekit\Database\ORM\Attribute as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Role entity with PHP 8 Attributes for ORM and Validation.
 */
#[ORM\Entity(tableClass: '@system_role')]
class Role implements \JsonSerializable
{
    use RoleModelTrait;

    /**
     * The identifier of the anonymous role.
     */
    public const ROLE_ANONYMOUS = 1;

    /**
     * The identifier of the authenticated role.
     */
    public const ROLE_AUTHENTICATED = 2;

    /**
     * The identifier of the administrator role.
     */
    public const ROLE_ADMINISTRATOR = 3;

    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    #[Assert\NotBlank(message: 'validation.role.name_required')]
    #[Assert\Length(
        min: 2,
        max: 255,
        minMessage: 'validation.role.name_min_length',
        maxMessage: 'validation.role.name_max_length'
    )]
    public ?string $name = null;

    #[ORM\Column(type: 'integer')]
    #[Assert\PositiveOrZero(message: 'validation.role.priority_invalid')]
    public int $priority = 0;

    #[ORM\Column(type: 'simple_array')]
    public array $permissions = [];

    protected static array $properties = [
        'locked' => 'isLocked',
        'anonymous' => 'isAnonymous',
        'authenticated' => 'isAuthenticated',
        'administrator' => 'isAdministrator',
    ];

    /**
     * Check if the role has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions);
    }

    /**
     * Add a permission to the role.
     */
    public function addPermission(string $permission): void
    {
        $this->permissions[] = $permission;
    }

    /**
     * Clear all permissions from the role.
     */
    public function clearPermissions(): void
    {
        $this->permissions = [];
    }

    /**
     * Check if this is a system-locked role.
     */
    public function isLocked(): bool
    {
        return in_array($this->id, [self::ROLE_ANONYMOUS, self::ROLE_AUTHENTICATED, self::ROLE_ADMINISTRATOR]);
    }

    /**
     * Check if this is the anonymous role.
     */
    public function isAnonymous(): bool
    {
        return $this->id == self::ROLE_ANONYMOUS;
    }

    /**
     * Check if this is the authenticated role.
     */
    public function isAuthenticated(): bool
    {
        return $this->id == self::ROLE_AUTHENTICATED;
    }

    /**
     * Check if this is the administrator role.
     */
    public function isAdministrator(): bool
    {
        return $this->id == self::ROLE_ADMINISTRATOR;
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        return (string) $this->name;
    }
}
