<?php

namespace Pagekit\Cache\Controller;

use Pagekit\Application as App;

/**
 * @Access(admin=true)
 */
class CacheController
{
    /**
     * @Route("/clear", methods="POST")
     */
    public function clearAction(): array
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $request = App::request();
        
        $caches = $request->request->all()['caches'] ?? [];
        if (empty($caches) && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
            $caches = $json['caches'] ?? [];
        }
        
        App::module('system/cache')->clearCache($caches);

        return ['message' => 'success'];
    }
}
