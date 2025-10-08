<?php

namespace Pagekit\Menucards\Controller;

use Pagekit\Application as App;
use Pagekit\Menucards\Model\Product;

/**
 * Product API Controller
 * Handles all CRUD operations for products
 * 
 * @Access("menucards: manage products", admin=true)
 * @Route("/api/menucards/product", name="@menucards/api/product")
 */
class ProductApiController
{
    /**
     * Get all products
     * 
     * @Route("/", methods="GET")
     */
    public function indexAction()
    {
        App::log()->debug('ProductApiController: Fetching all products');
        
        try {
            $products = Product::findAll();
            App::log()->debug("ProductApiController: Found " . count($products) . " products");
            
            return [
                'products' => $products,
                'count' => count($products)
            ];
        } catch (\Exception $e) {
            App::log()->error("ProductApiController: Error fetching products - " . $e->getMessage());
            App::abort(500, 'Failed to fetch products');
        }
    }

    /**
     * Get single product by ID
     * 
     * @Route("/{id}", methods="GET", requirements={"id"="\d+"})
     */
    public function getAction($id)
    {
        App::log()->debug("ProductApiController: Fetching product ID {$id}");

        if (!$product = Product::find($id)) {
            App::log()->warning("ProductApiController: Product ID {$id} not found");
            App::abort(404, 'Product not found');
        }

        App::log()->debug("ProductApiController: Found product: {$product->name}");
        return ['product' => $product];
    }

    /**
     * Create new product
     * 
     * @Route("/", methods="POST")
     * @Request({"product": "array"}, csrf=true)
     */
    public function createAction($data)
    {
        App::log()->debug('ProductApiController: Creating new product', $data);

        // Create and populate product
        $product = Product::create();
        $product->name = $data['name'] ?? '';
        $product->description = $data['description'] ?? '';
        $product->price = $data['price'] ?? 0;
        $product->image = $data['image'] ?? null;
        
        // Validate
        $errors = $product->validate();
        if (!empty($errors)) {
            App::log()->warning('ProductApiController: Validation failed', $errors);
            App::abort(400, implode(', ', $errors));
        }

        try {
            $product->save();
            App::log()->info("ProductApiController: Product created successfully with ID {$product->id}");

            return [
                'product' => $product,
                'message' => 'Product created successfully'
            ];
        } catch (\Exception $e) {
            App::log()->error("ProductApiController: Error creating product - " . $e->getMessage());
            App::abort(500, 'Failed to create product');
        }
    }

    /**
     * Update existing product
     * 
     * @Route("/{id}", methods="POST", requirements={"id"="\d+"})
     * @Request({"product": "array"}, csrf=true)
     */
    public function updateAction($id, $data)
    {
        App::log()->debug("ProductApiController: Updating product ID {$id}", $data);

        if (!$product = Product::find($id)) {
            App::log()->warning("ProductApiController: Product ID {$id} not found for update");
            App::abort(404, 'Product not found');
        }

        // Update fields
        $product->name = $data['name'] ?? $product->name;
        $product->description = $data['description'] ?? $product->description;
        $product->price = $data['price'] ?? $product->price;
        $product->image = $data['image'] ?? $product->image;
        
        // Validate
        $errors = $product->validate();
        if (!empty($errors)) {
            App::log()->warning('ProductApiController: Validation failed on update', $errors);
            App::abort(400, implode(', ', $errors));
        }

        try {
            $product->save();
            App::log()->info("ProductApiController: Product ID {$id} updated successfully");

            return [
                'product' => $product,
                'message' => 'Product updated successfully'
            ];
        } catch (\Exception $e) {
            App::log()->error("ProductApiController: Error updating product - " . $e->getMessage());
            App::abort(500, 'Failed to update product');
        }
    }

    /**
     * Delete product
     * 
     * @Route("/{id}", methods="DELETE", requirements={"id"="\d+"})
     */
    public function deleteAction($id)
    {
        App::log()->debug("ProductApiController: Deleting product ID {$id}");

        if (!$product = Product::find($id)) {
            App::log()->warning("ProductApiController: Product ID {$id} not found for deletion");
            App::abort(404, 'Product not found');
        }

        try {
            // Remove from all category associations
            $deleted = App::db()->delete('@menucards_category_product', ['product_id' => $id]);
            App::log()->debug("ProductApiController: Removed product from {$deleted} category associations");

            // Delete product
            $product->delete();
            App::log()->info("ProductApiController: Product ID {$id} deleted successfully");

            return ['message' => 'Product deleted successfully'];
        } catch (\Exception $e) {
            App::log()->error("ProductApiController: Error deleting product - " . $e->getMessage());
            App::abort(500, 'Failed to delete product');
        }
    }

    /**
     * Bulk delete products
     * 
     * @Route("/bulk-delete", methods="POST")
     * @Request({"ids": "array"}, csrf=true)
     */
    public function bulkDeleteAction($ids)
    {
        App::log()->debug("ProductApiController: Bulk deleting products", $ids);

        if (empty($ids)) {
            App::abort(400, 'No product IDs provided');
        }

        try {
            $deleted = 0;
            foreach ($ids as $id) {
                if ($product = Product::find($id)) {
                    // Remove category associations
                    App::db()->delete('@menucards_category_product', ['product_id' => $id]);
                    $product->delete();
                    $deleted++;
                }
            }

            App::log()->info("ProductApiController: Bulk deleted {$deleted} products");
            return [
                'message' => "Successfully deleted {$deleted} products",
                'count' => $deleted
            ];
        } catch (\Exception $e) {
            App::log()->error("ProductApiController: Error in bulk delete - " . $e->getMessage());
            App::abort(500, 'Failed to delete products');
        }
    }
}
