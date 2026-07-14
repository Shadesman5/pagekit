<?php

declare(strict_types=1);

namespace Pagekit\User\Tests;

use Doctrine\DBAL\Result;
use Pagekit\Auth\Encoder\PasswordEncoderInterface;
use Pagekit\Auth\UserInterface;
use Pagekit\Database\Connection;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Metadata;
use Pagekit\Database\ORM\MetadataManager;
use Pagekit\Database\Query\QueryBuilder as DbalQueryBuilder;
use Pagekit\Event\EventDispatcherInterface;
use Pagekit\User\Auth\UserProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see UserProvider}.
 *
 * `validateCredentials()` is the authentication gate and is fully unit-testable
 * with a mocked {@see PasswordEncoderInterface}: it gets the bulk of the
 * coverage here (verdict pass-through for both outcomes plus the exact
 * hash/raw argument order, which is what actually decides a login).
 *
 * `findByCredentials()` is exercised as far as feasible without a database — its
 * `password`-stripping precondition and the empty-result `null` branch — by
 * driving `User::where()` through a mock-backed ORM (a real {@see EntityManager}
 * wired to mock Connection/Metadata, mirroring
 * {@see \Pagekit\Database\Tests\ORM\QueryBuilderCacheTest}). That test runs in an
 * isolated process because `User::where()` resolves the shared, process-static
 * EntityManager singleton via `ModelTrait::getManager()`.
 *
 * NOTE - deferred to Step 2.1.9 (Test Coverage Expansion): the happy-path DB
 * lookups `UserProvider::find()`, `findByUsername()` and `findByCredentials()`
 * (static `User::` reads that hydrate a real row into a `User`) need a booted
 * kernel + database and belong to integration coverage, not this unit suite
 * (see ticket discovery note 6).
 */
class UserProviderTest extends TestCase
{
    // -----------------------------------------------------------------------
    // validateCredentials(): the authentication gate (pure, fully mockable).
    // -----------------------------------------------------------------------

    public function testValidateCredentialsReturnsTrueWhenEncoderAccepts(): void
    {
        $encoder = $this->createMock(PasswordEncoderInterface::class);
        $encoder->method('verify')->willReturn(true);

        $provider = new UserProvider($encoder);

        $this->assertTrue(
            $provider->validateCredentials($this->userWithPassword('$2y$10$storedhash'), ['password' => 'raw-candidate'])
        );
    }

    public function testValidateCredentialsReturnsFalseWhenEncoderRejects(): void
    {
        $encoder = $this->createMock(PasswordEncoderInterface::class);
        $encoder->method('verify')->willReturn(false);

        $provider = new UserProvider($encoder);

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

        $provider = new UserProvider($encoder);

        $this->assertTrue(
            $provider->validateCredentials($this->userWithPassword('$2y$10$storedhash'), ['password' => 'raw-candidate'])
        );
    }

    // -----------------------------------------------------------------------
    // findByCredentials(): stripping + guard exercised without a DB.
    // -----------------------------------------------------------------------

    /**
     * The `password` key must be dropped before the lookup: only the remaining
     * credentials reach `where()`. A mutant that removes the `unset()` (or
     * negates the `isset` guard) would leak `password` into the condition and
     * fail the matcher. An empty result set then drives the `null` branch.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFindByCredentialsStripsPasswordAndReturnsNullWhenNoRow(): void
    {
        $query = $this->createMock(DbalQueryBuilder::class);
        $query->method('from')->willReturnSelf();
        $query->method('limit')->willReturnSelf();
        $query->expects($this->once())
            ->method('where')
            ->with(['username' => 'alice'], [])
            ->willReturnSelf();

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(false);
        $query->method('executeQuery')->willReturn($result);

        $this->primeEntityManager($query);

        $provider = new UserProvider($this->createMock(PasswordEncoderInterface::class));

        $this->assertNull($provider->findByCredentials([
            'username' => 'alice',
            'password' => 'raw-candidate',
        ]));
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

    /**
     * Boots a real {@see EntityManager} backed entirely by mocks (no DB) and
     * registers it as the process-static singleton that `User::where()` resolves
     * through `ModelTrait::getManager()`.
     */
    private function primeEntityManager(DbalQueryBuilder $query): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($query);

        $metadata = $this->createMock(Metadata::class);
        $metadata->method('getTable')->willReturn('@system_user');
        $metadata->method('getEventPrefix')->willReturn('user');

        $metadataManager = $this->createMock(MetadataManager::class);
        $metadataManager->method('get')->willReturn($metadata);

        // Constructing the EntityManager registers it as the static singleton
        // consumed by ModelTrait::getManager() (hence the isolated process).
        new EntityManager($connection, $metadataManager, $this->createMock(EventDispatcherInterface::class));
    }
}
