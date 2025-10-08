<?php

namespace Pagekit\Menucards\Controller;

use Pagekit\Application as App;

/**
 * Menucard Admin Controller
 * 
 * @Access(admin=true)
 */
class MenucardController
{
    /**
     * Default action - redirect to menu
     */
    public function indexAction()
    {
        return App::redirect('@menucards/menu');
    }
    
    /**
     * Menu management view
     * @Access("menucards: manage menucards")
     */
    public function menuAction()
    {
        App::log()->debug('MenucardController: Menu action called');
        
        return [
            '$view' => [
                'title' => __('Menu Cards'),
                'name' => 'menucards:views/admin/menu.php'
            ],
            '$data' => [
                'config' => []
            ]
        ];
    }
    
    /**
     * Product management view
     * @Access("menucards: manage products")
     */
    public function productAction()
    {
        App::log()->debug('MenucardController: Product action called');
        
        return [
            '$view' => [
                'title' => __('Products'),
                'name' => 'menucards:views/admin/product.php'
            ],
            '$data' => [
                'config' => []
            ]
        ];
    }
}
