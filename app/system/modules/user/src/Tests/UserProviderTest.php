<?php

declare(strict_types=1);

namespace Pagekit\User\Tests;

use Pagekit\Auth\Encoder\PasswordEncoderInterface;
use Pagekit\Auth\UserInterface;
use Pagekit\User\Auth\UserProvider;
use Pagekit\User\Model\User;
use Pagekit\User\Model\UserRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see UserProvider}.
 *
 * The provider is a thin adapter over an injected {@see UserRepository}: `find()`,
 * `findByUsername()` and `findByCredentials()` delegate to the repository
 * (data-mapper reads), and `validateCredentials()` is the password gate. All of it
 * is fully unit testable with a mocked repository + encoder — no booted kernel and
 * no database.
 *
 * The central hydration type-guard (a non-`User` row surfacing from a query) is
 * covered once, at its choke point, by the EntityManager `load()` guard test
 * ({@see \Pagekit\Database\Tests\ORM\EntityManagerTest}); it no longer needs a
 * per-provider case here.
 */
class UserProviderTest extends TestCase
{
    // -----------------------------------------------------------------------
    // find() / findByUsername(): straight delegation to the repository.
    // -----------------------------------------------------------------------

    public function testFindDelegatesToRepository(): void
    {
        $user = new User();

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('find')->with('7')->willReturn($user);

        $this->assertSame($user, $this->provider($users)->find('7'));
    }

    public function testFindByUsernameDelegatesToRepository(): void
    {
        $user = new User();

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('findByUsername')->with('alice')->willReturn($user);

        $this->assertSame($user, $this->provider($users)->findByUsername('alice'));
    }

    // -----------------------------------------------------------------------
    // findByCredentials(): strip password, then delegate.
    // -----------------------------------------------------------------------

    /**
     * The `password` key must be dropped before the lookup: only the remaining
     * credentials reach the repository. A mutant that removes the `unset()` (or
     * negates the `isset` guard) would leak `password` into the delegated
     * condition and fail the `with()` matcher.
     */
    public function testFindByCredentialsStripsPasswordBeforeDelegating(): void
    {
        $user = new User();

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())
            ->method('findByCredentials')
            ->with(['username' => 'alice'])
            ->willReturn($user);

        $this->assertSame($user, $this->provider($users)->findByCredentials([
            'username' => 'alice',
            'password' => 'raw-candidate',
        ]));
    }

    /**
     * A miss from the repository must pass straight through as `null`.
     */
    public function testFindByCredentialsReturnsNullWhenRepositoryFindsNoUser(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findByCredentials')->willReturn(null);

        $this->assertNull($this->provider($users)->findByCredentials(['username' => 'ghost']));
    }

    // -----------------------------------------------------------------------
    // validateCredentials(): the authentication gate (pure, fully mockable).
    // -----------------------------------------------------------------------

    public function testValidateCredentialsReturnsTrueWhenEncoderAccepts(): void
    {
        $encoder = $this->createMock(PasswordEncoderInterface::class);
        $encoder->method('verify')->willReturn(true);

        $provider = new UserProvider($encoder, $this->createMock(UserRepository::class));

        $this->assertTrue(
            $provider->validateCredentials($this->userWithPassword('$2y$10$storedhash'), ['password' => 'raw-candidate'])
        );
    }

    public function testValidateCredentialsReturnsFalseWhenEncoderRejects(): void
    {
        $encoder = $this->createMock(PasswordEncoderInterface::class);
        $encoder->method('verify')->willReturn(false);

        $provider = new UserProvider($encoder, $this->createMock(UserRepository::class));

        $this->assertFalse(
            $provider->validateCredentials($this->userWithPassword('$2y$10$storedhash'), ['password' => 'raw-candidate'])
        );
    }

    /**
     * The verdict must come from verifying the raw candidate against the stored
     * hash in that exact order. Distinct hash/raw values make a swapped-argument
     * mutant (`verify($raw, $hash)`) fail the matcher, pinning the call that
     * gates authentication.
     */
    public function testValidateCredentialsVerifiesStoredHashAgainstRawPasswordInOrder(): void
    {
        $encoder = $this->createMock(PasswordEncoderInterface::class);
        $encoder->expects($this->once())
            ->method('verify')
            ->with('$2y$10$storedhash', 'raw-candidate')
            ->willReturn(true);

        $provider = new UserProvider($encoder, $this->createMock(UserRepository::class));

        $this->assertTrue(
            $provider->validateCredentials($this->userWithPassword('$2y$10$storedhash'), ['password' => 'raw-candidate'])
        );
    }

    // -----------------------------------------------------------------------
    // Fixtures.
    // -----------------------------------------------------------------------

    /**
     * Builds a provider backed by the given repository mock and a throwaway
     * encoder (the finder tests never reach password verification).
     */
    private function provider(UserRepository $users): UserProvider
    {
        return new UserProvider($this->createMock(PasswordEncoderInterface::class), $users);
    }

    /**
     * Builds a {@see UserInterface} whose `getPassword()` returns the stored hash.
     */
    private function userWithPassword(string $password): UserInterface
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getPassword')->willReturn($password);

        return $user;
    }
}
