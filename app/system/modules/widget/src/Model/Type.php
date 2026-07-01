<?php

declare(strict_types=1);

namespace Pagekit\Widget\Model;

use Pagekit\Module\Module;

class Type extends Module implements TypeInterface
{
    /**
     * {@inheritdoc}
     */
    public function render(Widget $widget): string
    {
        if (is_callable($this->get('render'))) {
            return call_user_func($this->get('render'), $widget);
        }

        return '';
    }

    /**
     * {@inheritdoc}
     *
     * @return mixed Genuinely unknown type — required by \JsonSerializable contract; this implementation returns an array of widget type properties.
     */
    public function jsonSerialize(): mixed
    {
        return $this->get(['name', 'label']);
    }
}
