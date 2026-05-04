<?php

declare(strict_types=1);

namespace Pagekit\Widget\Controller;

use function Pagekit\__;

use Pagekit\Routing\Attribute\Request;
use Pagekit\Site\MenuManager;
use Pagekit\Site\Model\Node;
use Pagekit\User\Attribute\Access;
use Pagekit\User\Model\Role;
use Pagekit\Widget\Model\Widget;
use Pagekit\Widget\PositionManager;
use Pagekit\Widget\WidgetManager;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Access('system: manage widgets', admin: true)]
class WidgetController
{
    public function __construct(
        private readonly WidgetManager $widget,
        private readonly MenuManager $menu,
        private readonly PositionManager $position,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function indexAction(): array
    {
        return [
            '$view' => [
                'title' => __('Widgets'),
                'name' => 'system/widget/index.php',
            ],
            '$data' => [
                'widgets' => array_values(Widget::findAll()),
                'types' => $this->widget->all(),
                'config' => [
                    'menus' => $this->menu,
                    'nodes' => array_values(Node::query()->get()),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[Request(['id' => 'int', 'type' => 'string'])]
    public function editAction(int $id = 0, ?string $type = null): array
    {
        if (!$id) {
            $widget = Widget::create(['type' => $type]);
        } elseif (!$widget = Widget::find($id)) {
            throw new NotFoundHttpException('Widget not found.');
        }

        if ($widget->id) {
            $positions = $this->position->all();
            foreach ($positions as $position) {
                if (in_array($widget->id, $position['assigned'])) {
                    $widget->position = $position['name'];

                    break;
                }
            }
        }

        return [
            '$view' => [
                'title' => __('Widgets'),
                'name' => 'system/widget/edit.php',
            ],
            '$data' => [
                'widget' => $widget,
                'config' => [
                    'menus' => $this->menu,
                    'nodes' => array_values(Node::query()->get()),
                    'roles' => array_values(Role::findAll()),
                    'types' => array_values($this->widget->all()),
                    'positions' => array_values($this->position->all()),
                ],
            ],
        ];
    }
}
