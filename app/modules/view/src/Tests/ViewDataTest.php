<?php

declare(strict_types=1);

namespace Pagekit\View\Tests;

use Pagekit\View\Helper\DataHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RequestContext;

/**
 * The admin JavaScript builds every API URL from `$pagekit.url`, and the
 * router's request context is the only place that knows how the request
 * actually arrived: '' when mod_rewrite served it from the domain root, the
 * base path when the site lives in a subdirectory, and '…/index.php' when the
 * front controller was addressed directly. The `view.data` listener therefore
 * has to publish that value unchanged — substituting '/index.php' for an empty
 * base URL breaks every rewrite setup, and under FastCGI that PATH_INFO form
 * answers "No input file specified".
 *
 * The listener lives in the system view module's index.php, which is a module
 * definition rather than an autoloaded class: it is pulled in below so the
 * closure binds to the container stub declared in the same scope.
 */
final class ViewDataTest extends TestCase
{
    private const TOKEN = 'csrf-token';

    public function testAnEmptyBaseUrlIsPublishedVerbatim(): void
    {
        self::assertSame(
            ['url' => '', 'csrf' => self::TOKEN],
            $this->publish('')->get('$pagekit'),
            'an empty base URL is the correct value once mod_rewrite serves the request',
        );
    }

    public function testAFrontControllerBaseUrlIsPublishedVerbatim(): void
    {
        self::assertSame(
            ['url' => '/index.php', 'csrf' => self::TOKEN],
            $this->publish('/index.php')->get('$pagekit'),
            'without rewriting, the context reports the front controller and the client must use it',
        );
    }

    public function testASubdirectoryBaseUrlIsPublishedVerbatim(): void
    {
        self::assertSame(
            ['url' => '/cms', 'csrf' => self::TOKEN],
            $this->publish('/cms')->get('$pagekit'),
            'a site installed below the domain root must keep its base path',
        );
    }

    /**
     * Runs the `view.data` listener against a router context reporting the given
     * base URL and hands back the data the listener wrote.
     */
    private function publish(string $baseUrl): DataHelper
    {
        $app = new class (new RequestContext($baseUrl), self::TOKEN) {
            public function __construct(
                private readonly RequestContext $context,
                private readonly string $token,
            ) {
            }

            public function get(string $id): object
            {
                return match ($id) {
                    'router' => new class ($this->context) {
                        public function __construct(private readonly RequestContext $context)
                        {
                        }

                        public function getContext(): RequestContext
                        {
                            return $this->context;
                        }
                    },
                    'csrf' => new class ($this->token) {
                        public function __construct(private readonly string $token)
                        {
                        }

                        public function generate(): string
                        {
                            return $this->token;
                        }
                    },
                    default => throw new \LogicException(sprintf('The view.data listener must not resolve "%s".', $id)),
                };
            }
        };

        /** @var array{events: array{'view.data': callable}} $module */
        $module = require __DIR__ . '/../../../../system/modules/view/index.php';

        $data = new DataHelper();
        $module['events']['view.data'](null, $data);

        return $data;
    }
}
