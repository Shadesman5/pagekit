<?php

declare(strict_types=1);

namespace Pagekit\Auth\Handler;

interface HandlerInterface
{
    /**
     * Gets the current user.
     *
     * @return int|null
     */
    public function read(): ?int;

    /**
     * Sets the current user.
     */
    public function write(int|string $user, bool $remember = false): void;

    /**
     * Removes the user.
     */
    public function destroy(): void;
}
