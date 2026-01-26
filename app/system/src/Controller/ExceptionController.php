<?php

namespace Pagekit\System\Controller;

use Pagekit\Application as App;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ExceptionController
{
    /**
     * Converts an Exception to a Response.
     *
     * @param  Request          $request
     * @param  FlattenException $exception
     */
    public function showAction(Request $request, FlattenException $exception): Response
    {
        // if (is_subclass_of($exception->getClass(), 'Pagekit\Kernel\Exception\HttpException')) {
        //     $title = $exception->getMessage();
        // } else {
        //     $title = __('Whoops, looks like something went wrong.');
        // }

        if (is_subclass_of($exception->getClass(), 'Pagekit\Kernel\Exception\HttpException')) {
            $title = $exception->getMessage();
            if ($exception->getCode() == 404)
                $title = __('Page not found.');
            } else {
                $title = __('Whoops, looks like something went wrong.');
        }

        $content  = $this->getAndCleanOutputBuffering($request->headers->get('X-Php-Ob-Level', -1));
        $response = App::view('system/error.php', compact('title', 'exception', 'content'));

        // Ensure a valid HTTP status code (must be between 100 and 599)
        $statusCode = $exception->getCode();
        if ($statusCode < 100 || $statusCode > 599) {
            $statusCode = 500;
        }

        return App::response($response, $statusCode, $exception->getHeaders());
    }

    /**
     * Cleans output buffer.
     *
     * @param  int    $level
     * @return string
     */
    protected function getAndCleanOutputBuffering($level)
    {
        if (ob_get_level() <= $level) {
            return '';
        }

        Response::closeOutputBuffers($level + 1, true);

        return ob_get_clean();
    }
}
