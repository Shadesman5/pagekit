<?php

declare(strict_types=1);

namespace Pagekit\Widget;

use Pagekit\Database\ORM\Repository;
use Pagekit\Site\Model\Node;
use Pagekit\User\Model\User;
use Pagekit\View\Helper\Helper;
use Pagekit\Widget\Model\Widget;

class PositionHelper extends Helper
{
    /** @var array<int|string, Widget>|null */
    private ?array $activeWidgets = null;

    /** @var array<string, array<int, Widget>> */
    private array $renderedPositions = [];

    /**
     * @param Repository<Widget> $widgets
     */
    public function __construct(
        private readonly PositionManager $positions,
        private readonly User $user,
        private readonly Node $node,
        private readonly WidgetManager $widget,
        private readonly Repository $widgets,
    ) {
    }

    /**
     * Set shortcut.
     *
     * @see render()
     *
     * @param array<string, mixed>|string|null $view
     * @param array<string, mixed>             $parameters
     */
    public function __invoke(string $name, array|string|null $view = null, array $parameters = []): ?string
    {
        return $this->render($name, $view, $parameters);
    }

    /**
     * Checks if the position exists.
     */
    public function exists(string $name): bool
    {
        return (bool) $this->getWidgets($name);
    }

    /**
     * Renders a position.
     *
     * @param array<string, mixed>|string|null $view
     * @param array<string, mixed>             $parameters
     */
    public function render(string $name, array|string|null $view = null, array $parameters = []): ?string
    {
        if (is_array($view)) {
            $parameters = $view;
            $view = false;
        }

        $parameters['widgets'] = $this->getWidgets($name);

        if ($this->view === null) {
            throw new \LogicException('PositionHelper has not been registered with a View instance.');
        }

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
     * @return array<int, Widget>
     */
    protected function getWidgets(?string $position): array
    {
        if (null === $this->activeWidgets) {
            $this->activeWidgets = $this->widgets->where(['status' => 1])->get();
        }

        if ($position === null) {
            return [];
        }

        if (!$pos = $this->positions->get($position)) {
            return [];
        }

        if (!isset($this->renderedPositions[$position])) {

            $this->renderedPositions[$position] = [];
            $widgets = $this->activeWidgets;

            foreach ($pos['assigned'] as $id) {

                if (!isset($widgets[$id])) {
                    continue;
                }

                $widget = $widgets[$id];

                if (!$widget->hasAccess($this->user)
                    or ($nodes = $widget->nodes and !in_array($this->node->id, $nodes))
                    or !$type = $this->widget->get($widget->type ?? '')
                ) {
                    continue;
                }

                $result = $type->render($widget);

                $widget->set('result', $result);
                $this->renderedPositions[$position][] = $widget;
            }
        }

        return $this->renderedPositions[$position];
    }
}
