<?php

namespace Pagekit\Menucards\Controller;

use Pagekit\Application as App;
use Pagekit\Menucards\Model\Product;

/**
 * @Access("menucards: manage products")
 * @Route("/product", name="product")
 */
class ProductApiController
{
    /**
     * @Route("/", methods="GET")
     * @Request({"filter": "array", "page":"int"})
     */
    public function indexAction($filter = [], $page = 0)
    {
        // Debug: Product list requested
        error_log('[Menucards] ProductApiController::indexAction called');

        $query = Product::query();
        $limit = 20;
        $count = $query->count();
        $pages = ceil($count / $limit);
        $page  = max(0, min($pages - 1, $page));

        // Apply filters
        if (isset($filter['search']) && $filter['search']) {
            $query->where(function ($query) use ($filter) {
                $query->orWhere(['name LIKE :search', 'description LIKE :search'], ['search' => "%{$filter['search']}%"]);
            });
        }

        $products = $query->offset($page * $limit)->limit($limit)->orderBy('name', 'ASC')->get();

        return compact('products', 'pages', 'count');
    }

    /**
     * @Route("/{id}", methods="GET", requirements={"id"="\d+"})
     */
    public function getAction($id)
    {
        // Debug: Product get requested
        error_log('[Menucards] ProductApiController::getAction called for id: ' . $id);

        if (!$product = Product::find($id)) {
            App::abort(404, __('Product not found.'));
        }

        return $product;
    }

    /**
     * @Route("/", methods="POST")
     * @Route("/{id}", methods="POST", requirements={"id"="\d+"})
     * @Request({"product": "array"}, csrf=true)
     */
    public function saveAction($data, $id = 0)
    {
        // Debug: Product save requested
        error_log('[Menucards] ProductApiController::saveAction called with data: ' . json_encode($data));

        if (!$product = Product::find($id)) {
            $product = Product::create();
        }

        // Validate required fields
        if (empty($data['name'])) {
            App::abort(400, __('Product name is required.'));
        }

        // Set properties
        $product->name = $data['name'];
        $product->description = $data['description'] ?? null;
        $product->price = isset($data['price']) && $data['price'] !== '' ? (float)$data['price'] : null;
        $product->image = $data['image'] ?? null;
        $product->allergens = $data['allergens'] ?? null;

        // Set data field for additional metadata
        $product->set('data', $data['data'] ?? []);

        try {
            $product->save();
            error_log('[Menucards] Product saved successfully with id: ' . $product->id);
        } catch (\Exception $e) {
            error_log('[Menucards] Error saving product: ' . $e->getMessage());
            App::abort(500, __('Error saving product: %error%', ['%error%' => $e->getMessage()]));
        }

        // CRITICAL: Return the complete product object
        return ['message' => __('Product saved.'), 'product' => $product];
    }

    /**
     * @Route("/{id}", methods="DELETE", requirements={"id"="\d+"})
     * @Request(csrf=true)
     */
    public function deleteAction($id)
    {
        // Debug: Product delete requested
        error_log('[Menucards] ProductApiController::deleteAction called for id: ' . $id);

        if (!$product = Product::find($id)) {
            App::abort(404, __('Product not found.'));
        }

        try {
            $product->delete();
            error_log('[Menucards] Product deleted successfully: ' . $id);
        } catch (\Exception $e) {
            error_log('[Menucards] Error deleting product: ' . $e->getMessage());
            App::abort(500, __('Error deleting product: %error%', ['%error%' => $e->getMessage()]));
        }

        return ['message' => __('Product deleted.')];
    }

    /**
     * @Route("/bulk", methods="POST")
     * @Request({"ids": "int[]"}, csrf=true)
     */
    public function bulkDeleteAction($ids = [])
    {
        // Debug: Bulk delete requested
        error_log('[Menucards] ProductApiController::bulkDeleteAction called for ids: ' . implode(',', $ids));

        foreach ($ids as $id) {
            if ($product = Product::find($id)) {
                $product->delete();
            }
        }

        return ['message' => __('%count% products deleted.', ['%count%' => count($ids)])];
    }
}
