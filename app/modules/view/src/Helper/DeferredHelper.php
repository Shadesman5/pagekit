<?php

declare(strict_types=1);

namespace Pagekit\View\Helper;

use Pagekit\Event\EventDispatcherInterface;
use Pagekit\View\Event\ViewEvent;
use Pagekit\View\View;

class DeferredHelper implements HelperInterface
{
    protected \Pagekit\Event\EventDispatcherInterface $events;

    /** @var array<string, ViewEvent> */
    protected array $deferred = [];

    /** @var array<string, string> */
    protected array $placeholder = [];

    public function __construct(EventDispatcherInterface $events)
    {
        $this->events = $events;
    }

    /**
     * {@inheritdoc}
     */
    public function register(View $view): void
    {
        $view->on('render', function ($event) {

            $name = $event->getTemplate();

            if (isset($this->placeholder[$name])) {

                $this->deferred[$name] = clone $event;

                $event->setResult($this->placeholder[$name]);
                $event->stopPropagation();
            }

        }, 15);

        $this->events->on('response', function ($e, $request, $response) use ($view) {

            foreach ($this->deferred as $name => $event) {
                $view->trigger($event->setName($name), [$view]);
                $response->setContent(str_replace($this->placeholder[$name], $event->getResult(), $response->getContent()));
            }

        }, 10);
    }

    /**
     * Defers a template render call.
     */
    public function __invoke(string $name): void
    {
        $this->placeholder[$name] = sprintf('<!-- %s -->', uniqid());
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'defer';
    }
}
