<?php

declare(strict_types=1);

namespace Pagekit\Kernel\Event;

use Pagekit\Kernel\HttpKernelInterface;

class ExceptionEvent extends KernelEvent
{
    use ResponseTrait;

    protected ?\Exception $exception = null;

    public function __construct(string $name, HttpKernelInterface $kernel, \Exception $e)
    {
        parent::__construct($name, $kernel);

        $this->setException($e);
    }

    /**
     * Gets the thrown exception.
     */
    public function getException(): ?\Exception
    {
        return $this->exception;
    }

    /**
     * Sets the thrown exception.
     *
     * @param \Exception $exception
     */
    public function setException(\Exception $exception): void
    {
        $this->exception = $exception;
    }
}
