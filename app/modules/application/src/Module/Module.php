<?php

declare(strict_types=1);

namespace Pagekit\Module;

use Pagekit\Application as App;
use Pagekit\Event\EventSubscriberInterface;
use Pagekit\Util\Arr;

class Module implements ModuleInterface, EventSubscriberInterface
{
    public string $name;

    public string $path;

    /** @var array<string, mixed> */
    public array $config;

    /** @var array<string, mixed> */
    public array $options;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->name = $options['name'];
        $this->path = $options['path'];
        $this->config = $options['config'];
        $this->options = $options;
    }

    /**
     * @return mixed Genuinely unknown type — module bootstrap closures may return a service object, an array, or nothing; the return value is not consumed by the framework.
     */
    public function main(App $app): mixed
    {
        $main = $this->options['main'];

        if ($main instanceof \Closure) {
            $main = $main->bindTo($this, $this);
        }

        if (is_callable($main)) {
            return call_user_func($main, $app);
        }

        return null;
    }

    /**
     * {@inheritdoc}
     *
     * @return mixed Genuinely unknown type — option values are user-supplied via module config arrays; any scalar, array, or object is valid.
     */
    public function get(string|array $key, mixed $default = null): mixed
    {
        if (is_array($key)) {
            return Arr::extract($this->options, $key);
        }

        return Arr::get($this->options, $key, $default);
    }

    /**
     * {@inheritdoc}
     *
     * @return mixed Genuinely unknown type — config values are user-supplied via module config arrays; any scalar, array, or object is valid.
     */
    public function config(string|array|null $key = null, mixed $default = null): mixed
    {
        if (is_array($key)) {
            return Arr::extract($this->config, $key);
        }

        return Arr::get($this->config, $key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function subscribe(): array
    {
        return $this->options['events'] ?? [];
    }
}
