<?php

declare(strict_types=1);

namespace Pagekit\Session;

use Symfony\Component\HttpFoundation\Session\Flash\AutoExpireFlashBag;

class MessageBag extends AutoExpireFlashBag
{
    /**
     * Detailed debug information
     */
    public const DEBUG = 'debug';

    /**
     * Interesting events
     */
    public const INFO = 'info';

    /**
     * Exceptional occurrences that are not errors
     */
    public const WARNING = 'warning';

    /**
     * Runtime errors
     */
    public const ERROR = 'error';

    /**
     * Success messages
     */
    public const SUCCESS = 'success';

    public function __construct(string $name = 'messages', string $storageKey = '_pk_messages')
    {
        parent::__construct($storageKey);

        $this->setName($name);
    }

    public function debug(string $message): void
    {
        $this->add(self::DEBUG, $message);
    }

    public function info(string $message): void
    {
        $this->add(self::INFO, $message);
    }

    public function warning(string $message): void
    {
        $this->add(self::WARNING, $message);
    }

    public function error(string $message): void
    {
        $this->add(self::ERROR, $message);
    }

    public function success(string $message): void
    {
        $this->add(self::SUCCESS, $message);
    }

    /**
     * Gets array of message levels
     *
     * @return list<string>
     */
    public static function levels(): array
    {
        return [
            self::DEBUG,
            self::INFO,
            self::WARNING,
            self::ERROR,
            self::SUCCESS,
        ];
    }
}
