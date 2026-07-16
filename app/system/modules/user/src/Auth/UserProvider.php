<?php

declare(strict_types=1);

namespace Pagekit\User\Auth;

use Pagekit\Auth\Encoder\PasswordEncoderInterface;
use Pagekit\Auth\UserInterface;
use Pagekit\Auth\UserProviderInterface;
use Pagekit\User\Model\UserRepository;

class UserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly PasswordEncoderInterface $encoder,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function find($id): ?UserInterface
    {
        return $this->users->find($id);
    }

    /**
     * {@inheritdoc}
     */
    public function findByUsername($username): ?UserInterface
    {
        return $this->users->findByUsername($username);
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

        return $this->users->findByCredentials($credentials);
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
