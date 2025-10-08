<?php

namespace Pagekit\Menucards\Controller;

use Pagekit\Application as App;
use Pagekit\Menucards\Model\Product;

/**
 * @Route("product", name="product")
 */
class ProductApiController
{
    /**
     * @Access("menucards: manage products")
     * @Route("/", methods="GET")
     */
    public function indexAction()
    {
        try {
            App::log()->debug('ProductApiController: Fetching all products');
            
            $products = Product::findAll();
            App::log()->debug("ProductApiController: Found " . count($products) . " products");
            
            return [
                'products' => $products,
                'count' => count($products)
            ];
        } catch (\Exception $e) {
            App::log()->error('ProductApiController ERROR: ' . $e->getMessage());
            
            return [
                'error' => $e->getMessage(),
                'products' => [],
                'count' => 0
            ];
        }
    }

    /**
     * @Access("menucards: manage products")
     * @Route("/{id}", methods="GET", requirements={"id"="\d+"})
     */
    public function getAction($id)
    {
        App::log()->debug("ProductApiController: Fetching product ID {$id}");

        if (!$product = Product::find($id)) {
            App::abort(404, 'Product not found');
        }

        return ['product' => $product];
    }

    /**
     * @Access("menucards: manage products")
     * @Route("/", methods="POST")
     * @Request({"product": "array"}, csrf=true)
     */
    public function createAction($data)
    {
        App::log()->debug('ProductApiController: Creating product', $data);

        $product = Product::create();
        $product->name = $data['name'] ?? '';
        $product->description = $data['description'] ?? '';
        $product->price = $data['price'] ?? 0;
        $product->image = $data['image'] ?? null;
        $product->created = new \DateTime();
        
        $errors = $product->validate();
        if (!empty($errors)) {
            App::abort(400, implode(', ', $errors));
        }

        $product->save();
        App::log()->info("ProductApiController: Product created with ID {$product->id}");

        return [
            'product' => $product,
            'message' => 'Product created successfully'
        ];
    }

    /**
     * @Access("menucards: manage products")
     * @Route("/{id}", methods="POST", requirements={"id"="\d+"})
     * @Request({"product": "array"}, csrf=true)
     */
    public function updateAction($id, $data)
    {
        if (!$product = Product::find($id)) {
            App::abort(404, 'Product not found');
        }

        $product->name = $data['name'] ?? $product->name;
        $product->description = $data['description'] ?? $product->description;
        $product->price = $data['price'] ?? $product->price;
        $product->image = $data['image'] ?? $product->image;
        
        $errors = $product->validate();
        if (!empty($errors)) {
            App::abort(400, implode(', ', $errors));
        }

        $product->save();

        return [
            'product' => $product,
            'message' => 'Product updated successfully'
        ];
    }

    /**
     * @Access("menucards: manage products")
     * @Route("/{id}", methods="DELETE", requirements={"id"="\d+"})
     */
    public function deleteAction($id)
    {
        if (!$product = Product::find($id)) {
            App::abort(404, 'Product not found');
        }

        // Remove from all categories
        App::db()->delete('@menucards_category_product', ['product_id' => $id]);
        $product->delete();

        return ['message' => 'Product deleted successfully'];
    }

    /**
     * @Access("menucards: manage products")
     * @Route("/bulk-delete", methods="POST")
     * @Request({"ids": "array"}, csrf=true)
     */
    public function bulkDeleteAction($ids)
    {
        if (empty($ids)) {
            App::abort(400, 'No product IDs provided');
        }

        $deleted = 0;
        foreach ($ids as $id) {
            if ($product = Product::find($id)) {
                App::db()->delete('@menucards_category_product', ['product_id' => $id]);
                $product->delete();
                $deleted++;
            }
        }

        return [
            'message' => "Successfully deleted {$deleted} products",
            'count' => $deleted
        ];
    }
}
