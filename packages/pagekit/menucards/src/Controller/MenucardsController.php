<?php

namespace Pagekit\Menucards\Controller;

use Pagekit\Application as App;

/**
 * @Access("menucards: manage menus", admin=true)
 */
class MenucardsController
{
    /**
     * @Route("/", name="@menucards/admin")
     */
    public function indexAction()
    {
        // Debug: Admin index page requested
        error_log('[Menucards] MenucardsController::indexAction called');

        return [
            '$view' => [
                'title' => __('Menucards'),
                'name' => 'menucards:app/views/admin/menucards.php'
            ],
            '$data' => [
                'config' => App::module('menucards')->config
            ]
        ];
    }

    /**
     * @Route("/products", name="@menucards/admin/products")
     * @Access("menucards: manage products", admin=true)
     */
    public function productsAction()
    {
        // Debug: Products page requested
        error_log('[Menucards] MenucardsController::productsAction called');

        return [
            '$view' => [
                'title' => __('Products'),
                'name' => 'menucards:app/views/admin/products.php'
            ],
            '$data' => [
                'config' => App::module('menucards')->config
            ]
        ];
    }

    /**
     * @Route("/menu/{id}", name="@menucards/admin/menu", requirements={"id"="\d+"})
     */
    public function menuAction($id = 0)
    {
        // Debug: Menu edit page requested
        error_log('[Menucards] MenucardsController::menuAction called for id: ' . $id);

        return [
            '$view' => [
                'title' => __('Edit Menu'),
                'name' => 'menucards:app/views/admin/menu-edit.php'
            ],
            '$data' => [
                'menu_id' => (int)$id,
                'config' => App::module('menucards')->config
            ]
        ];
    }
}
