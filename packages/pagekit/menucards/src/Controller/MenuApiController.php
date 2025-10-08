<?php

namespace Pagekit\Menucards\Controller;

use Pagekit\Application as App;
use Pagekit\Menucards\Model\Menu;
use Pagekit\Menucards\Model\Category;

/**
 * @Route("menu", name="menu")
 */
class MenuApiController
{
    /**
     * @Access("menucards: manage menucards")
     * @Route("/", methods="GET")
     */
    public function indexAction()
    {
        try {
            App::log()->debug('MenuApiController: Fetching all menus');
            
            // Test if Menu class exists
            if (!class_exists('Pagekit\\Menucards\\Model\\Menu')) {
                throw new \Exception('Menu class not found!');
            }
            
            // Test database connection
            $tables = App::db()->getUtility()->listTableNames();
            if (!in_array(App::db()->getPrefix() . 'menucards_menu', $tables)) {
                throw new \Exception('menucards_menu table not found!');
            }
            
            // Test findAll
            $menus = Menu::findAll();
            App::log()->debug("MenuApiController: Found " . count($menus) . " menus");
            
            return [
                'menus' => $menus,
                'count' => count($menus)
            ];
        } catch (\Exception $e) {
            App::log()->error('MenuApiController ERROR: ' . $e->getMessage());
            App::log()->error('Stack: ' . $e->getTraceAsString());
            
            return [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'menus' => [],
                'count' => 0
            ];
        }
    }

    /**
     * @Access("menucards: manage menucards")
     * @Route("/{id}", methods="GET", requirements={"id"="\d+"})
     */
    public function getAction($id)
    {
        App::log()->debug("MenuApiController: Fetching menu ID {$id}");

        if (!$menu = Menu::find($id)) {
            App::abort(404, 'Menu not found');
        }

        // Load categories with products
        $categories = $menu->getCategories();
        $categoriesWithProducts = [];
        
        foreach ($categories as $category) {
            $categoryData = $category->jsonSerialize();
            $categoryData['products'] = $category->getProducts();
            $categoriesWithProducts[] = $categoryData;
        }
        
        return [
            'menu' => $menu,
            'categories' => $categoriesWithProducts
        ];
    }

    /**
     * @Access("menucards: manage menucards")
     * @Route("/", methods="POST")
     * @Request({"menu": "array"}, csrf=true)
     */
    public function createAction($data)
    {
        App::log()->debug('MenuApiController: Creating menu', $data);

        if (empty($data['title'])) {
            App::abort(400, 'Menu title is required');
        }

        $menu = Menu::create();
        $menu->title = $data['title'];
        $menu->slug = $this->generateSlug($data['slug'] ?? $data['title']);
        $menu->description = $data['description'] ?? '';
        $menu->status = $data['status'] ?? 0;
        $menu->created = new \DateTime();
        $menu->save();

        App::log()->info("MenuApiController: Menu created with ID {$menu->id}");

        return [
            'menu' => $menu,
            'message' => 'Menu created successfully'
        ];
    }

    /**
     * @Access("menucards: manage menucards")
     * @Route("/{id}", methods="POST", requirements={"id"="\d+"})
     * @Request({"menu": "array"}, csrf=true)
     */
    public function updateAction($id, $data)
    {
        if (!$menu = Menu::find($id)) {
            App::abort(404, 'Menu not found');
        }

        $menu->title = $data['title'] ?? $menu->title;
        if (isset($data['slug'])) {
            $menu->slug = $this->generateSlug($data['slug']);
        }
        $menu->description = $data['description'] ?? $menu->description;
        $menu->status = $data['status'] ?? $menu->status;
        $menu->save();

        return [
            'menu' => $menu,
            'message' => 'Menu updated successfully'
        ];
    }

    /**
     * @Access("menucards: manage menucards")
     * @Route("/{id}", methods="DELETE", requirements={"id"="\d+"})
     */
    public function deleteAction($id)
    {
        if (!$menu = Menu::find($id)) {
            App::abort(404, 'Menu not found');
        }

        // Delete all categories
        $categories = $menu->getCategories();
        foreach ($categories as $category) {
            App::db()->delete('@menucards_category_product', ['category_id' => $category->id]);
            $category->delete();
        }
        
        $menu->delete();

        return ['message' => 'Menu deleted successfully'];
    }

    /**
     * @Access("menucards: manage menucards")
     * @Route("/{id}/category", methods="POST", requirements={"id"="\d+"})
     * @Request({"category": "array"}, csrf=true)
     */
    public function addCategoryAction($id, $data)
    {
        if (!$menu = Menu::find($id)) {
            App::abort(404, 'Menu not found');
        }

        if (empty($data['title'])) {
            App::abort(400, 'Category title is required');
        }

        $category = Category::create();
        $category->menu_id = $menu->id;
        $category->title = $data['title'];
        $category->priority = $data['priority'] ?? 0;
        $category->save();

        return [
            'category' => $category,
            'message' => 'Category created successfully'
        ];
    }

    /**
     * @Access("menucards: manage menucards")
     * @Route("/category/{categoryId}", methods="POST", requirements={"categoryId"="\d+"})
     * @Request({"category": "array"}, csrf=true)
     */
    public function updateCategoryAction($categoryId, $data)
    {
        if (!$category = Category::find($categoryId)) {
            App::abort(404, 'Category not found');
        }

        $category->title = $data['title'] ?? $category->title;
        $category->priority = $data['priority'] ?? $category->priority;
        $category->save();

        return [
            'category' => $category,
            'message' => 'Category updated successfully'
        ];
    }

    /**
     * @Access("menucards: manage menucards")
     * @Route("/category/{categoryId}", methods="DELETE", requirements={"categoryId"="\d+"})
     */
    public function deleteCategoryAction($categoryId)
    {
        if (!$category = Category::find($categoryId)) {
            App::abort(404, 'Category not found');
        }

        App::db()->delete('@menucards_category_product', ['category_id' => $categoryId]);
        $category->delete();

        return ['message' => 'Category deleted successfully'];
    }

    /**
     * @Access("menucards: manage menucards")
     * @Route("/category/{categoryId}/product/{productId}", methods="POST", requirements={"categoryId"="\d+", "productId"="\d+"})
     */
    public function attachProductAction($categoryId, $productId)
    {
        if (!$category = Category::find($categoryId)) {
            App::abort(404, 'Category not found');
        }

        $category->attachProduct($productId, 0);

        return ['message' => 'Product attached successfully'];
    }

    /**
     * @Access("menucards: manage menucards")
     * @Route("/category/{categoryId}/product/{productId}", methods="DELETE", requirements={"categoryId"="\d+", "productId"="\d+"})
     */
    public function detachProductAction($categoryId, $productId)
    {
        if (!$category = Category::find($categoryId)) {
            App::abort(404, 'Category not found');
        }

        $category->detachProduct($productId);

        return ['message' => 'Product detached successfully'];
    }

    protected function generateSlug($text)
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        
        $originalSlug = $text;
        $counter = 1;
        
        while (Menu::where(['slug' => $text])->first()) {
            $text = $originalSlug . '-' . $counter;
            $counter++;
        }
        
        return $text;
    }
}
