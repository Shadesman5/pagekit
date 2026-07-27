<?php

declare(strict_types=1);

namespace Pagekit\Kernel\Event;

use Pagekit\Event\EventSubscriberInterface;
use Pagekit\Kernel\Exception\HttpException;
use Psr\Log\LoggerInterface;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpFoundation\Request;

class ExceptionListener implements EventSubscriberInterface
{
    /** @var callable|string|array{0: object|class-string, 1: string} */
    protected mixed $controller;

    protected ?LoggerInterface $logger = null;

    /**
     * @param callable|string|array{0: object|class-string, 1: string} $controller
     */
    public function __construct(callable|string|array $controller, ?LoggerInterface $logger = null)
    {
        $this->controller = $controller;
        $this->logger = $logger;
    }

    public function onException(ExceptionEvent $event, Request $request): bool|null
    {
        static $handling;

        if ($handling === true) {
            return false;
        }

        $handling = true;

        $exception = $event->getException();

        if ($exception === null) {
            return null;
        }

        $this->logException($exception, sprintf('Uncaught PHP Exception %s: "%s" at %s line %s', get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine()));

        $request = $this->duplicateRequest($exception, $request);

        try {

            $response = $event->getKernel()->handle($request);

        } catch (\Exception $e) {

            $this->logException($e, sprintf('Exception thrown when handling an exception (%s: %s at %s line %s)', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));

            $handling = false;
            $wrapper = $e;

            while ($prev = $wrapper->getPrevious()) {
                if ($exception === $wrapper = $prev) {
                    throw $e;
                }
            }

            $prev = new \ReflectionProperty('Exception', 'previous');
            $prev->setValue($wrapper, $exception);

            throw $e;
        }

        $event->setResponse($response);

        $handling = false;

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function subscribe(): array
    {
        return [
            'exception' => ['onException', -100],
        ];
    }

    /**
     * Logs an exception.
     */
    protected function logException(\Exception $exception, string $message): void
    {
        if ($this->logger !== null) {
            if (!$exception instanceof HttpException || $exception->getCode() >= 500) {
                $this->logger->critical($message, ['exception' => $exception]);
            } else {
                $this->logger->error($message, ['exception' => $exception]);
            }
        }
    }

    /**
     * Clones the request for the exception.
     *
     * @param \Exception $exception
     * @param  Request   $request
     * @return Request   $request
     */
    protected function duplicateRequest(\Exception $exception, Request $request): Request
    {
        $attributes = [
            '_controller' => $this->controller,
            'exception' => FlattenException::create($exception),
            'logger' => $this->logger,
        ];

        $request = $request->duplicate(null, null, $attributes);
        $request->setMethod('GET');

        return $request;
    }
}
