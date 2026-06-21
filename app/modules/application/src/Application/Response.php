<?php

declare(strict_types=1);

namespace Pagekit\Application;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Response
{
    public function __construct(protected UrlProvider $url)
    {
    }

    /**
     * Create shortcut.
     *
     * @see create()
     *
     * @param array<string, string> $headers
     */
    public function __invoke(mixed $content = '', int $status = 200, array $headers = []): HttpResponse
    {
        return $this->create($content, $status, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public function create(mixed $content = '', int $status = 200, array $headers = []): HttpResponse
    {
        return new HttpResponse($content, $status, $headers);
    }

    /**
     * @param array<string, mixed>  $parameters
     * @param array<string, string> $headers
     */
    public function redirect(string $url, array $parameters = [], int $status = 302, array $headers = []): RedirectResponse
    {
        $resolved = $this->url->get($url, $parameters);

        if ($resolved === false) {
            throw new \InvalidArgumentException(sprintf('Cannot redirect to "%s": URL or route could not be resolved.', $url));
        }

        return new RedirectResponse($resolved, $status, $headers);
    }

    /**
     * @param string|array<int|string, mixed> $data
     * @param array<string, string>           $headers
     */
    public function json(string|array $data = [], int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public function stream(callable $callback, int $status = 200, array $headers = []): StreamedResponse
    {
        return new StreamedResponse($callback, $status, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public function download(string $file, ?string $name = null, array $headers = []): BinaryFileResponse
    {
        $response = new BinaryFileResponse($file, 200, $headers, true, 'attachment');

        if ($name !== null) {
            $response->setContentDisposition('attachment', $name);
        }

        return $response;
    }
}
