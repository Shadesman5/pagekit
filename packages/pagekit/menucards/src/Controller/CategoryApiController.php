<?php

namespace Pagekit\Menucards\Controller;

use Pagekit\Application as App;
use Pagekit\Menucards\Model\Category;
use Pagekit\Menucards\Model\Product;

/**
 * @Access("menucards: manage menus")
 * @Route("/category", name="category")
 */
class CategoryApiController
{
    /**
     * @Route("/", methods="POST")
     * @Route("/{id}", methods="POST", requirements={"id"="\d+"})
     * @Request({"category": "array"}, csrf=true)
     */
    public function saveAction($data, $id = 0)
    {
        // Debug: Category save requested
        error_log('[Menucards] CategoryApiController::saveAction called with data: ' . json_encode($data));

        if (!$category = Category::find($id)) {
            $category = Category::create();
        }

        // Validate required fields
        if (empty($data['title'])) {
            App::abort(400, __('Category title is required.'));
        }

        if (empty($data['menu_id'])) {
            App::abort(400, __('Menu ID is required.'));
        }

        // Set properties
        $category->menu_id = (int)$data['menu_id'];
        $category->title = $data['title'];
        $category->description = $data['description'] ?? null;
        $category->priority = isset($data['priority']) ? (int)$data['priority'] : 0;

        // Set data field for additional metadata
        $category->set('data', $data['data'] ?? []);

        try {
            $category->save();
            error_log('[Menucards] Category saved successfully with id: ' . $category->id);
        } catch (\Exception $e) {
            error_log('[Menucards] Error saving category: ' . $e->getMessage());
            App::abort(500, __('Error saving category: %error%', ['%error%' => $e->getMessage()]));
        }

        return ['message' => __('Category saved.'), 'category' => $category];
    }

    /**
     * @Route("/{id}", methods="DELETE", requirements={"id"="\d+"})
     * @Request(csrf=true)
     */
    public function deleteAction($id)
    {
        // Debug: Category delete requested
        error_log('[Menucards] CategoryApiController::deleteAction called for id: ' . $id);

        if (!$category = Category::find($id)) {
            App::abort(404, __('Category not found.'));
        }

        try {
            $category->delete();
            error_log('[Menucards] Category deleted successfully: ' . $id);
        } catch (\Exception $e) {
            error_log('[Menucards] Error deleting category: ' . $e->getMessage());
            App::abort(500, __('Error deleting category: %error%', ['%error%' => $e->getMessage()]));
        }

        return ['message' => __('Category deleted.')];
    }

    /**
     * @Route("/{id}/product", methods="POST", requirements={"id"="\d+"})
     * @Request({"product_id": "int", "priority": "int"}, csrf=true)
     */
    public function addProductAction($id, $product_id, $priority = 0)
    {
        // Debug: Add product to category
        error_log('[Menucards] CategoryApiController::addProductAction called for category: ' . $id . ', product: ' . $product_id);

        if (!$category = Category::find($id)) {
            App::abort(404, __('Category not found.'));
        }

        if (!$product = Product::find($product_id)) {
            App::abort(404, __('Product not found.'));
        }

        try {
            $db = App::db();

            // Check if relationship already exists
            $existing = $db->fetchAssoc(
                'SELECT * FROM @menucards_category_product WHERE category_id = ? AND product_id = ?',
                [$id, $product_id]
            );

            if ($existing) {
                // Update priority if already exists
                $db->update('@menucards_category_product', ['priority' => $priority], ['category_id' => $id, 'product_id' => $product_id]);
                error_log('[Menucards] Updated product priority in category');
            } else {
                // Insert new relationship
                $db->insert('@menucards_category_product', ['category_id' => $id, 'product_id' => $product_id, 'priority' => $priority]);
                error_log('[Menucards] Added product to category');
            }
        } catch (\Exception $e) {
            error_log('[Menucards] Error adding product to category: ' . $e->getMessage());
            App::abort(500, __('Error adding product to category: %error%', ['%error%' => $e->getMessage()]));
        }

        return ['message' => __('Product added to category.')];
    }

    /**
     * @Route("/{id}/product/{product_id}", methods="DELETE", requirements={"id"="\d+", "product_id"="\d+"})
     * @Request(csrf=true)
     */
    public function removeProductAction($id, $product_id)
    {
        // Debug: Remove product from category
        error_log('[Menucards] CategoryApiController::removeProductAction called for category: ' . $id . ', product: ' . $product_id);

        if (!$category = Category::find($id)) {
            App::abort(404, __('Category not found.'));
        }

        try {
            App::db()->delete('@menucards_category_product', ['category_id' => $id, 'product_id' => $product_id]);
            error_log('[Menucards] Removed product from category');
        } catch (\Exception $e) {
            error_log('[Menucards] Error removing product from category: ' . $e->getMessage());
            App::abort(500, __('Error removing product from category: %error%', ['%error%' => $e->getMessage()]));
        }

        return ['message' => __('Product removed from category.')];
    }

    /**
     * @Route("/{id}/product/reorder", methods="POST", requirements={"id"="\d+"})
     * @Request({"products": "array"}, csrf=true)
     */
    public function reorderProductsAction($id, $products = [])
    {
        // Debug: Reorder products in category
        error_log('[Menucards] CategoryApiController::reorderProductsAction called for category: ' . $id);

        if (!$category = Category::find($id)) {
            App::abort(404, __('Category not found.'));
        }

        try {
            $db = App::db();

            foreach ($products as $index => $product_id) {
                $db->update('@menucards_category_product', ['priority' => $index], ['category_id' => $id, 'product_id' => $product_id]);
            }

            error_log('[Menucards] Reordered products in category');
        } catch (\Exception $e) {
            error_log('[Menucards] Error reordering products: ' . $e->getMessage());
            App::abort(500, __('Error reordering products: %error%', ['%error%' => $e->getMessage()]));
        }

        return ['message' => __('Products reordered.')];
    }
}
