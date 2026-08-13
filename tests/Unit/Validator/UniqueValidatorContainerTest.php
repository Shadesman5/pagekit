<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Validator;

use Doctrine\DBAL\DriverManager;
use Pagekit\Application;
use Pagekit\Database\Connection;
use Pagekit\Intl\Loader\PhpFileLoader;
use Pagekit\System\Validator\Constraints\Unique;
use Pagekit\System\Validator\Constraints\UniqueValidator;
use Pagekit\System\ValidatorServiceProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\ContainerConstraintValidatorFactory;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Covers the Unique constraint reaching its database through the container.
 *
 * The validator needs a connection, and Symfony's default factory builds
 * validators with `new $class()`, which cannot supply one. The provider
 * therefore registers the validator as a container entry under its own class
 * name and hands the validator builder a factory that looks that name up, so
 * the connection arrives as a constructor argument and belongs to one
 * application rather than to the process.
 *
 * The tests run against a real in-memory SQLite database: the query the
 * constraint builds is the behaviour worth pinning, and a stub of the
 * connection would only replay what the validator was written to ask for.
 */
final class UniqueValidatorContainerTest extends TestCase
{
    /** @var list<Connection> */
    private array $connections = [];

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }

        $this->connections = [];
    }

    // ------------------------------------------------------------------
    // Container resolution
    // ------------------------------------------------------------------

    /**
     * The id the factory asks for is the constraint's own `validatedBy()`
     * name. Registering the validator under any other id would leave the
     * factory on its `new $class()` fallback, which cannot satisfy the
     * constructor and would fail on the first form that carries the
     * constraint.
     */
    public function testTheFactoryResolvesTheValidatorTheConstraintNames(): void
    {
        $app = $this->containerWith($this->userDatabase([]));

        $factory = new ContainerConstraintValidatorFactory($app);
        $validator = $factory->getInstance(new Unique(table: '@system_user', column: 'username'));

        $this->assertInstanceOf(UniqueValidator::class, $validator);
    }

    /**
     * Two applications, two databases, one shared constraint declaration: each
     * validator answers from the connection its own container handed it. A
     * validator that reached for process-wide state would answer both
     * applications from whichever connection was registered last.
     */
    public function testEachContainerValidatesAgainstItsOwnConnection(): void
    {
        $taken = $this->containerWith($this->userDatabase([1 => 'admin']));
        $free = $this->containerWith($this->userDatabase([]));

        $account = $this->account(null, 'admin');

        $this->assertCount(1, $this->validate($taken, $account));
        $this->assertCount(0, $this->validate($free, $account));
    }

    /**
     * Swapping the constraint validator factory must not cost the stateless
     * validators Symfony ships: they are not container entries, so the factory
     * has to keep building them itself.
     */
    public function testStandardConstraintsStillResolveWithoutAContainerEntry(): void
    {
        $app = $this->containerWith($this->userDatabase([]));

        $violations = $this->validate($app, new BlankUsernameFixture());

        $this->assertCount(1, $violations);
        $this->assertSame('This field is required.', $violations->get(0)->getMessage());
    }

    // ------------------------------------------------------------------
    // Uniqueness behaviour through the resolved validator
    // ------------------------------------------------------------------

    /**
     * A taken name is reported against the property that carries the
     * constraint, and the message is resolved through the translator the same
     * provider wired up — the container factory replaces neither.
     */
    public function testATakenNameIsRejectedWithATranslatedMessage(): void
    {
        $app = $this->containerWith($this->userDatabase([1 => 'admin', 2 => 'editor']));

        $violations = $this->validate($app, $this->account(null, 'admin'));

        $this->assertCount(1, $violations);
        $this->assertSame('username', $violations->get(0)->getPropertyPath());
        $this->assertSame('Username is not available.', $violations->get(0)->getMessage());
    }

    /**
     * Names are compared lowercased on both sides, so a different spelling of
     * a taken name is still taken.
     */
    public function testATakenNameIsRejectedInAnySpelling(): void
    {
        $app = $this->containerWith($this->userDatabase([1 => 'admin']));

        $this->assertCount(1, $this->validate($app, $this->account(null, 'AdMiN')));
    }

    public function testANameNobodyHoldsIsAccepted(): void
    {
        $app = $this->containerWith($this->userDatabase([1 => 'admin', 2 => 'editor']));

        $this->assertCount(0, $this->validate($app, $this->account(null, 'newcomer')));
    }

    /**
     * Editing an existing account without renaming it: the row being saved is
     * excluded by its id, so an account does not collide with itself.
     */
    public function testTheRowBeingEditedDoesNotCollideWithItself(): void
    {
        $app = $this->containerWith($this->userDatabase([1 => 'admin', 2 => 'editor']));

        $this->assertCount(0, $this->validate($app, $this->account(1, 'admin')));
    }

    /**
     * The exclusion is limited to the edited row — taking over somebody else's
     * name while editing is still a collision.
     */
    public function testAnotherRowsNameIsStillRejectedWhileEditing(): void
    {
        $app = $this->containerWith($this->userDatabase([1 => 'admin', 2 => 'editor']));

        $this->assertCount(1, $this->validate($app, $this->account(2, 'admin')));
    }

    /**
     * An absent value is nothing to reserve, so it is not looked up at all.
     * The stored empty name proves the point: a lookup would have matched it.
     */
    public function testAnAbsentValueIsNotLookedUp(): void
    {
        $db = $this->userDatabase([1 => '']);
        $app = $this->containerWith($db);

        $stored = $db->executeQuery("SELECT COUNT(*) FROM @system_user WHERE username = ''")->fetchOne();
        $this->assertSame(1, (int) $stored);

        $this->assertCount(0, $this->validate($app, $this->account(null, '')));
        $this->assertCount(0, $this->validate($app, $this->account(null, null)));
    }

    /**
     * The validator reads table and column off the constraint it is given, so
     * a foreign constraint has nothing to query and is refused rather than
     * silently passed.
     */
    public function testAForeignConstraintIsRefused(): void
    {
        $validator = new UniqueValidator($this->userDatabase([]));

        $this->expectException(UnexpectedTypeException::class);

        $validator->validate('admin', new Assert\NotBlank());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * An application wired the way SystemModule wires one: the connection and
     * the translator the provider's factories pull from the container.
     */
    private function containerWith(Connection $db): Application
    {
        $translator = new Translator('en_US');
        $translator->addLoader('php', new PhpFileLoader());
        $translator->addResource(
            'php',
            dirname(__DIR__, 3) . '/app/system/languages/en_US/validators.php',
            'en_US',
            'validators'
        );

        $app = new Application();
        $app->set('db', $db);
        $app->set('translator', $translator);

        ValidatorServiceProvider::register($app);

        return $app;
    }

    /**
     * A prefixed user table, so the constraint's `@system_user` goes through
     * the same placeholder replacement it does in a real installation.
     *
     * @param array<int, string> $accounts Stored usernames, keyed by row id.
     */
    private function userDatabase(array $accounts): Connection
    {
        $params = ['driver' => 'pdo_sqlite', 'memory' => true, 'prefix' => 'pk_'];
        $template = DriverManager::getConnection($params);

        $db = new Connection($params, $template->getDriver(), $template->getConfiguration());
        $this->connections[] = $db;

        $db->executeStatement('CREATE TABLE pk_system_user (id INTEGER PRIMARY KEY, username TEXT NOT NULL)');

        foreach ($accounts as $id => $username) {
            $db->executeStatement(
                'INSERT INTO @system_user (id, username) VALUES (:id, :username)',
                ['id' => $id, 'username' => $username]
            );
        }

        return $db;
    }

    private function account(?int $id, ?string $username): UniqueUsernameFixture
    {
        $account = new UniqueUsernameFixture();
        $account->id = $id;
        $account->username = $username;

        return $account;
    }

    private function validate(Application $app, object $entity): ConstraintViolationListInterface
    {
        $validator = $app->get('validator');
        $this->assertInstanceOf(ValidatorInterface::class, $validator);

        return $validator->validate($entity);
    }
}

/**
 * Mirrors the username field of the User model: the constraint declaration the
 * container-resolved validator has to serve.
 */
class UniqueUsernameFixture
{
    public ?int $id = null;

    #[Unique(
        table: '@system_user',
        column: 'username',
        message: 'validation.user.username_not_available'
    )]
    public ?string $username = null;
}

/**
 * Carries only a constraint whose validator Symfony ships — the factory has no
 * container entry to resolve it from.
 */
class BlankUsernameFixture
{
    #[Assert\NotBlank(message: 'validation.required')]
    public ?string $username = null;
}
