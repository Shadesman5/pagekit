<?php

namespace Pagekit\View;

use Pagekit\Event\EventDispatcherInterface;
use Pagekit\Event\EventInterface;
use Pagekit\Event\PrefixEventDispatcher;
use Pagekit\Util\ArrObject;
use Pagekit\View\Event\ViewEvent;
use Pagekit\View\Helper\HelperInterface;
use Pagekit\View\Engine\EngineInterface;
use Pagekit\View\Engine\DelegatingEngine;

class View
{
    protected \Pagekit\Event\EventDispatcherInterface $events;

    protected EngineInterface $engine;

    protected array $globals = [];

    /**
     * @var HelperInterface[]
     */
    protected array $helpers = [];

    /**
     * @var array[]
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
     */
    public function __invoke($name, array $parameters = [])
    {
        return $this->render($name, $parameters);
    }

    /**
     * Gets a helper or calls the helpers invoke method.
     *
     * @param  string $name
     * @param  array  $args
     * @return mixed
     */
    public function __call($name, $args)
    {
        if (!isset($this->helpers[$name])) {
            throw new \InvalidArgumentException(sprintf('Undefined helper "%s"', $name));
        }

        return $args ? call_user_func_array($this->helpers[$name], $args) : $this->helpers[$name];
    }

    /**
     * Gets a global parameter.
     *
     * @param  string $name
     * @return mixed
     */
    public function __get($name)
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
     */
    public function getGlobals(): array
    {
        return $this->globals;
    }

    /**
     * Adds a global parameter.
     *
     * @param  string $name
     * @param  mixed  $value
     */
    public function addGlobal($name, $value): self
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
     *
     * @param  string   $event
     * @param  callable $listener
     * @param  int      $priority
     */
    public function on($event, $listener, $priority = 0): void
    {
        $this->events->on($event, $listener, $priority);
    }

    /**
     * Triggers an event.
     *
     * @param  string $event
     * @param  array  $arguments
     */
    public function trigger($event, array $arguments = []): EventInterface
    {
        return $this->events->trigger($event, $arguments);
    }

    /**
     * {@inheritdoc}
     */
    public function render($name, array $parameters = []): ?string
    {
        error_log("[VIEW DEBUG] render() called with name: $name");
        error_log("[VIEW DEBUG] Parameters: " . json_encode(array_keys($parameters)));
        error_log("[VIEW DEBUG] Globals: " . json_encode(array_keys($this->globals)));
        
        $event = new ViewEvent('render', $name);
        $event->setParameters(array_replace($this->globals, end($this->parameters) ?: [], $parameters));

        error_log("[VIEW DEBUG] Triggering event for: $name");
        $this->events->trigger($event, [$this]);

        if (!$event->isPropagationStopped()) {
            $name = preg_replace('/\.php$/i', '', $name);
            error_log("[VIEW DEBUG] Triggering event again for cleaned name: $name");
            $this->events->trigger($event->setName($name), [$this]);
        }

        $result = $event->getResult();
        $params = $this->parameters[] = $event->getParameters();
        
        error_log("[VIEW DEBUG] Event result: " . ($result !== null ? 'HAS RESULT' : 'NULL'));

        if ($result === null) {
            $template = $event->getTemplate();
            error_log("[VIEW DEBUG] Attempting to render template: $template");
            
            // Render the template with our engine (PhpEngine or Twig)
            try {
                $result = $this->engine->render($template, $params);
                error_log("[VIEW DEBUG] Template rendered successfully");
            } catch (\Exception $e) {
                error_log("[VIEW DEBUG] Template rendering failed: " . $e->getMessage());
                // Template rendering failed
                throw new \RuntimeException(sprintf('Failed to render template "%s": %s', $template, $e->getMessage()), 0, $e);
            }
        }

        array_pop($this->parameters);

        error_log("[VIEW DEBUG] render() returning result length: " . strlen($result ?? ''));
        return $result;
    }
}
