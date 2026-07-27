<?php

declare(strict_types=1);

namespace Pagekit\Routing;

interface ResourceInterface extends \Serializable
{
    /**
     * Gets the resources modified time.
     */
    public function getModified(): int;
}
