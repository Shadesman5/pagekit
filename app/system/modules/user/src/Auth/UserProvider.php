<?php

declare(strict_types=1);

namespace Pagekit\User\Auth;

use Pagekit\Auth\Encoder\PasswordEncoderInterface;
use Pagekit\Auth\UserInterface;
use Pagekit\Auth\UserProviderInterface;
use Pagekit\User\Model\User;

class UserProvider implements UserProviderInterface
{
    protected \Pagekit\Auth\Encoder\PasswordEncoderInterface $encoder;

    /**
     * Constructor.
     *
     * @param PasswordEncoderInterface $encoder
     */
    public function __construct(PasswordEncoderInterface $encoder)
    {
        $this->encoder = $encoder;
    }

    /**
     * {@inheritdoc}
     */
    public function find($id): ?UserInterface
    {
        return User::find($id);
    }

    /**
     * {@inheritdoc}
     */
    public function findByUsername($username): ?UserInterface
    {
        return User::findByUsername($username);
    }

    /**
     * {@inheritdoc}
     *
     * @param array<string, mixed> $credentials
     */
    public function findByCredentials(array $credentials): ?UserInterface
    {
        if (isset($credentials['password'])) {
            unset($credentials['password']);
        }

        $entity = User::where($credentials)->first();
        if ($entity === null) {
            return null;
        }
        if (!$entity instanceof User) {
            throw new \LogicException(sprintf(
                'QueryBuilder::first() returned %s, expected %s',
                get_class($entity),
                User::class
            ));
        }

        return $entity;
    }

    /**
     * {@inheritdoc}
     *
     * @param array<string, mixed> $credentials
     */
    public function validateCredentials(UserInterface $user, array $credentials): bool
    {
        return $this->encoder->verify($user->getPassword(), $credentials['password']);
    }
}
