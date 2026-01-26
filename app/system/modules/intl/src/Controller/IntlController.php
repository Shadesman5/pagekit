<?php

declare(strict_types=1);

namespace Pagekit\Intl\Controller;

use Pagekit\Application as App;
use Pagekit\Routing\Attribute\Request;
use Pagekit\Routing\Attribute\Route;

class IntlController
{
    #[Route('/{locale}', requirements: ['locale' => '[a-zA-Z0-9_-]+'], defaults: ['_maintenance' => true])]
    #[Request(['locale' => 'string'])]
    public function indexAction($locale = null)
    {
        $intl = App::module('system/intl');
        $intl->loadLocale($locale);

        $messages = $intl->getFormats($locale) ?: [];
        $messages['locale'] = $locale;
        $messages['translations'] = [$locale => App::translator()->getCatalogue($locale)->all()];
        $messages = json_encode($messages);

        $request = App::request();

        $json = $request->isXmlHttpRequest();

        $response = ($json ? App::response()->json() : App::response('', 200, ['Content-Type' => 'application/javascript']));
        $response->setETag(md5($json . $messages))->setPublic();

        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response->setContent($json ? $messages : sprintf('var $locale = %s;', $messages));
    }
}
