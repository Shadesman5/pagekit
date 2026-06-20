<?php

declare(strict_types=1);

namespace Pagekit\Debug\Event;

use Pagekit\Event\EventDispatcherInterface;
use Pagekit\Event\EventInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * @author    Fabien Potencier <fabien@symfony.com>
 * @copyright Copyright (c) 2004-2015 Fabien Potencier
 */
class WrappedListener
{
    /** @var callable */
    protected mixed $listener;
    protected string $name;
    protected ?int $priority;
    protected bool $called;
    protected bool $stoppedPropagation;
    protected Stopwatch $stopwatch;
    protected ?EventDispatcherInterface $dispatcher = null;

    public function __construct(callable $listener, string $name, ?int $priority, Stopwatch $stopwatch, ?EventDispatcherInterface $dispatcher = null)
    {
        $this->listener = $listener;
        $this->name = $name;
        $this->priority = $priority;
        $this->stopwatch = $stopwatch;
        $this->dispatcher = $dispatcher;
        $this->called = false;
        $this->stoppedPropagation = false;
    }

    /**
     * @return callable
     */
    public function getWrappedListener()
    {
        return $this->listener;
    }

    public function getPriority(): ?int
    {
        return $this->priority;
    }

    public function wasCalled(): bool
    {
        return $this->called;
    }

    public function stoppedPropagation(): bool
    {
        return $this->stoppedPropagation;
    }

    public function __invoke(EventInterface $event): void
    {
        $this->called = true;

        $e = $this->stopwatch->start($this->name, 'event_listener');

        $args = func_get_args();

        call_user_func_array($this->listener, $args);

        if ($e->isStarted()) {
            $e->stop();
        }

        if ($event->isPropagationStopped()) {
            $this->stoppedPropagation = true;
        }
    }
}
