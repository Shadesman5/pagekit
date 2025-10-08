<?php

namespace Pagekit\Menucards\Controller;

use Pagekit\Application as App;

/**
 * Product Admin Controller
 * Renders the Vue.js product management interface
 * 
 * @Access("menucards: manage products", admin=true)
 */
class ProductController
{
    /**
     * @Route("/")
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
            ],
            'data' => [
                'config' => [
                    'api' => '/api/menucards'
                ]
            ]
        ];
    }
}
