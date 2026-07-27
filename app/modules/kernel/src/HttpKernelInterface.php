<?php

declare(strict_types=1);

namespace Pagekit\Kernel;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface HttpKernelInterface
{
    /**
     * Gets the current request.
     */
    public function getRequest(): ?Request;

    /**
     * Checks if this is a master request.
     */
    public function isMasterRequest(): bool;

    /**
     * Handles the request.
     *
     * @param  Request $request
     */
    public function handle(Request $request): Response;

    /**
     * Aborts the current request with HTTP exception.
     *
     * @param array<string, string> $headers
     * @throws HttpException
     */
    public function abort(int $code, ?string $message = null, array $headers = []): void;

    /**
     * Terminates the current request.
     */
    public function terminate(Request $request, Response $response): void;
}
