<?php

declare(strict_types=1);

namespace Pagekit\Kernel;

use Pagekit\Kernel\Exception\HttpException;
use Symfony\Component\ErrorHandler\ErrorHandler as DebugExceptionHandler;
use Symfony\Component\ErrorHandler\Exception\FlattenException;

class ExceptionHandler extends DebugExceptionHandler
{
    /**
     * @param \Throwable|FlattenException $exception
     */
    public function sendPhpResponse(\Throwable|FlattenException $exception): void
    {
        if ($exception instanceof HttpException) {
            $exception = FlattenException::create($exception, $exception->getCode());
        }

        parent::sendPhpResponse($exception);
    }

    /**
     * @param \Throwable|FlattenException $exception
     */
    public function createResponse(\Throwable|FlattenException $exception): \Symfony\Component\HttpFoundation\Response
    {
        if ($exception instanceof HttpException) {
            $exception = FlattenException::create($exception, $exception->getCode());
        }

        return parent::createResponse($exception);
    }
}
