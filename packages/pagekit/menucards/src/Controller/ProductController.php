<?php

namespace Pagekit\Menucards\Controller;

use Pagekit\Application as App;

/**
 * Product Admin Controller
 * Renders the Vue.js product management interface
 * 
 * @Access("menucards: manage products", admin=true)
 * @Route("/menucards/products", name="@menucards/admin/products")
 */
class ProductController
{
    /**
     * @Route("/", name="@menucards/admin/products/index")
     */
    public function indexAction()
    {
        App::log()->debug('ProductController: Rendering product management view');
        
        return [
            '$view' => [
                'title' => 'Products',
                'name' => 'menucards:views/admin/products.php'
            ],
            '$data' => [
                'config' => [
                    'api' => '/api/menucards'
                ]
            ]
        ];
    }
}
