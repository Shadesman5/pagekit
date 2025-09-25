<?php

namespace Pagekit\Dashboard\Controller;

use Pagekit\Application as App;
use Pagekit\Module\Module;

/**
 * @Access(admin=true)
 */
class DashboardController
{
    protected Module $dashboard;

    protected string $api = 'http://api.openweathermap.org/data/2.5';

    protected string $apiKey = '08c012f513db564bd6d4bae94b73cc94';

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->dashboard = App::module('system/dashboard');
    }

    /**
     * @Route("/", methods="GET")
     */
    public function indexAction(): array
    {
        return [
            '$view' => [
                'title' => __('Dashboard'),
                'name' => 'system/dashboard:views/index.php'
            ],
            '$data' => [
                'widgets' => array_values($this->dashboard->getWidgets()),
                'api' => App::get('system.api'),
                'version' => App::version(),
                'channel' => 'stable'
            ]
        ];
    }

    /**
     * @Route("/savewidgets", methods="POST")
     */
    public function saveWidgetsAction(): array
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $request = App::request();
        
        $widgets = $request->request->all()['widgets'] ?? [];
        if (empty($widgets) && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
            $widgets = $json['widgets'] ?? [];
        }

        $widgets = array_replace($this->dashboard->getWidgets(), $widgets);

        $this->dashboard->saveWidgets($widgets);

        return ['widgets' => $widgets];
    }


    /**
     * @Route("/", methods="POST")
     * @Route("/{id}", methods="POST", requirements={"id"="\w+"})
     */
    public function saveAction($id = 0)
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $request = App::request();
        
        if (!$id) {
            $id = $request->request->get('id', 0);
        }
        
        $widget = $request->request->all()['widget'] ?? [];
        if (empty($widget) && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
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
     * @Route("/{id}", methods="DELETE", requirements={"id"="\w+"})
     */
    public function deleteAction($id = null): array
    {
        // Get id from route if not provided (Symfony 6.4 compatibility)
        if (!$id) {
            $id = App::request()->get('id');
        }
        
        $widgets = $this->dashboard->getWidgets();

        unset($widgets[$id]);

        $this->dashboard->saveWidgets($widgets);

        return ['message' => __('Widget deleted.')];
    }

    /**
     * @Route("/reorder", methods="POST")
     */
    public function reorderAction(): array
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $request = App::request();
        
        $order = $request->request->all()['order'] ?? [];
        if (empty($order) && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
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

    /**
     * @Route("/weather", methods="GET")
     */
    public function weatherAction()
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $request = App::request();
        
        // Weather widget uses GET parameters
        $data = $request->query->all()['data'] ?? [];
        $action = $request->query->get('action', '');
        
        $url = $this->api;

        if ($action === 'weather') {
            $url .= '/weather';
        } elseif ($action === 'find') {
            $url .= '/find';
        }

        $data['APPID'] = $this->apiKey;
        $url .= '?' . http_build_query($data);

        return App::response(file_get_contents((string) $url), 200, ['Content-Type' => 'application/json']);
    }
}
