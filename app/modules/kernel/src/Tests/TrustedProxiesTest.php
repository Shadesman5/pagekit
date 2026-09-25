<?php

declare(strict_types=1);

namespace Pagekit\Tests;

use Pagekit\Application;
use Pagekit\Application\TrustedProxies;
use Pagekit\Kernel\HttpKernelInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trusting the reverse proxy an installation is served behind.
 *
 * Where TLS is terminated in front of Pagekit, the connection the webserver
 * accepts is plain HTTP on an internal address, and the request the visitor made
 * is only described in X-Forwarded-* headers. Until the proxy is trusted,
 * isSecure() reports false and every absolute URL, redirect and secure-cookie
 * decision is built from the internal connection instead — which is how such a
 * deployment ends up redirecting to HTTPS forever.
 *
 * Trust is global state on the Request class, so each test starts from nothing
 * trusted and puts back what it found.
 */
final class TrustedProxiesTest extends TestCase
{
    /**
     * A request as it arrives inside the container: plain HTTP from the proxy's
     * own address, with the visitor's request in the forwarding headers. The
     * prefix is one no proxy of ours sends — it is here to be ignored.
     */
    private const FORWARDED = [
        'REMOTE_ADDR' => '10.0.0.5',
        'HTTP_HOST' => 'pagekit.internal:8080',
        'SERVER_PORT' => '8080',
        'SCRIPT_NAME' => '/index.php',
        'SCRIPT_FILENAME' => '/var/www/html/public/index.php',
        'REQUEST_URI' => '/index.php/admin/login',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        'HTTP_X_FORWARDED_HOST' => 'example.com',
        'HTTP_X_FORWARDED_PORT' => '443',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_PREFIX' => '/injected',
    ];

    /** @var string[] */
    private array $proxies = [];

    private int $headers = 0;

    private ?string $variable = null;

    private ?string $peer = null;

    protected function setUp(): void
    {
        $this->proxies = Request::getTrustedProxies();
        $this->headers = Request::getTrustedHeaderSet();

        $variable = getenv(TrustedProxies::ENV_VAR);
        $this->variable = $variable === false ? null : $variable;

        $peer = $_SERVER['REMOTE_ADDR'] ?? null;
        $this->peer = is_string($peer) ? $peer : null;

        Request::setTrustedProxies([], 0);
        putenv(TrustedProxies::ENV_VAR);
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies($this->proxies, $this->headers);

        if ($this->variable === null) {
            putenv(TrustedProxies::ENV_VAR);
        } else {
            putenv(TrustedProxies::ENV_VAR.'='.$this->variable);
        }

        if ($this->peer === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->peer;
        }
    }

    // -----------------------------------------------------------------------
    // Reading the list.
    // -----------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function proxyLists(): array
    {
        return [
            'one address' => ['10.0.0.5', ['10.0.0.5']],
            'a CIDR range' => ['10.0.0.0/8', ['10.0.0.0/8']],
            'several, comma separated' => ['10.0.0.0/8,192.168.0.0/16', ['10.0.0.0/8', '192.168.0.0/16']],
            'spaced out for legibility' => ["\t10.0.0.0/8 , 192.168.0.0/16 ", ['10.0.0.0/8', '192.168.0.0/16']],
            'with a stray comma' => ['10.0.0.0/8,,192.168.0.0/16,', ['10.0.0.0/8', '192.168.0.0/16']],
            'the connecting peer' => ['REMOTE_ADDR', ['REMOTE_ADDR']],
            'nothing at all' => ['', []],
            'separators only' => [' , , ', []],
        ];
    }

    /**
     * The list comes out of a hand-edited env file, so it is read the way one
     * gets written: padded, and with a comma left behind after an edit. Gaps are
     * dropped rather than trusted as an empty address.
     *
     * @param list<string> $expected
     */
    #[DataProvider('proxyLists')]
    public function testTheListIsReadTheWayItGetsWrittenIntoAnEnvFile(string $value, array $expected): void
    {
        self::assertSame($expected, TrustedProxies::parse($value));
    }

    // -----------------------------------------------------------------------
    // Nothing set: trust nothing.
    // -----------------------------------------------------------------------

    /**
     * A directly exposed installation trusts nothing, because the forwarding
     * headers are then a client's own claim about who and where it is.
     */
    public function testAnInstallationWithoutTheVariableTrustsNothing(): void
    {
        TrustedProxies::configureFromEnvironment();

        self::assertSame([], Request::getTrustedProxies());

        $request = new Request([], [], [], [], [], self::FORWARDED);

        self::assertFalse($request->isSecure());
        self::assertSame('pagekit.internal', $request->getHost());
        self::assertSame(8080, $request->getPort());
        self::assertSame('10.0.0.5', $request->getClientIp());
    }

    /**
     * A variable left blank, or holding nothing but separators, means the same
     * as no variable at all rather than an empty list of trusted proxies.
     */
    public function testAVariableWithoutAnAddressInItTrustsNothing(): void
    {
        putenv(TrustedProxies::ENV_VAR.'= , ');

        TrustedProxies::configureFromEnvironment();

        self::assertSame([], Request::getTrustedProxies());

        $request = new Request([], [], [], [], [], self::FORWARDED);

        self::assertFalse($request->isSecure());
        self::assertSame('pagekit.internal', $request->getHost());
    }

    // -----------------------------------------------------------------------
    // Proxy trusted: the request describes what the visitor asked for.
    // -----------------------------------------------------------------------

    public function testATrustedProxyDescribesTheRequestTheVisitorMade(): void
    {
        putenv(TrustedProxies::ENV_VAR.'=10.0.0.0/8');

        TrustedProxies::configureFromEnvironment();

        self::assertSame(['10.0.0.0/8'], Request::getTrustedProxies());

        $request = new Request([], [], [], [], [], self::FORWARDED);

        self::assertTrue($request->isSecure());
        self::assertSame('example.com', $request->getHost());
        self::assertSame(443, $request->getPort());
        self::assertSame('https://example.com', $request->getSchemeAndHttpHost());
        self::assertSame('203.0.113.7', $request->getClientIp());
    }

    /**
     * The literal REMOTE_ADDR trusts whichever peer connected. A container that
     * can only be reached through its proxy can use it without being told the
     * proxy's address, which is not knowable before the network exists.
     */
    public function testTheConnectingPeerCanBeTrustedWithoutKnowingItsAddress(): void
    {
        $_SERVER['REMOTE_ADDR'] = self::FORWARDED['REMOTE_ADDR'];
        putenv(TrustedProxies::ENV_VAR.'=REMOTE_ADDR');

        TrustedProxies::configureFromEnvironment();

        self::assertSame(['10.0.0.5'], Request::getTrustedProxies());

        $request = new Request([], [], [], [], [], self::FORWARDED);

        self::assertTrue($request->isSecure());
        self::assertSame('example.com', $request->getHost());
    }

    // -----------------------------------------------------------------------
    // What a trusted proxy may not do.
    // -----------------------------------------------------------------------

    /**
     * X-Forwarded-Prefix is left out of the trusted set. Pagekit derives its own
     * base path, and a header that could prepend to it would decide where every
     * generated link and redirect points.
     */
    public function testATrustedProxyCannotPrependAPathToEveryGeneratedLink(): void
    {
        putenv(TrustedProxies::ENV_VAR.'=10.0.0.0/8');

        TrustedProxies::configureFromEnvironment();

        $request = new Request([], [], [], [], [], self::FORWARDED);

        self::assertSame('/injected', $request->headers->get('X-Forwarded-Prefix'));
        self::assertSame('/index.php', $request->getBaseUrl());
    }

    /**
     * Only the four X-Forwarded-* headers are trusted, so the RFC 7239
     * Forwarded header is ignored even coming from the proxy. Accepting both
     * would give an attacker who can reach one of them two ways to describe the
     * request, and the two need not agree.
     */
    public function testTheForwardedHeaderIsIgnoredEvenFromATrustedProxy(): void
    {
        putenv(TrustedProxies::ENV_VAR.'=10.0.0.0/8');

        TrustedProxies::configureFromEnvironment();

        $server = [
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_HOST' => 'pagekit.internal:8080',
            'SERVER_PORT' => '8080',
            'HTTP_FORWARDED' => 'for=203.0.113.9;host=elsewhere.example;proto=https',
        ];

        $request = new Request([], [], [], [], [], $server);

        self::assertFalse($request->isSecure());
        self::assertSame('pagekit.internal', $request->getHost());
        self::assertSame('10.0.0.5', $request->getClientIp());
    }

    // -----------------------------------------------------------------------
    // Application::run() is where the environment is consulted.
    // -----------------------------------------------------------------------

    public function testTheApplicationTrustsTheProxiesNamedInItsEnvironment(): void
    {
        putenv(TrustedProxies::ENV_VAR.'=10.0.0.0/8, 192.168.0.0/16');

        $app = $this->applicationHandling();

        $this->silence(static function () use ($app): void {
            $app->run();
        });

        self::assertSame(['10.0.0.0/8', '192.168.0.0/16'], Request::getTrustedProxies());
    }

    /**
     * A caller that builds its own request has already decided what to trust,
     * and the environment must not reach into it afterwards.
     */
    public function testARequestHandedInKeepsTheTrustItsCallerChose(): void
    {
        putenv(TrustedProxies::ENV_VAR.'=10.0.0.0/8');

        $request = new Request([], [], [], [], [], self::FORWARDED);
        $app = $this->applicationHandling($request);

        $this->silence(static function () use ($app, $request): void {
            $app->run($request);
        });

        self::assertSame([], Request::getTrustedProxies());
        self::assertFalse($request->isSecure());
    }

    // -----------------------------------------------------------------------

    /**
     * An application whose kernel answers once with an empty response, so run()
     * can be driven without a booted installation behind it.
     */
    private function applicationHandling(?Request $expected = null): Application
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $handle = $kernel->expects(self::once())->method('handle');

        if ($expected !== null) {
            $handle->with(self::identicalTo($expected));
        }

        $handle->willReturn(new Response());
        $kernel->expects(self::once())->method('terminate');

        $app = new Application();
        $app->set('kernel', $kernel);

        return $app;
    }

    /**
     * run() sends the response it gets, which in a test process means writing it
     * to stdout.
     */
    private function silence(\Closure $run): void
    {
        ob_start();

        try {
            $run();
        } finally {
            ob_end_clean();
        }
    }
}
