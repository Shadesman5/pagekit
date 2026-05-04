<?php

declare(strict_types=1);

namespace Pagekit\View\Helper;

use Pagekit\Application\UrlProvider;
use Pagekit\Routing\Generator\UrlGenerator;

class UrlHelper extends Helper
{
    protected \Pagekit\Application\UrlProvider $provider;

    /**
     * Constructor.
     *
     * @param UrlProvider $provider
     */
    public function __construct(UrlProvider $provider)
    {
        $this->provider = $provider;
    }

    /**
     * Get shortcut.
     *
     * @see get()
     *
     * @param array<string, mixed> $parameters
     */
    public function __invoke(string $path = '', array $parameters = [], int $referenceType = UrlGenerator::ABSOLUTE_PATH): string
    {
        return $this->provider->get($path, $parameters, $referenceType);
    }

    /**
     * Proxies all method calls to the provider.
     *
     * @param array<int, mixed> $args
     */
    public function __call(string $method, array $args): mixed
    {
        if (!is_callable($callable = [$this->provider, $method])) {
            throw new \InvalidArgumentException(sprintf('Undefined method call "%s::%s"', get_class($this->provider), $method));
        }

        return call_user_func_array($callable, $args);
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'url';
    }
}
