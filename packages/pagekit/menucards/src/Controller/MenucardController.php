<?php

namespace Pagekit\Menucards\Controller;

use Pagekit\Application as App;

/**
 * Menucard Admin Controller
 * Renders the Vue.js menu management interface with context-aware product creation
 * 
 * @Access(admin=true)
 */
class MenucardController
{
    /**
     * @Access("menucards: manage menucards")
     */
    public function indexAction()
    {
        App::log()->debug('MenucardController: Rendering menu management view');
        
        return [
            '$view' => [
                'title' => 'Menu Cards',
                'name' => 'menucards:views/admin/index.php'
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
    
    /**
     * @Access("menucards: manage products")
     */
    public function productsAction()
    {
        App::log()->debug('MenucardController: Rendering products management view');
        
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
