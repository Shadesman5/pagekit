<?php

declare(strict_types=1);

namespace Pagekit\Debug\DataCollector;

use DebugBar\DataCollector\DataCollectorInterface;
use Pagekit\Debug\Event\TraceableEventDispatcher;
use Pagekit\Event\EventDispatcherInterface;

class EventDataCollector implements DataCollectorInterface
{
    /** @var array<string, mixed> */
    protected array $data = [];
    protected EventDispatcherInterface $dispatcher;
    protected string $base;

    public function __construct(EventDispatcherInterface $dispatcher, string $base = '')
    {
        $this->dispatcher = $dispatcher;
        $this->base = $base;
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        if ($this->dispatcher instanceof TraceableEventDispatcher) {
            $this->data['called'] = $this->attachLink($this->dispatcher->getCalledListeners());
            $this->data['notcalled'] = $this->attachLink($this->dispatcher->getNotCalledListeners());
        }

        return $this->data;
    }

    /**
     * @param  array<string, array<string, mixed>> $listeners
     * @return array<string, array<string, mixed>>
     */
    public function attachLink(array $listeners): array
    {
        foreach ($listeners as &$listener) {
            if (isset($listener['file'], $listener['line'])) {
                $listener['relative'] = substr($listener['file'], strlen($this->base) + 1);
                $listener['link'] = $this->getFileLink($listener['file'], $listener['line']);
            }
        }

        return $listeners;
    }

    protected function getFileLink(string $file, int $line): string|false
    {
        if ($fileLinkFormat = ini_get('xdebug.file_link_format') and file_exists($file)) {
            return strtr($fileLinkFormat, ['%f' => $file, '%l' => $line]);
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'events';
    }
}
