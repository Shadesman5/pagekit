<?php

declare(strict_types=1);

namespace Pagekit\Kernel\Event;

use Pagekit\Kernel\Exception\HttpException as PagekitHttpException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ExceptionListenerWrapper
{
    /** @var \Closure */
    protected \Closure $closure;

    /**
     * @param callable $callback  Accepts closures, [$obj,'method'], and invokable objects.
     *                            Converted to Closure internally for uniform reflection.
     */
    public function __construct(callable $callback)
    {
        $this->closure = $callback instanceof \Closure
            ? $callback
            : \Closure::fromCallable($callback);
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getException();

        if ($exception === null) {
            return;
        }

        if (!$this->shouldRun($exception)) {
            return;
        }

        $code = $this->resolveStatusCode($exception);

        $response = ($this->closure)($exception, $code);

        if ($response instanceof Response) {
            $event->setResponse($response);
        }
    }

    private function resolveStatusCode(\Throwable $exception): int
    {
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode();
        }

        if ($exception instanceof PagekitHttpException) {
            return $exception->getCode();
        }

        return 500;
    }

    protected function shouldRun(\Throwable $exception): bool
    {
        $reflection = new \ReflectionFunction($this->closure);

        if ($reflection->getNumberOfParameters() > 0) {
            $paramType = $reflection->getParameters()[0]->getType();
            if ($paramType instanceof \ReflectionNamedType && !$paramType->isBuiltin()
                && !($exception instanceof ($paramType->getName()))) {
                return false;
            }
        }

        return true;
    }
}
