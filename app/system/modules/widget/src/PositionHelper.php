<?php

declare(strict_types=1);

namespace Pagekit\Widget;

use Pagekit\User\Model\User;
use Pagekit\View\Helper\Helper;
use Pagekit\Widget\Model\Widget;

class PositionHelper extends Helper
{
    public function __construct(
        private readonly PositionManager $positions,
        private readonly User $user,
        private readonly object $node,
        private readonly WidgetManager $widget,
    ) {
    }

    /**
     * Set shortcut.
     *
     * @see render()
     */
    public function __invoke($name, $view = null, array $parameters = [])
    {
        return $this->render($name, $view, $parameters);
    }

    /**
     * Checks if the position exists.
     *
     * @param  string $name
     */
    public function exists($name): bool
    {
        return (bool) $this->getWidgets($name);
    }

    /**
     * Renders a position.
     *
     * @param  string       $name
     * @param  array|string $view
     * @param  array        $parameters
     */
    public function render($name, $view = null, array $parameters = []): ?string
    {
        if (is_array($view)) {
            $parameters = $view;
            $view = false;
        }

        $parameters['widgets'] = $this->getWidgets($name);

        return $this->view->render($view ?: 'system/site/position.php', $parameters);
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'position';
    }

    /**
     * @param  string|null $position
     * @return Widget[]
     */
    protected function getWidgets($position)
    {
        static $widgets, $positions = [];

        if (null === $widgets) {
            $widgets = Widget::where(['status' => 1])->get();
        }

        if (!$pos = $this->positions->get($position)) {
            return [];
        }

        if (!isset($positions[$position])) {

            $positions[$position] = [];

            foreach ($pos['assigned'] as $id) {

                if (!isset($widgets[$id])
                    or !$widget = $widgets[$id]
                    or !$widget->hasAccess($this->user)
                    or ($nodes = $widget->nodes and !in_array($this->node->id, $nodes))
                    or !$type = $this->widget->get($widget->type)
                ) {
                    continue;
                }

                $result = $type->render($widget);

                $widget->set('result', $result);
                $positions[$position][] = $widget;
            }
        }

        return $positions[$position];
    }
}
