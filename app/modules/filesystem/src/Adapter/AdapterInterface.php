<?php

declare(strict_types=1);

namespace Pagekit\Filesystem\Adapter;

interface AdapterInterface
{
    /**
     * Gets stream wrapper classname.
     */
    public function getStreamWrapper(): ?string;

    /**
     * Gets file path info.
     *
     * @param array<string, mixed> $info
     *
     * @return array<string, mixed>
     */
    public function getPathInfo(array $info): array;
}
