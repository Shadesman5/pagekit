<?php

declare(strict_types=1);

namespace Pagekit\Auth\Tests;

use Pagekit\Auth\Handler\DatabaseHandler;
use Pagekit\Cookie\CookieJar;
use Pagekit\Database\Connection;
use PHPUnit\Framework\TestCase;
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
}
