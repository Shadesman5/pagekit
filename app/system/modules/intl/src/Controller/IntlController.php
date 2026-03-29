<?php

declare(strict_types=1);

namespace Pagekit\Intl\Controller;

use Pagekit\Routing\Attribute\Request;
use Pagekit\Routing\Attribute\Route;

class IntlController
{
    public function __construct(
        private readonly mixed $module,
        private readonly mixed $translator,
        private readonly mixed $request,
        private readonly mixed $response,
    ) {
    }

    #[Route('/{locale}', requirements: ['locale' => '[a-zA-Z0-9_-]+'], defaults: ['_maintenance' => true])]
    #[Request(['locale' => 'string'])]
    public function indexAction($locale = null)
    {
        $intl = $this->module->get('system/intl');
        $intl->loadLocale($locale);

        $messages = $intl->getFormats($locale) ?: [];
        $messages['locale'] = $locale;
        $messages['translations'] = [$locale => $this->translator->getCatalogue($locale)->all()];
        $messages = json_encode($messages);

        $json = $this->request->isXmlHttpRequest();

        $httpResponse = ($json ? $this->response->json() : $this->response->create('', 200, ['Content-Type' => 'application/javascript']));
        $httpResponse->setETag(md5($json . $messages))->setPublic();

        if ($httpResponse->isNotModified($this->request)) {
            return $httpResponse;
        }

        return $httpResponse->setContent($json ? $messages : sprintf('var $locale = %s;', $messages));
    }
}
