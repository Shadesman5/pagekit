<?php

declare(strict_types=1);

namespace Pagekit\View\Helper;

use Pagekit\View\View;

abstract class Helper implements HelperInterface
{
    protected ?View $view = null;

    /**
     * {@inheritdoc}
     */
    public function register(View $view): void
    {
        $this->view = $view;
    }
}
