<?php

namespace Pagekit\Widget\Controller;

use Pagekit\Application as App;
use Pagekit\Widget\Model\Widget;

/**
 * @Access("system: manage widgets")
 */
class WidgetApiController
{
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
    public function getAction($id): Widget
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
     * @Request({"position", "ids": "array"}, csrf=true)
     */
    public function assignAction($position, $ids): array
    {
        App::position()->assign($position, $ids);

        return ['message' => 'success'];
    }

    /**
     * @Route("/", methods="POST")
     * @Route("/{id}", methods="POST", requirements={"id"="\d+"})
     * @Request({"widget": "array", "id": "int"}, csrf=true)
     */
    public function saveAction($data, $id = 0): array
    {
        if (!$id) {
            $widget = Widget::create();
        } elseif (!$widget = Widget::find($id)) {
            App::abort(404, 'Widget not found.');
        }

        if (empty($data['title'])) {
            App::abort(400, 'Widget title empty.');
        }
        
        // Extract position before saving (it's not a database field)
        $position = isset($data['position']) ? $data['position'] : null;

        $widget->save($data);
        
        // Set position property after save for the event handler
        if ($position !== null) {
            $widget->position = $position;
        }

        return ['message' => 'success', 'widget' => $widget, 'data' => $data];
    }

    /**
     * @Route("/{id}", methods="DELETE", requirements={"id"="\d+"})
     * @Request({"id": "int"}, csrf=true)
     */
    public function deleteAction($id): array
    {
        if (!$widget = Widget::find($id)) {
            App::abort(404, 'Widget not found.');
        }

        $widget->delete();

        return ['message' => 'success'];
    }

    /**
     * @Route(methods="POST")
     * @Request({"ids": "int[]"}, csrf=true)
     */
    public function copyAction($ids = []): array
    {
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
     * @Request({"widgets": "array"}, csrf=true)
     */
    public function bulkSaveAction($widgets = []): array
    {
        foreach ($widgets as $data) {
            $this->saveAction($data, isset($data['id']) ? $data['id'] : 0);
        }

        return ['message' => 'success'];
    }

    /**
     * @Route("/bulk", methods="DELETE")
     * @Request({"ids": "array"}, csrf=true)
     */
    public function bulkDeleteAction($ids = []): array
    {
        foreach (array_filter($ids) as $id) {
            $this->deleteAction($id);
        }

        return ['message' => 'success'];
    }
}
