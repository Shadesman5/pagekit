<?php

declare(strict_types=1);

namespace Pagekit\Kernel;

use Pagekit\Kernel\Exception\HttpException;
use Symfony\Component\ErrorHandler\ErrorHandler as DebugExceptionHandler;
use Symfony\Component\ErrorHandler\Exception\FlattenException;

class ExceptionHandler extends DebugExceptionHandler
{
    /**
     * {@inheritdoc}
     */
    public function sendPhpResponse($exception): void
    {
        if ($exception instanceof HttpException) {
            $exception = FlattenException::create($exception, $exception->getCode());
        }

        parent::sendPhpResponse($exception);
    }

    /**
     * {@inheritdoc}
     */
    public function createResponse($exception)
    {
        if ($exception instanceof HttpException) {
            $exception = FlattenException::create($exception, $exception->getCode());
        }

        return parent::createResponse($exception);
    }
}
