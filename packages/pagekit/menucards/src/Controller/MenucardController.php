<?php

namespace Pagekit\Menucards\Controller;

use Pagekit\Application as App;

/**
 * Menucard Admin Controller
 * Renders the Vue.js menu management interface with context-aware product creation
 * 
 * @Access("menucards: manage menucards", admin=true)
 */
class MenucardController
{
    /**
     * @Route("/")
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
