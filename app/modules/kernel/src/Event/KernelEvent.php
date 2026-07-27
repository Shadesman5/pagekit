<?php

declare(strict_types=1);

namespace Pagekit\Kernel\Event;

use Pagekit\Event\Event;
use Pagekit\Kernel\HttpKernelInterface;

class KernelEvent extends Event
{
    protected HttpKernelInterface $kernel;

    public function __construct(string $name, HttpKernelInterface $kernel)
    {
        parent::__construct($name);

        $this->kernel = $kernel;
    }

    /**
     * Gets the kernel.
     */
    public function getKernel(): HttpKernelInterface
    {
        return $this->kernel;
    }

    /**
     * Checks if this is a master request.
     */
    public function isMasterRequest(): bool
    {
        return $this->kernel->isMasterRequest();
    }
}
