<?php

namespace Pagekit\View;

use Pagekit\Event\EventDispatcherInterface;
use Pagekit\Event\EventInterface;
use Pagekit\Event\PrefixEventDispatcher;
use Pagekit\Util\ArrObject;
use Pagekit\View\Event\ViewEvent;
use Pagekit\View\Helper\HelperInterface;
use Symfony\Component\Templating\DelegatingEngine;
use Symfony\Component\Templating\EngineInterface;
use Symfony\Component\Templating\TemplateReference;

class View
{
    protected \Pagekit\Event\EventDispatcherInterface $events;

    protected \Symfony\Component\Templating\EngineInterface $engine;

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
        $this->engine->addEngine($engine);

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
        $event = new ViewEvent('render', $name);
        $event->setParameters(array_replace($this->globals, end($this->parameters) ?: [], $parameters));

        $this->events->trigger($event, [$this]);

        if (!$event->isPropagationStopped()) {
            $name = preg_replace('/\.php$/i', '', $name);
            $this->events->trigger($event->setName($name), [$this]);
        }

        $result = $event->getResult();
        $params = $this->parameters[] = $event->getParameters();

        if ($result === null) {
            $template = $event->getTemplate();
            
            // Symfony 6.4 compatibility: Handle string templates differently
            // The engine expects either a TemplateReference or can parse strings directly
            try {
                // Try to render directly with the template string/object
                $result = $this->engine->render($template, $params);
            } catch (\TypeError | \Exception $e) {
                // If that fails, try different approaches
                if (is_string($template)) {
                    // Create a properly configured TemplateReference
                    $templateRef = new TemplateReference($template, 'php');
                    $templateRef->set('name', $template);
                    $templateRef->set('engine', 'php');
                    
                    try {
                        if ($this->engine->supports($templateRef)) {
                            $result = $this->engine->render($templateRef, $params);
                        }
                    } catch (\Exception $e2) {
                        // Last resort: try with just the string
                        try {
                            // Some engines might handle strings directly
                            if (method_exists($this->engine, 'exists') && $this->engine->exists($template)) {
                                $result = $this->engine->render($template, $params);
                            }
                        } catch (\Exception $e3) {
                            // Template rendering failed completely
                        }
                    }
                }
            }
        }

        array_pop($this->parameters);

        return $result;
    }
}
