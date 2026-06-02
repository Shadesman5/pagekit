<?php

declare(strict_types=1);

namespace Pagekit\System\Controller;

use Pagekit\Application\Response as PagekitResponse;
use Pagekit\View\View;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ExceptionController
{
    public function __construct(
        private readonly View $view,
        private readonly PagekitResponse $response,
    ) {
    }

    /**
     * Converts an Exception to a Response.
     */
    public function showAction(Request $request, FlattenException $exception): Response
    {
        if (is_subclass_of($exception->getClass(), 'Pagekit\Kernel\Exception\HttpException')) {
            $title = $exception->getMessage();
            if ($exception->getCode() === 404) {
                $title = __('Page not found.');
            }
        } else {
            $title = __('Whoops, looks like something went wrong.');
        }

        $content = $this->getAndCleanOutputBuffering((int) ($request->headers->get('X-Php-Ob-Level') ?? -1));
        $rendered = ($this->view)('system/error.php', compact('title', 'exception', 'content'));

        $statusCode = $exception->getCode();
        if ($statusCode < 100 || $statusCode > 599) {
            $statusCode = 500;
        }

        return $this->response->create($rendered, $statusCode, $exception->getHeaders());
    }

    /**
     * Cleans output buffer.
     */
    protected function getAndCleanOutputBuffering(int $level): string
    {
        if (ob_get_level() <= $level) {
            return '';
        }

        Response::closeOutputBuffers($level + 1, true);

        return (string) ob_get_clean();
    }
}
