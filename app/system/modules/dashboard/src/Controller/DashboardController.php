<?php

declare(strict_types=1);

namespace Pagekit\Dashboard\Controller;

use function Pagekit\__;

use Pagekit\Application\Response as PagekitResponse;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\Routing\Attribute\Route;
use Pagekit\User\Attribute\Access;
use Symfony\Component\HttpFoundation\Request;

#[Access(admin: true)]
class DashboardController
{
    protected Module $dashboard;

    protected string $api;

    protected string $apiKey;

    public function __construct(
        private readonly ModuleManager $module,
        private readonly Request $request,
        private readonly PagekitResponse $response,
        private readonly string $version,
        private readonly string $systemApi,
    ) {
        $this->dashboard = $this->module->get('system/dashboard');
        $this->api = $this->dashboard->config('weather.api', 'http://api.openweathermap.org/data/2.5');
        $this->apiKey = $this->dashboard->config('weather.key', '');
    }

    /**
     * @return array<string, mixed>
     */
    #[Route('/', methods: ['GET'])]
    public function indexAction(): array
    {
        return [
            '$view' => [
                'title' => __('Dashboard'),
                'name' => 'system/dashboard:views/index.php',
            ],
            '$data' => [
                'widgets' => array_values($this->dashboard->getWidgets()),
                'api' => $this->systemApi,
                'version' => $this->version,
                'channel' => 'stable',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
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


    /**
     * @return array<string, mixed>
     */
    #[Route('/', methods: ['POST'])]
    #[Route('/{id}', methods: ['POST'], requirements: ['id' => '\w+'])]
    public function saveAction(string $id = ''): array
    {
        if (!$id) {
            $id = (string) $this->request->request->get('id', '');
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

    /**
     * @return array{message: string}
     */
    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\w+'])]
    public function deleteAction(?string $id = null): array
    {
        if (!$id) {
            $id = (string) $this->request->get('id');
        }

        $widgets = $this->dashboard->getWidgets();

        unset($widgets[$id]);

        $this->dashboard->saveWidgets($widgets);

        return ['message' => __('Widget deleted.')];
    }

    /**
     * @return array{message: string}
     */
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
    public function weatherAction(): \Symfony\Component\HttpFoundation\Response
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
