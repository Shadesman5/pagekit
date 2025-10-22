<?php

declare(strict_types=1);

namespace Pagekit\Widget\Model;

interface TypeInterface extends \JsonSerializable
{
    /**
     * Renders the widget.
     *
     * @param  Widget $widget
     */
    public function render(Widget $widget): string;
}
