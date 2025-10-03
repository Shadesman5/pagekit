<?php

namespace Pagekit\Menucards\Controller;

use Pagekit\Application as App;
use Pagekit\Menucards\Model\Menu;

/**
 * Public site controller for displaying menus
 */
class SiteController
{
    /**
     * @Route("/menucard/{slug}", name="@menucards/site")
     * @Request({"slug": "string"})
     */
    public function indexAction($slug)
    {
        // Debug: Public menu page requested
        error_log('[Menucards] SiteController::indexAction called for slug: ' . $slug);

        // Find menu by slug with all related data
        $menu = Menu::where(['slug' => $slug, 'status' => 1])
            ->related('categories.products')
            ->first();

        if (!$menu) {
            App::abort(404, __('Menu not found.'));
        }

        // Sort categories by priority
        if ($menu->categories) {
            usort($menu->categories, function ($a, $b) {
                return $a->priority - $b->priority;
            });

            // Sort products within each category by priority
            foreach ($menu->categories as $category) {
                if ($category->products) {
                    // Get product priorities from join table
                    $productPriorities = [];
                    $result = App::db()->fetchAll(
                        'SELECT product_id, priority FROM @menucards_category_product WHERE category_id = ? ORDER BY priority',
                        [$category->id]
                    );
                    foreach ($result as $row) {
                        $productPriorities[$row['product_id']] = $row['priority'];
                    }

                    // Sort products by priority
                    usort($category->products, function ($a, $b) use ($productPriorities) {
                        $priorityA = $productPriorities[$a->id] ?? 0;
                        $priorityB = $productPriorities[$b->id] ?? 0;
                        return $priorityA - $priorityB;
                    });
                }
            }
        }

        return [
            '$view' => [
                'title' => $menu->title,
                'name' => 'menucards:views/menu.php'
            ],
            'menu' => $menu,
            'config' => App::module('menucards')->config
        ];
    }
}
