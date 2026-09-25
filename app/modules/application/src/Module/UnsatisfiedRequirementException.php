<?php

declare(strict_types=1);

namespace Pagekit\Module;

/**
 * A module named a requirement this installation will not load.
 */
final class UnsatisfiedRequirementException extends \RuntimeException
{
    public function __construct(
        public readonly string $depender,
        public readonly string $requirement,
        public readonly bool $registered,
    ) {
        parent::__construct(strtr(self::sentence($registered), [
            '%depender%' => $depender,
            '%required%' => $requirement,
        ]));
    }

    /**
     * Catalogue id for the panel. The exception message is this sentence with the names filled in.
     */
    public function messageId(): string
    {
        return self::sentence($this->registered);
    }

    private static function sentence(bool $registered): string
    {
        return $registered
            ? 'Module "%depender%" requires "%required%", which is registered but disabled.'
            : 'Module "%depender%" requires "%required%", which is not registered.';
    }
}
