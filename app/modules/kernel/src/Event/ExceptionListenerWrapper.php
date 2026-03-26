<?php

declare(strict_types=1);

namespace Pagekit\Kernel\Event;

use Pagekit\Kernel\Exception\HttpException as PagekitHttpException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ExceptionListenerWrapper
{
    // Only \Closure is accepted — all internal callers use fn()/function() literals.
    public function __construct(
        protected \Closure $callback,
    ) {
    }

    public function __invoke(object $event): void
    {
        $exception = $event->getException();

        if (!$this->shouldRun($exception)) {
            return;
        }

        $code = $this->resolveStatusCode($exception);

        $response = call_user_func($this->callback, $exception, $code);

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
        $callbackReflection = new \ReflectionFunction($this->callback);

        if ($callbackReflection->getNumberOfParameters() > 0) {
            $paramType = $callbackReflection->getParameters()[0]->getType();
            if ($paramType instanceof \ReflectionNamedType && !$paramType->isBuiltin()
                && !($exception instanceof ($paramType->getName()))) {
                return false;
            }
        }

        return true;
    }
}
