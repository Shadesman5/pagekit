<?php

declare(strict_types=1);

namespace Pagekit\Kernel\Exception;

class BadRequestException extends HttpException
{
    /**
     * {@inheritdoc}
     */
    public function __construct($message = null, $previous = null, $code = 400)
    {
        parent::__construct($message ?: 'Bad Request', $previous, $code);
    }
}
