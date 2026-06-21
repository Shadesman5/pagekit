<?php

declare(strict_types=1);

namespace Pagekit\Intl\Controller;

use Pagekit\Application\Response as PagekitResponse;
use Pagekit\Module\ModuleManager;
use Pagekit\Routing\Attribute\Request as RequestAttr;
use Pagekit\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Translation\Translator;

class IntlController
{
    public function __construct(
        private readonly ModuleManager $module,
        private readonly Translator $translator,
        private readonly Request $request,
        private readonly PagekitResponse $response,
    ) {
    }

    #[Route('/{locale}', requirements: ['locale' => '[a-zA-Z0-9_-]+'], defaults: ['_maintenance' => true])]
    #[RequestAttr(['locale' => 'string'])]
    public function indexAction(?string $locale = null): Response
    {
        $intl = $this->module->get('system/intl');
        $intl->loadLocale($locale);

        $messages = $intl->getFormats($locale) ?: [];
        $messages['locale'] = $locale;
        $messages['translations'] = [$locale => $this->translator->getCatalogue($locale)->all()];
        $messages = json_encode($messages, JSON_THROW_ON_ERROR);

        $json = $this->request->isXmlHttpRequest();

        $httpResponse = ($json ? $this->response->json() : $this->response->create('', 200, ['Content-Type' => 'application/javascript']));
        $httpResponse->setETag(md5($json . $messages))->setPublic();

        if ($httpResponse->isNotModified($this->request)) {
            return $httpResponse;
        }

        return $httpResponse->setContent($json ? $messages : sprintf('var $locale = %s;', $messages));
    }
}
