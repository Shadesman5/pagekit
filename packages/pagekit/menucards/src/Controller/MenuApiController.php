<?php

namespace Pagekit\Menucards\Controller;

use Pagekit\Application as App;
use Pagekit\Menucards\Model\Menu;
use Pagekit\Menucards\Model\Category;

/**
 * @Access("menucards: manage menus")
 * @Route("/menu", name="menu")
 */
class MenuApiController
{
    /**
     * @Route("/", methods="GET")
     * @Request({"filter": "array", "page":"int"})
     */
    public function indexAction($filter = [], $page = 0)
    {
        // Debug: Menu list requested
        error_log('[Menucards] MenuApiController::indexAction called');

        $query = Menu::query();
        $limit = 20;
        $count = $query->count();
        $pages = ceil($count / $limit);
        $page  = max(0, min($pages - 1, $page));

        // Apply filters
        if (isset($filter['search']) && $filter['search']) {
            $query->where(function ($query) use ($filter) {
                $query->orWhere(['title LIKE :search', 'description LIKE :search'], ['search' => "%{$filter['search']}%"]);
            });
        }

        if (isset($filter['status']) && is_numeric($filter['status'])) {
            $query->where(['status' => intval($filter['status'])]);
        }

        $menus = $query->offset($page * $limit)->limit($limit)->orderBy('title', 'ASC')->related('categories')->get();

        return compact('menus', 'pages', 'count');
    }

    /**
     * @Route("/{id}", methods="GET", requirements={"id"="\d+"})
     */
    public function getAction($id)
    {
        // Debug: Menu get requested
        error_log('[Menucards] MenuApiController::getAction called for id: ' . $id);

        if (!$menu = Menu::where(['id' => $id])->related('categories.products')->first()) {
            App::abort(404, __('Menu not found.'));
        }

        return $menu;
    }

    /**
     * @Route("/", methods="POST")
     * @Route("/{id}", methods="POST", requirements={"id"="\d+"})
     * @Request({"menu": "array"}, csrf=true)
     */
    public function saveAction($data, $id = 0)
    {
        // Debug: Menu save requested
        error_log('[Menucards] MenuApiController::saveAction called with data: ' . json_encode($data));

        if (!$menu = Menu::find($id)) {
            $menu = Menu::create();
        }

        // Validate required fields
        if (empty($data['title'])) {
            App::abort(400, __('Menu title is required.'));
        }

        // Generate slug if not provided
        if (empty($data['slug'])) {
            $data['slug'] = App::filter($data['title'], 'slugify');
        }

        // Check for duplicate slug
        if ($existing = Menu::where(['slug' => $data['slug']])->where('id <> ?', [$id ?: 0])->first()) {
            App::abort(400, __('Slug already exists.'));
        }

        // Set properties
        $menu->title = $data['title'];
        $menu->slug = $data['slug'];
        $menu->description = $data['description'] ?? null;
        $menu->status = isset($data['status']) ? (int)$data['status'] : 0;

        // Set data field for additional metadata
        $menu->set('data', $data['data'] ?? []);

        try {
            $menu->save();
            error_log('[Menucards] Menu saved successfully with id: ' . $menu->id);
        } catch (\Exception $e) {
            error_log('[Menucards] Error saving menu: ' . $e->getMessage());
            App::abort(500, __('Error saving menu: %error%', ['%error%' => $e->getMessage()]));
        }

        return ['message' => __('Menu saved.'), 'menu' => $menu];
    }

    /**
     * @Route("/{id}", methods="DELETE", requirements={"id"="\d+"})
     * @Request(csrf=true)
     */
    public function deleteAction($id)
    {
        // Debug: Menu delete requested
        error_log('[Menucards] MenuApiController::deleteAction called for id: ' . $id);

        if (!$menu = Menu::find($id)) {
            App::abort(404, __('Menu not found.'));
        }

        try {
            // Delete all categories and their product relationships
            foreach ($menu->categories as $category) {
                // Category-Product relationships will be deleted by foreign key constraints
                $category->delete();
            }

            $menu->delete();
            error_log('[Menucards] Menu deleted successfully: ' . $id);
        } catch (\Exception $e) {
            error_log('[Menucards] Error deleting menu: ' . $e->getMessage());
            App::abort(500, __('Error deleting menu: %error%', ['%error%' => $e->getMessage()]));
        }

        return ['message' => __('Menu deleted.')];
    }
}
