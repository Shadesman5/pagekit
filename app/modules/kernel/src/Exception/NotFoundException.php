<?php

declare(strict_types=1);

namespace Pagekit\Kernel\Exception;

class NotFoundException extends HttpException
{
    /**
     * {@inheritdoc}
     */
    public function __construct($message = null, $previous = null, $code = 404)
    {
        parent::__construct($message ?: 'Not Found', $previous, $code);
    }
}
