<?php

declare(strict_types=1);

namespace Pagekit\Widget\Controller;

use Pagekit\Application as App;
use Pagekit\System\Controller\ValidatesRequestTrait;
use Pagekit\Widget\Model\Widget;
use function Pagekit\__;

/**
 * API Controller for Widget management.
 *
 * Uses Symfony Validator for entity validation (Step 1.13 - Hybrid Mode).
 *
 * @Access("system: manage widgets")
 */
class WidgetApiController
{
    use ValidatesRequestTrait;

    /**
     * @Route("/", methods="GET")
     */
    public function indexAction(): array
    {
        $widgets = Widget::findAll();
        $positions = App::position()->all();

        foreach ($positions as &$position) {
            $position['widgets'] = [];

            foreach ($position['assigned'] as $id) {
                if (isset($widgets[$id])) {
                    // Set the position property on the widget
                    $widgets[$id]->position = $position['name'];
                    $position['widgets'][] = $widgets[$id];
                    unset($widgets[$id]);
                }
            }
        }

        return ['positions' => array_values($positions), 'unassigned' => array_values($widgets)];
    }

    /**
     * @Route("/{id}", methods="GET", requirements={"id"="\d+"})
     */
    public function getAction(int $id): Widget
    {
        if (!$widget = Widget::find($id)) {
            App::abort(404, 'Widget not found.');
        }

        // Find and set the widget's position
        $positions = App::position()->all();
        foreach ($positions as $position) {
            if (in_array($id, $position['assigned'])) {
                $widget->position = $position['name'];
                break;
            }
        }

        return $widget;
    }

    /**
     * @Route("/assign", methods="POST")
     */
    public function assignAction(): array
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $request = App::request();

        $position = $request->request->get('position', '');
        $ids = $request->request->all()['ids'] ?? [];

        if ($request->getContent()) {
            $json = json_decode($request->getContent(), true);
            if ($json) {
                $position = $json['position'] ?? $position;
                $ids = $json['ids'] ?? $ids;
            }
        }

        App::position()->assign($position, $ids);

        return ['message' => 'success'];
    }

    /**
     * Save a widget (create or update).
     *
     * Uses Symfony Validator for validation (replaces manual validation).
     *
     * @Route("/", methods="POST")
     * @Route("/{id}", methods="POST", requirements={"id"="\d+"})
     */
    public function saveAction(int $id = 0, ?array $data = null): array
    {
        // Get parameters from request if not provided (Symfony 6.4 compatibility)
        if ($data === null) {
            $request = App::request();

            $data = $request->request->all()['widget'] ?? [];
            if (empty($data) && $request->getContent()) {
                $json = json_decode($request->getContent(), true);
                $data = $json['widget'] ?? $json ?? [];
            }
        }

        // Get id from route or data
        if (!$id && isset($data['id'])) {
            $id = (int) $data['id'];
        }

        if (!$id) {
            $widget = Widget::create();
        } elseif (!$widget = Widget::find($id)) {
            App::abort(404, 'Widget not found.');
        }

        // Extract position before saving (it's not a database field)
        $position = isset($data['position']) ? $data['position'] : null;

        // Assign data to entity for validation (without saving yet)
        foreach ($data as $key => $value) {
            if (property_exists($widget, $key)) {
                $widget->$key = $value;
            }
        }

        // Validate using Symfony Validator (replaces manual title validation)
        // Rule #4: DELETE OVER WRAP - old manual check removed
        $this->validateOrFail($widget);

        $widget->save($data);

        // Set position property after save for the event handler
        if ($position !== null) {
            $widget->position = $position;
        }

        return ['message' => 'success', 'widget' => $widget, 'data' => $data];
    }

    /**
     * @Route("/{id}", methods="DELETE", requirements={"id"="\d+"})
     */
    public function deleteAction(int $id = 0): array
    {
        // Get id from route if not provided (Symfony 6.4 compatibility)
        if (!$id) {
            $id = (int) App::request()->get('id', 0);
        }

        if (!$widget = Widget::find($id)) {
            App::abort(404, 'Widget not found.');
        }

        $widget->delete();

        return ['message' => 'success'];
    }

    /**
     * @Route("/copy", methods="POST")
     */
    public function copyAction(): array
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $request = App::request();

        $ids = $request->request->all()['ids'] ?? [];
        if (empty($ids) && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
            $ids = $json['ids'] ?? [];
        }

        foreach ($ids as $id) {
            if ($widget = Widget::find((int) $id)) {
                $copy = clone $widget;
                $copy->id = null;
                $copy->status = 0;
                $copy->title = $widget->title.' - '.__('Copy');
                $copy->save();
            }
        }

        return ['message' => 'success'];
    }

    /**
     * @Route("/bulk", methods="POST")
     */
    public function bulkSaveAction(): array
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $request = App::request();

        $widgets = $request->request->all()['widgets'] ?? [];
        if (empty($widgets) && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
            $widgets = $json['widgets'] ?? [];
        }

        foreach ($widgets as $data) {
            $id = isset($data['id']) ? $data['id'] : 0;
            $this->saveAction($id, $data);
        }

        return ['message' => 'success'];
    }

    /**
     * @Route("/bulk", methods="DELETE")
     */
    public function bulkDeleteAction(): array
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $request = App::request();

        $ids = $request->request->all()['ids'] ?? [];
        if (empty($ids) && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
            $ids = $json['ids'] ?? [];
        }

        foreach (array_filter($ids) as $id) {
            $this->deleteAction($id);
        }

        return ['message' => 'success'];
    }
}
