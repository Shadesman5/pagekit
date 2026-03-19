<?php

declare(strict_types=1);

namespace Pagekit\Kernel\Event;

use Pagekit\Kernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Response;

class ExceptionListenerWrapper
{
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

        $code = $exception instanceof HttpException ? $exception->getCode() : 500;

        $response = call_user_func($this->callback, $exception, $code);

        if ($response instanceof Response) {
            $event->setResponse($response);
        }
    }

    protected function shouldRun(\Exception $exception): bool
    {
        $callbackReflection = new \ReflectionFunction($this->callback);

        if ($callbackReflection->getNumberOfParameters() > 0) {
            $parameters = $callbackReflection->getParameters();
            $expectedException = $parameters[0];
            $paramType = $expectedException->getType();
            if ($paramType instanceof \ReflectionNamedType && !$paramType->isBuiltin() && 
                !($exception instanceof ($paramType->getName()))) {
                return false;
            }
        }

        return true;
    }
}
