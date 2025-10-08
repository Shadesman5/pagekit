<?php

namespace Pagekit\Menucards\Controller;

use Pagekit\Application as App;
use Pagekit\Menucards\Model\Menu;

/**
 * Site Controller
 * Handles public-facing menu card display
 * 
 * @Route("/menucard")
 */
class SiteController
{
    /**
     * Display menu card by slug
     * 
     * @Route("/{slug}", name="@menucards/site/view")
     */
    public function viewAction($slug = '')
    {
        App::log()->debug("SiteController: Viewing menu card with slug: {$slug}");

        // Find menu by slug
        $menu = Menu::where(['slug' => $slug, 'status' => 1])->first();

        if (!$menu) {
            App::log()->warning("SiteController: Menu with slug '{$slug}' not found or not published");
            App::abort(404, 'Menu card not found');
        }

        // Load categories with products
        $categories = $menu->getCategories();
        $categoriesWithProducts = [];
        
        foreach ($categories as $category) {
            $categoryData = [
                'id' => $category->id,
                'title' => $category->title,
                'priority' => $category->priority,
                'products' => $category->getProducts()
            ];
            $categoriesWithProducts[] = $categoryData;
        }

        App::log()->debug("SiteController: Loaded menu '{$menu->title}' with " . count($categories) . " categories");

        return [
            '$view' => [
                'title' => $menu->title,
                'name' => 'menucards:views/site/view.php'
            ],
            'menu' => $menu,
            'categories' => $categoriesWithProducts
        ];
    }

    /**
     * List all published menus
     * 
     * @Route("/", name="@menucards/site/index")
     */
    public function indexAction()
    {
        App::log()->debug("SiteController: Listing all published menu cards");

        $menus = Menu::where(['status' => 1])->orderBy('title', 'ASC')->get();

        App::log()->debug("SiteController: Found " . count($menus) . " published menus");

        return [
            '$view' => [
                'title' => 'Menu Cards',
                'name' => 'menucards:views/site/index.php'
            ],
            'menus' => $menus
        ];
    }
}
