<?php

declare(strict_types=1);

namespace Pagekit\User\Model;

use Pagekit\Auth\UserInterface;
use Pagekit\Database\ORM\Attribute as ORM;
use Pagekit\System\Model\DataModelTrait;
use Pagekit\System\Validator\Constraints as PagekitAssert;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * User entity with PHP 8 Attributes for ORM and Validation.
 */
#[ORM\Entity(tableClass: '@system_user')]
class User implements UserInterface, \JsonSerializable
{
    use AccessModelTrait;
    use DataModelTrait;
    use UserModelTrait;

    /**
     * The blocked status.
     */
    public const STATUS_BLOCKED = 0;

    /**
     * The active status.
     */
    public const STATUS_ACTIVE = 1;

    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    public ?int $id = null;

    #[ORM\Column]
    #[Assert\NotBlank(message: 'validation.user.username_required')]
    #[Assert\Length(
        min: 3,
        max: 255,
        minMessage: 'validation.user.username_min_length',
        maxMessage: 'validation.user.username_max_length'
    )]
    #[Assert\Regex(
        pattern: '/^[a-zA-Z0-9._\-]+$/',
        message: 'validation.user.username_invalid'
    )]
    #[PagekitAssert\Unique(
        table: '@system_user',
        column: 'username',
        message: 'validation.user.username_not_available'
    )]
    public ?string $username = '';

    #[ORM\Column]
    #[Assert\NotBlank(message: 'validation.user.password_required', groups: ['registration'])]
    public ?string $password = '';

    #[ORM\Column]
    #[Assert\NotBlank(message: 'validation.user.email_required')]
    #[Assert\Email(message: 'validation.user.email_invalid')]
    #[PagekitAssert\Unique(
        table: '@system_user',
        column: 'email',
        message: 'validation.user.email_not_available'
    )]
    public ?string $email = '';

    #[ORM\Column]
    #[Assert\Url(message: 'validation.user.url_invalid')]
    public ?string $url = '';

    #[ORM\Column(type: 'datetime')]
    public ?\DateTime $registered = null;

    #[ORM\Column(type: 'integer')]
    #[Assert\Choice(
        choices: [self::STATUS_BLOCKED, self::STATUS_ACTIVE],
        message: 'validation.user.status_invalid'
    )]
    public int $status = User::STATUS_ACTIVE;

    #[ORM\Column]
    #[Assert\NotBlank(message: 'validation.user.name_required')]
    #[Assert\Length(
        max: 255,
        maxMessage: 'validation.user.name_max_length'
    )]
    public ?string $name = null;

    #[ORM\Column(type: 'datetime')]
    public ?\DateTime $login = null;

    #[ORM\Column]
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
            self::STATUS_BLOCKED => __('Blocked'),
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

        $exp = preg_replace('/[^01&\(\)\|!]/', '', preg_replace_callback('/[a-z_][a-z-_\.:\d\s]*/i', fn ($permission) => (int) $user->hasPermission(trim($permission[0])), $expression));

        try {
            return self::evaluateBooleanExpression((string) $exp);
        } catch (\Throwable) {
            throw new \InvalidArgumentException(
                sprintf('Unable to parse the given access string "%s"', $expression)
            );
        }
    }

    /**
     * Evaluate a sanitized boolean expression composed of `0`, `1`, `&`, `&&`,
     * `|`, `||`, `!` and parentheses using a recursive-descent parser.
     *
     * Replaces the legacy `create_function()` based evaluator. Pure PHP — no
     * `eval()`, no `Closure::fromCallable`, no `assert()`, no
     * `ExpressionLanguage` dependency.
     *
     * Grammar:
     *   expr    -> orExpr
     *   orExpr  -> andExpr ( ( '||' | '|' ) andExpr )*
     *   andExpr -> notExpr ( ( '&&' | '&' ) notExpr )*
     *   notExpr -> '!' notExpr | atom
     *   atom    -> '(' expr ')' | '0' | '1'
     *
     * Precedence: `!` > `&&` > `||`.
     *
     * @throws \InvalidArgumentException on malformed input.
     */
    private static function evaluateBooleanExpression(string $exp): bool
    {
        $pos = 0;
        $len = strlen($exp);

        $result = self::parseOrExpr($exp, $len, $pos);

        if ($pos !== $len) {
            throw new \InvalidArgumentException(sprintf('Unexpected trailing input at position %d', $pos));
        }

        return $result;
    }

    private static function parseOrExpr(string $exp, int $len, int &$pos): bool
    {
        $left = self::parseAndExpr($exp, $len, $pos);

        while ($pos < $len && $exp[$pos] === '|') {
            $pos++;
            if ($pos < $len && $exp[$pos] === '|') {
                $pos++;
            }
            $right = self::parseAndExpr($exp, $len, $pos);
            $left = $left || $right;
        }

        return $left;
    }

    private static function parseAndExpr(string $exp, int $len, int &$pos): bool
    {
        $left = self::parseNotExpr($exp, $len, $pos);

        while ($pos < $len && $exp[$pos] === '&') {
            $pos++;
            if ($pos < $len && $exp[$pos] === '&') {
                $pos++;
            }
            $right = self::parseNotExpr($exp, $len, $pos);
            $left = $left && $right;
        }

        return $left;
    }

    private static function parseNotExpr(string $exp, int $len, int &$pos): bool
    {
        if ($pos < $len && $exp[$pos] === '!') {
            $pos++;

            return !self::parseNotExpr($exp, $len, $pos);
        }

        return self::parseAtom($exp, $len, $pos);
    }

    private static function parseAtom(string $exp, int $len, int &$pos): bool
    {
        if ($pos >= $len) {
            throw new \InvalidArgumentException('Unexpected end of expression');
        }

        $ch = $exp[$pos];

        if ($ch === '(') {
            $pos++;
            $value = self::parseOrExpr($exp, $len, $pos);
            if ($pos >= $len || $exp[$pos] !== ')') {
                throw new \InvalidArgumentException('Missing closing parenthesis');
            }
            $pos++;

            return $value;
        }

        if ($ch === '0' || $ch === '1') {
            $pos++;

            return $ch === '1';
        }

        throw new \InvalidArgumentException(sprintf('Unexpected character "%s" at position %d', $ch, $pos));
    }

    /**
     * {@inheritdoc}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray([], ['password', 'activation']);
    }
}
