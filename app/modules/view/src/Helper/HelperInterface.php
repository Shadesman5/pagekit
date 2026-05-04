<?php

declare(strict_types=1);

namespace Pagekit\View\Helper;

use Pagekit\View\View;

interface HelperInterface
{
    /**
     * Registers the helper.
     */
    public function register(View $view): void;

    /**
     * Returns the name.
     */
    public function getName(): string;
}
