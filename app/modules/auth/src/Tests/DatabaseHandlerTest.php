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
}
