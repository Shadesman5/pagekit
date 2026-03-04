<?php

declare(strict_types=1);

namespace Pagekit\Dashboard\Controller;

use Pagekit\Application as App;
use Pagekit\Module\Module;
use Pagekit\Routing\Attribute\Route;
use Pagekit\User\Attribute\Access;
use function Pagekit\__;

#[Access(admin: true)]
class DashboardController
{
    protected Module $dashboard;

    protected string $api = 'http://api.openweathermap.org/data/2.5';

    protected string $apiKey = '08c012f513db564bd6d4bae94b73cc94';

    public function __construct(
        private readonly mixed $module,
        private readonly mixed $request,
        private readonly mixed $response,
        private readonly mixed $version,
    ) {
        $this->dashboard = $this->module->get('system/dashboard');
    }

    #[Route('/', methods: ['GET'])]
    public function indexAction(): array
    {
        return [
            '$view' => [
                'title' => __('Dashboard'),
                'name' => 'system/dashboard:views/index.php'
            ],
            '$data' => [
                'widgets' => array_values($this->dashboard->getWidgets()),
                'api' => App::getInstance()->get('system.api'), // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
                'version' => $this->version,
                'channel' => 'stable'
            ]
        ];
    }

    #[Route('/savewidgets', methods: ['POST'])]
    public function saveWidgetsAction(): array
    {
        $widgets = $this->request->request->all()['widgets'] ?? [];
        if (empty($widgets) && $this->request->getContent()) {
            $json = json_decode($this->request->getContent(), true);
            $widgets = $json['widgets'] ?? [];
        }

        $widgets = array_replace($this->dashboard->getWidgets(), $widgets);

        $this->dashboard->saveWidgets($widgets);

        return ['widgets' => $widgets];
    }


    #[Route('/', methods: ['POST'])]
    #[Route('/{id}', methods: ['POST'], requirements: ['id' => '\w+'])]
    public function saveAction($id = 0)
    {
        if (!$id) {
            $id = $this->request->request->get('id', 0);
        }
        
        $widget = $this->request->request->all()['widget'] ?? [];
        if (empty($widget) && $this->request->getContent()) {
            $json = json_decode($this->request->getContent(), true);
            $widget = $json['widget'] ?? [];
            if (!$id && isset($json['id'])) {
                $id = $json['id'];
            }
        }
        
        if ($new = !$id) {
            $id = uniqid();
        }

        $widget['id'] = $id;

        $this->dashboard->saveWidgets(array_replace($this->dashboard->getWidgets(), [$id => $widget]));

        return $widget;
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\w+'])]
    public function deleteAction($id = null): array
    {
        if (!$id) {
            $id = $this->request->get('id');
        }
        
        $widgets = $this->dashboard->getWidgets();

        unset($widgets[$id]);

        $this->dashboard->saveWidgets($widgets);

        return ['message' => __('Widget deleted.')];
    }

    #[Route('/reorder', methods: ['POST'])]
    public function reorderAction(): array
    {
        $order = $this->request->request->all()['order'] ?? [];
        if (empty($order) && $this->request->getContent()) {
            $json = json_decode($this->request->getContent(), true);
            $order = $json['order'] ?? [];
        }
        
        $widgets = $this->dashboard->getWidgets();
        $reordered = [];

        foreach ($order as $id) {
            if ($widget = $this->dashboard->getWidget($id)) {
                $reordered[$id] = $widget;
            }
        }

        if (count($widgets) === count($reordered)) {
            $this->dashboard->saveWidgets($reordered);
        }

        return ['message' => __('Widgets reordered.')];
    }

    #[Route('/weather', methods: ['GET'])]
    public function weatherAction()
    {
        $data = $this->request->query->all()['data'] ?? [];
        $action = $this->request->query->get('action', '');
        
        $url = $this->api;

        if ($action === 'weather') {
            $url .= '/weather';
        } elseif ($action === 'find') {
            $url .= '/find';
        }

        $data['APPID'] = $this->apiKey;
        $url .= '?' . http_build_query($data);

        return ($this->response)(file_get_contents((string) $url), 200, ['Content-Type' => 'application/json']);
    }
}
