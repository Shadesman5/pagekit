<?php

namespace Pagekit\Menucards\Controller;

use Pagekit\Application as App;
use Pagekit\Menucards\Model\Menu;
use Pagekit\Menucards\Model\Category;
use Pagekit\Menucards\Model\Product;

/**
 * Menu API Controller
 * Handles CRUD operations for menus and category/product management
 * 
 * @Access("menucards: manage menucards", admin=true)
 * @Route("/api/menucards/menu", name="@menucards/api/menu")
 */
class MenuApiController
{
    /**
     * Get all menus
     * 
     * @Route("/", methods="GET")
     */
    public function indexAction()
    {
        App::log()->debug('MenuApiController: Fetching all menus');
        
        try {
            $menus = Menu::findAll();
            App::log()->debug("MenuApiController: Found " . count($menus) . " menus");
            
            return [
                'menus' => $menus,
                'count' => count($menus)
            ];
        } catch (\Exception $e) {
            App::log()->error("MenuApiController: Error fetching menus - " . $e->getMessage());
            App::abort(500, 'Failed to fetch menus');
        }
    }

    /**
     * Get single menu by ID with categories and products
     * 
     * @Route("/{id}", methods="GET", requirements={"id"="\d+"})
     */
    public function getAction($id)
    {
        App::log()->debug("MenuApiController: Fetching menu ID {$id}");

        if (!$menu = Menu::find($id)) {
            App::log()->warning("MenuApiController: Menu ID {$id} not found");
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

        App::log()->debug("MenuApiController: Found menu with " . count($categories) . " categories");
        
        return [
            'menu' => $menu,
            'categories' => $categoriesWithProducts
        ];
    }

    /**
     * Create new menu
     * 
     * @Route("/", methods="POST")
     * @Request({"menu": "array"}, csrf=true)
     */
    public function createAction($data)
    {
        App::log()->debug('MenuApiController: Creating new menu', $data);

        // Validate
        if (empty($data['title'])) {
            App::abort(400, 'Menu title is required');
        }

        // Create menu
        $menu = Menu::create();
        $menu->title = $data['title'];
        $menu->slug = $this->generateSlug($data['slug'] ?? $data['title']);
        $menu->description = $data['description'] ?? '';
        $menu->status = $data['status'] ?? 0;

        try {
            $menu->save();
            App::log()->info("MenuApiController: Menu created successfully with ID {$menu->id}");

            return [
                'menu' => $menu,
                'message' => 'Menu created successfully'
            ];
        } catch (\Exception $e) {
            App::log()->error("MenuApiController: Error creating menu - " . $e->getMessage());
            App::abort(500, 'Failed to create menu');
        }
    }

    /**
     * Update existing menu
     * 
     * @Route("/{id}", methods="POST", requirements={"id"="\d+"})
     * @Request({"menu": "array"}, csrf=true)
     */
    public function updateAction($id, $data)
    {
        App::log()->debug("MenuApiController: Updating menu ID {$id}", $data);

        if (!$menu = Menu::find($id)) {
            App::abort(404, 'Menu not found');
        }

        // Update fields
        $menu->title = $data['title'] ?? $menu->title;
        if (isset($data['slug'])) {
            $menu->slug = $this->generateSlug($data['slug']);
        }
        $menu->description = $data['description'] ?? $menu->description;
        $menu->status = $data['status'] ?? $menu->status;

        try {
            $menu->save();
            App::log()->info("MenuApiController: Menu ID {$id} updated successfully");

            return [
                'menu' => $menu,
                'message' => 'Menu updated successfully'
            ];
        } catch (\Exception $e) {
            App::log()->error("MenuApiController: Error updating menu - " . $e->getMessage());
            App::abort(500, 'Failed to update menu');
        }
    }

    /**
     * Delete menu (and all categories)
     * 
     * @Route("/{id}", methods="DELETE", requirements={"id"="\d+"})
     */
    public function deleteAction($id)
    {
        App::log()->debug("MenuApiController: Deleting menu ID {$id}");

        if (!$menu = Menu::find($id)) {
            App::abort(404, 'Menu not found');
        }

        try {
            // Delete all categories (and their product associations)
            $categories = $menu->getCategories();
            foreach ($categories as $category) {
                // Remove product associations
                App::db()->delete('@menucards_category_product', ['category_id' => $category->id]);
                $category->delete();
            }
            
            // Delete menu
            $menu->delete();
            App::log()->info("MenuApiController: Menu ID {$id} deleted with " . count($categories) . " categories");

            return ['message' => 'Menu deleted successfully'];
        } catch (\Exception $e) {
            App::log()->error("MenuApiController: Error deleting menu - " . $e->getMessage());
            App::abort(500, 'Failed to delete menu');
        }
    }

    /**
     * Add category to menu
     * 
     * @Route("/{id}/category", methods="POST", requirements={"id"="\d+"})
     * @Request({"category": "array"}, csrf=true)
     */
    public function addCategoryAction($id, $data)
    {
        App::log()->debug("MenuApiController: Adding category to menu ID {$id}", $data);

        if (!$menu = Menu::find($id)) {
            App::abort(404, 'Menu not found');
        }

        if (empty($data['title'])) {
            App::abort(400, 'Category title is required');
        }

        // Create category
        $category = Category::create();
        $category->menu_id = $menu->id;
        $category->title = $data['title'];
        $category->priority = $data['priority'] ?? 0;

        try {
            $category->save();
            App::log()->info("MenuApiController: Category created with ID {$category->id}");

            return [
                'category' => $category,
                'message' => 'Category created successfully'
            ];
        } catch (\Exception $e) {
            App::log()->error("MenuApiController: Error creating category - " . $e->getMessage());
            App::abort(500, 'Failed to create category');
        }
    }

    /**
     * Update category
     * 
     * @Route("/category/{categoryId}", methods="POST", requirements={"categoryId"="\d+"})
     * @Request({"category": "array"}, csrf=true)
     */
    public function updateCategoryAction($categoryId, $data)
    {
        App::log()->debug("MenuApiController: Updating category ID {$categoryId}", $data);

        if (!$category = Category::find($categoryId)) {
            App::abort(404, 'Category not found');
        }

        // Update fields
        $category->title = $data['title'] ?? $category->title;
        $category->priority = $data['priority'] ?? $category->priority;

        try {
            $category->save();
            App::log()->info("MenuApiController: Category ID {$categoryId} updated");

            return [
                'category' => $category,
                'message' => 'Category updated successfully'
            ];
        } catch (\Exception $e) {
            App::log()->error("MenuApiController: Error updating category - " . $e->getMessage());
            App::abort(500, 'Failed to update category');
        }
    }

    /**
     * Delete category (and product associations)
     * 
     * @Route("/category/{categoryId}", methods="DELETE", requirements={"categoryId"="\d+"})
     */
    public function deleteCategoryAction($categoryId)
    {
        App::log()->debug("MenuApiController: Deleting category ID {$categoryId}");

        if (!$category = Category::find($categoryId)) {
            App::abort(404, 'Category not found');
        }

        try {
            // Remove product associations
            App::db()->delete('@menucards_category_product', ['category_id' => $categoryId]);
            
            // Delete category
            $category->delete();
            App::log()->info("MenuApiController: Category ID {$categoryId} deleted");

            return ['message' => 'Category deleted successfully'];
        } catch (\Exception $e) {
            App::log()->error("MenuApiController: Error deleting category - " . $e->getMessage());
            App::abort(500, 'Failed to delete category');
        }
    }

    /**
     * Attach product to category
     * CRITICAL: Used for context-aware product creation!
     * 
     * @Route("/category/{categoryId}/product/{productId}", methods="POST", requirements={"categoryId"="\d+", "productId"="\d+"})
     */
    public function attachProductAction($categoryId, $productId)
    {
        App::log()->debug("MenuApiController: Attaching product {$productId} to category {$categoryId}");

        if (!$category = Category::find($categoryId)) {
            App::abort(404, 'Category not found');
        }

        if (!$product = Product::find($productId)) {
            App::abort(404, 'Product not found');
        }

        try {
            $category->attachProduct($productId, 0);
            App::log()->info("MenuApiController: Product {$productId} attached to category {$categoryId}");

            return [
                'message' => 'Product attached successfully',
                'category' => $category,
                'product' => $product
            ];
        } catch (\Exception $e) {
            App::log()->error("MenuApiController: Error attaching product - " . $e->getMessage());
            App::abort(500, 'Failed to attach product');
        }
    }

    /**
     * Detach product from category
     * 
     * @Route("/category/{categoryId}/product/{productId}", methods="DELETE", requirements={"categoryId"="\d+", "productId"="\d+"})
     */
    public function detachProductAction($categoryId, $productId)
    {
        App::log()->debug("MenuApiController: Detaching product {$productId} from category {$categoryId}");

        if (!$category = Category::find($categoryId)) {
            App::abort(404, 'Category not found');
        }

        try {
            $category->detachProduct($productId);
            App::log()->info("MenuApiController: Product {$productId} detached from category {$categoryId}");

            return ['message' => 'Product detached successfully'];
        } catch (\Exception $e) {
            App::log()->error("MenuApiController: Error detaching product - " . $e->getMessage());
            App::abort(500, 'Failed to detach product');
        }
    }

    /**
     * Generate URL-safe slug
     * 
     * @param string $text
     * @return string
     */
    protected function generateSlug($text)
    {
        // Basic slug generation
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        
        // Ensure uniqueness
        $originalSlug = $text;
        $counter = 1;
        
        while (Menu::where(['slug' => $text])->first()) {
            $text = $originalSlug . '-' . $counter;
            $counter++;
        }
        
        return $text;
    }
}
