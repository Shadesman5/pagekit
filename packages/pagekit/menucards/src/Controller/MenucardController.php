<?php

namespace Pagekit\Menucards\Controller;

use Pagekit\Application as App;

/**
 * Menucard Admin Controller
 * Renders the Vue.js menu management interface with context-aware product creation
 * 
 * @Access("menucards: manage menucards", admin=true)
 * @Route("/menucards", name="@menucards/admin")
 */
class MenucardController
{
    /**
     * @Route("/", name="@menucards/admin/index")
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
            ]
        ];
    }
}
