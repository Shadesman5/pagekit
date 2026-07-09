<?php

declare(strict_types=1);

namespace Pagekit\Auth\Tests;

use Doctrine\DBAL\Result;
use Pagekit\Auth\Handler\DatabaseHandler;
use Pagekit\Cookie\CookieJar;
use Pagekit\Database\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for the database-backed session {@see DatabaseHandler}.
 *
 * Infection ignores (Step 2.1.8, see infection.json.dist `mutators`):
 *   - ReturnRemoval at read:45 and destroy:107 — the `$config === null` early
 *     returns are redundant: getToken() independently returns null when config
 *     is null, so both method bodies are inert without the guard. Equivalent.
 *   - LessThan at read:53 — the `strtotime($access) + timeout < time()` session
 *     timeout boundary only flips when the sum lands exactly on `time()`, which
 *     is not deterministically reproducible without an injectable clock.
 *     Deferred to Step 2.1.9 (clock injection).
 */
class DatabaseHandlerTest extends TestCase
{
    /**
     * Default config consumed by DatabaseHandler::write().
     */
    private const CONFIG = [
        'table' => 'sessions',
        'cookie' => [
            'name' => 'pk_auth',
            'lifetime' => 3600,
        ],
        'timeout' => 1800,
    ];

    /**
     * Build a RequestStack containing an empty Request (no cookies, no IP, no UA).
     *
     * The empty cookies guarantee getToken() returns null in write(),
     * so the upfront delete branch is skipped.
     */
    private function buildRequestStackWithEmptyRequest(): RequestStack
    {
        $stack = new RequestStack();
        $stack->push(new Request());

        return $stack;
    }

    /**
     * Build a RequestStack whose Request carries the pk_auth cookie, so getToken()
     * resolves the given token and read()/destroy() reach their DB branches.
     */
    private function buildRequestStackWithToken(string $token): RequestStack
    {
        $request = new Request();
        $request->cookies->set(self::CONFIG['cookie']['name'], $token);

        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }

    public function testConstructorAcceptsNewSignature(): void
    {
        $connection = $this->createMock(Connection::class);
        $requests = $this->createMock(RequestStack::class);
        $cookie = $this->createMock(CookieJar::class);

        $handler = new DatabaseHandler($connection, $requests, $cookie, self::CONFIG);

        $this->assertSame(4, (new \ReflectionClass($handler))->getConstructor()?->getNumberOfParameters());
    }

    public function testConstructorAllowsNullConfig(): void
    {
        $connection = $this->createMock(Connection::class);
        $requests = $this->createMock(RequestStack::class);
        $cookie = $this->createMock(CookieJar::class);

        $handler = new DatabaseHandler($connection, $requests, $cookie);

        $this->assertSame(4, (new \ReflectionClass($handler))->getConstructor()?->getNumberOfParameters());
    }

    public function testConstructorContractMatchesModernSignature(): void
    {
        $typeName = static function (\ReflectionParameter $param): ?string {
            $type = $param->getType();

            return $type instanceof \ReflectionNamedType ? $type->getName() : null;
        };

        $ref = new \ReflectionClass(DatabaseHandler::class);
        $ctor = $ref->getConstructor();

        $this->assertNotNull($ctor);
        $this->assertSame(4, $ctor->getNumberOfParameters(), 'Constructor must take exactly 4 parameters');
        $this->assertSame(3, $ctor->getNumberOfRequiredParameters(), 'Only the last parameter may be optional');

        $params = $ctor->getParameters();

        $this->assertSame('connection', $params[0]->getName());
        $this->assertSame(Connection::class, $typeName($params[0]));

        $this->assertSame('requests', $params[1]->getName());
        $this->assertSame(RequestStack::class, $typeName($params[1]));

        $this->assertSame('cookie', $params[2]->getName());
        $this->assertSame(CookieJar::class, $typeName($params[2]));

        $this->assertSame('config', $params[3]->getName());
        $this->assertSame('array', $typeName($params[3]));
        $this->assertTrue($params[3]->allowsNull(), '$config must be ?array');
        $this->assertTrue($params[3]->isDefaultValueAvailable());
        $this->assertNull($params[3]->getDefaultValue());

        $this->assertFalse(
            in_array('random', array_column(array_map(fn ($p) => ['name' => $p->getName()], $params), 'name'), true),
            'Legacy $random parameter must be absent'
        );
    }

    public function testWriteGeneratesHexTokenAndStoresSha1(): void
    {
        $connection = $this->createMock(Connection::class);
        $cookie = $this->createMock(CookieJar::class);
        $requests = $this->buildRequestStackWithEmptyRequest();

        $capturedCookieToken = null;
        $cookie->expects($this->once())
            ->method('set')
            ->willReturnCallback(function (
                $name,
                $value,
                $expire = 0,
                $path = null,
                $domain = null,
                $secure = false,
                $httpOnly = true
            ) use (&$capturedCookieToken) {
                $capturedCookieToken = $value;

                return new \Symfony\Component\HttpFoundation\Cookie($name, $value);
            });

        $capturedInsertData = null;
        $connection->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data, array $types = []) use (&$capturedInsertData): int {
                $capturedInsertData = $data;

                return 1;
            });

        // delete() must NOT be called: getToken() returns null on an empty Request.
        $connection->expects($this->never())->method('delete');

        $handler = new DatabaseHandler($connection, $requests, $cookie, self::CONFIG);
        $handler->write(42);

        $this->assertIsString($capturedCookieToken);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $capturedCookieToken);

        $this->assertIsArray($capturedInsertData);
        $this->assertSame(sha1($capturedCookieToken), $capturedInsertData['id']);
        $this->assertSame(42, $capturedInsertData['user_id']);
    }

    public function testWriteRespectsRememberFlag(): void
    {
        $connection = $this->createMock(Connection::class);
        $cookie = $this->createMock(CookieJar::class);
        $requests = $this->buildRequestStackWithEmptyRequest();

        $cookie->method('set')->willReturn(
            new \Symfony\Component\HttpFoundation\Cookie('pk_auth', 'placeholder')
        );

        $capturedStatuses = [];
        $connection->expects($this->exactly(2))
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data, array $types = []) use (&$capturedStatuses): int {
                $capturedStatuses[] = $data['status'];

                return 1;
            });

        $handler = new DatabaseHandler($connection, $requests, $cookie, self::CONFIG);
        $handler->write(1, true);
        $handler->write(2, false);

        $this->assertSame(
            [DatabaseHandler::STATUS_REMEMBERED, DatabaseHandler::STATUS_ACTIVE],
            $capturedStatuses
        );
    }

    public function testWriteDefaultsToActiveStatusWhenRememberOmitted(): void
    {
        $connection = $this->createMock(Connection::class);
        $cookie = $this->createMock(CookieJar::class);
        $requests = $this->buildRequestStackWithEmptyRequest();

        $cookie->method('set')->willReturn(new Cookie('pk_auth', 'placeholder'));

        $capturedStatus = null;
        $connection->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data, array $types = []) use (&$capturedStatus): int {
                $capturedStatus = $data['status'];

                return 1;
            });

        // No second argument -> the `bool $remember = false` default must persist an
        // ACTIVE (not REMEMBERED) session. A FalseValue mutant flips the default to
        // true and would store STATUS_REMEMBERED instead.
        $handler = new DatabaseHandler($connection, $requests, $cookie, self::CONFIG);
        $handler->write(42);

        $this->assertSame(DatabaseHandler::STATUS_ACTIVE, $capturedStatus);
    }

    public function testWriteSetsCookieExpiryInTheFuture(): void
    {
        $connection = $this->createMock(Connection::class);
        $cookie = $this->createMock(CookieJar::class);
        $requests = $this->buildRequestStackWithEmptyRequest();

        $capturedExpire = null;
        $cookie->expects($this->once())
            ->method('set')
            ->willReturnCallback(function ($name, $value, $expire = 0) use (&$capturedExpire) {
                $capturedExpire = $expire;

                return new Cookie($name, $value);
            });
        $connection->method('insert')->willReturn(1);

        $handler = new DatabaseHandler($connection, $requests, $cookie, self::CONFIG);
        $handler->write(42);

        // Expiry is `lifetime + time()` -> a timestamp in the future. A Plus->Minus
        // mutant yields `lifetime - time()` (a large negative), which this rejects.
        $this->assertIsInt($capturedExpire);
        $this->assertGreaterThan(time(), $capturedExpire);
        $this->assertLessThanOrEqual(time() + self::CONFIG['cookie']['lifetime'], $capturedExpire);
    }

    public function testWriteStoresClientMetadataFromRequest(): void
    {
        $connection = $this->createMock(Connection::class);
        $cookie = $this->createMock(CookieJar::class);
        $cookie->method('set')->willReturn(new Cookie('pk_auth', 'placeholder'));

        // A request carrying a client IP + User-Agent but NO auth cookie, so
        // getToken() stays null and the upfront delete branch is skipped.
        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.7',
            'HTTP_USER_AGENT' => 'AgentTest/1.0',
        ]);
        $stack = new RequestStack();
        $stack->push($request);

        $capturedData = null;
        $connection->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data, array $types = []) use (&$capturedData): int {
                $capturedData = $data;

                return 1;
            });

        $handler = new DatabaseHandler($connection, $stack, $cookie, self::CONFIG);
        $handler->write(42);

        $this->assertIsArray($capturedData);
        $decoded = json_decode((string) $capturedData['data'], true);

        // Both keys must survive with the request values: ArrayItem / ArrayItemRemoval
        // mutants drop or mangle the 'ip' / 'user-agent' entries.
        $this->assertSame('198.51.100.7', $decoded['ip']);
        $this->assertSame('AgentTest/1.0', $decoded['user-agent']);
    }

    public function testWriteStoresNullClientMetadataWhenNoRequest(): void
    {
        $connection = $this->createMock(Connection::class);
        $cookie = $this->createMock(CookieJar::class);
        $cookie->method('set')->willReturn(new Cookie('pk_auth', 'placeholder'));

        // Empty stack -> getRequest() returns null. The null-safe `?->` operators
        // must yield null metadata rather than dereferencing null; the
        // NullSafeMethodCall / NullSafePropertyCall mutants strip `?` and fatal here.
        $requests = new RequestStack();

        $capturedData = null;
        $connection->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data, array $types = []) use (&$capturedData): int {
                $capturedData = $data;

                return 1;
            });

        $handler = new DatabaseHandler($connection, $requests, $cookie, self::CONFIG);
        $handler->write(42);

        $this->assertIsArray($capturedData);
        $decoded = json_decode((string) $capturedData['data'], true);
        $this->assertNull($decoded['ip']);
        $this->assertNull($decoded['user-agent']);
    }

    public function testReadReturnsNullWhenConfigIsNull(): void
    {
        $connection = $this->createMock(Connection::class);
        $cookie = $this->createMock(CookieJar::class);
        $requests = $this->createMock(RequestStack::class);

        // Null config short-circuits before any request or DB access.
        $requests->expects($this->never())->method('getCurrentRequest');
        $connection->expects($this->never())->method('executeQuery');

        $handler = new DatabaseHandler($connection, $requests, $cookie);

        $this->assertNull($handler->read());
    }

    public function testReadReturnsNullWhenNoToken(): void
    {
        $connection = $this->createMock(Connection::class);
        $cookie = $this->createMock(CookieJar::class);
        $requests = $this->buildRequestStackWithEmptyRequest();

        // Empty cookies -> getToken() is null -> the `and` short-circuits, SELECT never runs.
        $connection->expects($this->never())->method('executeQuery');

        $handler = new DatabaseHandler($connection, $requests, $cookie, self::CONFIG);

        $this->assertNull($handler->read());
    }

    public function testReadReturnsNullWhenNoRow(): void
    {
        $connection = $this->createMock(Connection::class);
        $cookie = $this->createMock(CookieJar::class);
        $requests = $this->buildRequestStackWithToken('missing-session-token');

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $connection->expects($this->once())->method('executeQuery')->willReturn($result);
        $connection->expects($this->never())->method('update');

        $handler = new DatabaseHandler($connection, $requests, $cookie, self::CONFIG);

        $this->assertNull($handler->read());
    }

    public function testReadReturnsUserIdAndTouchesAccessWithinTimeout(): void
    {
        $connection = $this->createMock(Connection::class);
        $cookie = $this->createMock(CookieJar::class);
        $token = 'fresh-session-token';
        $requests = $this->buildRequestStackWithToken($token);

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn([
            'user_id' => 42,
            'status' => DatabaseHandler::STATUS_ACTIVE,
            'access' => date('Y-m-d H:i:s'),
        ]);
        $connection->method('executeQuery')->willReturn($result);

        // Within timeout: only the access timestamp of this exact session id is refreshed.
        $connection->expects($this->once())
            ->method('update')
            ->with(self::CONFIG['table'], $this->arrayHasKey('access'), ['id' => sha1($token)]);
        $connection->expects($this->never())->method('insert');

        $handler = new DatabaseHandler($connection, $requests, $cookie, self::CONFIG);

        $this->assertSame(42, $handler->read());
    }

    public function testReadQueriesSessionBySha1OfToken(): void
    {
        $connection = $this->createMock(Connection::class);
        $cookie = $this->createMock(CookieJar::class);
        $token = 'session-token-xyz';
        $requests = $this->buildRequestStackWithToken($token);

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn([
            'user_id' => 5,
            'status' => DatabaseHandler::STATUS_ACTIVE,
            'access' => date('Y-m-d H:i:s'),
        ]);

        // The lookup must bind sha1() of the cookie token as :id. Dropping the
        // 'id' entry (ArrayItemRemoval) leaves the :id placeholder unbound, so
        // pinning the executeQuery() parameters kills that mutant.
        $connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->stringContains('WHERE id = :id'),
                $this->callback(static fn ($params): bool => is_array($params)
                    && ($params['id'] ?? null) === sha1($token))
            )
            ->willReturn($result);
        $connection->method('update')->willReturn(1);

        $handler = new DatabaseHandler($connection, $requests, $cookie, self::CONFIG);

        $this->assertSame(5, $handler->read());
    }

    public function testReadReWritesRememberedSessionWhenTimeoutExpired(): void
    {
        $connection = $this->createMock(Connection::class);
        $cookie = $this->createMock(CookieJar::class);
        $token = 'remembered-session-token';
        $requests = $this->buildRequestStackWithToken($token);

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn([
            'user_id' => 7,
            'status' => DatabaseHandler::STATUS_REMEMBERED,
            'access' => date('Y-m-d H:i:s', time() - (self::CONFIG['timeout'] + 3600)),
        ]);
        $connection->method('executeQuery')->willReturn($result);

        // Expired + remembered -> write() re-issues the session: stale row deleted, fresh
        // remembered row inserted, new cookie set. These side effects prove the re-write().
        $connection->expects($this->once())
            ->method('delete')
            ->with(self::CONFIG['table'], ['id' => sha1($token)]);
        $cookie->expects($this->once())->method('set')->willReturn(new Cookie('pk_auth', 'renewed'));
        $connection->expects($this->once())
            ->method('insert')
            ->with(
                self::CONFIG['table'],
                $this->callback(
                    static fn ($data): bool => is_array($data)
                        && ($data['status'] ?? null) === DatabaseHandler::STATUS_REMEMBERED
                )
            );

        // The access timestamp is still refreshed afterwards and the user id returned.
        $connection->expects($this->once())
            ->method('update')
            ->with(self::CONFIG['table'], $this->arrayHasKey('access'), ['id' => sha1($token)]);

        $handler = new DatabaseHandler($connection, $requests, $cookie, self::CONFIG);

        $this->assertSame(7, $handler->read());
    }

    public function testReadReturnsNullWhenTimeoutExpiredAndNotRemembered(): void
    {
        $connection = $this->createMock(Connection::class);
        $cookie = $this->createMock(CookieJar::class);
        $requests = $this->buildRequestStackWithToken('active-session-token');

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn([
            'user_id' => 9,
            'status' => DatabaseHandler::STATUS_ACTIVE,
            'access' => date('Y-m-d H:i:s', time() - (self::CONFIG['timeout'] + 3600)),
        ]);
        $connection->method('executeQuery')->willReturn($result);

        // Expired + not remembered -> bail out before touching access or re-writing.
        $connection->expects($this->never())->method('update');
        $connection->expects($this->never())->method('insert');

        $handler = new DatabaseHandler($connection, $requests, $cookie, self::CONFIG);

        $this->assertNull($handler->read());
    }

    public function testDestroyReturnsEarlyWhenConfigIsNull(): void
    {
        $connection = $this->createMock(Connection::class);
        $cookie = $this->createMock(CookieJar::class);
        $requests = $this->createMock(RequestStack::class);

        // Null config short-circuits before resolving the token or writing.
        $requests->expects($this->never())->method('getCurrentRequest');
        $connection->expects($this->never())->method('update');

        $handler = new DatabaseHandler($connection, $requests, $cookie);
        $handler->destroy();
    }

    public function testDestroyMarksSessionInactiveWhenTokenPresent(): void
    {
        $connection = $this->createMock(Connection::class);
        $cookie = $this->createMock(CookieJar::class);
        $token = 'live-session-token';
        $requests = $this->buildRequestStackWithToken($token);

        $connection->expects($this->once())
            ->method('update')
            ->with(
                self::CONFIG['table'],
                ['status' => DatabaseHandler::STATUS_INACTIVE],
                ['id' => sha1($token)]
            );

        $handler = new DatabaseHandler($connection, $requests, $cookie, self::CONFIG);
        $handler->destroy();
    }

    public function testDestroyDoesNothingWithoutToken(): void
    {
        $connection = $this->createMock(Connection::class);
        $cookie = $this->createMock(CookieJar::class);
        $requests = $this->buildRequestStackWithEmptyRequest();

        // No cookie -> no token -> the status update is skipped entirely.
        $connection->expects($this->never())->method('update');

        $handler = new DatabaseHandler($connection, $requests, $cookie, self::CONFIG);
        $handler->destroy();
    }

    public function testGetTokenStaysReachableFromSubclass(): void
    {
        $connection = $this->createMock(Connection::class);
        $cookie = $this->createMock(CookieJar::class);
        $token = 'subclass-visible-token';
        $requests = $this->buildRequestStackWithToken($token);

        $handler = new ExposedDatabaseHandler($connection, $requests, $cookie, self::CONFIG);

        // getToken() is `protected` so handler subclasses (custom session backends)
        // can reuse it. Reaching it from a subclass pins that visibility: the
        // ProtectedVisibility mutant narrows it to private and this call fatals.
        $this->assertSame($token, $handler->exposeToken());
    }

    public function testGetRequestStaysReachableFromSubclass(): void
    {
        $connection = $this->createMock(Connection::class);
        $cookie = $this->createMock(CookieJar::class);
        $request = new Request();
        $stack = new RequestStack();
        $stack->push($request);

        $handler = new ExposedDatabaseHandler($connection, $stack, $cookie, self::CONFIG);

        // Same protected-visibility contract as getToken(): a subclass must be able
        // to resolve the current request. Kills the ProtectedVisibility mutant.
        $this->assertSame($request, $handler->exposeRequest());
    }
}

/**
 * Test double that surfaces DatabaseHandler's protected request/token helpers, so
 * their `protected` (not `private`) visibility can be asserted from subclass scope.
 */
final class ExposedDatabaseHandler extends DatabaseHandler
{
    public function exposeToken(): ?string
    {
        return $this->getToken();
    }

    public function exposeRequest(): ?Request
    {
        return $this->getRequest();
    }
}
