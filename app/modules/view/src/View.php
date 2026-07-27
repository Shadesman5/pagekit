<?php

declare(strict_types=1);

namespace Pagekit\View;

use Pagekit\Event\EventDispatcherInterface;
use Pagekit\Event\EventInterface;
use Pagekit\Event\PrefixEventDispatcher;
use Pagekit\View\Engine\DelegatingEngine;
use Pagekit\View\Engine\EngineInterface;
use Pagekit\View\Event\ViewEvent;
use Pagekit\View\Helper\HelperInterface;

class View
{
    protected \Pagekit\Event\EventDispatcherInterface $events;

    protected EngineInterface $engine;

    /**
     * @var array<string, mixed>
     */
    protected array $globals = [];

    /**
     * @var array<string, HelperInterface>
     */
    protected array $helpers = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $parameters = [];

    /**
     * Constructor.
     *
     * @param EventDispatcherInterface $events
     * @param EngineInterface          $engine
     */
    public function __construct(?EventDispatcherInterface $events = null, ?EngineInterface $engine = null)
    {
        $this->events = $events ?: new PrefixEventDispatcher('view.');
        $this->engine = $engine ?: new DelegatingEngine();

        $this->trigger('init', [$this]);
    }

    /**
     * Render shortcut.
     *
     * @see render()
     *
     * @param array<string, mixed> $parameters
     */
    public function __invoke(string $name, array $parameters = []): ?string
    {
        return $this->render($name, $parameters);
    }

    /**
     * Gets a helper or calls the helpers invoke method.
     *
     * @param  array<int, mixed>  $args
     * @return mixed Genuinely unknown type — when called with no args, returns a HelperInterface; otherwise delegates to the helper's __invoke which may return any type.
     */
    public function __call(string $name, array $args): mixed
    {
        if (!isset($this->helpers[$name])) {
            throw new \InvalidArgumentException(sprintf('Undefined helper "%s"', $name));
        }

        $helper = $this->helpers[$name];

        if (!$args) {
            return $helper;
        }

        if (!is_callable($helper)) {
            throw new \BadMethodCallException(sprintf('Helper "%s" is not invokable.', $name));
        }

        return call_user_func_array($helper, $args);
    }

    /**
     * Gets a global parameter.
     *
     * @return mixed Genuinely unknown type — global view parameters are registered by modules and templates; any type (string, array, object) is valid.
     */
    public function __get(string $name): mixed
    {
        return isset($this->globals[$name]) ? $this->globals[$name] : null;
    }

    /**
     * Gets the templating engine.
     */
    public function getEngine(): EngineInterface
    {
        return $this->engine;
    }

    /**
     * Adds a templating engine.
     *
     * @param  EngineInterface $engine
     */
    public function addEngine(EngineInterface $engine): self
    {
        if ($this->engine instanceof DelegatingEngine) {
            $this->engine->addEngine($engine);
        } else {
            // Replace with delegating engine if needed
            $delegating = new DelegatingEngine();
            if ($this->engine) {
                $delegating->addEngine($this->engine);
            }
            $delegating->addEngine($engine);
            $this->engine = $delegating;
        }

        return $this;
    }

    /**
     * Gets the global parameters.
     *
     * @return array<string, mixed>
     */
    public function getGlobals(): array
    {
        return $this->globals;
    }

    /**
     * Adds a global parameter.
     */
    public function addGlobal(string $name, mixed $value): self
    {
        $this->globals[$name] = $value;

        return $this;
    }

    /**
     * Adds a view helper.
     *
     * @param  HelperInterface $helper
     */
    public function addHelper(HelperInterface $helper): self
    {
        $this->helpers[$helper->getName()] = $helper;

        $helper->register($this);

        return $this;
    }

    /**
     * Adds multiple view helpers.
     *
     * @param  HelperInterface[] $helpers
     */
    public function addHelpers(array $helpers): self
    {
        foreach ($helpers as $helper) {
            $this->addHelper($helper);
        }

        return $this;
    }

    /**
     * Adds an event listener.
     */
    public function on(string $event, callable $listener, int $priority = 0): void
    {
        $this->events->on($event, $listener, $priority);
    }

    /**
     * Triggers an event.
     *
     * @param string|EventInterface $event
     * @param array<int, mixed>     $arguments
     */
    public function trigger($event, array $arguments = []): EventInterface
    {
        return $this->events->trigger($event, $arguments);
    }

    /**
     * Returns true if the template exists.
     */
    public function exists(string $name): bool
    {
        try {
            return $this->engine->exists($name);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param array<string, mixed> $parameters
     */
    public function render(string $name, array $parameters = []): ?string
    {
        $event = new ViewEvent('render', $name);
        $event->setParameters(array_replace($this->globals, end($this->parameters) ?: [], $parameters));

        $this->events->trigger($event, [$this]);

        if (!$event->isPropagationStopped()) {
            $name = preg_replace('/\.php$/i', '', $name) ?? $name;
            $this->events->trigger($event->setName($name), [$this]);
        }

        $result = $event->getResult();
        $params = $this->parameters[] = $event->getParameters();

        if ($result === null) {
            $template = $event->getTemplate();

            // Special handling for 'layout' - if no layout template exists, return null
            if ($template === 'layout' && !$this->engine->exists($template)) {
                array_pop($this->parameters);

                return null;
            }

            if ($template === null) {
                array_pop($this->parameters);

                return null;
            }

            // Render the template with our engine (PhpEngine or Twig)
            try {
                $result = $this->engine->render($template, $params);
            } catch (\Exception $e) {
                // Template rendering failed
                throw new \RuntimeException(sprintf('Failed to render template "%s": %s', $template, $e->getMessage()), 0, $e);
            }
        }

        array_pop($this->parameters);

        return $result;
    }
}
