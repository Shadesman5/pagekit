<?php

declare(strict_types=1);

namespace Pagekit\User\Model;

use Pagekit\Auth\UserInterface;
use Pagekit\System\Model\DataModelTrait;
use Pagekit\System\Validator\Constraints as PagekitAssert;
use Pagekit\User\Model\AccessModelTrait;
use Pagekit\User\Model\UserModelTrait;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * User entity with Symfony Validator integration (Hybrid Mode).
 *
 * Validation: Uses PHP 8 Attributes (#[Assert\...])
 * ORM: Still uses Doctrine Annotations (@Entity, @Column) - TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
 *
 * @Entity(tableClass="@system_user")
 */
class User implements UserInterface, \JsonSerializable
{
    use AccessModelTrait, DataModelTrait, UserModelTrait;

    /**
     * The blocked status.
     */
    public const STATUS_BLOCKED = 0;

    /**
     * The active status.
     */
    public const STATUS_ACTIVE = 1;

    /**
     * @Column(type="integer") @Id
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public ?int $id = null;

    /**
     * @Column
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\NotBlank(message: 'Username is required.')]
    #[Assert\Length(
        min: 3,
        max: 255,
        minMessage: 'Username must be at least {{ limit }} characters.',
        maxMessage: 'Username cannot exceed {{ limit }} characters.'
    )]
    #[Assert\Regex(
        pattern: '/^[a-zA-Z0-9._\-]+$/',
        message: 'Username is invalid. Only letters, numbers, dots, underscores and hyphens are allowed.'
    )]
    #[PagekitAssert\Unique(
        table: '@system_user',
        column: 'username',
        message: 'Username is not available.'
    )]
    public ?string $username = '';

    /**
     * @Column
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\NotBlank(message: 'Password is required.', groups: ['registration'])]
    public ?string $password = '';

    /**
     * @Column
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\NotBlank(message: 'Email is required.')]
    #[Assert\Email(message: 'Email is invalid.')]
    #[PagekitAssert\Unique(
        table: '@system_user',
        column: 'email',
        message: 'Email is not available.'
    )]
    public ?string $email = '';

    /**
     * @Column
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\Url(message: 'URL is invalid.')]
    public ?string $url = '';

    /**
     * @Column(type="datetime")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public ?\DateTime $registered = null;

    /**
     * @Column(type="integer")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\Choice(
        choices: [self::STATUS_BLOCKED, self::STATUS_ACTIVE],
        message: 'Invalid status.'
    )]
    public int $status = User::STATUS_ACTIVE;

    /**
     * @Column
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    #[Assert\NotBlank(message: 'Name is required.')]
    #[Assert\Length(
        max: 255,
        maxMessage: 'Name cannot exceed {{ limit }} characters.'
    )]
    public ?string $name = null;

    /**
     * @Column(type="datetime")
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public ?\DateTime $login = null;

    /**
     * @Column
     */
    // TODO: Must be refactored in Step 1.14 (ORM Attributes migration)
    public ?string $activation = null;

    protected ?array $permissions = null;

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return (string) $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getUsername(): string
    {
        return (string) $this->username;
    }

    /**
     * {@inheritdoc}
     */
    public function getPassword(): string
    {
        return (string) $this->password;
    }

    public function getStatusText(): string
    {
        $statuses = self::getStatuses();

        return $statuses[$this->status] ?? __('Unknown');
    }

    public static function getStatuses(): array
    {
        return [
            self::STATUS_ACTIVE => __('Active'),
            self::STATUS_BLOCKED => __('Blocked')
        ];
    }

    /**
     * Check if the user has the anonymous role.
     */
    public function isAnonymous(): bool
    {
        return $this->hasRole(Role::ROLE_ANONYMOUS);
    }

    /**
     * Check if the user has the authenticated role.
     */
    public function isAuthenticated(): bool
    {
        return $this->hasRole(Role::ROLE_AUTHENTICATED);
    }

    /**
     * Check if the user has the administrator role.
     */
    public function isAdministrator(): bool
    {
        return $this->hasRole(Role::ROLE_ADMINISTRATOR);
    }

    /**
     * Check if the user is active.
     */
    public function isActive(): bool
    {
        return $this->status == self::STATUS_ACTIVE;
    }

    /**
     * Check if the user is blocked.
     */
    public function isBlocked(): bool
    {
        return $this->status == self::STATUS_BLOCKED;
    }

    /**
     * Check if the user has access for a provided permission identifier
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->permissions === null) {

            $this->permissions = [];
            foreach (self::findRoles($this) as $role) {
                $this->permissions = array_merge($this->permissions, $role->permissions);
            }

        }

        return in_array($permission, $this->permissions);
    }

    /**
     * Check if the user has access for a provided access expression.
     *
     * Expression forms:
     *   - a single permission string starting with a letter and consisting of letters, numbers and characters .:-_ and whitespace
     *   - a boolean expression with multiple permissions and operators like &&, || and (...) parenthesis
     *
     * Examples:
     *   - a single permission string can be "create_posts", "create posts", "posts:create" etc.
     *   - a boolean expression with multiple permissions boolean expression can be "create_posts && delete_posts", "(create posts && delete posts) || manage posts" etc.
     *
     * @throws \InvalidArgumentException
     */
    public function hasAccess(?string $expression): bool
    {
        $user = $this;

        if ($this->isAdministrator() || empty($expression)) {
            return true;
        }

        if (!preg_match('/[&\(\)\|\!]/', $expression)) {
            return $this->hasPermission($expression);
        }

        $exp = preg_replace('/[^01&\(\)\|!]/', '', preg_replace_callback('/[a-z_][a-z-_\.:\d\s]*/i', fn($permission) => (int) $user->hasPermission(trim($permission[0])), $expression));

        if (!$fn = @create_function("", "return $exp;")) {
            throw new \InvalidArgumentException(sprintf('Unable to parse the given access string "%s"', $expression));
        }

        return (bool) $fn();
    }

    // NOTE: The old validate() method has been REMOVED per Rule #4 (DELETE OVER WRAP).
    // All validation is now handled by Symfony Validator attributes on the properties.
    // Controllers must use ValidatesRequestTrait::validate($user) or ValidatesRequestTrait::validateOrFail($user).

    /**
     * {@inheritdoc}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray([], ['password', 'activation']);
    }
}
